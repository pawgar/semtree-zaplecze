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
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center" href="index.php">
            <img src="https://semtree.pl/wp-content/uploads/2023/06/logo.svg" alt="Semtree" height="28" class="me-2">
            Zaplecze
        </a>
        <a href="#" class="text-light text-decoration-none small opacity-75 ms-1" data-bs-toggle="modal" data-bs-target="#changelogModal" title="Changelog i roadmapa">v2.4</a>
        <div class="navbar-nav ms-auto d-flex flex-row align-items-center gap-3">
            <a class="nav-link <?= ($page ?? '') === '' ? 'active' : '' ?>" href="index.php">
                <i class="bi bi-grid"></i> Dashboard
            </a>
            <a class="nav-link <?= ($page ?? '') === 'order' ? 'active' : '' ?>" href="index.php?page=order">
                <i class="bi bi-magic"></i> Zamow i opublikuj
            </a>
            <a class="nav-link <?= ($page ?? '') === 'publish' ? 'active' : '' ?>" href="index.php?page=publish">
                <i class="bi bi-pencil-square"></i> Publikuj artykuly
            </a>
            <a class="nav-link <?= ($page ?? '') === 'import' ? 'active' : '' ?>" href="index.php?page=import">
                <i class="bi bi-cloud-upload"></i> Import masowy
            </a>
            <a class="nav-link <?= ($page ?? '') === 'links' ? 'active' : '' ?>" href="index.php?page=links">
                <i class="bi bi-link-45deg"></i> Linki
            </a>
            <?php if (isAdmin()): ?>
            <a class="nav-link <?= ($page ?? '') === 'users' ? 'active' : '' ?>" href="index.php?page=users">
                <i class="bi bi-people"></i> Uzytkownicy
            </a>
            <?php endif; ?>
            <a class="nav-link <?= ($page ?? '') === 'profile' ? 'active' : '' ?>" href="index.php?page=profile">
                <i class="bi bi-person-circle"></i>
                <?= htmlspecialchars($_SESSION['username']) ?>
                <span class="badge bg-<?= isAdmin() ? 'danger' : 'secondary' ?>"><?= $_SESSION['role'] ?></span>
            </a>
            <span class="nav-link d-flex align-items-center gap-1 pe-0" id="claudeStatusIndicator" title="Sprawdzanie statusu API...">
                <span class="status-led status-led-unknown" id="claudeStatusLed"></span>
                <small class="d-none d-xl-inline" id="claudeStatusLabel">API</small>
            </span>
            <a class="nav-link" href="index.php?page=logout">
                <i class="bi bi-box-arrow-right"></i> Wyloguj
            </a>
        </div>
    </div>
</nav>
<?php endif; ?>

<?php if (isLoggedIn()): ?>
<!-- Squidward Fun Facts Bar -->
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
        el.textContent = squidwardFacts[Math.floor(Math.random() * squidwardFacts.length)];
        el.style.opacity = 1;
    }, 300);
}
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('funFactText');
    if (el) el.textContent = squidwardFacts[Math.floor(Math.random() * squidwardFacts.length)];
});
</script>
<?php endif; ?>

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

                        <h6><span class="badge bg-danger">v2.5</span> — w przygotowaniu</h6>
                        <ul class="small">
                            <li><strong>Integracja Google Search Console</strong> — klikniecia i wyswietlenia per domena na dashboardzie (ostatnie 30 dni)</li>
                            <li>Metryki jakosciowe stron zapleczowych z GSC</li>
                            <li>Automatyczne odswiezanie danych GSC (CRON)</li>
                        </ul>

                        <h6><span class="badge bg-warning text-dark">v2.6</span> — planowane</h6>
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

<div class="container-fluid">
