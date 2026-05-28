<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/two_factor.php';
$viewUserId = (int) ($_GET['user_id'] ?? 0);
$isOwnProfile = !$viewUserId || $viewUserId === (int) $_SESSION['user_id'];

// 2FA state for the current logged-in user (always shown for own profile)
$tfaRow = tfaUserRow((int) $_SESSION['user_id']);
$tfaActive = tfaIsEnabled($tfaRow);
$tfaRecoveryRemaining = tfaRecoveryRemaining($tfaRow);
?>

<!-- Summary cards -->
<div class="row row-cards mb-3" id="profileSummary" style="display:none">
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-primary text-white avatar"><i class="ti ti-file-text"></i></span></div>
                    <div class="col">
                        <div class="h2 mb-0" id="profileTotalPubs">0</div>
                        <div class="text-secondary small">Publikacje</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-green text-white avatar"><i class="ti ti-link"></i></span></div>
                    <div class="col">
                        <div class="h2 mb-0" id="profileTotalLinks">0</div>
                        <div class="text-secondary small">Artykuły z linkiem</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-azure text-white avatar"><i class="ti ti-world"></i></span></div>
                    <div class="col">
                        <div class="h2 mb-0" id="profileTotalSites">0</div>
                        <div class="text-secondary small">Stron użytych</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-orange text-white avatar"><i class="ti ti-users"></i></span></div>
                    <div class="col">
                        <div class="h2 mb-0" id="profileTotalClients">0</div>
                        <div class="text-secondary small">Klientów linkowanych</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row row-cards">
    <!-- Left column -->
    <div class="col-lg-8">
        <!-- Activity chart -->
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-chart-line me-2"></i>Aktywność publikacji (ostatnie 12 miesięcy)</h3>
            </div>
            <div class="card-body">
                <div id="profileActivityChart" style="height:200px;display:flex;align-items:flex-end;gap:4px"></div>
            </div>
        </div>

        <!-- Publications list -->
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-list me-2"></i>Historia publikacji</h3>
            </div>
            <div class="card-body">
                <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
                    <label class="small text-secondary text-nowrap mb-0">Od:</label>
                    <input type="date" class="form-control form-control-sm" id="profileDateFrom" style="width:160px">
                    <label class="small text-secondary text-nowrap mb-0 ms-2">Do:</label>
                    <input type="date" class="form-control form-control-sm" id="profileDateTo" style="width:160px">
                    <button class="btn btn-outline-primary btn-sm ms-2" onclick="filterProfilePublications()">
                        <i class="ti ti-filter me-1"></i>Filtruj
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="clearProfileDateFilter()">
                        <i class="ti ti-x me-1"></i>Wyczyść
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Blog</th>
                                <th>Linkowana domena</th>
                                <th>Artykuł</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody id="publicationsBody">
                            <tr><td colspan="4" class="text-center text-secondary py-3">Ładowanie...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Right column -->
    <div class="col-lg-4">
        <!-- Top linked clients -->
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-trophy me-2"></i>Najczęściej linkowani klienci</h3>
            </div>
            <div class="card-body" id="profileTopClients">
                <div class="text-secondary small text-center">Ładowanie...</div>
            </div>
        </div>

        <!-- Top used sites -->
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-chart-bar me-2"></i>Najczęściej używane strony</h3>
            </div>
            <div class="card-body" id="profileTopSites">
                <div class="text-secondary small text-center">Ładowanie...</div>
            </div>
        </div>

        <!-- Monthly stats table -->
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-calendar me-2"></i>Statystyki miesięczne</h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-vcenter card-table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Miesiąc</th>
                                <th class="text-center">Artykuły</th>
                                <th class="text-center">Z linkiem</th>
                            </tr>
                        </thead>
                        <tbody id="monthlyStatsBody">
                            <tr><td colspan="3" class="text-center text-secondary py-3">Ładowanie...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($isOwnProfile): ?>
        <!-- Password change -->
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-key me-2"></i>Zmiana hasła</h3>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Aktualne hasło</label>
                    <input type="password" class="form-control form-control-sm" id="currentPassword">
                </div>
                <div class="mb-3">
                    <label class="form-label">Nowe hasło</label>
                    <input type="password" class="form-control form-control-sm" id="newPassword">
                </div>
                <div class="mb-3">
                    <label class="form-label">Powtórz nowe hasło</label>
                    <input type="password" class="form-control form-control-sm" id="confirmPassword">
                </div>
                <button class="btn btn-primary btn-sm" onclick="changeOwnPassword()"><i class="ti ti-check me-1"></i>Zmień hasło</button>
                <div id="passwordMsg" class="mt-2"></div>
            </div>
        </div>

        <!-- ─── 2FA (TOTP) ────────────────────────────────────────── -->
        <div class="card mb-3" id="tfaCard">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-shield-lock me-2"></i>Logowanie dwuetapowe (2FA)</h3>
                <span class="ms-auto badge <?= $tfaActive ? 'bg-green-lt' : 'bg-secondary-lt' ?>" id="tfaStatusBadge">
                    <?= $tfaActive ? 'Włączone' : 'Wyłączone' ?>
                </span>
            </div>
            <div class="card-body">
                <?php if ($tfaActive): ?>
                    <p class="small text-secondary mb-2">
                        2FA aktywne od <strong><?= htmlspecialchars((string)($tfaRow['totp_enabled_at'] ?? '')) ?></strong>.
                    </p>
                    <p class="small mb-3">
                        Pozostałe kody odzyskiwania: <strong id="tfaRecoveryCount"><?= (int)$tfaRecoveryRemaining ?></strong> / <?= RECOVERY_CODE_COUNT ?>
                    </p>
                    <div class="btn-list">
                        <button class="btn btn-outline-warning btn-sm" onclick="tfaRegenerateCodes()">
                            <i class="ti ti-refresh me-1"></i>Wygeneruj nowe kody
                        </button>
                        <button class="btn btn-outline-danger btn-sm" onclick="tfaDisable()">
                            <i class="ti ti-shield-off me-1"></i>Wyłącz 2FA
                        </button>
                    </div>
                <?php else: ?>
                    <p class="small text-secondary mb-3">
                        Włącz 2FA, aby chronić konto dodatkowym kodem z aplikacji uwierzytelniającej
                        (Google&nbsp;Authenticator, Authy, 1Password&hellip;).
                    </p>
                    <button class="btn btn-primary btn-sm" id="tfaSetupBtn" onclick="tfaStartSetup()">
                        <i class="ti ti-shield-plus me-1"></i>Włącz 2FA
                    </button>
                <?php endif; ?>

                <!-- Setup wizard (hidden until tfaStartSetup runs) -->
                <div id="tfaSetupWizard" class="mt-3" style="display:none">
                    <hr>
                    <div class="text-center mb-3">
                        <div id="tfaQrBox" class="d-inline-block p-2" style="background:#fff;border-radius:4px;min-height:200px;min-width:200px"></div>
                    </div>
                    <p class="small text-secondary text-center mb-2">
                        Zeskanuj kod QR w aplikacji lub wpisz sekret ręcznie:
                    </p>
                    <div class="input-group input-group-sm mb-3">
                        <input type="text" class="form-control text-center" id="tfaSecretText" readonly style="letter-spacing:0.1em;font-family:monospace">
                        <button class="btn btn-outline-secondary" type="button" onclick="tfaCopySecret()" title="Kopiuj"><i class="ti ti-copy"></i></button>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Wpisz 6-cyfrowy kod z aplikacji</label>
                        <input type="text" class="form-control text-center" id="tfaVerifyCode" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
                               style="letter-spacing:0.5em;font-size:1.2rem">
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-success btn-sm flex-fill" onclick="tfaConfirmEnable()">
                            <i class="ti ti-check me-1"></i>Aktywuj
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="tfaCancelSetup()">Anuluj</button>
                    </div>
                    <div id="tfaMsg" class="mt-2"></div>
                </div>

                <!-- Recovery codes display (shown once after activation / regenerate) -->
                <div id="tfaCodesBox" class="mt-3" style="display:none">
                    <hr>
                    <div class="alert alert-warning small mb-2">
                        <i class="ti ti-alert-triangle me-1"></i>
                        <strong>Zapisz te kody w bezpiecznym miejscu</strong> — pozwolą Ci się zalogować, jeśli stracisz dostęp do aplikacji 2FA.
                        Każdy kod jest <strong>jednorazowy</strong>. Nie będą wyświetlone ponownie.
                    </div>
                    <div id="tfaCodesList" class="row g-2 small font-monospace mb-2"></div>
                    <div class="btn-list">
                        <button class="btn btn-outline-primary btn-sm" onclick="tfaCopyCodes()"><i class="ti ti-copy me-1"></i>Kopiuj</button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="tfaDownloadCodes()"><i class="ti ti-download me-1"></i>Pobierz .txt</button>
                        <button class="btn btn-primary btn-sm" onclick="tfaHideCodes()"><i class="ti ti-check me-1"></i>Zapisałem</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- QR code library (UMD, ~20KB) — generated client-side from otpauth URL -->
<script src="assets/vendor/qrcode/qrcode.min.js"></script>

<input type="hidden" id="profileUserId" value="<?= $viewUserId ?: $_SESSION['user_id'] ?>">

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
