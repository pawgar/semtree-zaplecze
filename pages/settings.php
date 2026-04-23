<?php
require_once __DIR__ . '/../includes/header.php';
$isAdminUser = isAdmin();
?>

<div class="row row-cards">
    <!-- API Keys -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-key me-2"></i>Klucze API</h3>
            </div>
            <div class="card-body">
                <div class="mb-4">
                    <label class="form-label">Anthropic API Key <span class="text-secondary small">(Claude)</span></label>
                    <p class="text-secondary small mb-2">Klucz API do generowania artykułów. Pobierz z <a href="https://console.anthropic.com/" target="_blank" rel="noopener">Anthropic Console</a>.</p>
                    <div class="input-group">
                        <input type="password" class="form-control" id="anthropicApiKeyInput" placeholder="sk-ant-...">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('anthropicApiKeyInput', this)" title="Pokaż/ukryj">
                            <i class="ti ti-eye"></i>
                        </button>
                        <button class="btn btn-primary" onclick="saveAnthropicKey()">Zapisz</button>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label">Gemini API Key <span class="text-secondary small">(Obrazki)</span></label>
                    <p class="text-secondary small mb-2">Klucz do generowania obrazków AI. Pobierz z <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google AI Studio</a>.</p>
                    <div class="input-group">
                        <input type="password" class="form-control" id="geminiApiKey" placeholder="AIza...">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('geminiApiKey', this)">
                            <i class="ti ti-eye"></i>
                        </button>
                        <button class="btn btn-primary" onclick="saveGeminiKey()">Zapisz</button>
                    </div>
                </div>

                <div class="mb-0">
                    <label class="form-label">Speed-Links API Key <span class="text-secondary small">(Indeksacja)</span></label>
                    <p class="text-secondary small mb-2">Klucz do automatycznej indeksacji. Pobierz z <a href="https://speed-links.net/" target="_blank" rel="noopener">Speed-Links.net</a>.</p>
                    <div class="input-group">
                        <input type="password" class="form-control" id="speedLinksApiKey" placeholder="Klucz API...">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('speedLinksApiKey', this)">
                            <i class="ti ti-eye"></i>
                        </button>
                        <button class="btn btn-primary" onclick="saveSpeedLinksKey()">Zapisz</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- AI Model & Defaults -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-cpu me-2"></i>Generowanie treści</h3>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Model AI</label>
                    <select class="form-select" id="settingsAiModel">
                        <option value="claude-sonnet-4-6">Claude Sonnet 4 (domyślny)</option>
                        <option value="claude-opus-4-0-20250115">Claude Opus 4 (wyższa jakość, wolniejszy)</option>
                        <option value="claude-3-5-haiku-20241022">Claude Haiku 3.5 (szybki, tańszy)</option>
                    </select>
                    <small class="form-hint">Model używany do generowania i korekty artykułów.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label">Domyślny język</label>
                    <select class="form-select" id="settingsDefaultLang">
                        <?php
                        require_once __DIR__ . '/../includes/article_prompt.php';
                        foreach (getLanguageList() as $code => $info) {
                            $selected = ($code === 'pl') ? ' selected' : '';
                            echo "<option value=\"{$code}\"{$selected}>" . htmlspecialchars($info['name']) . "</option>\n";
                        }
                        ?>
                    </select>
                    <small class="form-hint">Język domyślny w formularzach generowania.</small>
                </div>

                <button class="btn btn-primary" onclick="saveContentSettings()">
                    <i class="ti ti-check me-1"></i>Zapisz ustawienia
                </button>
            </div>
        </div>
    </div>

    <?php if ($isAdminUser): ?>
    <!-- Google Search Console -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-brand-google me-2"></i>Google Search Console</h3>
            </div>
            <div class="card-body">
                <p class="text-secondary small mb-3">Integracja z GSC do pobierania danych o kliknięciach, wyświetleniach i pozycjach.</p>

                <div class="mb-3">
                    <label class="form-label">Client ID</label>
                    <input type="text" class="form-control" id="gscClientId" placeholder="xxxx.apps.googleusercontent.com">
                </div>
                <div class="mb-3">
                    <label class="form-label">Client Secret</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="gscClientSecret" placeholder="GOCSPX-...">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('gscClientSecret', this)">
                            <i class="ti ti-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="d-flex gap-2 mb-3 flex-wrap">
                    <button class="btn btn-primary" onclick="saveGscCredentials()">
                        <i class="ti ti-check me-1"></i>Zapisz
                    </button>
                    <button class="btn btn-success" id="gscConnectBtn" onclick="connectGsc()">
                        <i class="ti ti-plug-connected me-1"></i>Połącz z Google
                    </button>
                    <button class="btn btn-outline-danger d-none" id="gscDisconnectBtn" onclick="disconnectGsc()">
                        <i class="ti ti-plug-x me-1"></i>Rozłącz
                    </button>
                </div>
                <div id="gscStatus" class="small"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdminUser): ?>
    <!-- Telegram -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-brand-telegram me-2"></i>Telegram Bot</h3>
            </div>
            <div class="card-body">
                <p class="text-secondary small mb-3">Powiadomienia o auto-publikacjach wysyłane na Telegram. Utwórz bota przez <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>.</p>
                <div class="mb-3">
                    <label class="form-label">Bot Token</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="telegramBotToken" placeholder="123456:ABC-DEF...">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('telegramBotToken', this)">
                            <i class="ti ti-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Chat ID</label>
                    <input type="text" class="form-control" id="telegramChatId" placeholder="-1001234567890">
                    <small class="form-hint">ID czatu lub grupy. Możesz użyć <a href="https://t.me/userinfobot" target="_blank" rel="noopener">@userinfobot</a> aby poznać swoje ID.</small>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary" onclick="saveTelegramSettings()"><i class="ti ti-check me-1"></i>Zapisz</button>
                    <button class="btn btn-outline-success" onclick="testTelegram()"><i class="ti ti-send me-1"></i>Testuj</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdminUser): ?>
    <!-- CRON Settings -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-clock-hour-9 me-2"></i>Automatyczne odświeżanie (CRON)</h3>
            </div>
            <div class="card-body">
                <p class="text-secondary small mb-3">Ustaw cron job na serwerze do automatycznego odświeżania statusów stron (np. o 23:00).</p>
                <div class="mb-3">
                    <label class="form-label">Token CRON</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="cronTokenInput" placeholder="Wygeneruj lub wpisz token">
                        <button class="btn btn-outline-secondary" onclick="generateCronToken()" type="button">Generuj</button>
                        <button class="btn btn-primary" onclick="saveCronToken()" type="button">Zapisz</button>
                    </div>
                </div>
                <div class="mb-0">
                    <label class="form-label small text-secondary">Komendy CRON:</label>
                    <code class="d-block bg-muted-lt p-2 rounded small mb-1" id="cronCommandPreview" style="white-space: pre-wrap; word-break: break-all;">0 23 * * * curl -s "<?= htmlspecialchars(rtrim(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-app.com'), '/')) ?>/api/cron-status.php?token=YOUR_TOKEN"</code>
                    <code class="d-block bg-muted-lt p-2 rounded small mb-1" id="cronGscCommandPreview" style="white-space: pre-wrap; word-break: break-all;">0 6 * * * curl -s "<?= htmlspecialchars(rtrim(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-app.com'), '/')) ?>/api/cron-gsc.php?token=YOUR_TOKEN"</code>
                    <code class="d-block bg-muted-lt p-2 rounded small" id="cronAutoPublishCommandPreview" style="white-space: pre-wrap; word-break: break-all;">0 9 * * * /usr/local/php83/bin/php <?= htmlspecialchars(realpath(__DIR__ . '/../api/cron-auto-publish.php') ?: '/PATH/TO/api/cron-auto-publish.php') ?> --token=YOUR_TOKEN >> <?= htmlspecialchars(realpath(__DIR__ . '/../data') ?: '/PATH/TO/data') ?>/cron-auto-publish.log 2>&amp;1</code>
                    <small class="form-hint">Statusy stron (23:00), dane GSC (6:00), auto-publikacje (9:00 — <strong>uruchamiane przez PHP CLI</strong>, bez limitu czasu serwera HTTP).</small>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdminUser): ?>
    <!-- Password Management -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-lock me-2"></i>Hasła WordPress</h3>
            </div>
            <div class="card-body">
                <p class="text-secondary small mb-3">Zmień hasło logowania na wszystkich stronach zapleczowych jednocześnie. Application Passwords pozostaną bez zmian.</p>
                <div class="mb-3">
                    <label class="form-label">Nowe hasło</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="newGlobalPassword">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('newGlobalPassword', this)">
                            <i class="ti ti-eye"></i>
                        </button>
                    </div>
                </div>
                <button class="btn btn-warning" id="btnChangeAllPasswords" onclick="changeAllPasswords()">
                    <i class="ti ti-key me-1"></i>Zmień hasło na wszystkich
                </button>
                <div id="passwordChangeResults" class="d-none mt-3">
                    <hr>
                    <h4>Wyniki:</h4>
                    <div id="passwordChangeLog"></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
