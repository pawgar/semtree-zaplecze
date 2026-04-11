<?php
require_once __DIR__ . '/../includes/header.php';
$siteId = (int)($_GET['id'] ?? 0);
if (!$siteId) {
    echo '<div class="alert alert-danger">Brak ID strony.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
$db = getDb();
$stmt = $db->prepare('SELECT * FROM sites WHERE id = :id');
$stmt->bindValue(':id', $siteId, SQLITE3_INTEGER);
$site = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if (!$site) {
    echo '<div class="alert alert-danger">Strona nie znaleziona.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Count links for this site
$linkStmt = $db->prepare('SELECT COUNT(*) as cnt FROM links WHERE site_id = :id');
$linkStmt->bindValue(':id', $siteId, SQLITE3_INTEGER);
$linkCount = $linkStmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'] ?? 0;

// Count publications
$pubStmt = $db->prepare('SELECT COUNT(*) as cnt FROM publications WHERE site_id = :id');
$pubStmt->bindValue(':id', $siteId, SQLITE3_INTEGER);
$pubCount = $pubStmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'] ?? 0;
?>

<input type="hidden" id="siteCardId" value="<?= $siteId ?>">
<input type="hidden" id="siteCardUrl" value="<?= htmlspecialchars($site['url']) ?>">

<div id="siteCardContainer">
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
        <h4 class="mb-0"><?= htmlspecialchars($site['name']) ?></h4>
        <a href="<?= htmlspecialchars($site['url']) ?>" target="_blank" class="text-muted small"><?= htmlspecialchars($site['url']) ?> <i class="bi bi-box-arrow-up-right"></i></a>
    </div>

    <!-- Stats row -->
    <div class="row g-3 mb-4">
        <div class="col">
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--success"><i class="bi bi-file-earmark-text"></i></div>
                <div class="stat-card-value"><?= $site['post_count'] ?? '-' ?></div>
                <div class="stat-card-label">Wpisy</div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--info"><i class="bi bi-link-45deg"></i></div>
                <div class="stat-card-value"><?= $linkCount ?></div>
                <div class="stat-card-label">Linki</div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--primary"><i class="bi bi-pencil-square"></i></div>
                <div class="stat-card-value"><?= $pubCount ?></div>
                <div class="stat-card-label">Publikacje</div>
            </div>
        </div>
        <div class="col" id="siteCardGscClicks" style="display:none">
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--primary"><i class="bi bi-cursor"></i></div>
                <div class="stat-card-value" id="scClicks">0</div>
                <div class="stat-card-label">Kliknięcia</div>
                <div class="stat-card-change" id="scClicksChange"></div>
            </div>
        </div>
        <div class="col" id="siteCardGscImpressions" style="display:none">
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--warning"><i class="bi bi-eye"></i></div>
                <div class="stat-card-value" id="scImpressions">0</div>
                <div class="stat-card-label">Wyświetlenia</div>
                <div class="stat-card-change" id="scImpressionsChange"></div>
            </div>
        </div>
        <div class="col" id="siteCardGscCtr" style="display:none">
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--success"><i class="bi bi-percent"></i></div>
                <div class="stat-card-value" id="scCtr">0%</div>
                <div class="stat-card-label">CTR</div>
            </div>
        </div>
        <div class="col" id="siteCardGscPos" style="display:none">
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--secondary"><i class="bi bi-sort-numeric-down"></i></div>
                <div class="stat-card-value" id="scPosition">0</div>
                <div class="stat-card-label">Śr. pozycja</div>
            </div>
        </div>
    </div>

    <!-- GSC controls -->
    <div class="d-flex gap-2 align-items-center mb-3" id="siteCardGscControls" style="display:none">
        <select class="form-select form-select-sm" id="scDateRange" onchange="loadSiteCard()" style="width:160px">
            <option value="7d">Ostatnie 7 dni</option>
            <option value="28d" selected>Ostatnie 28 dni</option>
            <option value="3m">Ostatnie 3 miesiące</option>
            <option value="6m">Ostatnie 6 miesięcy</option>
            <option value="12m">Ostatnie 12 miesięcy</option>
        </select>
        <button class="btn btn-outline-success btn-sm" onclick="refreshSiteCardGsc()">
            <i class="bi bi-arrow-clockwise"></i> Odśwież GSC
        </button>
        <span class="text-muted small" id="scDateInfo"></span>
    </div>

    <!-- GSC Chart -->
    <div class="content-card mb-4" id="siteCardChart" style="display:none">
        <div class="content-card-header">
            <i class="bi bi-graph-up"></i> Wykres GSC
        </div>
        <div class="content-card-body">
            <div style="position:relative;height:220px">
                <canvas id="scChartCanvas"></canvas>
            </div>
        </div>
    </div>

    <!-- Top Keywords + Top Pages -->
    <div class="row g-4" id="siteCardTables" style="display:none">
        <div class="col-lg-6">
            <div class="content-card">
                <div class="content-card-header">
                    <i class="bi bi-search"></i> Top słowa kluczowe (20)
                </div>
                <div class="content-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead><tr><th>Słowo kluczowe</th><th class="text-end">Klik.</th><th class="text-end">Wyśw.</th><th class="text-end">CTR</th><th class="text-end">Poz.</th></tr></thead>
                            <tbody id="scKeywordsBody"><tr><td colspan="5" class="text-center text-muted">Ładowanie...</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="content-card">
                <div class="content-card-header">
                    <i class="bi bi-file-earmark"></i> Top strony (20)
                </div>
                <div class="content-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead><tr><th>URL</th><th class="text-end">Klik.</th><th class="text-end">Wyśw.</th><th class="text-end">CTR</th><th class="text-end">Poz.</th></tr></thead>
                            <tbody id="scPagesBody"><tr><td colspan="5" class="text-center text-muted">Ładowanie...</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="siteCardNoGsc" class="alert alert-info mt-3" style="display:none">
        <i class="bi bi-info-circle"></i> Brak danych GSC dla tej strony. Upewnij się, że GSC jest połączone w <a href="index.php?page=settings">ustawieniach</a> i strona jest dodana do Google Search Console.
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
