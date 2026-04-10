<?php
require_once __DIR__ . '/../includes/header.php';
$isAdminUser = isAdmin();
?>

<div class="row g-4">
    <!-- API Keys -->
    <div class="col-lg-6">
        <div class="content-card">
            <div class="content-card-header">
                <i class="bi bi-key"></i> Klucze API
            </div>
            <div class="content-card-body">
                <div class="mb-4">
                    <label class="form-label fw-semibold">Anthropic API Key <small class="text-muted">(Claude)</small></label>
                    <p class="text-muted small mb-2">Klucz API do generowania artykułów. Pobierz z <a href="https://console.anthropic.com/" target="_blank">Anthropic Console</a>.</p>
                    <div class="input-group">
                        <input type="password" class="form-control" id="anthropicApiKeyInput" placeholder="sk-ant-...">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('anthropicApiKeyInput', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button class="btn btn-primary" onclick="saveAnthropicKey()">Zapisz</button>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Gemini API Key <small class="text-muted">(Obrazki)</small></label>
                    <p class="text-muted small mb-2">Klucz do generowania obrazków AI. Pobierz z <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a>.</p>
                    <div class="input-group">
                        <input type="password" class="form-control" id="geminiApiKey" placeholder="AIza...">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('geminiApiKey', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button class="btn btn-primary" onclick="saveGeminiKey()">Zapisz</button>
                    </div>
                </div>

                <div class="mb-0">
                    <label class="form-label fw-semibold">Speed-Links API Key <small class="text-muted">(Indeksacja)</small></label>
                    <p class="text-muted small mb-2">Klucz do automatycznej indeksacji. Pobierz z <a href="https://speed-links.net/" target="_blank">Speed-Links.net</a>.</p>
                    <div class="input-group">
                        <input type="password" class="form-control" id="speedLinksApiKey" placeholder="Klucz API...">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('speedLinksApiKey', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button class="btn btn-primary" onclick="saveSpeedLinksKey()">Zapisz</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- AI Model & Defaults -->
    <div class="col-lg-6">
        <div class="content-card">
            <div class="content-card-header">
                <i class="bi bi-cpu"></i> Generowanie treści
            </div>
            <div class="content-card-body">
                <div class="mb-4">
                    <label class="form-label fw-semibold">Model AI</label>
                    <select class="form-select" id="settingsAiModel">
                        <option value="claude-sonnet-4-6">Claude Sonnet 4 (domyślny)</option>
                        <option value="claude-opus-4-0-20250115">Claude Opus 4 (wyższa jakość, wolniejszy)</option>
                        <option value="claude-3-5-haiku-20241022">Claude Haiku 3.5 (szybki, tańszy)</option>
                    </select>
                    <div class="form-text">Model używany do generowania i korekty artykułów.</div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Domyślny język</label>
                    <select class="form-select" id="settingsDefaultLang">
                        <?php
                        require_once __DIR__ . '/../includes/article_prompt.php';
                        foreach (getLanguageList() as $code => $info) {
                            $selected = ($code === 'pl') ? ' selected' : '';
                            echo "<option value=\"{$code}\"{$selected}>" . htmlspecialchars($info['name']) . "</option>\n";
                        }
                        ?>
                    </select>
                    <div class="form-text">Język domyślny w formularzach generowania.</div>
                </div>

                <button class="btn btn-primary" onclick="saveContentSettings()">
                    <i class="bi bi-check-lg"></i> Zapisz ustawienia
                </button>
            </div>
        </div>
    </div>

    <?php if ($isAdminUser): ?>
    <!-- Google Search Console -->
    <div class="col-lg-6">
        <div class="content-card">
            <div class="content-card-header">
                <i class="bi bi-google"></i> Google Search Console
            </div>
            <div class="content-card-body">
                <p class="text-muted small mb-3">Integracja z GSC do pobierania danych o kliknięciach, wyświetleniach i pozycjach.</p>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Client ID</label>
                    <input type="text" class="form-control" id="gscClientId" placeholder="xxxx.apps.googleusercontent.com">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Client Secret</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="gscClientSecret" placeholder="GOCSPX-...">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('gscClientSecret', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="d-flex gap-2 mb-3">
                    <button class="btn btn-primary" onclick="saveGscCredentials()">
                        <i class="bi bi-check-lg"></i> Zapisz
                    </button>
                    <button class="btn btn-success" id="gscConnectBtn" onclick="connectGsc()">
                        <i class="bi bi-plug"></i> Połącz z Google
                    </button>
                    <button class="btn btn-outline-danger d-none" id="gscDisconnectBtn" onclick="disconnectGsc()">
                        <i class="bi bi-x-circle"></i> Rozłącz
                    </button>
                </div>
                <div id="gscStatus" class="small"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdminUser): ?>
    <!-- CRON Settings -->
    <div class="col-lg-6">
        <div class="content-card">
            <div class="content-card-header">
                <i class="bi bi-clock-history"></i> Automatyczne odświeżanie (CRON)
            </div>
            <div class="content-card-body">
                <p class="text-muted small mb-3">Ustaw cron job na serwerze do automatycznego odświeżania statusów stron (np. o 23:00).</p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Token CRON</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="cronTokenInput" placeholder="Wygeneruj lub wpisz token">
                        <button class="btn btn-outline-secondary" onclick="generateCronToken()">Generuj</button>
                        <button class="btn btn-primary" onclick="saveCronToken()">Zapisz</button>
                    </div>
                </div>
                <div class="mb-0">
                    <label class="form-label small text-muted">Komendy CRON:</label>
                    <code class="d-block bg-light p-2 rounded small mb-1" id="cronCommandPreview">
                        0 23 * * * curl -s "<?= htmlspecialchars(rtrim(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-app.com'), '/')) ?>/api/cron-status.php?token=YOUR_TOKEN"
                    </code>
                    <code class="d-block bg-light p-2 rounded small" id="cronGscCommandPreview">
                        0 6 * * * curl -s "<?= htmlspecialchars(rtrim(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-app.com'), '/')) ?>/api/cron-gsc.php?token=YOUR_TOKEN"
                    </code>
                    <div class="form-text">Pierwsza komenda: statusy stron (23:00). Druga: dane GSC (6:00).</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdminUser): ?>
    <!-- Password Management -->
    <div class="col-lg-6">
        <div class="content-card">
            <div class="content-card-header">
                <i class="bi bi-shield-lock"></i> Hasła WordPress
            </div>
            <div class="content-card-body">
                <p class="text-muted small mb-3">Zmień hasło logowania na wszystkich stronach zapleczowych jednocześnie. Application Passwords pozostaną bez zmian.</p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nowe hasło</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="newGlobalPassword">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('newGlobalPassword', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <button class="btn btn-warning" id="btnChangeAllPasswords" onclick="changeAllPasswords()">
                    <i class="bi bi-key"></i> Zmień hasło na wszystkich
                </button>
                <div id="passwordChangeResults" class="d-none mt-3">
                    <hr>
                    <h6>Wyniki:</h6>
                    <div id="passwordChangeLog"></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
