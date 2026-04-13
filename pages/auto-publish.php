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
                    <p class="text-muted small mb-0">
                        Automatyczna publikacja artykulow na stronach zapleczowych. Zaladuj content plan (XLSX), skonfiguruj ustawienia per strona,
                        a CRON codziennie wygeneruje i opublikuje artykuly. Raporty sa wysylane na Telegram.
                    </p>
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
            <div class="stat-card-label">Oczekujace</div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card">
            <div class="stat-card-icon stat-card-icon--danger"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="stat-card-value" id="apTotalErrors">0</div>
            <div class="stat-card-label">Bledy</div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card">
            <div class="stat-card-icon stat-card-icon--info"><i class="bi bi-collection"></i></div>
            <div class="stat-card-value" id="apTotalQueued">0</div>
            <div class="stat-card-label">Lacznie w kolejce</div>
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
        <button class="btn btn-sm btn-outline-primary" onclick="loadAutoPublish()"><i class="bi bi-arrow-clockwise"></i> Odswiez</button>
    </div>
    <div class="content-card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="apSitesTable">
                <thead>
                    <tr>
                        <th>Strona</th>
                        <th class="text-center" style="width:80px">Dziennie</th>
                        <th class="text-center" style="width:60px">Speed</th>
                        <th class="text-center" style="width:60px">Grafiki</th>
                        <th class="text-center" style="width:60px">Autor</th>
                        <th class="text-center" style="width:60px">Aktywna</th>
                        <th style="width:200px">Kolejka</th>
                        <th style="width:200px">Content plan</th>
                        <th class="text-center" style="width:120px">Akcje</th>
                    </tr>
                </thead>
                <tbody id="apSitesBody">
                    <tr><td colspan="9" class="text-center text-muted py-4"><i class="bi bi-hourglass-split"></i> Ladowanie...</td></tr>
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
                    <button class="btn btn-sm btn-outline-danger" onclick="clearApQueue('pending')"><i class="bi bi-trash"></i> Usun oczekujace</button>
                    <button class="btn btn-sm btn-outline-danger" onclick="clearApQueue('error')"><i class="bi bi-trash"></i> Usun bledy</button>
                    <button class="btn btn-sm btn-outline-danger" onclick="clearApQueue('all')"><i class="bi bi-trash"></i> Usun wszystko (bez opublikowanych)</button>
                    <div class="ms-auto">
                        <select class="form-select form-select-sm" id="apQueueFilter" onchange="filterApQueue()" style="width:auto">
                            <option value="all">Wszystkie</option>
                            <option value="pending">Oczekujace</option>
                            <option value="published">Opublikowane</option>
                            <option value="error">Bledy</option>
                        </select>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width:40px">#</th>
                                <th>Tytul</th>
                                <th>Slowo kluczowe</th>
                                <th>Kategoria</th>
                                <th class="text-center" style="width:100px">Status</th>
                                <th style="width:200px">URL / Blad</th>
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
                <p class="text-muted small">Przypisz kategorie z content planu do kategorii WordPress. Nowe kategorie pojawia sie po zaladowaniu content planu.</p>
                <div id="apCatLoading" class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Ladowanie...</div>
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
