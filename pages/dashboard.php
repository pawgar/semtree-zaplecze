<?php
require_once __DIR__ . '/../includes/header.php';
$isAdminUser = isAdmin();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-grid"></i> Strony zapleczowe</h4>
    <div class="d-flex gap-2 align-items-center">
        <select class="form-select form-select-sm" id="categoryFilter" style="width:200px" onchange="filterSites(this.value)">
            <option value="">Wszystkie kategorie</option>
        </select>
        <button class="btn btn-outline-primary btn-sm" onclick="refreshAllStatuses()">
            <i class="bi bi-arrow-clockwise"></i> Odswiez statusy
        </button>
        <?php if ($isAdminUser): ?>
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
        <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
            <i class="bi bi-key"></i> Zmien haslo na wszystkich
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-striped table-hover" id="sitesTable">
        <thead class="table-dark">
            <tr>
                <th>#</th>
                <th>Nazwa</th>
                <th>URL</th>
                <th>Login WP</th>
                <th>App Password</th>
                <th>Kategorie</th>
                <th>Wpisy</th>
                <th>Linki</th>
                <th>Status HTTP</th>
                <th>API</th>
                <?php if ($isAdminUser): ?>
                <th>Akcje</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody id="sitesBody">
            <tr><td colspan="11" class="text-center text-muted">Ladowanie...</td></tr>
        </tbody>
    </table>
</div>

<?php if ($isAdminUser): ?>
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

<script>
    const IS_ADMIN = <?= $isAdminUser ? 'true' : 'false' ?>;
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
