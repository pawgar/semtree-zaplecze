<?php
require_once __DIR__ . '/../includes/header.php';
$viewUserId = (int) ($_GET['user_id'] ?? 0);
$isOwnProfile = !$viewUserId || $viewUserId === (int) $_SESSION['user_id'];
?>

<!-- Summary cards -->
<div class="row g-3 mb-4" id="profileSummary" style="display:none">
    <div class="col-sm-6 col-lg-3">
        <div class="content-card">
            <div class="content-card-body d-flex align-items-center gap-3">
                <div class="profile-stat-icon bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-file-earmark-text"></i>
                </div>
                <div>
                    <div class="text-muted small">Publikacje</div>
                    <div class="fw-bold fs-4" id="profileTotalPubs">0</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="content-card">
            <div class="content-card-body d-flex align-items-center gap-3">
                <div class="profile-stat-icon bg-success bg-opacity-10 text-success">
                    <i class="bi bi-link-45deg"></i>
                </div>
                <div>
                    <div class="text-muted small">Artykuły z linkiem</div>
                    <div class="fw-bold fs-4" id="profileTotalLinks">0</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="content-card">
            <div class="content-card-body d-flex align-items-center gap-3">
                <div class="profile-stat-icon bg-info bg-opacity-10 text-info">
                    <i class="bi bi-globe2"></i>
                </div>
                <div>
                    <div class="text-muted small">Stron użytych</div>
                    <div class="fw-bold fs-4" id="profileTotalSites">0</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="content-card">
            <div class="content-card-body d-flex align-items-center gap-3">
                <div class="profile-stat-icon bg-warning bg-opacity-10 text-warning">
                    <i class="bi bi-people"></i>
                </div>
                <div>
                    <div class="text-muted small">Klientów linkowanych</div>
                    <div class="fw-bold fs-4" id="profileTotalClients">0</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Left column -->
    <div class="col-lg-8">
        <!-- Activity chart -->
        <div class="content-card mb-4">
            <div class="content-card-header">
                <i class="bi bi-graph-up"></i> Aktywność publikacji (ostatnie 12 miesięcy)
            </div>
            <div class="content-card-body">
                <div id="profileActivityChart" style="height:200px;display:flex;align-items:flex-end;gap:4px"></div>
            </div>
        </div>

        <!-- Publications list -->
        <div class="content-card mb-4">
            <div class="content-card-header">
                <i class="bi bi-list-ul"></i> Historia publikacji
            </div>
            <div class="content-card-body">
                <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
                    <label class="small text-muted text-nowrap">Od:</label>
                    <input type="date" class="form-control form-control-sm" id="profileDateFrom" style="width:160px">
                    <label class="small text-muted text-nowrap ms-2">Do:</label>
                    <input type="date" class="form-control form-control-sm" id="profileDateTo" style="width:160px">
                    <button class="btn btn-outline-primary btn-sm ms-2" onclick="filterProfilePublications()">
                        <i class="bi bi-funnel"></i> Filtruj
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="clearProfileDateFilter()">
                        <i class="bi bi-x-lg"></i> Wyczyść
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead class="table-dark">
                            <tr>
                                <th>Blog</th>
                                <th>Linkowana domena</th>
                                <th>Artykuł</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody id="publicationsBody">
                            <tr><td colspan="4" class="text-center text-muted">Ładowanie...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Right column -->
    <div class="col-lg-4">
        <!-- Top linked clients -->
        <div class="content-card mb-4">
            <div class="content-card-header">
                <i class="bi bi-trophy"></i> Najczęściej linkowani klienci
            </div>
            <div class="content-card-body" id="profileTopClients">
                <div class="text-muted small text-center">Ładowanie...</div>
            </div>
        </div>

        <!-- Top used sites -->
        <div class="content-card mb-4">
            <div class="content-card-header">
                <i class="bi bi-bar-chart"></i> Najczęściej używane strony
            </div>
            <div class="content-card-body" id="profileTopSites">
                <div class="text-muted small text-center">Ładowanie...</div>
            </div>
        </div>

        <!-- Monthly stats table -->
        <div class="content-card mb-4">
            <div class="content-card-header">
                <i class="bi bi-calendar3"></i> Statystyki miesięczne
            </div>
            <div class="content-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-sm mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Miesiąc</th>
                                <th class="text-center">Artykuły</th>
                                <th class="text-center">Z linkiem</th>
                            </tr>
                        </thead>
                        <tbody id="monthlyStatsBody">
                            <tr><td colspan="3" class="text-center text-muted">Ładowanie...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($isOwnProfile): ?>
        <!-- Password change -->
        <div class="content-card mb-4">
            <div class="content-card-header">
                <i class="bi bi-key"></i> Zmiana hasła
            </div>
            <div class="content-card-body">
                <div class="mb-3">
                    <label class="form-label small">Aktualne hasło</label>
                    <input type="password" class="form-control form-control-sm" id="currentPassword">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Nowe hasło</label>
                    <input type="password" class="form-control form-control-sm" id="newPassword">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Powtórz nowe hasło</label>
                    <input type="password" class="form-control form-control-sm" id="confirmPassword">
                </div>
                <button class="btn btn-primary btn-sm" onclick="changeOwnPassword()">Zmień hasło</button>
                <div id="passwordMsg" class="mt-2"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<input type="hidden" id="profileUserId" value="<?= $viewUserId ?: $_SESSION['user_id'] ?>">

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
