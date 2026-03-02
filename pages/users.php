<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-people"></i> Uzytkownicy</h4>
    <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-person-plus"></i> Dodaj pracownika
    </button>
</div>

<div class="table-responsive">
    <table class="table table-striped table-hover">
        <thead class="table-dark">
            <tr>
                <th>#</th>
                <th>Login</th>
                <th>Rola</th>
                <th>Utworzono</th>
                <th>Akcje</th>
            </tr>
        </thead>
        <tbody id="usersBody">
            <tr><td colspan="5" class="text-center text-muted">Ladowanie...</td></tr>
        </tbody>
    </table>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Dodaj pracownika</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Login</label>
                    <input type="text" class="form-control" id="newUserLogin" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Haslo</label>
                    <input type="password" class="form-control" id="newUserPassword" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" onclick="addUser()">Dodaj</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
