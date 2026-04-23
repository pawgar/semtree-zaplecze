<?php
require_once __DIR__ . '/../includes/header.php';
?>

<div id="gscReportContainer">
    <!-- Info section -->
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="d-flex align-items-start gap-3">
                <span class="avatar avatar-md bg-primary text-white"><i class="ti ti-chart-line"></i></span>
                <div>
                    <h3 class="card-title mb-1">Raport Google Search Console</h3>
                    <p class="text-secondary small mb-0">
                        Zbiorczy przegląd widoczności wszystkich stron zapleczowych w Google.
                        Dane pochodzą z GSC (kliknięcia, wyświetlenia, CTR, średnia pozycja) i są odświeżane przez CRON lub ręcznie.
                        Wybierz zakres dat i kliknij <strong>Generuj raport</strong> aby pobrać dane.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Controls -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-auto">
                    <select class="form-select form-select-sm" id="gscReportRange" style="min-width:170px">
                        <option value="7d">Ostatnie 7 dni</option>
                        <option value="28d" selected>Ostatnie 28 dni</option>
                        <option value="3m">Ostatnie 3 miesiące</option>
                        <option value="6m">Ostatnie 6 miesięcy</option>
                        <option value="12m">Ostatnie 12 miesięcy</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-primary btn-sm" id="gscReportGenerateBtn" onclick="loadGscReport()">
                        <i class="ti ti-player-play me-1"></i> Generuj raport
                    </button>
                </div>
                <div class="col-auto">
                    <button class="btn btn-outline-success btn-sm" onclick="refreshGscReport()" title="Odśwież dane z GSC API i wygeneruj raport">
                        <i class="ti ti-refresh me-1"></i> Odśwież dane z GSC
                    </button>
                </div>
                <div class="col-auto">
                    <button class="btn btn-outline-secondary btn-sm" id="gscExportBtn" onclick="exportGscReportXlsx()" style="display:none" title="Eksportuj do XLSX">
                        <i class="ti ti-file-spreadsheet me-1"></i> Eksportuj XLSX
                    </button>
                </div>
                <div class="col">
                    <span class="text-secondary small" id="gscReportDateInfo"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary cards (hidden until report generated) -->
    <div class="row row-cards mb-3" id="gscReportSummary" style="display:none">
        <div class="col-sm-6 col-lg">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto"><span class="bg-primary text-white avatar"><i class="ti ti-trophy"></i></span></div>
                        <div class="col">
                            <div class="h2 mb-0" id="gscRepBestClicks">-</div>
                            <div class="text-secondary small">Najwięcej kliknięć</div>
                            <div class="small text-secondary text-truncate" id="gscRepBestClicksName"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto"><span class="bg-green text-white avatar"><i class="ti ti-trending-up"></i></span></div>
                        <div class="col">
                            <div class="h2 mb-0" id="gscRepBestTrend">-</div>
                            <div class="text-secondary small">Najlepszy trend</div>
                            <div class="small text-secondary text-truncate" id="gscRepBestTrendName"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto"><span class="bg-orange text-white avatar"><i class="ti ti-eye"></i></span></div>
                        <div class="col">
                            <div class="h2 mb-0" id="gscRepBestImpressions">-</div>
                            <div class="text-secondary small">Najwięcej wyświetleń</div>
                            <div class="small text-secondary text-truncate" id="gscRepBestImpressionsName"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto"><span class="bg-azure text-white avatar"><i class="ti ti-target"></i></span></div>
                        <div class="col">
                            <div class="h2 mb-0" id="gscRepBestCtr">-</div>
                            <div class="text-secondary small">Najlepszy CTR</div>
                            <div class="small text-secondary text-truncate" id="gscRepBestCtrName"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card card-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto"><span class="bg-secondary text-white avatar"><i class="ti ti-chart-bar"></i></span></div>
                        <div class="col">
                            <div class="h2 mb-0" id="gscRepTotalClicks">-</div>
                            <div class="text-secondary small">Suma kliknięć <span id="gscRepTotalClicksChange"></span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Report table -->
    <div class="card" id="gscReportTableCard" style="display:none">
        <div class="card-header">
            <h3 class="card-title"><i class="ti ti-chart-line me-2"></i>Raport GSC — wszystkie strony</h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-striped table-hover mb-0" id="gscReportTable">
                    <thead>
                        <tr>
                            <th class="w-1">#</th>
                            <th class="sortable" data-sort="name" onclick="sortGscReport('name')" style="cursor:pointer">Strona <i class="ti ti-arrows-sort text-secondary"></i></th>
                            <th class="sortable text-end" data-sort="clicks" onclick="sortGscReport('clicks')" style="cursor:pointer">Kliknięcia <i class="ti ti-arrows-sort text-secondary"></i></th>
                            <th class="sortable text-end" data-sort="impressions" onclick="sortGscReport('impressions')" style="cursor:pointer">Wyświetlenia <i class="ti ti-arrows-sort text-secondary"></i></th>
                            <th class="sortable text-end" data-sort="ctr" onclick="sortGscReport('ctr')" style="cursor:pointer">CTR <i class="ti ti-arrows-sort text-secondary"></i></th>
                            <th class="sortable text-end" data-sort="position" onclick="sortGscReport('position')" style="cursor:pointer">Śr. pozycja <i class="ti ti-arrows-sort text-secondary"></i></th>
                            <th class="sortable text-end" data-sort="clicks_change" onclick="sortGscReport('clicks_change')" style="cursor:pointer">Klik. zmiana <i class="ti ti-arrows-sort text-secondary"></i></th>
                            <th class="sortable text-end" data-sort="impressions_change" onclick="sortGscReport('impressions_change')" style="cursor:pointer">Wyśw. zmiana <i class="ti ti-arrows-sort text-secondary"></i></th>
                            <th>Trend kliknięć</th>
                        </tr>
                    </thead>
                    <tbody id="gscReportBody">
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Initial state message -->
    <div id="gscReportInitial" class="alert alert-info mt-0" role="alert">
        <div class="d-flex">
            <div><i class="ti ti-info-circle me-2"></i></div>
            <div>Kliknij <strong>Generuj raport</strong> aby pobrać dane z Google Search Console.</div>
        </div>
    </div>

    <div id="gscReportNoData" class="alert alert-warning mt-3" style="display:none" role="alert">
        <div class="d-flex">
            <div><i class="ti ti-alert-triangle me-2"></i></div>
            <div>Brak danych GSC. Upewnij się, że GSC jest połączone w <a href="index.php?page=settings" class="alert-link">ustawieniach</a>.</div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
