<?php
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
requireAdminApi();

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak pliku lub blad uploadu']);
    exit;
}

$tmpPath = $_FILES['file']['tmp_name'];
$filename = $_FILES['file']['name'];

try {
    $result = parseDocx($tmpPath, $filename);
    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function parseDocx(string $path, string $filename): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Nie mozna otworzyc pliku DOCX');
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === false) {
        throw new RuntimeException('Nie znaleziono word/document.xml');
    }

    $html = convertDocxXmlToHtml($xml);
    $html = cleanHtml($html);
    $html = stripBoldFromHeadings($html);

    // Extract title from first H1
    $title = '';
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
        $title = strip_tags($m[1]);
        $html = preg_replace('/<h1[^>]*>.*?<\/h1>/is', '', $html, 1);
    }

    // Fallback: use filename as title
    if (empty(trim($title))) {
        $title = pathinfo($filename, PATHINFO_FILENAME);
    }

    $html = trim($html);

    return [
        'title' => $title,
        'html_body' => $html,
        'filename' => $filename,
    ];
}

function convertDocxXmlToHtml(string $xml): string {
    $doc = new DOMDocument();
    $doc->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING);

    $html = '';
    $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    $body = $doc->getElementsByTagNameNS($ns, 'body')->item(0);
    if (!$body) return '';

    foreach ($body->childNodes as $node) {
        if ($node->localName === 'p') {
            $html .= convertParagraph($node, $ns);
        } elseif ($node->localName === 'tbl') {
            $html .= convertTable($node, $ns);
        }
    }

    return $html;
}

function convertParagraph(DOMNode $p, string $ns): string {
    // Check paragraph style for headings
    $tag = 'p';
    $pPr = null;
    foreach ($p->childNodes as $child) {
        if ($child->localName === 'pPr') {
            $pPr = $child;
            break;
        }
    }

    if ($pPr) {
        foreach ($pPr->childNodes as $prop) {
            if ($prop->localName === 'pStyle') {
                $val = $prop->getAttribute('w:val');
                if (preg_match('/^Heading(\d)$/i', $val, $m) || preg_match('/^Nagwek(\d)$/i', $val, $m)) {
                    $level = min((int)$m[1], 6);
                    $tag = 'h' . $level;
                }
            }
        }

        // Check for list items
        foreach ($pPr->childNodes as $prop) {
            if ($prop->localName === 'numPr') {
                $tag = 'li';
            }
        }
    }

    // Extract text runs
    $content = '';
    foreach ($p->childNodes as $child) {
        if ($child->localName === 'r') {
            $content .= convertRun($child, $ns);
        } elseif ($child->localName === 'hyperlink') {
            foreach ($child->childNodes as $hChild) {
                if ($hChild->localName === 'r') {
                    $content .= convertRun($hChild, $ns);
                }
            }
        }
    }

    $content = trim($content);
    if ($content === '') return '';

    return "<{$tag}>{$content}</{$tag}>\n";
}

function convertRun(DOMNode $r, string $ns): string {
    $text = '';
    $bold = false;
    $italic = false;

    foreach ($r->childNodes as $child) {
        if ($child->localName === 'rPr') {
            foreach ($child->childNodes as $prop) {
                if ($prop->localName === 'b') $bold = true;
                if ($prop->localName === 'i') $italic = true;
            }
        }
        if ($child->localName === 't') {
            $text .= htmlspecialchars($child->textContent);
        }
        if ($child->localName === 'br') {
            $text .= '<br>';
        }
    }

    if ($bold) $text = '<strong>' . $text . '</strong>';
    if ($italic) $text = '<em>' . $text . '</em>';

    return $text;
}

function convertTable(DOMNode $tbl, string $ns): string {
    $html = '<table>';
    foreach ($tbl->childNodes as $row) {
        if ($row->localName !== 'tr') continue;
        $html .= '<tr>';
        foreach ($row->childNodes as $cell) {
            if ($cell->localName !== 'tc') continue;
            $cellContent = '';
            foreach ($cell->childNodes as $p) {
                if ($p->localName === 'p') {
                    $pHtml = convertParagraph($p, $ns);
                    $cellContent .= strip_tags($pHtml, '<strong><em><br>');
                }
            }
            $html .= '<td>' . trim($cellContent) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';
    return $html . "\n";
}

function stripBoldFromHeadings(string $html): string {
    return preg_replace_callback(
        '/<(h[1-6])(\s[^>]*)?>(.+?)<\/\1>/si',
        function ($m) {
            $tag = $m[1];
            $attrs = $m[2] ?? '';
            $content = $m[3];
            $content = preg_replace('/<strong>(.*?)<\/strong>/si', '$1', $content);
            $content = preg_replace('/<b>(.*?)<\/b>/si', '$1', $content);
            $content = preg_replace('/<span[^>]*style="[^"]*font-weight\s*:\s*(bold|[7-9]\d\d)[^"]*"[^>]*>(.*?)<\/span>/si', '$2', $content);
            return "<{$tag}{$attrs}>{$content}</{$tag}>";
        },
        $html
    );
}

function cleanHtml(string $html): string {
    // Remove class, id, style, data-* attributes
    $html = preg_replace('/\s+(class|id|style)\s*=\s*["\'][^"\']*["\']/i', '', $html);
    $html = preg_replace('/\s+data-[\w-]+\s*=\s*["\'][^"\']*["\']/i', '', $html);
    // Remove empty span tags
    $html = preg_replace('/<span>(.*?)<\/span>/s', '$1', $html);
    // Collapse whitespace
    $html = preg_replace('/\n{3,}/', "\n\n", $html);
    return trim($html);
}
