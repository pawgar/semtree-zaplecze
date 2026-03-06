<?php
/**
 * Shared functions for extracting external links from HTML content.
 * Used by api/links.php (post-publish) and api/scan-links.php (full scan).
 */

/**
 * Extract external links from HTML content.
 * @param string $html HTML content of a post
 * @param string $siteDomain Domain of the PBN site (e.g., "zaplecze1.pl")
 * @return array Array of ['target_url', 'anchor_text', 'link_type']
 */
function extractExternalLinks(string $html, string $siteDomain): array {
    if (empty(trim($html))) return [];

    $siteDomain = strtolower(preg_replace('/^www\./', '', $siteDomain));

    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

    $links = [];
    $anchors = $doc->getElementsByTagName('a');

    foreach ($anchors as $a) {
        $href = trim($a->getAttribute('href'));

        // Skip empty, anchors, mailto, javascript, tel
        if (!$href || $href[0] === '#') continue;
        if (preg_match('/^(mailto:|javascript:|tel:)/i', $href)) continue;

        // Parse URL
        $parsed = @parse_url($href);
        if (!$parsed || empty($parsed['host'])) continue;

        $linkDomain = strtolower(preg_replace('/^www\./', '', $parsed['host']));

        // Skip internal links (same domain)
        if ($linkDomain === $siteDomain) continue;

        // Skip WordPress internal paths
        if (preg_match('/\/(wp-admin|wp-content|wp-includes|wp-login)\//i', $href)) continue;

        $rel = strtolower($a->getAttribute('rel') ?: '');
        $linkType = str_contains($rel, 'nofollow') ? 'nofollow' : 'dofollow';

        $anchorText = trim($a->textContent);

        $links[] = [
            'target_url' => $href,
            'anchor_text' => $anchorText,
            'link_type' => $linkType,
        ];
    }

    return $links;
}

/**
 * Match a target URL's domain against a list of client domains.
 * @param string $targetUrl Full URL (e.g., "https://pocash.pl/kredyty")
 * @param array $clientDomains Associative array [domain => client_id] (e.g., ["pocash.pl" => 3])
 * @return int|null Client ID or null if no match
 */
function matchClientDomain(string $targetUrl, array $clientDomains): ?int {
    $parsed = @parse_url($targetUrl);
    if (!$parsed || empty($parsed['host'])) return null;

    $domain = strtolower(preg_replace('/^www\./', '', $parsed['host']));

    // Exact match
    if (isset($clientDomains[$domain])) {
        return $clientDomains[$domain];
    }

    // Subdomain match (e.g., blog.pocash.pl matches pocash.pl)
    foreach ($clientDomains as $clientDomain => $clientId) {
        if (str_ends_with($domain, '.' . $clientDomain)) {
            return $clientId;
        }
    }

    return null;
}

/**
 * Clean/normalize a domain string for storage.
 * Strips protocol, www, trailing slash.
 */
function normalizeDomain(string $domain): string {
    $domain = trim($domain);
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = preg_replace('/^www\./i', '', $domain);
    $domain = rtrim($domain, '/');
    return strtolower($domain);
}
