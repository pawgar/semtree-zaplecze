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
                    <p class="text-muted small mb-2">Klucz API do generowania artykulow. Pobierz z <a href="https://console.anthropic.com/" target="_blank">Anthropic Console</a>.</p>
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
                    <p class="text-muted small mb-2">Klucz do generowania obrazkow AI. Pobierz z <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a>.</p>
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
                <i class="bi bi-cpu"></i> Generowanie tresci
            </div>
            <div class="content-card-body">
                <div class="mb-4">
                    <label class="form-label fw-semibold">Model AI</label>
                    <select class="form-select" id="settingsAiModel">
                        <option value="claude-sonnet-4-6">Claude Sonnet 4 (domyslny)</option>
                        <option value="claude-opus-4-0-20250115">Claude Opus 4 (wyzsza jakosc, wolniejszy)</option>
                        <option value="claude-3-5-haiku-20241022">Claude Haiku 3.5 (szybki, tanszy)</option>
                    </select>
                    <div class="form-text">Model uzywany do generowania i korekty artykulow.</div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Domyslny jezyk</label>
                    <select class="form-select" id="settingsDefaultLang">
                        <?php
                        require_once __DIR__ . '/../includes/article_prompt.php';
                        foreach (getLanguageList() as $code => $info) {
                            $selected = ($code === 'pl') ? ' selected' : '';
                            echo "<option value=\"{$code}\"{$selected}>" . htmlspecialchars($info['name']) . "</option>\n";
                        }
                        ?>
                    </select>
                    <div class="form-text">Jezyk domyslny w formularzach generowania.</div>
                </div>

                <button class="btn btn-primary" onclick="saveContentSettings()">
                    <i class="bi bi-check-lg"></i> Zapisz ustawienia
                </button>
            </div>
        </div>
    </div>

    <?php if ($isAdminUser): ?>
    <!-- CRON Settings -->
    <div class="col-lg-6">
        <div class="content-card">
            <div class="content-card-header">
                <i class="bi bi-clock-history"></i> Automatyczne odswiezanie (CRON)
            </div>
            <div class="content-card-body">
                <p class="text-muted small mb-3">Ustaw cron job na serwerze do automatycznego odswiezania statusow stron (np. o 23:00).</p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Token CRON</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="cronTokenInput" placeholder="Wygeneruj lub wpisz token">
                        <button class="btn btn-outline-secondary" onclick="generateCronToken()">Generuj</button>
                        <button class="btn btn-primary" onclick="saveCronToken()">Zapisz</button>
                    </div>
                </div>
                <div class="mb-0">
                    <label class="form-label small text-muted">Komenda CRON:</label>
                    <code class="d-block bg-light p-2 rounded small" id="cronCommandPreview">
                        0 23 * * * curl -s "<?= htmlspecialchars(rtrim(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-app.com'), '/')) ?>/api/cron-status.php?token=YOUR_TOKEN"
                    </code>
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
                <i class="bi bi-shield-lock"></i> Hasla WordPress
            </div>
            <div class="content-card-body">
                <p class="text-muted small mb-3">Zmien haslo logowania na wszystkich stronach zapleczowych jednoczesnie. Application Passwords pozostana bez zmian.</p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nowe haslo</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="newGlobalPassword">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('newGlobalPassword', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <button class="btn btn-warning" id="btnChangeAllPasswords" onclick="changeAllPasswords()">
                    <i class="bi bi-key"></i> Zmien haslo na wszystkich
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
