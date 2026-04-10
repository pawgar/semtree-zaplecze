<?php
require_once __DIR__ . '/../includes/header.php';
$viewUserId = (int) ($_GET['user_id'] ?? 0);
$isOwnProfile = !$viewUserId || $viewUserId === (int) $_SESSION['user_id'];
?>

<input type="hidden" id="profileTitleHolder" data-target="profileTitle">

<?php if ($isOwnProfile): ?>
<!-- Password change -->
<div class="card mb-4" style="max-width:500px">
    <div class="card-header"><i class="bi bi-key"></i> Zmiana hasla</div>
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label">Aktualne haslo</label>
            <input type="password" class="form-control" id="currentPassword">
        </div>
        <div class="mb-3">
            <label class="form-label">Nowe haslo</label>
            <input type="password" class="form-control" id="newPassword">
        </div>
        <div class="mb-3">
            <label class="form-label">Powtorz nowe haslo</label>
            <input type="password" class="form-control" id="confirmPassword">
        </div>
        <button class="btn btn-primary" onclick="changeOwnPassword()">Zmien haslo</button>
        <div id="passwordMsg" class="mt-2"></div>
    </div>
</div>
<?php endif; ?>

<!-- Monthly stats -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-bar-chart"></i> Statystyki miesieczne</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-sm">
                <thead class="table-dark">
                    <tr>
                        <th>Miesiac</th>
                        <th>Opublikowane artykuly</th>
                        <th>Artykuly z linkiem</th>
                    </tr>
                </thead>
                <tbody id="monthlyStatsBody">
                    <tr><td colspan="3" class="text-center text-muted">Ladowanie...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Detailed publications list -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-list-ul"></i> Historia publikacji</div>
    <div class="card-body">
        <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
            <label class="small text-muted text-nowrap">Od:</label>
            <input type="date" class="form-control form-control-sm" id="profileDateFrom" style="width:160px">
            <label class="small text-muted text-nowrap ms-2">Do:</label>
            <input type="date" class="form-control form-control-sm" id="profileDateTo" style="width:160px">
            <button class="btn btn-outline-primary btn-sm ms-2" onclick="filterProfilePublications()">
                <i class="bi bi-funnel"></i> Filtruj
            </button>
            <button class="btn btn-outline-secondary btn-sm" onclick="clearProfileDateFilter()">
                <i class="bi bi-x-lg"></i> Wyczysc
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-sm">
                <thead class="table-dark">
                    <tr>
                        <th>Blog</th>
                        <th>Linkowana domena</th>
                        <th>Artykul</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody id="publicationsBody">
                    <tr><td colspan="4" class="text-center text-muted">Ladowanie...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<input type="hidden" id="profileUserId" value="<?= $viewUserId ?: $_SESSION['user_id'] ?>">

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
