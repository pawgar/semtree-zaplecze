<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-magic"></i> Zamow i opublikuj</h4>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#anthropicKeyModal" onclick="loadAnthropicKey()">
            <i class="bi bi-key"></i> Anthropic API Key
        </button>
        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#geminiKeyModal" onclick="loadGeminiKey()">
            <i class="bi bi-key"></i> Gemini API Key
        </button>
        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#speedLinksKeyModal" onclick="loadSpeedLinksKey()">
            <i class="bi bi-key"></i> Speed-Links API Key
        </button>
    </div>
</div>

<!-- Tabs: Single / Bulk -->
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#orderSingleTab" type="button">
            <i class="bi bi-file-earmark-text"></i> Pojedynczy artykul
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#orderBulkTab" type="button">
            <i class="bi bi-files"></i> Zamowienie masowe (CSV)
        </button>
    </li>
</ul>

<div class="tab-content">
<!-- ========== SINGLE MODE ========== -->
<div class="tab-pane fade show active" id="orderSingleTab">

<!-- Step 1: Select site -->
<div class="card mb-3">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-1-circle"></i> Wybierz strone zapleczowa</h6>
        <div class="d-flex align-items-center gap-3">
            <div class="position-relative" style="max-width:400px;flex:1">
                <input type="text" class="form-control" id="orderSiteSearch" placeholder="Szukaj strony..." autocomplete="off"
                       onfocus="orderShowSiteDropdown()" oninput="orderFilterSites()">
                <div class="dropdown-menu w-100" id="orderSiteDropdown" style="max-height:300px;overflow-y:auto"></div>
                <input type="hidden" id="orderSiteId">
            </div>
            <span id="orderSiteLabel" class="text-muted small"></span>
        </div>
    </div>
</div>

<!-- Step 2: Article parameters -->
<div class="card mb-3" id="orderFormCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-2-circle"></i> Parametry artykulu</h6>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Tytul artykulu <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="orderTitle" placeholder="np. Jak dziala fotowoltaika w 2026 roku" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Kategoria</label>
                <div class="position-relative">
                    <input type="text" class="form-control" id="orderCategorySearch" placeholder="Wybierz kategorie..." autocomplete="off"
                           onfocus="orderShowCategoryDropdown()" oninput="orderFilterCategories()">
                    <div class="dropdown-menu w-100" id="orderCategoryDropdown" style="max-height:200px;overflow-y:auto"></div>
                    <input type="hidden" id="orderCategoryId">
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Glowne slowo kluczowe <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="orderMainKeyword" placeholder="np. fotowoltaika" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Slowa kluczowe pomocnicze <span class="text-muted">(opcjonalnie)</span></label>
                <input type="text" class="form-control" id="orderSecondaryKeywords" placeholder="np. panele sloneczne, energia odnawialna">
            </div>
            <div class="col-12">
                <label class="form-label">Dodatkowe informacje <span class="text-muted">(opcjonalnie)</span></label>
                <textarea class="form-control" id="orderNotes" rows="3" placeholder="Dodatkowe szczegoly, kontekst lub wskazowki dla AI dotyczace tego artykulu..."></textarea>
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="orderInlineImages">
                    <label class="form-check-label" for="orderInlineImages">
                        Generuj grafiki w tekscie (w kazdej sekcji H2)
                    </label>
                </div>
            </div>
            <div class="col-12">
                <button class="btn btn-primary" onclick="orderGenerate()" id="orderGenerateBtn">
                    <i class="bi bi-stars"></i> Generuj artykul
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Step 3: Progress -->
<div class="card mb-3" id="orderProgressCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-3-circle"></i> Generowanie</h6>
        <div class="progress mb-2" style="height:24px">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="orderProgressBar" style="width:0%">0%</div>
        </div>
        <div id="orderProgressLog" class="small"></div>
    </div>
</div>

<!-- Step 4: Edit & Preview -->
<div class="card mb-3" id="orderEditCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-4-circle"></i> Edycja artykulu</h6>

        <div class="mb-3">
            <label class="form-label fw-bold">Tytul:</label>
            <input type="text" class="form-control" id="orderEditTitle">
        </div>

        <div class="row mb-3">
            <div class="col-md-9">
                <label class="form-label fw-bold">Tresc (HTML):</label>
                <div id="orderEditContent" contenteditable="true" class="form-control" style="min-height:400px;max-height:600px;overflow-y:auto"></div>
                <div class="d-flex gap-3 mt-1">
                    <span class="text-muted small" id="orderCharCount"></span>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Grafika wyroznajaca:</label>
                <div id="orderFeaturedPreview" class="border rounded p-2 text-center" style="min-height:150px">
                    <span class="text-muted">Brak</span>
                </div>
                <button class="btn btn-outline-info btn-sm mt-2 w-100" onclick="orderRegenerateFeatured()">
                    <i class="bi bi-arrow-clockwise"></i> Regeneruj grafike
                </button>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <label class="form-label">Kategoria:</label>
                <select class="form-select form-select-sm" id="orderPublishCategory"></select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Status:</label>
                <select class="form-select form-select-sm" id="orderPublishStatus">
                    <option value="publish">Publikuj</option>
                    <option value="draft">Szkic</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Data publikacji:</label>
                <input type="datetime-local" class="form-control form-control-sm" id="orderPublishDate">
            </div>
            <div class="col-md-3">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" id="orderSpeedLinks">
                    <label class="form-check-label" for="orderSpeedLinks">Speed-Links indexing</label>
                </div>
            </div>
        </div>

        <button class="btn btn-success btn-lg" onclick="orderPublish()" id="orderPublishBtn">
            <i class="bi bi-send"></i> Opublikuj artykul
        </button>
    </div>
</div>

<!-- Step 5: Result -->
<div class="card mb-3" id="orderResultCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-5-circle"></i> Wynik</h6>
        <div id="orderResultLog"></div>
    </div>
</div>

</div><!-- end single tab -->

<!-- ========== BULK MODE ========== -->
<div class="tab-pane fade" id="orderBulkTab">

<div class="card mb-3">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-1-circle"></i> Wybierz strone zapleczowa</h6>
        <div class="d-flex align-items-center gap-3">
            <div class="position-relative" style="max-width:400px;flex:1">
                <input type="text" class="form-control" id="bulkOrderSiteSearch" placeholder="Szukaj strony..." autocomplete="off"
                       onfocus="bulkOrderShowSiteDropdown()" oninput="bulkOrderFilterSites()">
                <div class="dropdown-menu w-100" id="bulkOrderSiteDropdown" style="max-height:300px;overflow-y:auto"></div>
                <input type="hidden" id="bulkOrderSiteId">
            </div>
            <span id="bulkOrderSiteLabel" class="text-muted small"></span>
        </div>
    </div>
</div>

<div class="card mb-3" id="bulkOrderUploadCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-2-circle"></i> Wgraj CSV</h6>
        <p class="text-muted small mb-2">Format CSV (separator: <code>;</code>): tytul; glowne slowo kluczowe; pomocnicze slowa kluczowe; dodatkowe informacje (opcjonalnie)</p>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <button class="btn btn-outline-primary btn-sm" onclick="document.getElementById('bulkOrderCsvFile').click()">
                <i class="bi bi-upload"></i> Wgraj plik CSV
            </button>
            <input type="file" id="bulkOrderCsvFile" accept=".csv,.txt" style="display:none" onchange="bulkOrderParseCsv(this)">
            <div class="form-check ms-3">
                <input class="form-check-input" type="checkbox" id="bulkOrderInlineImages">
                <label class="form-check-label" for="bulkOrderInlineImages">Grafiki w tekscie</label>
            </div>
            <div class="form-check ms-3">
                <input class="form-check-input" type="checkbox" id="bulkOrderSpeedLinks">
                <label class="form-check-label" for="bulkOrderSpeedLinks">Speed-Links</label>
            </div>
        </div>
        <div class="mt-2">
            <label class="form-label small text-muted">Dodatkowe informacje dla wszystkich artykulow (opcjonalnie):</label>
            <textarea class="form-control form-control-sm" id="bulkOrderGlobalNotes" rows="2" placeholder="Wspolne wskazowki dla AI dotyczace wszystkich artykulow..."></textarea>
        </div>
    </div>
</div>

<div class="card mb-3" id="bulkOrderTableCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-3-circle"></i> Artykuly do wygenerowania</h6>
        <div class="d-flex gap-2 mb-2 align-items-center">
            <label class="small text-muted">Kategoria:</label>
            <select class="form-select form-select-sm" id="bulkOrderCategory" style="width:200px"></select>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover" id="bulkOrderTable">
                <thead><tr><th>#</th><th>Tytul</th><th>Glowne KW</th><th>Pomocnicze KW</th><th>Informacje</th><th>Status</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <button class="btn btn-primary" onclick="bulkOrderStart()" id="bulkOrderStartBtn">
            <i class="bi bi-play-fill"></i> Generuj i publikuj wszystko
        </button>
    </div>
</div>

<div class="card mb-3" id="bulkOrderProgressCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-4-circle"></i> Postep</h6>
        <div class="progress mb-2" style="height:24px">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="bulkOrderProgressBar" style="width:0%">0%</div>
        </div>
        <div id="bulkOrderLog" class="small" style="max-height:400px;overflow-y:auto"></div>
    </div>
</div>

<div class="card mb-3" id="bulkOrderResultCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-5-circle"></i> Raport</h6>
        <div id="bulkOrderResultLog"></div>
        <button class="btn btn-outline-primary btn-sm mt-2" onclick="bulkOrderCopyUrls()">
            <i class="bi bi-clipboard"></i> Kopiuj linki
        </button>
    </div>
</div>

</div><!-- end bulk tab -->
</div><!-- end tab-content -->

<!-- Anthropic API Key Modal -->
<div class="modal fade" id="anthropicKeyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key"></i> Anthropic API Key</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Klucz API Anthropic do generowania artykulow przez Claude. Pobierz z <a href="https://console.anthropic.com/" target="_blank">Anthropic Console</a>.</p>
                <div class="mb-3">
                    <label class="form-label">API Key</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="anthropicApiKeyInput" placeholder="sk-ant-...">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('anthropicApiKeyInput', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" onclick="saveAnthropicKey()">Zapisz</button>
            </div>
        </div>
    </div>
</div>

<!-- Gemini API Key Modal -->
<div class="modal fade" id="geminiKeyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key"></i> Gemini API Key</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Klucz API do generowania obrazkow przez Google Gemini. Pobierz z <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a>.</p>
                <div class="mb-3">
                    <label class="form-label">API Key</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="geminiApiKey">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('geminiApiKey', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" onclick="saveGeminiKey()">Zapisz</button>
            </div>
        </div>
    </div>
</div>

<!-- Speed-Links API Key Modal -->
<div class="modal fade" id="speedLinksKeyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key"></i> Speed-Links API Key</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Klucz API do automatycznej indeksacji przez <a href="https://speed-links.net/" target="_blank">Speed-Links.net</a>.</p>
                <div class="mb-3">
                    <label class="form-label">API Key</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="speedLinksApiKey">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordField('speedLinksApiKey', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" onclick="saveSpeedLinksKey()">Zapisz</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
