<?php
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-link-45deg"></i> Linki</h4>
</div>

<!-- Nav tabs -->
<ul class="nav nav-tabs mb-3" id="linksTabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" id="tab-overview" data-bs-toggle="tab" data-bs-target="#pane-overview" type="button">
            <i class="bi bi-grid-3x3-gap"></i> Przeglad
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" id="tab-clients" data-bs-toggle="tab" data-bs-target="#pane-clients" type="button">
            <i class="bi bi-people"></i> Klienci
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" id="tab-history" data-bs-toggle="tab" data-bs-target="#pane-history" type="button">
            <i class="bi bi-clock-history"></i> Historia
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" id="tab-report" data-bs-toggle="tab" data-bs-target="#pane-report" type="button">
            <i class="bi bi-file-earmark-bar-graph"></i> Raport
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" id="tab-removelinks" data-bs-toggle="tab" data-bs-target="#pane-removelinks" type="button">
            <i class="bi bi-link-45deg text-danger"></i> Usun linki
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- ═══ TAB 1: Overview ═══ -->
    <div class="tab-pane fade show active" id="pane-overview">
        <div class="d-flex gap-2 mb-3">
            <button class="btn btn-outline-primary btn-sm" onclick="scanAllSitesLinks()">
                <i class="bi bi-search"></i> Skanuj wszystkie strony
            </button>
            <span id="scanAllStatus" class="text-muted small align-self-center"></span>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-sm">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Strona</th>
                        <th>Linki</th>
                        <th>Klienci</th>
                        <th>Ostatni link</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody id="linksOverviewBody">
                    <tr><td colspan="6" class="text-center text-muted">Ladowanie...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══ TAB 2: Clients ═══ -->
    <div class="tab-pane fade" id="pane-clients">
        <div class="d-flex gap-2 mb-3">
            <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#clientModal" onclick="resetClientModal()">
                <i class="bi bi-plus-lg"></i> Dodaj klienta
            </button>
            <button class="btn btn-outline-info btn-sm" onclick="document.getElementById('clientsCsvFile').click()">
                <i class="bi bi-upload"></i> Importuj CSV
            </button>
            <input type="file" id="clientsCsvFile" accept=".csv" style="display:none" onchange="importClientsCsv(this)">
            <button class="btn btn-outline-secondary btn-sm" onclick="exportClientsCsv()">
                <i class="bi bi-download"></i> Eksportuj CSV
            </button>
        </div>
        <div class="row">
            <div class="col-md-5">
                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead class="table-dark">
                            <tr>
                                <th>Klient</th>
                                <th>Domena</th>
                                <th>Linki</th>
                                <th>Strony</th>
                                <th>Akcje</th>
                            </tr>
                        </thead>
                        <tbody id="clientsBody">
                            <tr><td colspan="5" class="text-center text-muted">Ladowanie...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-md-7" id="clientLinksPanel" style="display:none">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span id="clientLinksTitle"><i class="bi bi-link-45deg"></i> Linki klienta</span>
                        <button class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('clientLinksPanel').style.display='none'">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height:500px; overflow-y:auto">
                            <table class="table table-sm table-striped mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Strona</th>
                                        <th>Post</th>
                                        <th>Anchor</th>
                                        <th>URL docelowy</th>
                                        <th>Typ</th>
                                        <th>Data</th>
                                    </tr>
                                </thead>
                                <tbody id="clientLinksBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ TAB 3: History ═══ -->
    <div class="tab-pane fade" id="pane-history">
        <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
            <select class="form-select form-select-sm" id="historyClientFilter" style="width:200px" onchange="loadLinksHistory()">
                <option value="">Wszyscy klienci</option>
            </select>
            <select class="form-select form-select-sm" id="historySiteFilter" style="width:200px" onchange="loadLinksHistory()">
                <option value="">Wszystkie strony</option>
            </select>
            <input type="date" class="form-control form-control-sm" id="historyDateFrom" style="width:150px" onchange="loadLinksHistory()">
            <span class="small text-muted">-</span>
            <input type="date" class="form-control form-control-sm" id="historyDateTo" style="width:150px" onchange="loadLinksHistory()">
            <button class="btn btn-outline-secondary btn-sm" onclick="exportLinksCsv()">
                <i class="bi bi-download"></i> CSV
            </button>
            <button class="btn btn-outline-danger btn-sm" onclick="clearAllLinks()">
                <i class="bi bi-trash"></i> Wyczysc wszystko
            </button>
            <span id="historyCount" class="text-muted small"></span>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-sm">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Data</th>
                        <th>Strona</th>
                        <th>Post</th>
                        <th>Klient</th>
                        <th>Anchor</th>
                        <th>URL docelowy</th>
                        <th>Typ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="linksHistoryBody">
                    <tr><td colspan="9" class="text-center text-muted">Ladowanie...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══ TAB 4: Report ═══ -->
    <div class="tab-pane fade" id="pane-report">
        <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
            <select class="form-select form-select-sm" id="reportClientSelect" style="width:200px">
                <option value="">-- wybierz klienta --</option>
            </select>
            <input type="date" class="form-control form-control-sm" id="reportDateFrom" style="width:150px">
            <span class="small text-muted">-</span>
            <input type="date" class="form-control form-control-sm" id="reportDateTo" style="width:150px">
            <button class="btn btn-primary btn-sm" onclick="generateReport()">
                <i class="bi bi-file-earmark-bar-graph"></i> Generuj raport
            </button>
            <button class="btn btn-outline-secondary btn-sm d-none" id="btnCopyReport" onclick="copyReportToClipboard()">
                <i class="bi bi-clipboard"></i> Kopiuj
            </button>
            <button class="btn btn-outline-secondary btn-sm d-none" id="btnExportReportCsv" onclick="exportReportCsv()">
                <i class="bi bi-download"></i> CSV
            </button>
        </div>
        <div id="reportContent"></div>
    </div>

    <!-- ═══ TAB 5: Remove Links ═══ -->
    <div class="tab-pane fade" id="pane-removelinks">
        <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
            <select class="form-select form-select-sm" id="removeLinkClientSelect" style="width:250px" onchange="loadRemoveLinks()">
                <option value="">-- wybierz klienta --</option>
            </select>
            <button class="btn btn-outline-danger btn-sm" id="btnRemoveSelected" onclick="removeSelectedLinks()" disabled>
                <i class="bi bi-trash"></i> Usun zaznaczone linki z wpisow
            </button>
            <span id="removeLinksStatus" class="text-muted small"></span>
        </div>
        <div class="alert alert-info small">
            <i class="bi bi-info-circle"></i>
            Usuwanie linku oznacza: tekst anchora pozostaje we wpisie, ale tag <code>&lt;a&gt;</code> zostaje usuniety.
            Wpis blogowy NIE jest kasowany.
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-sm">
                <thead class="table-dark">
                    <tr>
                        <th><input type="checkbox" id="removeLinksCheckAll" onchange="toggleRemoveCheckAll(this)"></th>
                        <th>Strona</th>
                        <th>Post</th>
                        <th>Anchor</th>
                        <th>URL docelowy</th>
                        <th>Typ</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody id="removeLinksBody">
                    <tr><td colspan="7" class="text-center text-muted">Wybierz klienta</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Client Modal -->
<div class="modal fade" id="clientModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="clientModalTitle">Dodaj klienta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="clientEditId" value="">
                <div class="mb-3">
                    <label class="form-label">Nazwa</label>
                    <input type="text" class="form-control" id="clientName" placeholder="np. PoCash">
                </div>
                <div class="mb-3">
                    <label class="form-label">Domena</label>
                    <input type="text" class="form-control" id="clientDomain" placeholder="np. pocash.pl">
                    <div class="form-text">Bez http:// i www. System automatycznie dopasuje linki do tej domeny.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Kolor</label>
                    <input type="color" class="form-control form-control-color" id="clientColor" value="#6c757d">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" onclick="saveClient()">Zapisz</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
