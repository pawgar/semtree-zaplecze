<?php
require_once __DIR__ . '/../includes/header.php';
requireAdmin();
?>

<!-- Intro / how-to -->
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex align-items-start gap-3">
            <span class="avatar avatar-md bg-primary text-white"><i class="ti ti-robot"></i></span>
            <div class="flex-fill">
                <h3 class="card-title mb-1">Auto publikacje</h3>
                <p class="text-secondary small mb-2">
                    Automatyczna publikacja artykułów na stronach zapleczowych. Załaduj content plan (XLSX), skonfiguruj ustawienia per strona,
                    a CRON codziennie wygeneruje i opublikuje artykuły. Raporty są wysyłane na Telegram.
                </p>
                <div class="small">
                    <strong>Jak to działa:</strong>
                    <ol class="mb-2 ps-3">
                        <li>Wgraj content plan XLSX (kolumny: Tytuł, Słowo kluczowe, Słowa poboczne, Kategoria, Notatki)</li>
                        <li>Zmapuj kategorie z planu na kategorie WordPress (ikona <i class="ti ti-sitemap"></i>)</li>
                        <li>Ustaw limit dzienny i włącz stronę przełącznikiem "Aktywna"</li>
                        <li>CRON codziennie o 9:00 generuje artykuły (Claude AI) z grafiką (Gemini) i publikuje na WP</li>
                    </ol>
                    <a href="assets/content-plan-wzor.xlsx" download class="btn btn-sm btn-outline-secondary">
                        <i class="ti ti-download me-1"></i> Pobierz wzór content planu (XLSX)
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Summary cards -->
<div id="apSummary" class="row row-cards mb-3" style="display:none">
    <div class="col-sm-6 col-lg">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-green text-white avatar"><i class="ti ti-circle-check"></i></span></div>
                    <div class="col">
                        <div class="h2 mb-0" id="apTotalPublished">0</div>
                        <div class="text-secondary small">Opublikowane</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-primary text-white avatar"><i class="ti ti-hourglass"></i></span></div>
                    <div class="col">
                        <div class="h2 mb-0" id="apTotalPending">0</div>
                        <div class="text-secondary small">Oczekujące</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-red text-white avatar"><i class="ti ti-alert-triangle"></i></span></div>
                    <div class="col">
                        <div class="h2 mb-0" id="apTotalErrors">0</div>
                        <div class="text-secondary small">Błędy</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-azure text-white avatar"><i class="ti ti-stack-2"></i></span></div>
                    <div class="col">
                        <div class="h2 mb-0" id="apTotalQueued">0</div>
                        <div class="text-secondary small">Łącznie w kolejce</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-orange text-white avatar"><i class="ti ti-world"></i></span></div>
                    <div class="col">
                        <div class="h2 mb-0" id="apTotalSites">0</div>
                        <div class="text-secondary small">Aktywnych stron</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sites table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="ti ti-list me-2"></i>Strony zapleczowe</h3>
        <div class="card-actions d-flex gap-2">
            <div id="apManualProgress" class="d-none align-items-center gap-2" style="min-width:280px">
                <div class="progress flex-grow-1" style="height:20px">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" id="apManualProgressBar" style="width:0%"></div>
                </div>
                <small class="text-nowrap" id="apManualProgressLabel">0/0</small>
            </div>
            <button class="btn btn-sm btn-success" id="apRunManualBtn" onclick="runAutoPublishManual()">
                <i class="ti ti-rocket me-1"></i> Uruchom ręcznie
            </button>
            <button class="btn btn-sm btn-outline-primary" onclick="loadAutoPublish()">
                <i class="ti ti-refresh me-1"></i> Odśwież
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-vcenter card-table table-hover table-sm mb-0" id="apSitesTable" style="table-layout:fixed">
                <thead>
                    <tr>
                        <th style="width:180px">Strona</th>
                        <th class="text-center" style="width:70px">Dziennie</th>
                        <th class="text-center" style="width:55px"><label class="form-check form-check-inline mb-0"><input type="checkbox" class="form-check-input" title="Zaznacz/odznacz wszystkie" onchange="toggleAllApCheckbox('ap-speed-links', this.checked)"><span class="form-check-label small">SL</span></label></th>
                        <th class="text-center" style="width:55px"><label class="form-check form-check-inline mb-0" title="Grafiki w treści artykułu"><input type="checkbox" class="form-check-input" onchange="toggleAllApCheckbox('ap-inline-images', this.checked)"><span class="form-check-label small">Img</span></label></th>
                        <th class="text-center" style="width:55px" title="Losowy autor"><label class="form-check form-check-inline mb-0"><input type="checkbox" class="form-check-input" onchange="toggleAllApCheckbox('ap-random-author', this.checked)"><span class="form-check-label small">Aut.</span></label></th>
                        <th class="text-center" style="width:55px"><label class="form-check form-check-inline mb-0"><input type="checkbox" class="form-check-input" onchange="toggleAllApCheckbox('ap-enabled', this.checked)"><span class="form-check-label small">On</span></label></th>
                        <th class="text-center" style="width:110px">Status</th>
                        <th style="width:170px">Kolejka</th>
                        <th style="width:180px">Content plan</th>
                        <th class="text-center" style="width:80px">Akcje</th>
                    </tr>
                </thead>
                <tbody id="apSitesBody">
                    <tr><td colspan="10" class="text-center text-secondary py-4">
                        <div class="spinner-border spinner-border-sm me-2"></div>Ładowanie...
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Queue Modal -->
<div class="modal modal-blur fade" id="apQueueModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ti ti-list-check me-2"></i>Kolejka: <span id="apQueueSiteName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body p-0">
                <div class="p-3 border-bottom d-flex gap-2 flex-wrap">
                    <button class="btn btn-sm btn-outline-danger" onclick="clearApQueue('pending')"><i class="ti ti-trash me-1"></i>Usuń oczekujące</button>
                    <button class="btn btn-sm btn-outline-danger" onclick="clearApQueue('error')"><i class="ti ti-trash me-1"></i>Usuń błędy</button>
                    <button class="btn btn-sm btn-outline-danger" onclick="clearApQueue('all')"><i class="ti ti-trash me-1"></i>Usuń wszystko (bez opublikowanych)</button>
                    <div class="ms-auto">
                        <select class="form-select form-select-sm" id="apQueueFilter" onchange="filterApQueue()" style="width:auto">
                            <option value="all">Wszystkie</option>
                            <option value="pending">Oczekujące</option>
                            <option value="published">Opublikowane</option>
                            <option value="error">Błędy</option>
                        </select>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter table-sm card-table table-striped mb-0">
                        <thead>
                            <tr>
                                <th style="width:50px">#</th>
                                <th>Tytuł</th>
                                <th>Słowo kluczowe</th>
                                <th>Kategoria</th>
                                <th class="text-center" style="width:100px">Status</th>
                                <th style="width:200px">URL / Błąd</th>
                            </tr>
                        </thead>
                        <tbody id="apQueueBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Zamknij</button>
            </div>
        </div>
    </div>
</div>

<!-- Category Mapping Modal -->
<div class="modal modal-blur fade" id="apCategoryModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ti ti-sitemap me-2"></i>Mapowanie kategorii: <span id="apCatSiteName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                <p class="text-secondary small">Przypisz kategorie z content planu do kategorii WordPress. Nowe kategorie pojawią się po załadowaniu content planu.</p>
                <div id="apCatLoading" class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Ładowanie...</div>
                <div id="apCatContent" style="display:none">
                    <table class="table table-vcenter table-sm">
                        <thead>
                            <tr>
                                <th>Kategoria z planu</th>
                                <th>Kategoria WordPress</th>
                            </tr>
                        </thead>
                        <tbody id="apCatBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary ms-auto" onclick="saveApCategoryMap()">
                    <i class="ti ti-check me-1"></i>Zapisz mapowanie
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
