<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php if (isLoggedIn()): ?>
<div class="app-layout">
    <!-- ═══ SIDEBAR ═══ -->
    <aside class="sidebar" id="appSidebar">
        <div class="sidebar-header">
            <a href="index.php" class="sidebar-brand">
                <img src="https://semtree.pl/wp-content/uploads/2023/06/logo.svg" alt="Semtree" height="26">
                <span class="sidebar-brand-text">Zaplecze</span>
            </a>
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" title="Zwin/rozwin menu">
                <i class="bi bi-list"></i>
            </button>
        </div>

        <nav class="sidebar-nav">
            <div class="sidebar-section">
                <span class="sidebar-section-label">Menu</span>
                <a class="sidebar-link <?= ($page ?? '') === '' ? 'active' : '' ?>" href="index.php">
                    <i class="bi bi-grid"></i><span>Dashboard</span>
                </a>
                <a class="sidebar-link <?= ($page ?? '') === 'order' ? 'active' : '' ?>" href="index.php?page=order">
                    <i class="bi bi-magic"></i><span>Zamow i opublikuj</span>
                </a>
                <a class="sidebar-link <?= ($page ?? '') === 'publish' ? 'active' : '' ?>" href="index.php?page=publish">
                    <i class="bi bi-pencil-square"></i><span>Publikuj artykuly</span>
                </a>
                <a class="sidebar-link <?= ($page ?? '') === 'import' ? 'active' : '' ?>" href="index.php?page=import">
                    <i class="bi bi-cloud-upload"></i><span>Import masowy</span>
                </a>
                <a class="sidebar-link <?= ($page ?? '') === 'links' ? 'active' : '' ?>" href="index.php?page=links">
                    <i class="bi bi-link-45deg"></i><span>Linki</span>
                </a>
            </div>

            <div class="sidebar-section">
                <span class="sidebar-section-label">System</span>
                <?php if (isAdmin()): ?>
                <a class="sidebar-link <?= ($page ?? '') === 'users' ? 'active' : '' ?>" href="index.php?page=users">
                    <i class="bi bi-people"></i><span>Uzytkownicy</span>
                </a>
                <?php endif; ?>
                <a class="sidebar-link <?= ($page ?? '') === 'settings' ? 'active' : '' ?>" href="index.php?page=settings">
                    <i class="bi bi-gear"></i><span>Ustawienia</span>
                </a>
                <a class="sidebar-link <?= ($page ?? '') === 'profile' ? 'active' : '' ?>" href="index.php?page=profile">
                    <i class="bi bi-person-circle"></i><span><?= htmlspecialchars($_SESSION['username']) ?></span>
                    <span class="badge bg-<?= isAdmin() ? 'danger' : 'secondary' ?> ms-auto sidebar-badge"><?= $_SESSION['role'] ?></span>
                </a>
            </div>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-status" id="claudeStatusIndicator" title="Sprawdzanie statusu API...">
                <span class="status-led status-led-unknown" id="claudeStatusLed"></span>
                <span class="sidebar-status-text" id="claudeStatusLabel">Claude API</span>
            </div>
            <a href="#" class="sidebar-version" data-bs-toggle="modal" data-bs-target="#changelogModal" title="Changelog i roadmapa">v2.5</a>
            <a class="sidebar-link sidebar-logout" href="index.php?page=logout">
                <i class="bi bi-box-arrow-right"></i><span>Wyloguj</span>
            </a>
        </div>
    </aside>

    <!-- ═══ MAIN CONTENT ═══ -->
    <main class="main-content" id="mainContent">
        <!-- Top bar -->
        <div class="topbar">
            <button class="sidebar-toggle-mobile" id="sidebarToggleMobile" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
            </button>
            <div class="topbar-breadcrumb">
                <?php
                $pageTitle = match($page ?? '') {
                    'order' => '<i class="bi bi-magic"></i> Zamow i opublikuj',
                    'publish' => '<i class="bi bi-pencil-square"></i> Publikuj artykuly',
                    'import' => '<i class="bi bi-cloud-upload"></i> Import masowy',
                    'links' => '<i class="bi bi-link-45deg"></i> Linki',
                    'users' => '<i class="bi bi-people"></i> Uzytkownicy',
                    'settings' => '<i class="bi bi-gear"></i> Ustawienia',
                    'profile' => '<i class="bi bi-person-circle"></i> Profil',
                    default => '<i class="bi bi-grid"></i> Dashboard',
                };
                echo $pageTitle;
                ?>
            </div>
        </div>

        <!-- Fun Fact Bar -->
        <div id="funFactBar" class="fun-fact-bar" onclick="nextFunFact()" title="Kliknij po nastepna ciekawostke">
            <div class="fun-fact-content">
                <span class="fun-fact-icon">💡</span>
                <span class="fun-fact-label">Czy wiesz, ze...</span>
                <span id="funFactText" class="fun-fact-text"></span>
            </div>
            <button class="fun-fact-close" onclick="event.stopPropagation(); document.getElementById('funFactBar').style.display='none'" title="Zamknij">&times;</button>
        </div>
        <script>
        const squidwardFacts = [
            "Skalmar Oblynos jest osmiornica, nie kalmarem — mimo ze jego angielskie imie zawiera slowo \"squid\" (kalmar). Potwierdzono to oficjalnie w odcinku \"Feral Friends\".",
            "Stephen Hillenburg celowo narysowal Skalmara z 6 mackami zamiast 8, bo \"tak bylo prosciej do animacji.\" Pelne 8 macek pokazano tylko w dwoch odcinkach w historii serialu.",
            "Tworcy zrezygnowali z zartow o wyrzucaniu atramentu przez Skalmara, bo wizualnie \"zawsze wygladalo to, jakby robil w spodnie.\"",
            "Charakterystyczny dzwiek krokow Skalmara (imitujacy przyssawki) jest tworzony przez pocieranie termoforow — gumowych butelek na goraca wode.",
            "Kolor skory Skalmara zmienial sie w trakcie serialu wedlug systemu kolorow PMS: od 332 w pilocie do 335 od polowy sezonu 2.",
            "Rower poziomy, ktorym Skalmar jezdzi w kilku odcinkach, to uklon w strone jego aktora glosowego Rodgera Bumpassa, ktory sam taki posiada.",
            "Pierwszym wyborem do glosu Skalmara byl Mr. Lawrence — ktory ostatecznie dostal role Planktona.",
            "Glos Skalmara porownywano do stylu komika Jacka Benny'ego — sam Bumpass odrzucil to porownanie jednym zdaniem: \"Jack Benny, nie.\"",
            "Rodger Bumpass wpadal w taki szal podczas nagrywania Skalmara, ze Tom Kenny (glos SpongeBoba) martwil sie, ze dostanie zatorowosci.",
            "Skalmar pierwotnie mial grac na oboju, nie na klarnecie — zmieniono to pozniej w procesie produkcji.",
            "Skalmar regularnie lamie czwarta sciane, odwolujac sie do \"11 minut\" — standardowej dlugosci jednego odcinka SpongeBoba.",
            "Kiedy Skalmar sie smieje, jego nos napompowuje sie i opada — we wczesniejszych odcinkach towarzyszyl temu specjalny efekt dzwiekowy, z ktorego pozniej zrezygnowano.",
            "Skalmar jest uczulony na az cztery rzeczy: sluz slimaka, glonojagody, orzechy i zwierzeta domowe.",
            "Google wykupilo domene \"squidward.com\" — do dzis przekierowuje ona na google.com.",
            "Tony Stark w filmie \"Avengers: Infinity War\" obraza Ebony Mawa, nazywajac go \"Squidwardem.\"",
            "Skalmar urodzil sie 9 pazdziernika — w Dzien Leifa Eriksona, swieto entuzjastycznie obchodzone przez SpongeBoba w odcinku \"Bubble Buddy.\"",
            "Pelne imie Skalmara to Squidward Quincy Tentacles, a w polskiej wersji — Skalmar J.Q. Oblynos.",
            "W 2000 roku powstala seria krotkometrazowek \"Astrology with Squidward\", w ktorej Skalmar wciela sie w jasnowidza opowiadajacego o znakach zodiaku.",
            "Skalmar pojawil sie w promowce Blue's Clues z 2002 roku, twierdzac ze bylby lepszym gospodarzem programu niz Joe.",
            "Znak zodiaku Skalmara to Waga (Libra).",
            "Skalmar ma druga najwieksza liczbe wystapien w serialu — wiecej niz Patryk, o okolo 40 odcinkow.",
            "Skalmar kiedys mial bujne, dlugie blond wlosy — stracil je po odejsciu Jima z Krusty Krab, co ujawniono w odcinku \"The Original Fry Cook.\"",
            "Ulubione jedzenie Skalmara to lody — ujawniono to w odcinku \"The Fish Bowl.\"",
            "Skalmar lubi muzyke conga — ujawniono to w odcinku \"Jolly Lodgers.\"",
            "Skalmar Oblynos ma problem z obgryzaniem paznokci — ujawniono to w odcinku \"SpongeBob's Bad Habit.\"",
            "Skalmar jest klaustrofobiczny i boi sie wysokosci — choc ta druga fobia jest niespojna, bo w \"No Hat for Pat\" stoi na trampolinie bez strachu.",
            "Postac przypominajaca Skalmara pojawia sie jako cameo w odcinku 15 japonskiego anime \"Tengen Toppa Gurren Lagann.\"",
            "W pazdzierniku 2007 Nickelodeon przebral Skalmara za Upiora z Opery w przerwach reklamowych.",
            "Skalmar ma gwiazde na Hollywoodzkiej Alei Slaw — w fikcyjnym swiecie filmu \"Chip 'n Dale: Rescue Rangers.\"",
            "Dom Skalmara (posag moai z Wyspy Wielkanocnej) sam z siebie pochylil sie, zeby spojrzec na tajemnicza skrzynke w odcinku \"The Secret Box\" — pierwszy przypadek samodzielnego ruchu domu.",
            "Skalmar jest jedna z najtrudniejszych postaci do rysowania — jego nos \"dzieli wszystko na pol\", co utrudnia oddanie emocji.",
            "W polskim dubbingu glosu Skalmarowi uzywa Zbigniew Suszynski. Wczesniej w wersji lektorskiej nazywal sie \"Squidward Macka.\"",
            "Na musicalu broadwayowskim Gavin Lee gral Skalmara z 4 sztucznymi nogami i stepowal na nich ponad 7 minut — dodatkowe nogi wazyly 11 kg.",
            "Gavin Lee zdobyl nagrode Drama Desk Award za role Skalmara i byl nominowany do Tony Award.",
            "Magazyny, ktore Skalmar czyta w pracy, zmieniaja sie w zaleznosci od odcinka — ich tytuly to m.in. \"Dance!\", \"Art\", \"Squid Ink\" i \"House Fancy.\"",
            "Skalmar w odcinku \"Reef Blower\" ma kolor fioletowy gdy SpongeBob wysysa wode z oceanu — niespojnosc nigdy niewyjastniona przez tworcow.",
            "Skalmar jako dziecko gral na kazoo tak zle, ze wszyscy uczniowie uciekli ze szkoly — sam myslal, ze gra doskonale.",
            "Skalmar potrafi karate — malo znany fakt ujawniony na polskiej wiki.",
            "Pharrell Williams wyznal, ze Skalmar to jego ulubiona postac i \"gdyby byl czlowiekiem, spedzalby z nim czas.\"",
            "Scenarzysta Casey Alexander powiedzial, ze Skalmar jest \"najbardziej ludzka postacia\" w serialu i ta, z ktora utozsamia sie najbardziej."
        ];
        function nextFunFact() {
            const el = document.getElementById('funFactText');
            if (!el) return;
            el.style.opacity = 0;
            setTimeout(() => {
                el.textContent = squidwardFacts[Math.floor(Math.random() * squidwardFacts.length)];
                el.style.opacity = 1;
            }, 300);
        }
        document.addEventListener('DOMContentLoaded', () => {
            const el = document.getElementById('funFactText');
            if (el) el.textContent = squidwardFacts[Math.floor(Math.random() * squidwardFacts.length)];
        });
        </script>

        <!-- Page content container -->
        <div class="content-container">
<?php endif; ?>

<?php if (!isLoggedIn()) return; ?>

<!-- Changelog Modal -->
<div class="modal fade" id="changelogModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-journal-text"></i> Semtree Zaplecze — Changelog & Roadmapa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#changelogTab">Changelog</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#roadmapTab">Roadmapa</a></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="changelogTab">

                        <h6 class="text-primary">v2.5 <small class="text-muted">— kwiecien 2026</small></h6>
                        <ul class="small">
                            <li>Nowy layout: sidebar nawigacyjny zamiast gornej belki</li>
                            <li>Strona Ustawienia — centralne zarzadzanie kluczami API i konfiguracjami</li>
                            <li>Toast notifications zamiast alert()</li>
                            <li>Karty z cieniami i zaokragleniami, spojne przyciski</li>
                            <li>Loading states i poprawiona responsywnosc</li>
                        </ul>

                        <h6 class="text-primary">v2.4 <small class="text-muted">— kwiecien 2026</small></h6>
                        <ul class="small">
                            <li>Dashboard: karty podsumowania, sortowanie kolumn, podswietlanie bledow</li>
                            <li>Dashboard: statusy zapisywane w bazie, diody LED zamiast tekstu</li>
                            <li>Dashboard: ukrycie kolumn Login/Password, CRON automatyczne odswiezanie</li>
                            <li>Dioda statusu Claude API w nawigacji (live z status.claude.com)</li>
                            <li>Wyszukiwarka klientow w zakladce Linki</li>
                            <li>Filtrowanie dat w historii publikacji</li>
                            <li>Korekta ortograficzna (Sonnet) jako osobny krok po generowaniu</li>
                        </ul>

                        <h6 class="text-primary">v2.3 <small class="text-muted">— kwiecien 2026</small></h6>
                        <ul class="small">
                            <li>Edytor WYSIWYG z toolbarem (linki, formatowanie, widok HTML)</li>
                            <li>Zamowienie masowe: checkboxy, losowe daty, edytowalne kategorie, status publikacji</li>
                            <li>Obsluga 35 jezykow europejskich w generowaniu artykulow</li>
                            <li>Zakazane wzorce AI per jezyk (PL, EN, DE, ES, NL, SV, CS, FR, IT, PT)</li>
                        </ul>

                        <h6 class="text-primary">v2.2 <small class="text-muted">— marzec/kwiecien 2026</small></h6>
                        <ul class="small">
                            <li>Zakladka "Zamow i opublikuj" — generowanie AI + grafiki Gemini + publikacja</li>
                            <li>Zamowienie masowe CSV z automatycznym mapowaniem kategorii</li>
                            <li>Integracja Speed-Links.net (indeksacja VIP)</li>
                            <li>Zakladka "Usun linki" — usuwanie linkow klienta z wpisow WP</li>
                        </ul>

                        <h6 class="text-primary">v2.1 <small class="text-muted">— marzec 2026</small></h6>
                        <ul class="small">
                            <li>Profile uzytkownikow ze statystykami publikacji</li>
                            <li>Zarzadzanie haslami i rolami (admin/worker)</li>
                            <li>Przeszukiwalne dropdowny (filtrowanie list)</li>
                            <li>Klikalna liczba linkow na dashboardzie</li>
                        </ul>

                        <h6 class="text-primary">v2.0 <small class="text-muted">— marzec 2026</small></h6>
                        <ul class="small">
                            <li>System sledzenia linkow (klienci, linki, skanowanie, raporty)</li>
                            <li>Import/eksport CSV klientow</li>
                            <li>Fuzzy matching tytulow DOCX/XLSX (Jaccard + subset)</li>
                            <li>Parser DOCX z zachowaniem hyperlinkow</li>
                        </ul>

                        <h6 class="text-primary">v1.0 <small class="text-muted">— luty/marzec 2026</small></h6>
                        <ul class="small">
                            <li>Dashboard stron zapleczowych z filtrami kategorii</li>
                            <li>Publikacja artykulow przez WP REST API</li>
                            <li>Import masowy z XLSX + DOCX</li>
                            <li>Generowanie grafik AI (Gemini)</li>
                            <li>Zarzadzanie haslami WP Application Passwords</li>
                        </ul>

                    </div>
                    <div class="tab-pane fade" id="roadmapTab">

                        <h6><span class="badge bg-danger">v2.6</span> — w przygotowaniu</h6>
                        <ul class="small">
                            <li><strong>Integracja Google Search Console</strong> — klikniecia i wyswietlenia per domena na dashboardzie (ostatnie 30 dni)</li>
                            <li>Metryki jakosciowe stron zapleczowych z GSC</li>
                            <li>Automatyczne odswiezanie danych GSC (CRON)</li>
                        </ul>

                        <h6><span class="badge bg-warning text-dark">v2.7</span> — planowane</h6>
                        <ul class="small">
                            <li>Panel statystyk admina (blog > linkowana domena > artykul > data > worker)</li>
                            <li>Rozszerzone statystyki profilu (miesieczna historia, wykresy)</li>
                        </ul>

                        <h6><span class="badge bg-secondary">Backlog</span></h6>
                        <ul class="small">
                            <li>Grupowanie stron po kategoriach na dashboardzie</li>
                            <li>Mini-wykresy trendow publikacji per domena</li>
                            <li>Powiadomienia o awariach stron (email/Slack)</li>
                            <li>API do integracji z zewnetrznymi narzedziami</li>
                        </ul>

                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toastContainer" style="z-index:9999"></div>
