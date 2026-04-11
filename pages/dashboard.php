<?php
require_once __DIR__ . '/../includes/header.php';
$isAdminUser = isAdmin();
?>

<!-- Toolbar -->
<div class="d-flex gap-2 align-items-center mb-3 flex-wrap">
    <select class="form-select form-select-sm" id="categoryFilter" style="width:200px" onchange="filterSites()">
        <option value="">Wszystkie kategorie</option>
    </select>
    <select class="form-select form-select-sm" id="clientFilter" style="width:280px" onchange="filterSites()">
        <option value="">Bez linku do...</option>
    </select>
    <button class="btn btn-outline-primary btn-sm" onclick="refreshAllStatuses()">
        <i class="bi bi-arrow-clockwise"></i> Odśwież statusy
    </button>
    <button class="btn btn-outline-success btn-sm" id="refreshGscBtn" onclick="refreshDashboardGsc()" style="display:none">
        <i class="bi bi-graph-up"></i> Odśwież GSC
    </button>
    <span class="text-muted small align-self-center" id="lastStatusCheck" title="Ostatnie odświeżenie statusów"></span>
    <div class="ms-auto d-flex gap-2">
        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addSiteModal">
            <i class="bi bi-plus-lg"></i> Dodaj stronę
        </button>
        <button class="btn btn-outline-primary btn-sm" onclick="document.getElementById('csvFile').click()">
            <i class="bi bi-upload"></i> Importuj CSV
        </button>
        <input type="file" id="csvFile" accept=".csv" style="display:none" onchange="importCsv(this)">
        <button class="btn btn-outline-secondary btn-sm" onclick="exportCsv()">
            <i class="bi bi-download"></i> Eksportuj CSV
        </button>
    </div>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-4" id="dashboardSummary" style="display:none">
    <div class="col">
        <div class="stat-card">
            <div class="stat-card-icon stat-card-icon--primary"><i class="bi bi-globe2"></i></div>
            <div class="stat-card-value" id="sumSites">0</div>
            <div class="stat-card-label">Stron</div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card">
            <div class="stat-card-icon stat-card-icon--success"><i class="bi bi-file-earmark-text"></i></div>
            <div class="stat-card-value" id="sumPosts">0</div>
            <div class="stat-card-label">Wpisów</div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card">
            <div class="stat-card-icon stat-card-icon--info"><i class="bi bi-link-45deg"></i></div>
            <div class="stat-card-value" id="sumLinks">0</div>
            <div class="stat-card-label">Linków</div>
        </div>
    </div>
    <div class="col" id="sumErrorsCard">
        <div class="stat-card">
            <div class="stat-card-icon stat-card-icon--danger"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="stat-card-value" id="sumErrors">0</div>
            <div class="stat-card-label">Błędy</div>
        </div>
    </div>
    <div class="col" id="gscClicksCard" style="display:none">
        <div class="stat-card">
            <div class="stat-card-icon stat-card-icon--primary"><i class="bi bi-cursor"></i></div>
            <div class="stat-card-value" id="sumGscClicks">0</div>
            <div class="stat-card-label">Kliknięcia</div>
            <div class="stat-card-change" id="sumGscClicksChange"></div>
        </div>
    </div>
    <div class="col" id="gscImpressionsCard" style="display:none">
        <div class="stat-card">
            <div class="stat-card-icon stat-card-icon--warning"><i class="bi bi-eye"></i></div>
            <div class="stat-card-value" id="sumGscImpressions">0</div>
            <div class="stat-card-label">Wyświetlenia</div>
            <div class="stat-card-change" id="sumGscImpressionsChange"></div>
        </div>
    </div>
    <div class="col" id="gscKeywordsCard" style="display:none">
        <div class="stat-card">
            <div class="stat-card-icon stat-card-icon--success"><i class="bi bi-search"></i></div>
            <div class="stat-card-value" id="sumGscKeywords">0</div>
            <div class="stat-card-label">Słowa kluczowe</div>
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-striped table-hover" id="sitesTable">
        <thead class="table-dark">
            <tr>
                <th>#</th>
                <th class="sortable th-fixed" data-sort="name" onclick="sortSites('name')">Nazwa <i class="bi bi-chevron-expand small"></i></th>
                <th class="th-fixed">Kategorie</th>
                <th class="sortable th-fixed" data-sort="post_count" onclick="sortSites('post_count')">Wpisy <i class="bi bi-chevron-expand small"></i></th>
                <th class="sortable th-fixed" data-sort="link_count" onclick="sortSites('link_count')">Linki <i class="bi bi-chevron-expand small"></i></th>
                <th class="gsc-col sortable th-fixed" data-sort="gsc_clicks" onclick="sortSites('gsc_clicks')" style="display:none">Klik. <i class="bi bi-chevron-expand small"></i></th>
                <th class="gsc-col sortable th-fixed" data-sort="gsc_impressions" onclick="sortSites('gsc_impressions')" style="display:none">Wyśw. <i class="bi bi-chevron-expand small"></i></th>
                <th class="th-fixed">HTTP</th>
                <th class="th-fixed">API</th>
                <th class="th-fixed">Akcje</th>
            </tr>
        </thead>
        <tbody id="sitesBody">
            <tr><td colspan="10" class="text-center text-muted">Ładowanie...</td></tr>
        </tbody>
    </table>
</div>

<!-- Add Site Modal -->
<div class="modal fade" id="addSiteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="siteModalTitle">Dodaj stronę</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="siteEditId" value="">
                <div class="mb-3">
                    <label class="form-label">Nazwa</label>
                    <input type="text" class="form-control" id="siteName" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">URL (https://)</label>
                    <input type="text" class="form-control" id="siteUrl" placeholder="https://example.com" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Kategorie</label>
                    <input type="text" class="form-control" id="siteCategories" placeholder="np. finanse, zdrowie - oddziel przecinkiem">
                </div>
                <div class="mb-3">
                    <label class="form-label">Login WordPress</label>
                    <input type="text" class="form-control" id="siteUsername" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Application Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="siteAppPassword" required>
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('siteAppPassword', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" onclick="saveSite()">Zapisz</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
