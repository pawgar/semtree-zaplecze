<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-cloud-upload"></i> Import masowy</h4>
    <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#geminiKeyModal" onclick="loadGeminiKey()">
        <i class="bi bi-key"></i> Gemini API Key
    </button>
</div>

<!-- Step 1: Select site -->
<div class="card mb-3">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-1-circle"></i> Wybierz strone</h6>
        <div class="d-flex align-items-center gap-3">
            <select class="form-select" id="publishSiteSelect" style="max-width:400px">
                <option value="">-- wybierz strone --</option>
            </select>
            <button class="btn btn-primary btn-sm" onclick="loadWpData()">
                <i class="bi bi-arrow-clockwise"></i> Odswiez dane WP
            </button>
            <span id="wpDataStatus" class="text-muted small"></span>
        </div>
    </div>
</div>

<!-- Step 2: Upload XLSX -->
<div class="card mb-3">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-2-circle"></i> Wgraj plan XLSX</h6>
        <div class="d-flex gap-2 align-items-center">
            <button class="btn btn-outline-success btn-sm" onclick="document.getElementById('xlsxFile').click()">
                <i class="bi bi-file-earmark-excel"></i> Wybierz plik XLSX
            </button>
            <input type="file" id="xlsxFile" accept=".xlsx" style="display:none" onchange="parseXlsxFile(this)">
            <span id="xlsxStatus" class="text-muted small"></span>
        </div>
        <!-- Category mapping table (shown after XLSX parse) -->
        <div id="categoryMapping" class="mt-3 d-none"></div>
    </div>
</div>

<!-- Step 3: Load DOCX files from disk -->
<div class="card mb-3 d-none" id="docxLoadCard">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-3-circle"></i> Wczytywanie plikow DOCX</h6>
        <span id="docxMatchStatus" class="text-muted small"></span>
        <div class="progress mt-2 d-none" id="docxProgress" style="height:20px">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="docxProgressBar" role="progressbar" style="width:0%">0 / 0</div>
        </div>
    </div>
</div>

<!-- Step 4: Articles table -->
<div class="card mb-3 d-none" id="importArticlesCard">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-4-circle"></i> Lista artykulow</h6>

        <!-- Bulk operations -->
        <div class="d-flex gap-3 mb-2 flex-wrap align-items-center">
            <div class="d-flex align-items-center gap-1">
                <label class="small text-muted text-nowrap">Kategoria wszystkim:</label>
                <select class="form-select form-select-sm" id="bulkCategory" style="width:180px" onchange="setBulkCategory(this.value)">
                    <option value="">--</option>
                </select>
            </div>
            <div class="d-flex align-items-center gap-1">
                <label class="small text-muted text-nowrap">Autor wszystkim:</label>
                <select class="form-select form-select-sm" id="bulkAuthor" style="width:180px" onchange="setBulkAuthor(this.value)">
                    <option value="">--</option>
                </select>
            </div>
            <div class="d-flex align-items-center gap-1">
                <label class="small text-muted text-nowrap">Status:</label>
                <select class="form-select form-select-sm" id="bulkStatus" style="width:130px" onchange="setBulkStatus(this.value)">
                    <option value="draft">Szkic</option>
                    <option value="publish">Publikuj</option>
                </select>
            </div>
            <div class="d-flex align-items-center gap-1">
                <label class="small text-muted text-nowrap">Losowe daty:</label>
                <input type="datetime-local" class="form-control form-control-sm" id="randomDateFrom" style="width:170px">
                <span class="small text-muted">-</span>
                <input type="datetime-local" class="form-control form-control-sm" id="randomDateTo" style="width:170px">
                <button class="btn btn-outline-secondary btn-sm" onclick="setRandomDates()" title="Wylosuj daty publikacji">
                    <i class="bi bi-shuffle"></i> Losuj
                </button>
            </div>
        </div>

        <div class="d-flex gap-2 mb-2">
            <button class="btn btn-outline-info btn-sm" onclick="bulkGenerateImages()">
                <i class="bi bi-stars"></i> Generuj obrazki AI
            </button>
            <span id="geminiStatus" class="text-muted small align-self-center"></span>
            <span id="articleCount" class="text-muted small align-self-center"></span>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-sm" id="articlesTable">
                <thead class="table-dark">
                    <tr>
                        <th style="width:30px">#</th>
                        <th>Tytul</th>
                        <th style="width:170px">Kategoria</th>
                        <th style="width:170px">Autor</th>
                        <th style="width:170px">Obrazek</th>
                        <th style="width:160px">Data publikacji</th>
                        <th style="width:100px">Status</th>
                        <th style="width:50px"></th>
                    </tr>
                </thead>
                <tbody id="articlesBody">
                    <tr><td colspan="8" class="text-center text-muted">Wgraj XLSX aby zaladowac artykuly.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Step 5: Publish -->
<div class="card mb-3 d-none" id="importPublishCard">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-5-circle"></i> Publikuj</h6>
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-success" id="btnPublishAll" onclick="publishAllArticles()">
                <i class="bi bi-send"></i> Publikuj wszystkie
            </button>
            <button class="btn btn-outline-secondary btn-sm" onclick="clearArticles(); document.getElementById('importArticlesCard').classList.add('d-none'); document.getElementById('importPublishCard').classList.add('d-none');">
                <i class="bi bi-trash"></i> Wyczysc liste
            </button>
        </div>
        <div class="progress mt-3 d-none" id="publishProgress" style="height:24px">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="publishProgressBar" role="progressbar" style="width:0%">0 / 0</div>
        </div>
    </div>
</div>

<!-- Step 6: Report -->
<div class="card mb-3 d-none" id="publishReport">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-6-circle"></i> Raport</h6>
        <div id="publishReportLog"></div>
        <button class="btn btn-outline-primary btn-sm mt-2" onclick="copyPublishedUrls()">
            <i class="bi bi-clipboard"></i> Kopiuj linki
        </button>
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
                    <input type="password" class="form-control" id="geminiApiKey">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" onclick="saveGeminiKey()">Zapisz</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
