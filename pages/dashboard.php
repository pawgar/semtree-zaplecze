<?php
require_once __DIR__ . '/../includes/header.php';
$isAdminUser = isAdmin();

// Polish greeting by hour
$h = (int)date('H');
$greetingPrefix = $h < 10 ? 'Dzień dobry' : ($h < 18 ? 'Witaj' : 'Dobry wieczór');
?>

<!-- ═══ ROW 1: Welcome + GSC totals + Network health gauge ═══ -->
<div class="row row-cards mb-3" id="dashboardSummary">
    <!-- Welcome card -->
    <div class="col-md-6 col-lg-5">
        <div class="card h-100" style="background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%)">
            <div class="card-body d-flex flex-column flex-sm-row align-items-center gap-3">
                <div class="flex-fill">
                    <h2 class="h3 mb-1 text-white"><?= $greetingPrefix ?>, <?= htmlspecialchars($_SESSION['username']) ?></h2>
                    <p class="text-secondary mb-3 small">Podsumowanie dzisiejszej aktywności</p>
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="text-secondary small mb-1">Publikacje dziś</div>
                            <div class="h2 mb-0 text-white" id="statTodayPubs">—</div>
                        </div>
                        <div class="col-6">
                            <div class="text-secondary small mb-1">Ostatnie 7 dni</div>
                            <div class="h2 mb-0 text-white" id="statWeekPubs">—</div>
                        </div>
                        <div class="col-12 mt-2">
                            <div class="text-secondary small mb-1">Sukces auto-publikacji (30 dni)</div>
                            <div class="progress progress-sm mb-1" style="background:rgba(255,255,255,0.08)">
                                <div class="progress-bar bg-success" id="statSuccessBar" style="width:0%"></div>
                            </div>
                            <div class="small text-success" id="statSuccessText">—</div>
                        </div>
                        <div class="col-12 mt-2">
                            <div class="text-secondary small" id="welcomeStatusInfo">—</div>
                        </div>
                    </div>
                </div>
                <img src="https://tabler.io/assets/illustrations/undraw_elements.svg" alt="" style="max-height:150px;opacity:0.9" onerror="this.style.display='none'">
            </div>
        </div>
    </div>

    <!-- GSC Impressions with sparkline -->
    <div class="col-md-6 col-lg-4">
        <div class="card h-100" id="gscImpressionsCard">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div>
                        <div class="subheader text-secondary small">Wyświetlenia GSC (28 dni)</div>
                        <div class="h1 m-0" id="statGscImpressions">—</div>
                    </div>
                    <div class="ms-auto lh-1">
                        <span class="text-secondary small">vs poprzednie 28d</span>
                        <div class="text-end mt-1" id="statGscImpressionsChange">—</div>
                    </div>
                </div>
                <div id="chartGscImpressions" style="min-height:100px;margin-top:12px"></div>
            </div>
        </div>
    </div>

    <!-- Network health gauge -->
    <div class="col-md-12 col-lg-3">
        <div class="card h-100">
            <div class="card-body text-center">
                <div class="subheader text-secondary small mb-2">Kondycja sieci</div>
                <div id="chartNetwork" style="height:140px"></div>
                <div class="mt-2 small text-secondary" id="statNetworkText">—</div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ ROW 2: GSC clicks / Publications / Keywords / Links (sparkline cards) ═══ -->
<div class="row row-cards mb-3">
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="subheader text-secondary small">Kliknięcia GSC</div>
                    <div class="ms-auto small" id="statGscClicksChange">—</div>
                </div>
                <div class="h1 mb-3 mt-1" id="statGscClicks">—</div>
                <div id="chartGscClicks" style="min-height:35px"></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="subheader text-secondary small">Publikacje (30 dni)</div>
                </div>
                <div class="h1 mb-3 mt-1" id="statPubsTotal">—</div>
                <div id="chartPubsDaily" style="min-height:40px"></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="subheader text-secondary small">Słowa kluczowe</div>
                </div>
                <div class="h1 mb-3 mt-1" id="statKeywords">—</div>
                <div class="text-secondary small">Unikalne frazy rankujące</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="subheader text-secondary small">Linki do klientów</div>
                </div>
                <div class="h1 mb-3 mt-1" id="statLinks">—</div>
                <div class="text-secondary small">Łącznie na wszystkich stronach</div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ ROW 3: Auto-publish info boxes ═══ -->
<div class="row row-cards mb-3">
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-green text-white avatar"><i class="ti ti-circle-check"></i></span></div>
                    <div class="col">
                        <div class="font-weight-medium"><span id="statTodayAutoPubs">0</span> dziś</div>
                        <div class="text-secondary small">Auto-publikacje</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-orange text-white avatar"><i class="ti ti-clock"></i></span></div>
                    <div class="col">
                        <div class="font-weight-medium"><span id="statPendingQueue">0</span> w kolejce</div>
                        <div class="text-secondary small">Oczekuje na publikację</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-red text-white avatar"><i class="ti ti-alert-triangle"></i></span></div>
                    <div class="col">
                        <div class="font-weight-medium"><span id="statAutoErrors">0</span> błędów</div>
                        <div class="text-secondary small">Auto-publikacje</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-primary text-white avatar"><i class="ti ti-robot"></i></span></div>
                    <div class="col">
                        <div class="font-weight-medium" id="statNextCron">—</div>
                        <div class="text-secondary small">Następny CRON</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Page header: filters + actions (after stats, kept compact) -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-center">
            <div class="col-auto">
                <select class="form-select form-select-sm" id="categoryFilter" style="min-width:180px" onchange="filterSites()">
                    <option value="">Wszystkie kategorie</option>
                </select>
            </div>
            <div class="col-auto">
                <select class="form-select form-select-sm" id="clientFilter" style="min-width:220px" onchange="filterSites()">
                    <option value="">Bez linku do...</option>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary btn-sm" onclick="refreshAllStatuses()">
                    <i class="ti ti-refresh me-1"></i> Odśwież statusy
                </button>
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-success btn-sm" id="refreshGscBtn" onclick="refreshDashboardGsc()" style="display:none">
                    <i class="ti ti-chart-line me-1"></i> Odśwież GSC
                </button>
            </div>
            <span id="lastStatusCheck" style="display:none"></span>
            <div class="col-auto ms-auto">
                <div class="btn-list">
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSiteModal">
                        <i class="ti ti-plus me-1"></i> Dodaj stronę
                    </button>
                    <button class="btn btn-outline-primary btn-sm" onclick="document.getElementById('csvFile').click()">
                        <i class="ti ti-upload me-1"></i> Importuj CSV
                    </button>
                    <input type="file" id="csvFile" accept=".csv" style="display:none" onchange="importCsv(this)">
                    <button class="btn btn-outline-secondary btn-sm" onclick="exportCsv()">
                        <i class="ti ti-download me-1"></i> Eksportuj CSV
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Legacy hidden IDs kept for app.js compatibility (updateDashboardSummary) -->
<div style="display:none">
    <span id="sumSites"></span><span id="sumPosts"></span><span id="sumLinks"></span>
    <span id="sumErrors"></span><span id="sumErrorsCard"></span>
    <span id="sumGscClicks"></span><span id="sumGscClicksChange"></span><span id="gscClicksCard"></span>
    <span id="sumGscImpressions"></span><span id="sumGscImpressionsChange"></span>
    <span id="sumGscKeywords"></span><span id="gscKeywordsCard"></span>
</div>

<!-- Sites table -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-vcenter card-table table-nowrap" id="sitesTable">
            <thead>
                <tr>
                    <th class="w-1 text-secondary small text-uppercase">#</th>
                    <th class="sortable text-secondary small text-uppercase" data-sort="name" onclick="sortSites('name')" style="cursor:pointer">Nazwa <i class="ti ti-arrows-sort"></i></th>
                    <th class="sortable text-secondary small text-uppercase" data-sort="post_count" onclick="sortSites('post_count')" style="cursor:pointer">Wpisy <i class="ti ti-arrows-sort"></i></th>
                    <th class="sortable text-secondary small text-uppercase" data-sort="link_count" onclick="sortSites('link_count')" style="cursor:pointer">Linki <i class="ti ti-arrows-sort"></i></th>
                    <th class="gsc-col sortable text-end text-secondary small text-uppercase" data-sort="gsc_clicks" onclick="sortSites('gsc_clicks')" style="display:none; cursor:pointer">Klik. <i class="ti ti-arrows-sort"></i></th>
                    <th class="gsc-col sortable text-end text-secondary small text-uppercase" data-sort="gsc_impressions" onclick="sortSites('gsc_impressions')" style="display:none; cursor:pointer">Wyśw. <i class="ti ti-arrows-sort"></i></th>
                    <th class="text-center text-secondary small text-uppercase">HTTP</th>
                    <th class="text-center text-secondary small text-uppercase">API</th>
                    <th class="text-end text-secondary small text-uppercase">Akcje</th>
                </tr>
            </thead>
            <tbody id="sitesBody">
                <tr><td colspan="9" class="text-center text-secondary py-4">
                    <div class="spinner-border spinner-border-sm me-2"></div>Ładowanie...
                </td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══ CRON activity section ═══ -->
<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title"><i class="ti ti-clock-hour-9 me-2"></i>Aktywność CRON</h3>
        <div class="card-subtitle text-secondary">Status automatycznych zadań w tle</div>
    </div>
    <div class="card-body">
        <div class="row row-cards">
            <!-- CRON 1: Statusy stron (23:00) -->
            <div class="col-md-4">
                <div class="card card-sm border">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-2">
                            <span class="bg-blue text-white avatar me-2"><i class="ti ti-activity"></i></span>
                            <div class="flex-fill">
                                <div class="fw-semibold">Statusy stron</div>
                                <div class="text-secondary small">codziennie o 23:00</div>
                            </div>
                            <span id="cronStatusBadge"></span>
                        </div>
                        <div class="text-secondary small">
                            <div>Ostatni run: <span id="cronStatusLast">—</span></div>
                            <div>Następny: <span id="cronStatusNext">—</span></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- CRON 2: GSC (6:00) -->
            <div class="col-md-4">
                <div class="card card-sm border">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-2">
                            <span class="bg-orange text-white avatar me-2"><i class="ti ti-chart-line"></i></span>
                            <div class="flex-fill">
                                <div class="fw-semibold">Dane Google Search Console</div>
                                <div class="text-secondary small">codziennie o 06:00</div>
                            </div>
                            <span id="cronGscBadge"></span>
                        </div>
                        <div class="text-secondary small">
                            <div>Ostatni run: <span id="cronGscLast">—</span></div>
                            <div>Następny: <span id="cronGscNext">—</span></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- CRON 3: Auto-publish (9:00) -->
            <div class="col-md-4">
                <div class="card card-sm border">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-2">
                            <span class="bg-green text-white avatar me-2"><i class="ti ti-robot"></i></span>
                            <div class="flex-fill">
                                <div class="fw-semibold">Auto-publikacje</div>
                                <div class="text-secondary small">codziennie o 09:00</div>
                            </div>
                            <span id="cronApBadge"></span>
                        </div>
                        <div class="text-secondary small">
                            <div>Ostatni run: <span id="cronApLast">—</span></div>
                            <div>Następny: <span id="cronApNext">—</span></div>
                            <div class="mt-1">Dziś: <strong id="cronApToday">0</strong> opublikowanych · <strong class="text-danger" id="cronApErrors">0</strong> błędów</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Site Modal -->
<div class="modal modal-blur fade" id="addSiteModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="siteModalTitle">Dodaj stronę</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="siteEditId" value="">
                <div class="mb-3">
                    <label class="form-label required">Nazwa</label>
                    <input type="text" class="form-control" id="siteName" required>
                </div>
                <div class="mb-3">
                    <label class="form-label required">URL (https://)</label>
                    <input type="text" class="form-control" id="siteUrl" placeholder="https://example.com" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Kategorie</label>
                    <input type="text" class="form-control" id="siteCategories" placeholder="np. finanse, zdrowie — oddziel przecinkiem">
                </div>
                <div class="mb-3">
                    <label class="form-label required">Login WordPress</label>
                    <input type="text" class="form-control" id="siteUsername" required>
                </div>
                <div class="mb-0">
                    <label class="form-label required">Application Password</label>
                    <div class="input-group input-group-flat">
                        <input type="password" class="form-control" id="siteAppPassword" required>
                        <span class="input-group-text">
                            <a href="#" class="link-secondary" onclick="event.preventDefault(); togglePasswordField('siteAppPassword', this);" title="Pokaż/ukryj">
                                <i class="ti ti-eye"></i>
                            </a>
                        </span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary ms-auto" onclick="saveSite()">
                    <i class="ti ti-check me-1"></i> Zapisz
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
