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
        <div class="col-auto">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <i class="bi bi-file-earmark-text fs-4 text-success"></i>
                    <div><div class="small text-muted">Wpisy</div><div class="fw-bold fs-5"><?= $site['post_count'] ?? '-' ?></div></div>
                </div>
            </div>
        </div>
        <div class="col-auto">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <i class="bi bi-link-45deg fs-4 text-info"></i>
                    <div><div class="small text-muted">Linki</div><div class="fw-bold fs-5"><?= $linkCount ?></div></div>
                </div>
            </div>
        </div>
        <div class="col-auto">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <i class="bi bi-pencil-square fs-4 text-primary"></i>
                    <div><div class="small text-muted">Publikacje</div><div class="fw-bold fs-5"><?= $pubCount ?></div></div>
                </div>
            </div>
        </div>
        <div class="col-auto" id="siteCardGscClicks" style="display:none">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <i class="bi bi-cursor fs-4 text-primary"></i>
                    <div>
                        <div class="small text-muted">Kliknięcia</div>
                        <div class="fw-bold fs-5" id="scClicks">0</div>
                        <div class="small" id="scClicksChange"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-auto" id="siteCardGscImpressions" style="display:none">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <i class="bi bi-eye fs-4 text-warning"></i>
                    <div>
                        <div class="small text-muted">Wyświetlenia</div>
                        <div class="fw-bold fs-5" id="scImpressions">0</div>
                        <div class="small" id="scImpressionsChange"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-auto" id="siteCardGscCtr" style="display:none">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <i class="bi bi-percent fs-4 text-success"></i>
                    <div>
                        <div class="small text-muted">CTR</div>
                        <div class="fw-bold fs-5" id="scCtr">0%</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-auto" id="siteCardGscPos" style="display:none">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                    <i class="bi bi-sort-numeric-down fs-4 text-secondary"></i>
                    <div>
                        <div class="small text-muted">Śr. pozycja</div>
                        <div class="fw-bold fs-5" id="scPosition">0</div>
                    </div>
                </div>
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
            <canvas id="scChartCanvas" height="250"></canvas>
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
