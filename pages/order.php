<?php require_once __DIR__ . '/../includes/header.php'; ?>


<!-- Tabs: Single / Bulk -->
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#orderSingleTab" type="button">
            <i class="ti ti-file-text"></i> Pojedynczy artykuł
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#orderBulkTab" type="button">
            <i class="ti ti-files"></i> Zamówienie masowe
        </button>
    </li>
</ul>

<div class="tab-content">
<!-- ========== SINGLE MODE ========== -->
<div class="tab-pane fade show active" id="orderSingleTab">

<!-- Step 1: Select site -->
<div class="card mb-3">
    <div class="card-body">
        <h6 class="card-title"><i class="ti ti-circle-number-1"></i> Wybierz stronę zapleczową</h6>
        <div class="d-flex align-items-center gap-3">
            <div class="position-relative" style="max-width:400px;flex:1">
                <input type="text" class="form-control input-dropdown" id="orderSiteSearch" placeholder="Szukaj strony..." autocomplete="new-password" data-lpignore="true"
                       onfocus="orderShowSiteDropdown()" oninput="orderFilterSites()">
                <div class="dropdown-menu w-100" id="orderSiteDropdown" style="max-height:300px;overflow-y:auto"></div>
                <input type="hidden" id="orderSiteId">
            </div>
            <span id="orderSiteLabel" class="text-secondary small"></span>
        </div>
    </div>
</div>

<!-- Step 2: Article parameters -->
<div class="card mb-3" id="orderFormCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="ti ti-circle-number-2"></i> Parametry artykułu</h6>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Tytuł artykułu <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="orderTitle" placeholder="np. Jak działa fotowoltaika w 2026 roku" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Kategoria</label>
                <div class="position-relative">
                    <input type="text" class="form-control input-dropdown" id="orderCategorySearch" placeholder="Wybierz kategorię..." autocomplete="new-password" data-lpignore="true"
                           onfocus="orderShowCategoryDropdown()" oninput="orderFilterCategories()">
                    <div class="dropdown-menu w-100" id="orderCategoryDropdown" style="max-height:200px;overflow-y:auto"></div>
                    <input type="hidden" id="orderCategoryId">
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Główne słowo kluczowe <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="orderMainKeyword" placeholder="np. fotowoltaika" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Język</label>
                <select class="form-select" id="orderLang">
                    <?php
                    require_once __DIR__ . '/../includes/article_prompt.php';
                    foreach (getLanguageList() as $code => $info) {
                        $selected = ($code === 'pl') ? ' selected' : '';
                        echo "<option value=\"{$code}\"{$selected}>" . htmlspecialchars($info['name']) . "</option>\n";
                    }
                    ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Słowa kluczowe pomocnicze <span class="text-secondary">(opcjonalnie)</span></label>
                <input type="text" class="form-control" id="orderSecondaryKeywords" placeholder="np. panele słoneczne, energia odnawialna">
            </div>
            <div class="col-12">
                <label class="form-label">Dodatkowe informacje <span class="text-secondary">(opcjonalnie)</span></label>
                <textarea class="form-control" id="orderNotes" rows="3" placeholder="Dodatkowe szczegóły, kontekst lub wskazówki dla AI dotyczące tego artykułu..."></textarea>
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="orderInlineImages">
                    <label class="form-check-label" for="orderInlineImages">
                        Generuj grafiki w treści (w każdej sekcji H2)
                    </label>
                </div>
            </div>
            <div class="col-12">
                <button class="btn btn-primary" onclick="orderGenerate()" id="orderGenerateBtn">
                    <i class="ti ti-sparkles"></i> Generuj artykuł
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Step 3: Progress -->
<div class="card mb-3" id="orderProgressCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="ti ti-circle-number-3"></i> Generowanie</h6>
        <div class="progress mb-2" style="height:24px">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="orderProgressBar" style="width:0%">0%</div>
        </div>
        <div id="orderProgressLog" class="small"></div>
    </div>
</div>

<!-- Step 4: Edit & Preview -->
<div class="card mb-3" id="orderEditCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="ti ti-circle-number-4"></i> Edycja artykułu</h6>

        <div class="mb-3">
            <label class="form-label fw-bold">Tytuł:</label>
            <input type="text" class="form-control" id="orderEditTitle">
        </div>

        <div class="row mb-3">
            <div class="col-md-9">
                <label class="form-label fw-bold">Treść:</label>
                <!-- Toolbar -->
                <div class="btn-toolbar border rounded-top bg-light p-1 gap-1" id="orderEditorToolbar">
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary" onclick="editorCmd('bold')" title="Pogrubienie (Ctrl+B)"><i class="ti ti-bold"></i></button>
                        <button type="button" class="btn btn-outline-secondary" onclick="editorCmd('italic')" title="Kursywa (Ctrl+I)"><i class="ti ti-italic"></i></button>
                        <button type="button" class="btn btn-outline-secondary" onclick="editorCmd('underline')" title="Podkreślenie (Ctrl+U)"><i class="ti ti-underline"></i></button>
                        <button type="button" class="btn btn-outline-secondary" onclick="editorCmd('strikeThrough')" title="Przekreślenie"><i class="ti ti-strikethrough"></i></button>
                    </div>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary" onclick="editorHeading('H2')" title="Nagłówek H2">H2</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="editorHeading('H3')" title="Nagłówek H3">H3</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="editorHeading('H4')" title="Nagłówek H4">H4</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="editorCmd('formatBlock', 'P')" title="Akapit">P</button>
                    </div>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary" onclick="editorCmd('insertUnorderedList')" title="Lista punktowana"><i class="ti ti-list"></i></button>
                        <button type="button" class="btn btn-outline-secondary" onclick="editorCmd('insertOrderedList')" title="Lista numerowana"><i class="ti ti-list-numbers"></i></button>
                    </div>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary" onclick="editorInsertLink()" title="Wstaw / edytuj link"><i class="ti ti-link"></i></button>
                        <button type="button" class="btn btn-outline-secondary" onclick="editorRemoveLink()" title="Usuń link"><i class="ti ti-link text-danger"></i><i class="ti ti-x" style="margin-left:-4px"></i></button>
                    </div>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary" onclick="editorCmd('removeFormat')" title="Usuń formatowanie"><i class="ti ti-eraser"></i></button>
                        <button type="button" class="btn btn-outline-secondary" onclick="editorToggleSource()" title="Widok HTML" id="orderToggleSourceBtn"><i class="ti ti-code"></i></button>
                    </div>
                </div>
                <!-- Editor -->
                <div id="orderEditContent" contenteditable="true" class="form-control border-top-0 rounded-top-0" style="min-height:400px;max-height:600px;overflow-y:auto"></div>
                <!-- HTML source (hidden by default) -->
                <textarea id="orderEditSource" class="form-control font-monospace border-top-0 rounded-top-0" style="min-height:400px;max-height:600px;display:none;font-size:0.8rem"></textarea>
                <div class="d-flex gap-3 mt-1">
                    <span class="text-secondary small" id="orderCharCount"></span>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Grafika wyróżniająca:</label>
                <div id="orderFeaturedPreview" class="border rounded p-2 text-center" style="min-height:150px">
                    <span class="text-secondary">Brak</span>
                </div>
                <button class="btn btn-outline-info btn-sm mt-2 w-100" onclick="orderRegenerateFeatured()">
                    <i class="ti ti-refresh"></i> Regeneruj grafikę
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
            <i class="ti ti-send"></i> Opublikuj artykuł
        </button>
    </div>
</div>

<!-- Step 5: Result -->
<div class="card mb-3" id="orderResultCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="ti ti-circle-number-5"></i> Wynik</h6>
        <div id="orderResultLog"></div>
    </div>
</div>

</div><!-- end single tab -->

<!-- ========== BULK MODE ========== -->
<div class="tab-pane fade" id="orderBulkTab">

<div class="card mb-3">
    <div class="card-body">
        <h6 class="card-title"><i class="ti ti-circle-number-1"></i> Wybierz stronę zapleczową</h6>
        <div class="d-flex align-items-center gap-3">
            <div class="position-relative" style="max-width:400px;flex:1">
                <input type="text" class="form-control input-dropdown" id="bulkOrderSiteSearch" placeholder="Szukaj strony..." autocomplete="new-password" data-lpignore="true"
                       onfocus="bulkOrderShowSiteDropdown()" oninput="bulkOrderFilterSites()">
                <div class="dropdown-menu w-100" id="bulkOrderSiteDropdown" style="max-height:300px;overflow-y:auto"></div>
                <input type="hidden" id="bulkOrderSiteId">
            </div>
            <span id="bulkOrderSiteLabel" class="text-secondary small"></span>
        </div>
    </div>
</div>

<div class="card mb-3" id="bulkOrderUploadCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="ti ti-circle-number-2"></i> Wgraj plik</h6>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <button class="btn btn-outline-primary btn-sm" onclick="document.getElementById('bulkOrderFile').click()">
                <i class="ti ti-file-excel"></i> Wgraj plik XLSX / CSV
            </button>
            <input type="file" id="bulkOrderFile" accept=".xlsx,.csv,.txt" style="display:none" onchange="bulkOrderParseFile(this)">
            <span id="bulkOrderFileStatus" class="text-secondary small"></span>
            <div class="form-check ms-3">
                <input class="form-check-input" type="checkbox" id="bulkOrderInlineImages">
                <label class="form-check-label" for="bulkOrderInlineImages">Grafiki w treści</label>
            </div>
            <div class="form-check ms-3">
                <input class="form-check-input" type="checkbox" id="bulkOrderSpeedLinks">
                <label class="form-check-label" for="bulkOrderSpeedLinks">Speed-Links</label>
            </div>
        </div>
        <div class="mt-2">
            <label class="form-label small text-secondary">Dodatkowe informacje dla wszystkich artykułów (opcjonalnie):</label>
            <textarea class="form-control form-control-sm" id="bulkOrderGlobalNotes" rows="2" placeholder="Wspólne wskazówki dla AI dotyczące wszystkich artykułów..."></textarea>
        </div>
    </div>
</div>

<!-- Column Mapping Modal -->
<div class="modal fade" id="bulkOrderMappingModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ti ti-sitemap"></i> Mapowanie kolumn</h5>
            </div>
            <div class="modal-body">
                <p class="text-secondary small mb-3">Przypisz kolumny z pliku do odpowiednich pól. Pola oznaczone <span class="text-danger">*</span> są wymagane.</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Tytuł artykułu <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" id="mapColTitle"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Główne słowo kluczowe <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" id="mapColMainKw"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Słowa kluczowe pomocnicze</label>
                        <select class="form-select form-select-sm" id="mapColSecondaryKw"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Dodatkowe informacje</label>
                        <select class="form-select form-select-sm" id="mapColNotes"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Kategoria</label>
                        <select class="form-select form-select-sm" id="mapColCategory"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Język</label>
                        <select class="form-select form-select-sm" id="mapColLang"></select>
                    </div>
                </div>
                <hr>
                <p class="small fw-semibold mb-2">Podgląd pierwszych wierszy:</p>
                <div class="table-responsive" style="max-height:200px;overflow-y:auto">
                    <table class="table table-sm table-bordered" id="bulkOrderPreviewTable">
                        <thead class="table-light"><tr></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="bulkOrderCancelMapping()">Anuluj</button>
                <button type="button" class="btn btn-primary" onclick="bulkOrderApplyMapping()">
                    <i class="ti ti-check"></i> Zatwierdź mapowanie
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Category mapping (optional step after file import) -->
<div class="card mb-3" id="bulkOrderCategoryMapCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="ti ti-circle-number-3"></i> Mapowanie kategorii <span class="text-secondary small fw-normal">(opcjonalne)</span></h6>
        <p class="text-secondary small mb-2">Przypisz kategorie z pliku do kategorii WordPress. Niedopasowane kategorie zostaną oznaczone.</p>
        <div class="table-responsive">
            <table class="table table-sm" id="bulkOrderCategoryMapTable">
                <thead><tr><th>Kategoria z pliku</th><th>Ilość</th><th>Kategoria WordPress</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm" onclick="bulkOrderApplyCategoryMapping()">
                <i class="ti ti-check"></i> Zatwierdź i przejdź dalej
            </button>
            <button class="btn btn-outline-secondary btn-sm" onclick="bulkOrderSkipCategoryMapping()">Pomiń</button>
        </div>
    </div>
</div>

<div class="card mb-3" id="bulkOrderTableCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="ti ti-circle-number-4"></i> Artykuły do wygenerowania</h6>
        <div class="d-flex gap-2 mb-2 align-items-center flex-wrap">
            <div class="d-flex align-items-center gap-1">
                <label class="small text-secondary text-nowrap">Kategoria domyślna:</label>
                <select class="form-select form-select-sm" id="bulkOrderFallbackCategory" style="width:200px"></select>
            </div>
            <div class="d-flex align-items-center gap-1 ms-3">
                <label class="small text-secondary text-nowrap">Język domyślny:</label>
                <select class="form-select form-select-sm" id="bulkOrderLang" style="width:200px">
                    <?php
                    foreach (getLanguageList() as $code => $info) {
                        $selected = ($code === 'pl') ? ' selected' : '';
                        echo "<option value=\"{$code}\"{$selected}>" . htmlspecialchars($info['name']) . "</option>\n";
                    }
                    ?>
                </select>
            </div>
        </div>

        <!-- Author assignment -->
        <div class="d-flex gap-2 mb-2 align-items-center flex-wrap">
            <div class="d-flex align-items-center gap-1">
                <label class="small text-secondary text-nowrap">Autor:</label>
                <select class="form-select form-select-sm" id="bulkOrderAuthorMode" style="width:200px" onchange="bulkOrderAuthorModeChanged()">
                    <option value="default">Domyślny autor</option>
                    <option value="random">Losuj autorów</option>
                    <option value="manual">Ręcznie per artykuł</option>
                </select>
            </div>
            <div class="d-flex align-items-center gap-1 ms-2" id="bulkOrderDefaultAuthorWrap">
                <select class="form-select form-select-sm" id="bulkOrderDefaultAuthor" style="width:200px">
                    <option value="">-- brak (domyślny WP) --</option>
                </select>
            </div>
            <div id="bulkOrderRandomAuthorsWrap" style="display:none" class="d-flex align-items-center gap-1 ms-2 flex-wrap">
                <span class="small text-secondary">Losuj spośród:</span>
                <div id="bulkOrderRandomAuthorsChecks" class="d-flex gap-2 flex-wrap"></div>
                <button class="btn btn-outline-secondary btn-sm ms-1" onclick="bulkOrderAssignRandomAuthors()" title="Wylosuj autorów">
                    <i class="ti ti-arrows-shuffle"></i> Losuj
                </button>
            </div>
        </div>

        <!-- Date randomization -->
        <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="bulkOrderRandomDates">
                <label class="form-check-label small" for="bulkOrderRandomDates">Losowe daty publikacji</label>
            </div>
            <div class="d-flex align-items-center gap-1 ms-2" id="bulkOrderDateRange" style="display:none!important">
                <label class="small text-secondary text-nowrap">Od:</label>
                <input type="date" class="form-control form-control-sm" id="bulkOrderDateFrom" style="width:160px">
                <label class="small text-secondary text-nowrap ms-2">Do:</label>
                <input type="date" class="form-control form-control-sm" id="bulkOrderDateTo" style="width:160px">
                <button class="btn btn-outline-secondary btn-sm ms-2" onclick="bulkOrderAssignRandomDates()" title="Wylosuj daty">
                    <i class="ti ti-arrows-shuffle"></i> Losuj
                </button>
            </div>
            <div class="d-flex align-items-center gap-1 ms-3">
                <label class="small text-secondary text-nowrap">Status:</label>
                <select class="form-select form-select-sm" id="bulkOrderPublishStatus" style="width:140px">
                    <option value="publish">Publikuj</option>
                    <option value="draft">Szkic</option>
                </select>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-hover" id="bulkOrderTable">
                <thead><tr>
                    <th><input type="checkbox" class="form-check-input" id="bulkOrderSelectAll" checked onchange="bulkOrderToggleAll(this.checked)"></th>
                    <th>#</th><th>Tytuł</th><th>Główne KW</th><th>Pomocnicze KW</th><th>Kategoria</th><th>Autor</th><th>Język</th><th>Data</th><th>Informacje</th><th>Status</th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" onclick="bulkOrderStart()" id="bulkOrderStartBtn">
                <i class="ti ti-player-play"></i> Generuj i publikuj zaznaczone
            </button>
            <span class="text-secondary small align-self-center" id="bulkOrderSelectedCount"></span>
        </div>
    </div>
</div>

<div class="card mb-3" id="bulkOrderProgressCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="ti ti-circle-number-5"></i> Postęp</h6>
        <div class="progress mb-2" style="height:24px">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="bulkOrderProgressBar" style="width:0%">0%</div>
        </div>
        <div id="bulkOrderLog" class="small" style="max-height:400px;overflow-y:auto"></div>
    </div>
</div>

<div class="card mb-3" id="bulkOrderResultCard" style="display:none">
    <div class="card-body">
        <h6 class="card-title"><i class="ti ti-circle-number-6"></i> Raport</h6>
        <div id="bulkOrderResultLog"></div>
        <button class="btn btn-outline-primary btn-sm mt-2" onclick="bulkOrderCopyUrls()">
            <i class="ti ti-clipboard"></i> Kopiuj linki
        </button>
    </div>
</div>

</div><!-- end bulk tab -->
</div><!-- end tab-content -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
