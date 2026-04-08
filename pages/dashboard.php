<?php
require_once __DIR__ . '/../includes/header.php';
$isAdminUser = isAdmin();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-grid"></i> Strony zapleczowe</h4>
    <div class="d-flex gap-2 align-items-center">
        <select class="form-select form-select-sm" id="categoryFilter" style="width:200px" onchange="filterSites()">
            <option value="">Wszystkie kategorie</option>
        </select>
        <select class="form-select form-select-sm" id="clientFilter" style="width:220px" onchange="filterSites()">
            <option value="">Wszyscy klienci</option>
        </select>
        <button class="btn btn-outline-primary btn-sm" onclick="refreshAllStatuses()">
            <i class="bi bi-arrow-clockwise"></i> Odswiez statusy
        </button>
        <span class="text-muted small align-self-center" id="lastStatusCheck" title="Ostatnie odswiezenie statusow"></span>
        <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#addSiteModal">
            <i class="bi bi-plus-lg"></i> Dodaj strone
        </button>
        <button class="btn btn-outline-info btn-sm" onclick="document.getElementById('csvFile').click()">
            <i class="bi bi-upload"></i> Importuj CSV
        </button>
        <input type="file" id="csvFile" accept=".csv" style="display:none" onchange="importCsv(this)">
        <button class="btn btn-outline-secondary btn-sm" onclick="exportCsv()">
            <i class="bi bi-download"></i> Eksportuj CSV
        </button>
        <?php if ($isAdminUser): ?>
        <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
            <i class="bi bi-key"></i> Zmien haslo na wszystkich
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-4" id="dashboardSummary" style="display:none">
    <div class="col-auto">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                <i class="bi bi-globe2 fs-4 text-primary"></i>
                <div><div class="small text-muted">Stron</div><div class="fw-bold fs-5" id="sumSites">0</div></div>
            </div>
        </div>
    </div>
    <div class="col-auto">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                <i class="bi bi-file-earmark-text fs-4 text-success"></i>
                <div><div class="small text-muted">Wpisow</div><div class="fw-bold fs-5" id="sumPosts">0</div></div>
            </div>
        </div>
    </div>
    <div class="col-auto">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                <i class="bi bi-link-45deg fs-4 text-info"></i>
                <div><div class="small text-muted">Linkow</div><div class="fw-bold fs-5" id="sumLinks">0</div></div>
            </div>
        </div>
    </div>
    <div class="col-auto">
        <div class="card border-0 shadow-sm" id="sumErrorsCard">
            <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle fs-4 text-danger"></i>
                <div><div class="small text-muted">Bledy HTTP/API</div><div class="fw-bold fs-5" id="sumErrors">0</div></div>
            </div>
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-striped table-hover" id="sitesTable">
        <thead class="table-dark">
            <tr>
                <th>#</th>
                <th class="sortable" data-sort="name" onclick="sortSites('name')">Nazwa <i class="bi bi-chevron-expand small"></i></th>
                <th>URL</th>
                <th>Kategorie</th>
                <th class="sortable" data-sort="post_count" onclick="sortSites('post_count')">Wpisy <i class="bi bi-chevron-expand small"></i></th>
                <th class="sortable" data-sort="link_count" onclick="sortSites('link_count')">Linki <i class="bi bi-chevron-expand small"></i></th>
                <th>HTTP</th>
                <th>API</th>
                <th>Akcje</th>
            </tr>
        </thead>
        <tbody id="sitesBody">
            <tr><td colspan="9" class="text-center text-muted">Ladowanie...</td></tr>
        </tbody>
    </table>
</div>

<!-- Add Site Modal -->
<div class="modal fade" id="addSiteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="siteModalTitle">Dodaj strone</h5>
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

<?php if ($isAdminUser): ?>
<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="bi bi-key"></i> Zmien haslo na wszystkich stronach</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">Nowe haslo zostanie ustawione na wszystkich stronach zapleczowych jako haslo logowania do wp-admin. Application Passwords pozostana bez zmian.</p>
                <div class="mb-3">
                    <label class="form-label">Nowe haslo</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="newGlobalPassword">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('newGlobalPassword', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <div id="passwordChangeResults" class="d-none">
                    <hr>
                    <h6>Wyniki:</h6>
                    <div id="passwordChangeLog"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zamknij</button>
                <button type="button" class="btn btn-warning" id="btnChangeAllPasswords" onclick="changeAllPasswords()">
                    <i class="bi bi-key"></i> Zmien haslo
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isAdminUser): ?>
<!-- Cron Settings -->
<div class="card mt-4 mb-4">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-clock-history"></i> Automatyczne odswiezanie statusow (CRON)</h6>
        <p class="text-muted small mb-2">Ustaw cron job na serwerze (np. o 23:00 czasu polskiego):</p>
        <div class="d-flex gap-2 align-items-center mb-2">
            <label class="small text-muted text-nowrap">Token CRON:</label>
            <input type="text" class="form-control form-control-sm" id="cronTokenInput" style="max-width:300px" placeholder="Wygeneruj lub wpisz token">
            <button class="btn btn-outline-primary btn-sm" onclick="saveCronToken()">Zapisz</button>
            <button class="btn btn-outline-secondary btn-sm" onclick="generateCronToken()">Generuj</button>
        </div>
        <code class="small d-block bg-light p-2 rounded" id="cronCommandPreview">
            0 23 * * * curl -s "<?= htmlspecialchars(rtrim(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-app.com'), '/')) ?>/api/cron-status.php?token=YOUR_TOKEN"
        </code>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
