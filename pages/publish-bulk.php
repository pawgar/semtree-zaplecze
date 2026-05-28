<?php require_once __DIR__ . '/../includes/header.php'; ?>

<!-- Step 1: Dodaj artykuły -->
<div class="card mb-3">
    <div class="card-body">
        <h6 class="card-title"><i class="ti ti-circle-number-1"></i> Dodaj artykuły</h6>
        <div class="text-secondary small mb-2">
            Wrzuć wiele plików DOCX naraz — każdy stanie się jednym wierszem. Stronę zapleczową i resztę szczegółów wybierzesz w tabeli.
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-outline-primary btn-sm" onclick="document.getElementById('bulkDocxFiles').click()">
                <i class="ti ti-file-word"></i> Wgraj DOCX (wiele)
            </button>
            <input type="file" id="bulkDocxFiles" accept=".docx" multiple style="display:none" onchange="uploadDocxBulk(this)">
            <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#bulkManualModal">
                <i class="ti ti-plus"></i> Dodaj ręcznie
            </button>
            <button class="btn btn-outline-info btn-sm" onclick="bulkGenerateImagesBulk()">
                <i class="ti ti-sparkles"></i> Generuj obrazki AI dla wszystkich
            </button>
            <span id="bulkGeminiStatus" class="text-secondary small align-self-center"></span>
            <span id="bulkArticleCount" class="text-secondary small align-self-center ms-auto"></span>
        </div>
    </div>
</div>

<!-- Step 2: Tabela artykułów + bulk-ops -->
<div class="card mb-3">
    <div class="card-body">
        <h6 class="card-title"><i class="ti ti-circle-number-2"></i> Lista artykułów</h6>

        <!-- Bulk operations row -->
        <div class="d-flex gap-3 mb-2 flex-wrap align-items-center">
            <div class="d-flex align-items-center gap-1">
                <label class="small text-secondary text-nowrap">Strona wszystkim:</label>
                <select class="form-select form-select-sm" id="bulkBulkSite" style="width:220px" onchange="setBulkAllSites(this.value)">
                    <option value="">--</option>
                </select>
            </div>
            <div class="d-flex align-items-center gap-1">
                <label class="small text-secondary text-nowrap">Kategoria wszystkim:</label>
                <select class="form-select form-select-sm" id="bulkBulkCategory" style="width:180px" onchange="setBulkAllCategory(this.value)" disabled title="Aktywne gdy wszystkie wiersze mają tę samą stronę">
                    <option value="">--</option>
                </select>
            </div>
            <div class="d-flex align-items-center gap-1">
                <label class="small text-secondary text-nowrap">Autor wszystkim:</label>
                <select class="form-select form-select-sm" id="bulkBulkAuthor" style="width:180px" onchange="setBulkAllAuthor(this.value)" disabled title="Aktywne gdy wszystkie wiersze mają tę samą stronę">
                    <option value="">--</option>
                </select>
            </div>
            <div class="d-flex align-items-center gap-1">
                <label class="small text-secondary text-nowrap">Status:</label>
                <select class="form-select form-select-sm" id="bulkBulkStatus" style="width:130px" onchange="setBulkAllStatus(this.value)">
                    <option value="draft">Szkic</option>
                    <option value="publish">Publikuj</option>
                </select>
            </div>
            <div class="d-flex align-items-center gap-1">
                <label class="small text-secondary text-nowrap">Losowe daty:</label>
                <input type="datetime-local" class="form-control form-control-sm" id="bulkRandomDateFrom" style="width:170px">
                <span class="small text-secondary">-</span>
                <input type="datetime-local" class="form-control form-control-sm" id="bulkRandomDateTo" style="width:170px">
                <button class="btn btn-outline-secondary btn-sm" onclick="setBulkRandomDates()" title="Wylosuj daty">
                    <i class="ti ti-arrows-shuffle"></i> Losuj
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-sm" id="bulkArticlesTable">
                <thead>
                    <tr>
                        <th style="width:30px">#</th>
                        <th>Tytuł</th>
                        <th style="width:220px">Strona zapleczowa</th>
                        <th style="width:170px">Kategoria</th>
                        <th style="width:170px">Autor</th>
                        <th style="width:170px">Obrazek</th>
                        <th style="width:160px">Data publikacji</th>
                        <th style="width:100px">Status</th>
                        <th style="width:50px"></th>
                    </tr>
                </thead>
                <tbody id="bulkArticlesBody">
                    <tr><td colspan="9" class="text-center text-secondary">Brak artykułów. Wgraj DOCX lub dodaj ręcznie.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Step 3: Publikuj -->
<div class="card mb-3">
    <div class="card-body">
        <h6 class="card-title"><i class="ti ti-circle-number-3"></i> Publikuj</h6>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <button class="btn btn-success" id="btnBulkPublishAll" onclick="publishBulkAll()">
                <i class="ti ti-send"></i> Publikuj wszystkie
            </button>
            <button class="btn btn-outline-info btn-sm" onclick="exportBulkJson()">
                <i class="ti ti-download"></i> Zapisz stan (JSON)
            </button>
            <button class="btn btn-outline-info btn-sm" onclick="document.getElementById('bulkImportJsonFile').click()">
                <i class="ti ti-upload"></i> Wczytaj stan (JSON)
            </button>
            <input type="file" id="bulkImportJsonFile" accept=".json" style="display:none" onchange="importBulkJson(this)">
            <button class="btn btn-outline-secondary btn-sm" onclick="clearBulkArticles()">
                <i class="ti ti-trash"></i> Wyczyść listę
            </button>
        </div>
        <div class="progress mt-3 d-none" id="bulkPublishProgress" style="height:24px">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="bulkPublishProgressBar" role="progressbar" style="width:0%">0 / 0</div>
        </div>
    </div>
</div>

<!-- Step 4: Raport -->
<div class="card mb-3 d-none" id="bulkPublishReport">
    <div class="card-body">
        <h6 class="card-title"><i class="ti ti-circle-number-4"></i> Raport</h6>
        <div id="bulkPublishReportLog"></div>
        <button class="btn btn-outline-primary btn-sm mt-2" onclick="copyBulkPublishedUrls()">
            <i class="ti ti-clipboard"></i> Kopiuj linki
        </button>
    </div>
</div>

<!-- Manual Article Modal -->
<div class="modal fade" id="bulkManualModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Dodaj artykuł ręcznie</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Tytuł</label>
                    <input type="text" class="form-control" id="bulkManualTitle" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Treść (HTML)</label>
                    <textarea class="form-control" id="bulkManualContent" rows="12" placeholder="<p>Treść artykułu...</p>"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Obrazek wyróżniający</label>
                    <div class="d-flex gap-2 align-items-start">
                        <input type="file" class="form-control" id="bulkManualImage" accept="image/*">
                        <button class="btn btn-outline-info btn-sm text-nowrap" onclick="generateBulkManualImage()" type="button">
                            <i class="ti ti-sparkles"></i> Generuj AI
                        </button>
                    </div>
                    <div id="bulkManualImagePreview" class="mt-2"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" onclick="addBulkManualArticle()">Dodaj do listy</button>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-init when the page loads
document.addEventListener('DOMContentLoaded', () => {
    if (typeof initBulkPublish === 'function') initBulkPublish();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
