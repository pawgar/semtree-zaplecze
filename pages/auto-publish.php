<?php
require_once __DIR__ . '/../includes/header.php';
requireAdmin();
?>

<div class="mb-4">
    <div class="content-card">
        <div class="content-card-body">
            <div class="d-flex align-items-start gap-3">
                <div class="stat-card-icon stat-card-icon--primary"><i class="bi bi-robot"></i></div>
                <div>
                    <h5 class="mb-1">Auto publikacje</h5>
                    <p class="text-muted small mb-2">
                        Automatyczna publikacja artykułów na stronach zapleczowych. Załaduj content plan (XLSX), skonfiguruj ustawienia per strona,
                        a CRON codziennie wygeneruje i opublikuje artykuły. Raporty są wysyłane na Telegram.
                    </p>
                    <div class="small">
                        <strong>Jak to działa:</strong>
                        <ol class="mb-2 ps-3" style="font-size:0.85rem">
                            <li>Wgraj content plan XLSX (kolumny: Tytuł, Słowo kluczowe, Słowa poboczne, Kategoria, Notatki)</li>
                            <li>Zmapuj kategorie z planu na kategorie WordPress (ikona <i class="bi bi-diagram-3"></i>)</li>
                            <li>Ustaw limit dzienny i włącz stronę przełącznikiem "Aktywna"</li>
                            <li>CRON codziennie o 9:00 generuje artykuły (Claude AI) z grafiką (Gemini) i publikuje na WP</li>
                        </ol>
                        <a href="assets/content-plan-wzor.xlsx" download class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i> Pobierz wzór content planu (XLSX)</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Summary cards -->
<div id="apSummary" class="row g-3 mb-4" style="display:none">
    <div class="col">
        <div class="stat-card">
            <div class="stat-card-icon stat-card-icon--success"><i class="bi bi-check-circle"></i></div>
            <div class="stat-card-value" id="apTotalPublished">0</div>
            <div class="stat-card-label">Opublikowane</div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card">
            <div class="stat-card-icon stat-card-icon--primary"><i class="bi bi-hourglass-split"></i></div>
            <div class="stat-card-value" id="apTotalPending">0</div>
            <div class="stat-card-label">Oczekujące</div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card">
            <div class="stat-card-icon stat-card-icon--danger"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="stat-card-value" id="apTotalErrors">0</div>
            <div class="stat-card-label">Błędy</div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card">
            <div class="stat-card-icon stat-card-icon--info"><i class="bi bi-collection"></i></div>
            <div class="stat-card-value" id="apTotalQueued">0</div>
            <div class="stat-card-label">Łącznie w kolejce</div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card">
            <div class="stat-card-icon stat-card-icon--warning"><i class="bi bi-globe2"></i></div>
            <div class="stat-card-value" id="apTotalSites">0</div>
            <div class="stat-card-label">Aktywnych stron</div>
        </div>
    </div>
</div>

<!-- Sites table -->
<div class="content-card">
    <div class="content-card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul"></i> Strony zapleczowe</span>
        <div class="d-flex gap-2">
            <div id="apManualProgress" class="d-none align-items-center gap-2" style="min-width:300px">
                <div class="progress flex-grow-1" style="height:20px">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" id="apManualProgressBar" style="width:0%"></div>
                </div>
                <small class="text-nowrap" id="apManualProgressLabel">0/0</small>
            </div>
            <button class="btn btn-sm btn-success" id="apRunManualBtn" onclick="runAutoPublishManual()"><i class="bi bi-rocket-takeoff"></i> Uruchom ręcznie</button>
            <button class="btn btn-sm btn-outline-primary" onclick="loadAutoPublish()"><i class="bi bi-arrow-clockwise"></i> Odśwież</button>
        </div>
    </div>
    <div class="content-card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 table-sm" id="apSitesTable" style="table-layout:fixed">
                <thead>
                    <tr>
                        <th style="width:180px">Strona</th>
                        <th class="text-center" style="width:65px">Dziennie</th>
                        <th class="text-center" style="width:50px"><input type="checkbox" class="form-check-input" title="Zaznacz/odznacz wszystkie" onchange="toggleAllApCheckbox('ap-speed-links', this.checked)"> SL</th>
                        <th class="text-center" style="width:50px"><input type="checkbox" class="form-check-input" title="Zaznacz/odznacz wszystkie — grafiki w treści artykułu" onchange="toggleAllApCheckbox('ap-inline-images', this.checked)"> Img</th>
                        <th class="text-center" style="width:50px" title="Losowy autor — jeśli na WP jest wielu autorów, wpisy będą przypisywane losowo"><input type="checkbox" class="form-check-input" title="Zaznacz/odznacz wszystkie" onchange="toggleAllApCheckbox('ap-random-author', this.checked)"> Aut.</th>
                        <th class="text-center" style="width:55px"><input type="checkbox" class="form-check-input" title="Zaznacz/odznacz wszystkie" onchange="toggleAllApCheckbox('ap-enabled', this.checked)"> On</th>
                        <th class="text-center" style="width:100px">Status</th>
                        <th style="width:160px">Kolejka</th>
                        <th style="width:170px">Content plan</th>
                        <th class="text-center" style="width:70px">Akcje</th>
                    </tr>
                </thead>
                <tbody id="apSitesBody">
                    <tr><td colspan="10" class="text-center text-muted py-4"><i class="bi bi-hourglass-split"></i> Ładowanie...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Queue Modal -->
<div class="modal fade" id="apQueueModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-list-check"></i> Kolejka: <span id="apQueueSiteName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="p-3 d-flex gap-2 border-bottom">
                    <button class="btn btn-sm btn-outline-danger" onclick="clearApQueue('pending')"><i class="bi bi-trash"></i> Usuń oczekujące</button>
                    <button class="btn btn-sm btn-outline-danger" onclick="clearApQueue('error')"><i class="bi bi-trash"></i> Usuń błędy</button>
                    <button class="btn btn-sm btn-outline-danger" onclick="clearApQueue('all')"><i class="bi bi-trash"></i> Usuń wszystko (bez opublikowanych)</button>
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
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width:40px">#</th>
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
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>
            </div>
        </div>
    </div>
</div>

<!-- Category Mapping Modal -->
<div class="modal fade" id="apCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-diagram-3"></i> Mapowanie kategorii: <span id="apCatSiteName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Przypisz kategorie z content planu do kategorii WordPress. Nowe kategorie pojawią się po załadowaniu content planu.</p>
                <div id="apCatLoading" class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Ładowanie...</div>
                <div id="apCatContent" style="display:none">
                    <table class="table table-sm">
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
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="saveApCategoryMap()"><i class="bi bi-check-lg"></i> Zapisz mapowanie</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
