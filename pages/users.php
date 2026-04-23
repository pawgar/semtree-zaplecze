<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="ti ti-users me-2"></i>Użytkownicy</h3>
        <div class="card-actions">
            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="ti ti-user-plus me-1"></i>Dodaj pracownika
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-vcenter card-table table-striped table-hover">
                <thead>
                    <tr>
                        <th class="w-1">#</th>
                        <th>Login</th>
                        <th>Rola</th>
                        <th>Utworzono</th>
                        <th class="text-center">Akcje</th>
                    </tr>
                </thead>
                <tbody id="usersBody">
                    <tr><td colspan="5" class="text-center text-secondary py-4">
                        <div class="spinner-border spinner-border-sm me-2"></div>Ładowanie...
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal modal-blur fade" id="addUserModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Dodaj pracownika</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label required">Login</label>
                    <input type="text" class="form-control" id="newUserLogin" required>
                </div>
                <div class="mb-0">
                    <label class="form-label required">Hasło</label>
                    <input type="password" class="form-control" id="newUserPassword" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary ms-auto" onclick="addUser()"><i class="ti ti-plus me-1"></i>Dodaj</button>
            </div>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal modal-blur fade" id="resetPasswordModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ustaw hasło dla: <span id="resetPasswordUser"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                <div class="mb-0">
                    <label class="form-label required">Nowe hasło</label>
                    <input type="password" class="form-control" id="resetPasswordInput" required>
                </div>
                <input type="hidden" id="resetPasswordUserId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-warning ms-auto" onclick="confirmResetPassword()"><i class="ti ti-key me-1"></i>Ustaw hasło</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
