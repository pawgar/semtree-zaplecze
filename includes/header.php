<!DOCTYPE html>
<html lang="pl" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= APP_NAME ?></title>
    <!-- Inter font (used by Tabler; preconnect to speed it up) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Tabler core + icons -->
    <link href="assets/vendor/tabler/css/tabler.min.css" rel="stylesheet">
    <link href="assets/vendor/tabler/css/tabler-vendors.min.css" rel="stylesheet">
    <link href="assets/vendor/tabler-icons/tabler-icons.min.css" rel="stylesheet">
    <!-- Keep Bootstrap Icons during gradual migration (used in pages/ and app.js) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Project custom styles -->
    <link href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>

<?php if (!isLoggedIn()) return; ?>

<div class="page">
    <!-- ═══ TOP NAVBAR (horizontal layout) ═══ -->
    <header class="navbar navbar-expand-md d-print-none">
        <div class="container-xxl">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <!-- Logo (shown on md+) -->
            <h1 class="navbar-brand navbar-brand-autodark d-none-navbar-horizontal pe-0 pe-md-3">
                <a href="index.php" class="text-decoration-none">
                    <span class="text-body fw-bold fs-3">Sem<span class="text-success">tree</span></span>
                    <span class="text-secondary ms-1 small">Zaplecze</span>
                </a>
            </h1>
            <div class="navbar-nav flex-row order-md-last">
                <div class="navbar-nav flex-row order-md-last">
                    <!-- Claude API status indicator -->
                    <div class="nav-item d-none d-md-flex me-3">
                        <div class="d-flex align-items-center" id="claudeStatusIndicator" title="Status Claude API">
                            <span class="status-indicator status-gray" id="claudeStatusLed"></span>
                            <span class="ms-2 text-secondary small" id="claudeStatusLabel">Claude API</span>
                        </div>
                    </div>
                    <!-- Version / changelog -->
                    <div class="nav-item d-none d-md-flex me-3">
                        <a href="#" class="nav-link px-0" data-bs-toggle="modal" data-bs-target="#changelogModal" title="Changelog i roadmapa">
                            <span class="badge bg-blue-lt">v2.6</span>
                        </a>
                    </div>
                    <!-- User dropdown -->
                    <div class="nav-item dropdown">
                        <a href="#" class="nav-link d-flex lh-1 p-0 px-2" data-bs-toggle="dropdown" aria-label="Otwórz menu użytkownika">
                            <span class="avatar avatar-sm bg-<?= isAdmin() ? 'red' : 'secondary' ?>-lt"><?= strtoupper(mb_substr($_SESSION['username'] ?? 'U', 0, 2)) ?></span>
                            <div class="d-none d-xl-block ps-2">
                                <div><?= htmlspecialchars($_SESSION['username']) ?></div>
                                <div class="mt-1 small text-secondary"><?= $_SESSION['role'] ?></div>
                            </div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                            <a href="index.php?page=profile" class="dropdown-item">
                                <i class="ti ti-user-circle me-2"></i> Profil
                            </a>
                            <a href="index.php?page=settings" class="dropdown-item">
                                <i class="ti ti-settings me-2"></i> Ustawienia
                            </a>
                            <div class="dropdown-divider"></div>
                            <a href="index.php?page=logout" class="dropdown-item">
                                <i class="ti ti-logout me-2"></i> Wyloguj
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- ═══ HORIZONTAL NAV MENU ═══ -->
        <header class="navbar-expand-md">
            <div class="collapse navbar-collapse" id="navbar-menu">
                <div class="navbar">
                    <div class="container-xxl">
                        <ul class="navbar-nav">
                            <li class="nav-item <?= ($page ?? '') === '' ? 'active' : '' ?>">
                                <a class="nav-link" href="index.php"><span class="nav-link-icon"><i class="ti ti-layout-dashboard"></i></span><span class="nav-link-title">Dashboard</span></a>
                            </li>
                            <li class="nav-item <?= ($page ?? '') === 'order' ? 'active' : '' ?>">
                                <a class="nav-link" href="index.php?page=order"><span class="nav-link-icon"><i class="ti ti-wand"></i></span><span class="nav-link-title">Zamów i opublikuj</span></a>
                            </li>
                            <li class="nav-item <?= ($page ?? '') === 'publish' ? 'active' : '' ?>">
                                <a class="nav-link" href="index.php?page=publish"><span class="nav-link-icon"><i class="ti ti-edit"></i></span><span class="nav-link-title">Publikuj</span></a>
                            </li>
                            <li class="nav-item <?= ($page ?? '') === 'import' ? 'active' : '' ?>">
                                <a class="nav-link" href="index.php?page=import"><span class="nav-link-icon"><i class="ti ti-cloud-upload"></i></span><span class="nav-link-title">Import</span></a>
                            </li>
                            <li class="nav-item <?= ($page ?? '') === 'links' ? 'active' : '' ?>">
                                <a class="nav-link" href="index.php?page=links"><span class="nav-link-icon"><i class="ti ti-link"></i></span><span class="nav-link-title">Linki</span></a>
                            </li>
                            <li class="nav-item <?= ($page ?? '') === 'gsc-report' ? 'active' : '' ?>">
                                <a class="nav-link" href="index.php?page=gsc-report"><span class="nav-link-icon"><i class="ti ti-chart-line"></i></span><span class="nav-link-title">Raport GSC</span></a>
                            </li>
                            <li class="nav-item <?= ($page ?? '') === 'auto-publish' ? 'active' : '' ?>">
                                <a class="nav-link" href="index.php?page=auto-publish"><span class="nav-link-icon"><i class="ti ti-robot"></i></span><span class="nav-link-title">Auto publikacje</span></a>
                            </li>
                            <?php if (isAdmin()): ?>
                            <li class="nav-item <?= ($page ?? '') === 'users' ? 'active' : '' ?>">
                                <a class="nav-link" href="index.php?page=users"><span class="nav-link-icon"><i class="ti ti-users"></i></span><span class="nav-link-title">Użytkownicy</span></a>
                            </li>
                            <?php endif; ?>
                            <li class="nav-item <?= ($page ?? '') === 'settings' ? 'active' : '' ?>">
                                <a class="nav-link" href="index.php?page=settings"><span class="nav-link-icon"><i class="ti ti-settings"></i></span><span class="nav-link-title">Ustawienia</span></a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </header>

        <script>
        const squidwardFacts = [
            "Skalmar Obłynos jest ośmiornicą, nie kalmarem — mimo że jego angielskie imię zawiera słowo \"squid\" (kalmar). Potwierdzono to oficjalnie w odcinku \"Feral Friends\".",
            "Stephen Hillenburg celowo narysował Skalmara z 6 mackami zamiast 8, bo \"tak było prościej do animacji.\" Pełne 8 macek pokazano tylko w dwóch odcinkach w historii serialu.",
            "Twórcy zrezygnowali z żartów o wyrzucaniu atramentu przez Skalmara, bo wizualnie \"zawsze wyglądało to, jakby robił w spodnie.\"",
            "Charakterystyczny dźwięk kroków Skalmara (imitujący przyssawki) jest tworzony przez pocieranie termoforów — gumowych butelek na gorącą wodę.",
            "Kolor skóry Skalmara zmieniał się w trakcie serialu według systemu kolorów PMS: od 332 w pilocie do 335 od połowy sezonu 2.",
            "Rower poziomy, którym Skalmar jeździ w kilku odcinkach, to ukłon w stronę jego aktora głosowego Rodgera Bumpassa, który sam taki posiada.",
            "Pierwszym wyborem do głosu Skalmara był Mr. Lawrence — który ostatecznie dostał rolę Planktona.",
            "Głos Skalmara porównywano do stylu komika Jacka Benny'ego — sam Bumpass odrzucił to porównanie jednym zdaniem: \"Jack Benny, nie.\"",
            "Rodger Bumpass wpadał w taki szał podczas nagrywania Skalmara, że Tom Kenny (głos SpongeBoba) martwił się, że dostanie zatorowości.",
            "Skalmar pierwotnie miał grać na oboju, nie na klarnecie — zmieniono to później w procesie produkcji.",
            "Skalmar regularnie łamie czwartą ścianę, odwołując się do \"11 minut\" — standardowej długości jednego odcinka SpongeBoba.",
            "Kiedy Skalmar się śmieje, jego nos napompowuje się i opada — we wcześniejszych odcinkach towarzyszył temu specjalny efekt dźwiękowy, z którego później zrezygnowano.",
            "Skalmar jest uczulony na aż cztery rzeczy: śluz ślimaka, glonojagody, orzechy i zwierzęta domowe.",
            "Google wykupiło domenę \"squidward.com\" — do dziś przekierowuje ona na google.com.",
            "Tony Stark w filmie \"Avengers: Infinity War\" obraża Ebony Mawa, nazywając go \"Squidwardem.\"",
            "Skalmar urodził się 9 października — w Dzień Leifa Eriksona, święto entuzjastycznie obchodzone przez SpongeBoba w odcinku \"Bubble Buddy.\"",
            "Pełne imię Skalmara to Squidward Quincy Tentacles, a w polskiej wersji — Skalmar J.Q. Obłynos.",
            "W 2000 roku powstała seria krótkometrażówek \"Astrology with Squidward\", w której Skalmar wcielał się w jasnowidza opowiadającego o znakach zodiaku.",
            "Skalmar pojawił się w promówce Blue's Clues z 2002 roku, twierdząc że byłby lepszym gospodarzem programu niż Joe.",
            "Znak zodiaku Skalmara to Waga (Libra).",
            "Skalmar ma drugą największą liczbę wystąpień w serialu — więcej niż Patryk, o około 40 odcinków.",
            "Skalmar kiedyś miał bujne, długie blond włosy — stracił je po odejściu Jima z Krusty Krab, co ujawniono w odcinku \"The Original Fry Cook.\"",
            "Ulubione jedzenie Skalmara to lody — ujawniono to w odcinku \"The Fish Bowl.\"",
            "Skalmar lubi muzykę conga — ujawniono to w odcinku \"Jolly Lodgers.\"",
            "Skalmar Obłynos ma problem z obgryzaniem paznokci — ujawniono to w odcinku \"SpongeBob's Bad Habit.\"",
            "Skalmar jest klaustrofobiczny i boi się wysokości — choć ta druga fobia jest niespójna, bo w \"No Hat for Pat\" stoi na trampolinie bez strachu.",
            "Postać przypominająca Skalmara pojawia się jako cameo w odcinku 15 japońskiego anime \"Tengen Toppa Gurren Lagann.\"",
            "W październiku 2007 Nickelodeon przebrał Skalmara za Upiora z Opery w przerwach reklamowych.",
            "Skalmar ma gwiazdę na Hollywoodzkiej Alei Sław — w fikcyjnym świecie filmu \"Chip 'n Dale: Rescue Rangers.\"",
            "Dom Skalmara (posąg moai z Wyspy Wielkanocnej) sam z siebie pochylił się, żeby spojrzeć na tajemniczą skrzynkę w odcinku \"The Secret Box\" — pierwszy przypadek samodzielnego ruchu domu.",
            "Skalmar jest jedną z najtrudniejszych postaci do rysowania — jego nos \"dzieli wszystko na pół\", co utrudnia oddanie emocji.",
            "W polskim dubbingu głosu Skalmarowi użycza Zbigniew Suszyński. Wcześniej w wersji lektorskiej nazywał się \"Squidward Macka.\"",
            "Na musicalu broadwayowskim Gavin Lee grał Skalmara z 4 sztucznymi nogami i stepował na nich ponad 7 minut — dodatkowe nogi ważyły 11 kg.",
            "Gavin Lee zdobył nagrodę Drama Desk Award za rolę Skalmara i był nominowany do Tony Award.",
            "Magazyny, które Skalmar czyta w pracy, zmieniają się w zależności od odcinka — ich tytuły to m.in. \"Dance!\", \"Art\", \"Squid Ink\" i \"House Fancy.\"",
            "Skalmar w odcinku \"Reef Blower\" ma kolor fioletowy gdy SpongeBob wysysa wodę z oceanu — niespójność nigdy niewyjaśniona przez twórców.",
            "Skalmar jako dziecko grał na kazoo tak źle, że wszyscy uczniowie uciekli ze szkoły — sam myślał, że gra doskonale.",
            "Skalmar potrafi karate — mało znany fakt ujawniony na polskiej wiki.",
            "Pharrell Williams wyznał, że Skalmar to jego ulubiona postać i \"gdyby był człowiekiem, spędzałby z nim czas.\"",
            "Scenarzysta Casey Alexander powiedział, że Skalmar jest \"najbardziej ludzką postacią\" w serialu i tą, z którą utożsamia się najbardziej."
        ];
        function nextFunFact() {
            const el = document.getElementById('funFactText');
            if (!el) return;
            el.style.opacity = 0;
            setTimeout(() => {
                el.textContent = ' ' + squidwardFacts[Math.floor(Math.random() * squidwardFacts.length)];
                el.style.opacity = 1;
            }, 300);
        }
        document.addEventListener('DOMContentLoaded', () => {
            const el = document.getElementById('funFactText');
            if (el) el.textContent = ' ' + squidwardFacts[Math.floor(Math.random() * squidwardFacts.length)];
        });
        </script>

        <!-- Page content starts here -->
        <div class="page-body">
            <div class="container-xxl">

                <!-- Fun fact banner (dismissable) -->
                <div id="funFactBar" class="alert alert-info alert-dismissible d-flex align-items-center mb-3" role="alert" style="cursor:pointer" onclick="nextFunFact()" title="Kliknij po następną ciekawostkę">
                    <i class="ti ti-bulb me-2 fs-3"></i>
                    <div class="flex-fill">
                        <strong>Czy wiesz, że...</strong>
                        <span id="funFactText"></span>
                    </div>
                    <a href="#" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij" onclick="event.stopPropagation()"></a>
                </div>

<!-- Changelog Modal -->
<div class="modal fade" id="changelogModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ti ti-notebook me-2"></i>Semtree Zaplecze — Changelog & Roadmapa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#changelogTab">Changelog</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#roadmapTab">Roadmapa</a></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="changelogTab">

                        <h6 class="text-primary">v2.6 <small class="text-muted">— kwiecień 2026</small></h6>
                        <ul class="small">
                            <li>Auto publikacje — automatyczne generowanie i publikacja artykułów z content planu XLSX</li>
                            <li>CRON auto-publish z obsługą wielu stron, losowym autorem, grafikami i Speed Links</li>
                            <li>Mapowanie kategorii z content planu na kategorie WordPress</li>
                            <li>Integracja Telegram Bot — raporty z auto-publikacji</li>
                            <li>Raport GSC — ładowanie na żądanie, sortowanie, eksport XLSX</li>
                            <li>Dashboard GSC — dane z bazy (bez API calls), przycisk odświeżania</li>
                        </ul>

                        <h6 class="text-primary">v2.5 <small class="text-muted">— kwiecień 2026</small></h6>
                        <ul class="small">
                            <li>Nowy layout: sidebar nawigacyjny zamiast górnej belki</li>
                            <li>Strona Ustawienia — centralne zarządzanie kluczami API i konfiguracjami</li>
                            <li>Toast notifications zamiast alert()</li>
                            <li>Karty z cieniami i zaokrągleniami, spójne przyciski</li>
                            <li>Loading states i poprawiona responsywność</li>
                            <li>Rozbudowany panel profilu z wykresami i statystykami</li>
                        </ul>

                        <h6 class="text-primary">v2.4 <small class="text-muted">— kwiecień 2026</small></h6>
                        <ul class="small">
                            <li>Dashboard: karty podsumowania, sortowanie kolumn, podświetlanie błędów</li>
                            <li>Dashboard: statusy zapisywane w bazie, diody LED zamiast tekstu</li>
                            <li>Dashboard: ukrycie kolumn Login/Password, CRON automatyczne odświeżanie</li>
                            <li>Dioda statusu Claude API w nawigacji (live z status.claude.com)</li>
                            <li>Wyszukiwarka klientów w zakładce Linki</li>
                            <li>Filtrowanie dat w historii publikacji</li>
                            <li>Korekta ortograficzna (Sonnet) jako osobny krok po generowaniu</li>
                        </ul>

                        <h6 class="text-primary">v2.3 <small class="text-muted">— kwiecień 2026</small></h6>
                        <ul class="small">
                            <li>Edytor WYSIWYG z toolbarem (linki, formatowanie, widok HTML)</li>
                            <li>Zamówienie masowe: checkboxy, losowe daty, edytowalne kategorie, status publikacji</li>
                            <li>Obsługa 35 języków europejskich w generowaniu artykułów</li>
                            <li>Zakazane wzorce AI per język (PL, EN, DE, ES, NL, SV, CS, FR, IT, PT)</li>
                        </ul>

                        <h6 class="text-primary">v2.2 <small class="text-muted">— marzec/kwiecień 2026</small></h6>
                        <ul class="small">
                            <li>Zakładka "Zamów i opublikuj" — generowanie AI + grafiki Gemini + publikacja</li>
                            <li>Zamówienie masowe CSV z automatycznym mapowaniem kategorii</li>
                            <li>Integracja Speed-Links.net (indeksacja VIP)</li>
                            <li>Zakładka "Usuń linki" — usuwanie linków klienta z wpisów WP</li>
                        </ul>

                        <h6 class="text-primary">v2.1 <small class="text-muted">— marzec 2026</small></h6>
                        <ul class="small">
                            <li>Profile użytkowników ze statystykami publikacji</li>
                            <li>Zarządzanie hasłami i rolami (admin/worker)</li>
                            <li>Przeszukiwalne dropdowny (filtrowanie list)</li>
                            <li>Klikalna liczba linków na dashboardzie</li>
                        </ul>

                        <h6 class="text-primary">v2.0 <small class="text-muted">— marzec 2026</small></h6>
                        <ul class="small">
                            <li>System śledzenia linków (klienci, linki, skanowanie, raporty)</li>
                            <li>Import/eksport CSV klientów</li>
                            <li>Fuzzy matching tytułów DOCX/XLSX (Jaccard + subset)</li>
                            <li>Parser DOCX z zachowaniem hyperlinków</li>
                        </ul>

                        <h6 class="text-primary">v1.0 <small class="text-muted">— luty/marzec 2026</small></h6>
                        <ul class="small">
                            <li>Dashboard stron zapleczowych z filtrami kategorii</li>
                            <li>Publikacja artykułów przez WP REST API</li>
                            <li>Import masowy z XLSX + DOCX</li>
                            <li>Generowanie grafik AI (Gemini)</li>
                            <li>Zarządzanie hasłami WP Application Passwords</li>
                        </ul>

                    </div>
                    <div class="tab-pane fade" id="roadmapTab">

                        <h6><span class="badge bg-danger">v2.7</span> — w przygotowaniu</h6>
                        <ul class="small">
                            <li><strong>Redesign UI</strong> — przejście na framework Tabler (branża admin panel)</li>
                            <li>ApexCharts zamiast Chart.js — lepsze wykresy GSC</li>
                            <li>Dark mode toggle</li>
                        </ul>

                        <h6><span class="badge bg-warning text-dark">v2.8</span> — planowane</h6>
                        <ul class="small">
                            <li>Panel statystyk admina (blog > linkowana domena > artykuł > data > worker)</li>
                            <li>Rozszerzone statystyki profilu (miesięczna historia, wykresy)</li>
                        </ul>

                        <h6><span class="badge bg-secondary">Backlog</span></h6>
                        <ul class="small">
                            <li>Grupowanie stron po kategoriach na dashboardzie</li>
                            <li>Mini-wykresy trendów publikacji per domena</li>
                            <li>Powiadomienia o awariach stron (email/Slack)</li>
                            <li>API do integracji z zewnętrznymi narzędziami</li>
                        </ul>

                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zamknij</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toastContainer" style="z-index:9999"></div>
