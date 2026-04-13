<?php
require_once __DIR__ . '/../includes/header.php';
?>

<div id="gscReportContainer">
    <!-- Info section -->
    <div class="content-card mb-4">
        <div class="content-card-body py-3 px-4">
            <div class="d-flex align-items-start gap-3">
                <div class="stat-card-icon stat-card-icon--primary flex-shrink-0" style="width:40px;height:40px;font-size:1rem;">
                    <i class="bi bi-graph-up"></i>
                </div>
                <div>
                    <h6 class="mb-1 fw-bold">Raport Google Search Console</h6>
                    <p class="text-muted small mb-0">
                        Zbiorczy przegląd widoczności wszystkich stron zapleczowych w Google.
                        Dane pochodzą z GSC (kliknięcia, wyświetlenia, CTR, średnia pozycja) i są odświeżane przez CRON lub ręcznie.
                        Wybierz zakres dat i kliknij <strong>Generuj raport</strong> aby pobrać dane.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Controls -->
    <div class="d-flex gap-2 align-items-center mb-3 flex-wrap">
        <select class="form-select form-select-sm" id="gscReportRange" style="width:160px">
            <option value="7d">Ostatnie 7 dni</option>
            <option value="28d" selected>Ostatnie 28 dni</option>
            <option value="3m">Ostatnie 3 miesiące</option>
            <option value="6m">Ostatnie 6 miesięcy</option>
            <option value="12m">Ostatnie 12 miesięcy</option>
        </select>
        <button class="btn btn-primary btn-sm" id="gscReportGenerateBtn" onclick="loadGscReport()">
            <i class="bi bi-play-fill"></i> Generuj raport
        </button>
        <button class="btn btn-outline-success btn-sm" onclick="refreshGscReport()" title="Odśwież dane z GSC API i wygeneruj raport">
            <i class="bi bi-arrow-clockwise"></i> Odśwież dane z GSC
        </button>
        <button class="btn btn-outline-secondary btn-sm" id="gscExportBtn" onclick="exportGscReportXlsx()" style="display:none" title="Eksportuj do XLSX">
            <i class="bi bi-file-earmark-spreadsheet"></i> Eksportuj XLSX
        </button>
        <span class="text-muted small" id="gscReportDateInfo"></span>
    </div>

    <!-- Summary cards (hidden until report generated) -->
    <div class="row g-3 mb-4" id="gscReportSummary" style="display:none">
        <div class="col">
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--primary"><i class="bi bi-trophy"></i></div>
                <div class="stat-card-value" id="gscRepBestClicks">-</div>
                <div class="stat-card-label">Najwięcej kliknięć</div>
                <div class="stat-card-change small text-muted" id="gscRepBestClicksName"></div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--success"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="stat-card-value" id="gscRepBestTrend">-</div>
                <div class="stat-card-label">Najlepszy trend</div>
                <div class="stat-card-change small text-muted" id="gscRepBestTrendName"></div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--warning"><i class="bi bi-eye"></i></div>
                <div class="stat-card-value" id="gscRepBestImpressions">-</div>
                <div class="stat-card-label">Najwięcej wyświetleń</div>
                <div class="stat-card-change small text-muted" id="gscRepBestImpressionsName"></div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--info"><i class="bi bi-bullseye"></i></div>
                <div class="stat-card-value" id="gscRepBestCtr">-</div>
                <div class="stat-card-label">Najlepszy CTR</div>
                <div class="stat-card-change small text-muted" id="gscRepBestCtrName"></div>
            </div>
        </div>
        <div class="col">
            <div class="stat-card">
                <div class="stat-card-icon stat-card-icon--secondary"><i class="bi bi-bar-chart"></i></div>
                <div class="stat-card-value" id="gscRepTotalClicks">-</div>
                <div class="stat-card-label">Suma kliknięć</div>
                <div class="stat-card-change" id="gscRepTotalClicksChange"></div>
            </div>
        </div>
    </div>

    <!-- Report table -->
    <div class="content-card" id="gscReportTableCard" style="display:none">
        <div class="content-card-header">
            <i class="bi bi-graph-up"></i> Raport GSC — wszystkie strony
        </div>
        <div class="content-card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0" id="gscReportTable">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th class="sortable th-fixed" data-sort="name" onclick="sortGscReport('name')">Strona <i class="bi bi-chevron-expand small"></i></th>
                            <th class="sortable th-fixed text-end" data-sort="clicks" onclick="sortGscReport('clicks')">Kliknięcia <i class="bi bi-chevron-expand small"></i></th>
                            <th class="sortable th-fixed text-end" data-sort="impressions" onclick="sortGscReport('impressions')">Wyświetlenia <i class="bi bi-chevron-expand small"></i></th>
                            <th class="sortable th-fixed text-end" data-sort="ctr" onclick="sortGscReport('ctr')">CTR <i class="bi bi-chevron-expand small"></i></th>
                            <th class="sortable th-fixed text-end" data-sort="position" onclick="sortGscReport('position')">Śr. pozycja <i class="bi bi-chevron-expand small"></i></th>
                            <th class="sortable th-fixed text-end" data-sort="clicks_change" onclick="sortGscReport('clicks_change')">Klik. zmiana <i class="bi bi-chevron-expand small"></i></th>
                            <th class="sortable th-fixed text-end" data-sort="impressions_change" onclick="sortGscReport('impressions_change')">Wyśw. zmiana <i class="bi bi-chevron-expand small"></i></th>
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
    <div id="gscReportInitial" class="alert alert-light border mt-0">
        <i class="bi bi-info-circle text-primary"></i> Kliknij <strong>Generuj raport</strong> aby pobrać dane z Google Search Console.
    </div>

    <div id="gscReportNoData" class="alert alert-info mt-3" style="display:none">
        <i class="bi bi-info-circle"></i> Brak danych GSC. Upewnij się, że GSC jest połączone w <a href="index.php?page=settings">ustawieniach</a>.
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
