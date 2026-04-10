<?php require_once __DIR__ . '/../includes/header.php'; ?>


<!-- Step 1: Select site -->
<div class="card mb-3">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-1-circle"></i> Wybierz stronę</h6>
        <div class="d-flex align-items-center gap-3">
            <select class="form-select" id="publishSiteSelect" style="max-width:400px">
                <option value="">-- wybierz stronę --</option>
            </select>
            <button class="btn btn-primary btn-sm" onclick="loadWpData()">
                <i class="bi bi-arrow-clockwise"></i> Załaduj kategorie i autorów
            </button>
            <span id="wpDataStatus" class="text-muted small"></span>
        </div>
    </div>
</div>

<!-- Step 2: Add articles -->
<div class="card mb-3">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-2-circle"></i> Dodaj artykuły</h6>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary btn-sm" onclick="document.getElementById('docxFiles').click()">
                <i class="bi bi-file-earmark-word"></i> Wgraj DOCX
            </button>
            <input type="file" id="docxFiles" accept=".docx" multiple style="display:none" onchange="uploadDocxFiles(this)">
            <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#manualArticleModal">
                <i class="bi bi-plus-lg"></i> Dodaj ręcznie
            </button>
            <button class="btn btn-outline-info btn-sm" onclick="bulkGenerateImages()">
                <i class="bi bi-stars"></i> Generuj obrazki AI
            </button>
            <span id="geminiStatus" class="text-muted small align-self-center"></span>
            <span id="articleCount" class="text-muted small align-self-center"></span>
        </div>
    </div>
</div>

<!-- Step 3: Articles table -->
<div class="card mb-3">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-3-circle"></i> Lista artykułów</h6>

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

        <div class="table-responsive">
            <table class="table table-striped table-sm" id="articlesTable">
                <thead class="table-dark">
                    <tr>
                        <th style="width:30px">#</th>
                        <th>Tytuł</th>
                        <th style="width:170px">Kategoria</th>
                        <th style="width:170px">Autor</th>
                        <th style="width:170px">Obrazek</th>
                        <th style="width:160px">Data publikacji</th>
                        <th style="width:100px">Status</th>
                        <th style="width:50px"></th>
                    </tr>
                </thead>
                <tbody id="articlesBody">
                    <tr><td colspan="8" class="text-center text-muted">Brak artykułów. Wgraj DOCX lub dodaj ręcznie.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Step 4: Publish -->
<div class="card mb-3">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-4-circle"></i> Publikuj</h6>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <button class="btn btn-success" id="btnPublishAll" onclick="publishAllArticles()">
                <i class="bi bi-send"></i> Publikuj wszystkie
            </button>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="speedLinksCheck">
                <label class="form-check-label small" for="speedLinksCheck">Wyślij do indeksacji (Speed-Links)</label>
            </div>
            <button class="btn btn-outline-info btn-sm" onclick="exportArticlesJson()">
                <i class="bi bi-download"></i> Zapisz stan (JSON)
            </button>
            <button class="btn btn-outline-info btn-sm" onclick="document.getElementById('importJsonFile').click()">
                <i class="bi bi-upload"></i> Wczytaj stan (JSON)
            </button>
            <input type="file" id="importJsonFile" accept=".json" style="display:none" onchange="importArticlesJson(this)">
            <button class="btn btn-outline-secondary btn-sm" onclick="clearArticles()">
                <i class="bi bi-trash"></i> Wyczyść listę
            </button>
        </div>
        <div class="progress mt-3 d-none" id="publishProgress" style="height:24px">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="publishProgressBar" role="progressbar" style="width:0%">0 / 0</div>
        </div>
    </div>
</div>

<!-- Step 5: Report -->
<div class="card mb-3 d-none" id="publishReport">
    <div class="card-body">
        <h6 class="card-title"><i class="bi bi-5-circle"></i> Raport</h6>
        <div id="publishReportLog"></div>
        <button class="btn btn-outline-primary btn-sm mt-2" onclick="copyPublishedUrls()">
            <i class="bi bi-clipboard"></i> Kopiuj linki
        </button>
    </div>
</div>

<!-- Manual Article Modal -->
<div class="modal fade" id="manualArticleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Dodaj artykuł ręcznie</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Tytuł</label>
                    <input type="text" class="form-control" id="manualTitle" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Treść (HTML)</label>
                    <textarea class="form-control" id="manualContent" rows="12" placeholder="<p>Treść artykułu...</p>"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Obrazek wyróżniający</label>
                    <div class="d-flex gap-2 align-items-start">
                        <input type="file" class="form-control" id="manualImage" accept="image/*">
                        <button class="btn btn-outline-info btn-sm text-nowrap" onclick="generateManualImage()" type="button">
                            <i class="bi bi-stars"></i> Generuj AI
                        </button>
                    </div>
                    <div id="manualImagePreview" class="mt-2"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" onclick="addManualArticle()">Dodaj do listy</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
