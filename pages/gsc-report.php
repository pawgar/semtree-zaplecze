<?php
require_once __DIR__ . '/../includes/header.php';
?>

<div id="gscReportContainer">
    <!-- Controls -->
    <div class="d-flex gap-2 align-items-center mb-3 flex-wrap">
        <select class="form-select form-select-sm" id="gscReportRange" onchange="loadGscReport()" style="width:160px">
            <option value="7d">Ostatnie 7 dni</option>
            <option value="28d" selected>Ostatnie 28 dni</option>
            <option value="3m">Ostatnie 3 miesiące</option>
            <option value="6m">Ostatnie 6 miesięcy</option>
            <option value="12m">Ostatnie 12 miesięcy</option>
        </select>
        <button class="btn btn-outline-success btn-sm" onclick="refreshGscReport()">
            <i class="bi bi-arrow-clockwise"></i> Odśwież dane
        </button>
        <span class="text-muted small" id="gscReportDateInfo"></span>
    </div>

    <!-- Report table -->
    <div class="content-card">
        <div class="content-card-header">
            <i class="bi bi-graph-up"></i> Raport GSC — wszystkie strony
        </div>
        <div class="content-card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0" id="gscReportTable">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Strona</th>
                            <th class="text-end">Kliknięcia</th>
                            <th class="text-end">Wyświetlenia</th>
                            <th class="text-end">CTR</th>
                            <th class="text-end">Śr. pozycja</th>
                            <th class="text-end">Klik. zmiana</th>
                            <th class="text-end">Wyśw. zmiana</th>
                            <th>Trend kliknięć (28d)</th>
                        </tr>
                    </thead>
                    <tbody id="gscReportBody">
                        <tr><td colspan="9" class="text-center text-muted">Ładowanie danych GSC...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="gscReportNoData" class="alert alert-info mt-3" style="display:none">
        <i class="bi bi-info-circle"></i> Brak danych GSC. Upewnij się, że GSC jest połączone w <a href="index.php?page=settings">ustawieniach</a>.
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
