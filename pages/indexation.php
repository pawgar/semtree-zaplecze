<?php
require_once __DIR__ . '/../includes/header.php';
?>

<div id="indexationContainer">
    <!-- Info -->
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="d-flex align-items-start gap-3">
                <span class="avatar avatar-md bg-primary text-white"><i class="ti ti-list-search"></i></span>
                <div>
                    <h3 class="card-title mb-1">Indeksacja stron zapleczowych</h3>
                    <p class="text-secondary small mb-0">
                        Stan indeksacji podstron w Google (dane z GSC URL Inspection: kliknięcia to nie to samo co indeksacja —
                        tu widzisz autorytatywny werdykt Google per URL). Zaznacz domeny, aby zbiorczo wyeksportować lub
                        zgłosić niezaindeksowane podstrony do Rapid URL Indexer. Skan pełny odbywa się nocnym CRON-em;
                        pojedynczą domenę odświeżysz przyciskiem <strong>Odśwież</strong>.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary cards -->
    <div class="row row-cards mb-3" id="idxSummary" style="display:none">
        <div class="col-sm-6 col-lg-3">
            <div class="card card-sm"><div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-secondary text-white avatar"><i class="ti ti-file-text"></i></span></div>
                    <div class="col"><div class="h2 mb-0" id="idxTotal">-</div><div class="text-secondary small">Podstron (łącznie)</div></div>
                </div>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card card-sm"><div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-green text-white avatar"><i class="ti ti-circle-check"></i></span></div>
                    <div class="col"><div class="h2 mb-0" id="idxIndexed">-</div><div class="text-secondary small">Zaindeksowane</div></div>
                </div>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card card-sm"><div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-red text-white avatar"><i class="ti ti-circle-x"></i></span></div>
                    <div class="col"><div class="h2 mb-0" id="idxNotIndexed">-</div><div class="text-secondary small">Niezaindeksowane</div></div>
                </div>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card card-sm"><div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-azure text-white avatar"><i class="ti ti-percentage"></i></span></div>
                    <div class="col"><div class="h2 mb-0" id="idxPct">-</div><div class="text-secondary small">Wskaźnik indeksacji</div></div>
                </div>
            </div></div>
        </div>
    </div>

    <!-- Trend chart -->
    <div class="card mb-3" id="idxChartCard" style="display:none">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-chart-area-line me-2"></i>Trend indeksacji (90 dni)</h3></div>
        <div class="card-body"><div id="idxChart" style="overflow-x:auto"></div></div>
    </div>

    <!-- Bulk actions -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-auto">
                    <span class="text-secondary small">Zaznaczono: <strong id="idxSelectedCount">0</strong> domen</span>
                </div>
                <div class="col-auto">
                    <button class="btn btn-outline-secondary btn-sm" onclick="exportNonIndexed(false)" title="Eksport CSV wszystkich niezaindeksowanych">
                        <i class="ti ti-file-spreadsheet me-1"></i> Eksport wszystkich
                    </button>
                </div>
                <div class="col-auto">
                    <button class="btn btn-outline-secondary btn-sm" id="idxExportSelBtn" onclick="exportNonIndexed(true)" disabled title="Eksport CSV niezaindeksowanych z zaznaczonych domen">
                        <i class="ti ti-file-export me-1"></i> Eksport zaznaczonych
                    </button>
                </div>
                <div class="col-auto">
                    <button class="btn btn-primary btn-sm" id="idxSubmitBtn" onclick="submitNonIndexedToRapid()" disabled title="Zgłoś niezaindeksowane z zaznaczonych domen do Rapid URL Indexer">
                        <i class="ti ti-rocket me-1"></i> Zgłoś zaznaczone do indeksacji
                    </button>
                </div>
                <div class="col text-end">
                    <button class="btn btn-outline-primary btn-sm" onclick="loadIndexationOverview()"><i class="ti ti-refresh me-1"></i> Odśwież widok</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Per-domain table -->
    <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-world-www me-2"></i>Indeksacja per domena</h3></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="w-1"><input type="checkbox" class="form-check-input m-0" id="idxSelectAll" onchange="idxToggleAll(this.checked)"></th>
                            <th>Domena</th>
                            <th class="text-end">Podstron</th>
                            <th class="text-end">Zaind.</th>
                            <th class="text-end">Niezaind.</th>
                            <th style="width:160px">Indeksacja</th>
                            <th>Ostatni skan</th>
                            <th class="text-end">Akcje</th>
                        </tr>
                    </thead>
                    <tbody id="idxTableBody">
                        <tr><td colspan="8" class="text-center text-muted py-4"><i class="bi bi-arrow-clockwise spin"></i> Ładowanie...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal: niezaindeksowane URL-e domeny -->
<div class="modal fade" id="idxUrlsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="idxUrlsModalTitle">Niezaindeksowane podstrony</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body" id="idxUrlsModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Zamknij</button>
                <button type="button" class="btn btn-primary" id="idxModalSubmitBtn"><i class="ti ti-rocket me-1"></i>Zgłoś te podstrony do indeksacji</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
