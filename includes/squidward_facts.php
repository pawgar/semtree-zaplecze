<?php
/**
 * Ciekawostki o Skalmarze — używane przez fun-fact bar (JS) oraz webhook Telegrama.
 * @return array<string>
 */
function getSquidwardFacts(): array {
    return [
        'Skalmar Obłynos jest ośmiornicą, nie kalmarem — mimo że jego angielskie imię zawiera słowo "squid" (kalmar). Potwierdzono to oficjalnie w odcinku "Feral Friends".',
        'Stephen Hillenburg celowo narysował Skalmara z 6 mackami zamiast 8, bo "tak było prościej do animacji." Pełne 8 macek pokazano tylko w dwóch odcinkach w historii serialu.',
        'Twórcy zrezygnowali z żartów o wyrzucaniu atramentu przez Skalmara, bo wizualnie "zawsze wyglądało to, jakby robił w spodnie."',
        'Charakterystyczny dźwięk kroków Skalmara (imitujący przyssawki) jest tworzony przez pocieranie termoforów — gumowych butelek na gorącą wodę.',
        'Kolor skóry Skalmara zmieniał się w trakcie serialu według systemu kolorów PMS: od 332 w pilocie do 335 od połowy sezonu 2.',
        'Rower poziomy, którym Skalmar jeździ w kilku odcinkach, to ukłon w stronę jego aktora głosowego Rodgera Bumpassa, który sam taki posiada.',
        'Pierwszym wyborem do głosu Skalmara był Mr. Lawrence — który ostatecznie dostał rolę Planktona.',
        'Głos Skalmara porównywano do stylu komika Jacka Benny\'ego — sam Bumpass odrzucił to porównanie jednym zdaniem: "Jack Benny, nie."',
        'Rodger Bumpass wpadał w taki szał podczas nagrywania Skalmara, że Tom Kenny (głos SpongeBoba) martwił się, że dostanie zatorowości.',
        'Skalmar pierwotnie miał grać na oboju, nie na klarnecie — zmieniono to później w procesie produkcji.',
        'Skalmar regularnie łamie czwartą ścianę, odwołując się do "11 minut" — standardowej długości jednego odcinka SpongeBoba.',
        'Kiedy Skalmar się śmieje, jego nos napompowuje się i opada — we wcześniejszych odcinkach towarzyszył temu specjalny efekt dźwiękowy, z którego później zrezygnowano.',
        'Skalmar jest uczulony na aż cztery rzeczy: śluz ślimaka, glonojagody, orzechy i zwierzęta domowe.',
        'Google wykupiło domenę "squidward.com" — do dziś przekierowuje ona na google.com.',
        'Tony Stark w filmie "Avengers: Infinity War" obraża Ebony Mawa, nazywając go "Squidwardem."',
        'Skalmar urodził się 9 października — w Dzień Leifa Eriksona, święto entuzjastycznie obchodzone przez SpongeBoba w odcinku "Bubble Buddy."',
        'Pełne imię Skalmara to Squidward Quincy Tentacles, a w polskiej wersji — Skalmar J.Q. Obłynos.',
        'W 2000 roku powstała seria krótkometrażówek "Astrology with Squidward", w której Skalmar wcielał się w jasnowidza opowiadającego o znakach zodiaku.',
        'Skalmar pojawił się w promówce Blue\'s Clues z 2002 roku, twierdząc że byłby lepszym gospodarzem programu niż Joe.',
        'Znak zodiaku Skalmara to Waga (Libra).',
        'Skalmar ma drugą największą liczbę wystąpień w serialu — więcej niż Patryk, o około 40 odcinków.',
        'Skalmar kiedyś miał bujne, długie blond włosy — stracił je po odejściu Jima z Krusty Krab, co ujawniono w odcinku "The Original Fry Cook."',
        'Ulubione jedzenie Skalmara to lody — ujawniono to w odcinku "The Fish Bowl."',
        'Skalmar lubi muzykę conga — ujawniono to w odcinku "Jolly Lodgers."',
        'Skalmar Obłynos ma problem z obgryzaniem paznokci — ujawniono to w odcinku "SpongeBob\'s Bad Habit."',
        'Skalmar jest klaustrofobiczny i boi się wysokości — choć ta druga fobia jest niespójna, bo w "No Hat for Pat" stoi na trampolinie bez strachu.',
        'Postać przypominająca Skalmara pojawia się jako cameo w odcinku 15 japońskiego anime "Tengen Toppa Gurren Lagann."',
        'W październiku 2007 Nickelodeon przebrał Skalmara za Upiora z Opery w przerwach reklamowych.',
        'Skalmar ma gwiazdę na Hollywoodzkiej Alei Sław — w fikcyjnym świecie filmu "Chip \'n Dale: Rescue Rangers."',
        'Dom Skalmara (posąg moai z Wyspy Wielkanocnej) sam z siebie pochylił się, żeby spojrzeć na tajemniczą skrzynkę w odcinku "The Secret Box" — pierwszy przypadek samodzielnego ruchu domu.',
        'Skalmar jest jedną z najtrudniejszych postaci do rysowania — jego nos "dzieli wszystko na pół", co utrudnia oddanie emocji.',
        'W polskim dubbingu głosu Skalmarowi użycza Zbigniew Suszyński. Wcześniej w wersji lektorskiej nazywał się "Squidward Macka."',
        'Na musicalu broadwayowskim Gavin Lee grał Skalmara z 4 sztucznymi nogami i stepował na nich ponad 7 minut — dodatkowe nogi ważyły 11 kg.',
        'Gavin Lee zdobył nagrodę Drama Desk Award za rolę Skalmara i był nominowany do Tony Award.',
        'Magazyny, które Skalmar czyta w pracy, zmieniają się w zależności od odcinka — ich tytuły to m.in. "Dance!", "Art", "Squid Ink" i "House Fancy."',
        'Skalmar w odcinku "Reef Blower" ma kolor fioletowy gdy SpongeBob wysysa wodę z oceanu — niespójność nigdy niewyjaśniona przez twórców.',
        'Skalmar jako dziecko grał na kazoo tak źle, że wszyscy uczniowie uciekli ze szkoły — sam myślał, że gra doskonale.',
        'Skalmar potrafi karate — mało znany fakt ujawniony na polskiej wiki.',
        'Pharrell Williams wyznał, że Skalmar to jego ulubiona postać i "gdyby był człowiekiem, spędzałby z nim czas."',
        'Scenarzysta Casey Alexander powiedział, że Skalmar jest "najbardziej ludzką postacią" w serialu i tą, z którą utożsamia się najbardziej.',
    ];
}

/**
 * Losuje jedną ciekawostkę.
 */
function randomSquidwardFact(): string {
    $facts = getSquidwardFacts();
    return $facts[array_rand($facts)];
}
