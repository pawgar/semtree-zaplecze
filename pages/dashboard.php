<?php
require_once __DIR__ . '/../includes/header.php';
$isAdminUser = isAdmin();
?>

<!-- Page header: filters + actions -->
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
            <div class="col-auto">
                <span class="text-secondary small" id="lastStatusCheck" title="Ostatnie odświeżenie statusów"></span>
            </div>
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

<!-- Summary cards -->
<div class="row row-cards mb-3" id="dashboardSummary" style="display:none">
    <div class="col-sm-6 col-lg">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-primary text-white avatar"><i class="ti ti-world"></i></span>
                    </div>
                    <div class="col">
                        <div class="h2 mb-0" id="sumSites">0</div>
                        <div class="text-secondary small">Stron</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-green text-white avatar"><i class="ti ti-file-text"></i></span>
                    </div>
                    <div class="col">
                        <div class="h2 mb-0" id="sumPosts">0</div>
                        <div class="text-secondary small">Wpisów</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-azure text-white avatar"><i class="ti ti-link"></i></span>
                    </div>
                    <div class="col">
                        <div class="h2 mb-0" id="sumLinks">0</div>
                        <div class="text-secondary small">Linków</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg" id="sumErrorsCard">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-red text-white avatar"><i class="ti ti-alert-triangle"></i></span>
                    </div>
                    <div class="col">
                        <div class="h2 mb-0" id="sumErrors">0</div>
                        <div class="text-secondary small">Błędy</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg" id="gscClicksCard" style="display:none">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-blue text-white avatar"><i class="ti ti-click"></i></span>
                    </div>
                    <div class="col">
                        <div class="h2 mb-0" id="sumGscClicks">0</div>
                        <div class="text-secondary small">Kliknięcia <span id="sumGscClicksChange"></span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg" id="gscImpressionsCard" style="display:none">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-orange text-white avatar"><i class="ti ti-eye"></i></span>
                    </div>
                    <div class="col">
                        <div class="h2 mb-0" id="sumGscImpressions">0</div>
                        <div class="text-secondary small">Wyświetlenia <span id="sumGscImpressionsChange"></span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg" id="gscKeywordsCard" style="display:none">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-teal text-white avatar"><i class="ti ti-search"></i></span>
                    </div>
                    <div class="col">
                        <div class="h2 mb-0" id="sumGscKeywords">0</div>
                        <div class="text-secondary small">Słowa kluczowe</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sites table -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-vcenter card-table table-striped table-hover" id="sitesTable">
            <thead>
                <tr>
                    <th class="w-1">#</th>
                    <th class="sortable" data-sort="name" onclick="sortSites('name')" style="cursor:pointer">Nazwa <i class="ti ti-arrows-sort text-secondary"></i></th>
                    <th>Kategorie</th>
                    <th class="sortable" data-sort="post_count" onclick="sortSites('post_count')" style="cursor:pointer">Wpisy <i class="ti ti-arrows-sort text-secondary"></i></th>
                    <th class="sortable" data-sort="link_count" onclick="sortSites('link_count')" style="cursor:pointer">Linki <i class="ti ti-arrows-sort text-secondary"></i></th>
                    <th class="gsc-col sortable" data-sort="gsc_clicks" onclick="sortSites('gsc_clicks')" style="display:none; cursor:pointer">Klik. <i class="ti ti-arrows-sort text-secondary"></i></th>
                    <th class="gsc-col sortable" data-sort="gsc_impressions" onclick="sortSites('gsc_impressions')" style="display:none; cursor:pointer">Wyśw. <i class="ti ti-arrows-sort text-secondary"></i></th>
                    <th class="text-center">HTTP</th>
                    <th class="text-center">API</th>
                    <th class="text-center">Akcje</th>
                </tr>
            </thead>
            <tbody id="sitesBody">
                <tr><td colspan="10" class="text-center text-secondary py-4">
                    <div class="spinner-border spinner-border-sm me-2"></div>Ładowanie...
                </td></tr>
            </tbody>
        </table>
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
