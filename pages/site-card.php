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
    <!-- Page header -->
    <div class="row align-items-center mb-4">
        <div class="col-auto">
            <a href="index.php" class="btn btn-icon btn-outline-secondary" title="Powrót"><i class="ti ti-arrow-left"></i></a>
        </div>
        <div class="col">
            <h2 class="page-title mb-1"><?= htmlspecialchars($site['name']) ?></h2>
            <a href="<?= htmlspecialchars($site['url']) ?>" target="_blank" rel="noopener" class="text-secondary small text-decoration-none">
                <?= htmlspecialchars($site['url']) ?> <i class="ti ti-external-link ms-1"></i>
            </a>
        </div>
    </div>

    <!-- Stats row -->
    <div class="row row-cards mb-4">
        <div class="col-sm-6 col-lg">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto"><span class="bg-green text-white avatar"><i class="ti ti-file-text"></i></span></div>
                        <div class="col">
                            <div class="h2 mb-0"><?= $site['post_count'] ?? '-' ?></div>
                            <div class="text-secondary small">Wpisy</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto"><span class="bg-azure text-white avatar"><i class="ti ti-link"></i></span></div>
                        <div class="col">
                            <div class="h2 mb-0"><?= $linkCount ?></div>
                            <div class="text-secondary small">Linki</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto"><span class="bg-primary text-white avatar"><i class="ti ti-edit"></i></span></div>
                        <div class="col">
                            <div class="h2 mb-0"><?= $pubCount ?></div>
                            <div class="text-secondary small">Publikacje</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg" id="siteCardGscClicks" style="display:none">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto"><span class="bg-blue text-white avatar"><i class="ti ti-click"></i></span></div>
                        <div class="col">
                            <div class="h2 mb-0" id="scClicks">0</div>
                            <div class="text-secondary small">Kliknięcia <span id="scClicksChange"></span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg" id="siteCardGscImpressions" style="display:none">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto"><span class="bg-orange text-white avatar"><i class="ti ti-eye"></i></span></div>
                        <div class="col">
                            <div class="h2 mb-0" id="scImpressions">0</div>
                            <div class="text-secondary small">Wyświetlenia <span id="scImpressionsChange"></span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg" id="siteCardGscCtr" style="display:none">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto"><span class="bg-teal text-white avatar"><i class="ti ti-percentage"></i></span></div>
                        <div class="col">
                            <div class="h2 mb-0" id="scCtr">0%</div>
                            <div class="text-secondary small">CTR</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg" id="siteCardGscPos" style="display:none">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto"><span class="bg-secondary text-white avatar"><i class="ti ti-sort-ascending"></i></span></div>
                        <div class="col">
                            <div class="h2 mb-0" id="scPosition">0</div>
                            <div class="text-secondary small">Śr. pozycja</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- GSC controls -->
    <div class="card mb-3" id="siteCardGscControls" style="display:none">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-auto">
                    <select class="form-select form-select-sm" id="scDateRange" onchange="loadSiteCard()" style="min-width:170px">
                        <option value="7d">Ostatnie 7 dni</option>
                        <option value="28d" selected>Ostatnie 28 dni</option>
                        <option value="3m">Ostatnie 3 miesiące</option>
                        <option value="6m">Ostatnie 6 miesięcy</option>
                        <option value="12m">Ostatnie 12 miesięcy</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-outline-success btn-sm" onclick="refreshSiteCardGsc()">
                        <i class="ti ti-refresh me-1"></i> Odśwież GSC
                    </button>
                </div>
                <div class="col">
                    <span class="text-secondary small" id="scDateInfo"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- GSC Chart -->
    <div class="card mb-3" id="siteCardChart" style="display:none">
        <div class="card-header">
            <h3 class="card-title"><i class="ti ti-chart-line me-2"></i>Wykres GSC</h3>
        </div>
        <div class="card-body">
            <div style="position:relative;height:240px">
                <canvas id="scChartCanvas"></canvas>
            </div>
        </div>
    </div>

    <!-- Top Keywords + Top Pages -->
    <div class="row row-cards" id="siteCardTables" style="display:none">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="ti ti-search me-2"></i>Top słowa kluczowe (20)</h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-vcenter table-sm card-table table-striped mb-0">
                            <thead><tr><th>Słowo kluczowe</th><th class="text-end">Klik.</th><th class="text-end">Wyśw.</th><th class="text-end">CTR</th><th class="text-end">Poz.</th></tr></thead>
                            <tbody id="scKeywordsBody"><tr><td colspan="5" class="text-center text-secondary py-3">Ładowanie...</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="ti ti-file me-2"></i>Top strony (20)</h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-vcenter table-sm card-table table-striped mb-0">
                            <thead><tr><th>URL</th><th class="text-end">Klik.</th><th class="text-end">Wyśw.</th><th class="text-end">CTR</th><th class="text-end">Poz.</th></tr></thead>
                            <tbody id="scPagesBody"><tr><td colspan="5" class="text-center text-secondary py-3">Ładowanie...</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="siteCardNoGsc" class="alert alert-info mt-3" style="display:none" role="alert">
        <div class="d-flex">
            <div><i class="ti ti-info-circle me-2"></i></div>
            <div>Brak danych GSC dla tej strony. Upewnij się, że GSC jest połączone w <a href="index.php?page=settings" class="alert-link">ustawieniach</a> i strona jest dodana do Google Search Console.</div>
        </div>
    </div>
</div>

<!-- Chart.js (still used on this page; migration to ApexCharts in next step) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
