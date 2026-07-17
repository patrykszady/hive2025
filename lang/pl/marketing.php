<?php

/*
|--------------------------------------------------------------------------
| Public marketing content — Polski (Polish)
|--------------------------------------------------------------------------
|
| Machine-translated from lang/en/marketing.php, same structure/keys/slugs.
| Regenerate with the i18n reassembly script; edit English in
| config/marketing.php + lang/en/marketing.php, not here.
|
*/

return array (
  'nav' => array(
    'contractors' => 'Wykonawcy',
    'homeowners' => 'Właściciele domów',
    'faq' => 'FAQ',
    'sign_in' => 'Zaloguj się',
    'get_started' => 'Rozpocznij',
    'menu' => 'Menu',
    'language' => 'Język',
    'pages' => array(
      'finances' => 'Finanse',
      'estimates' => 'Wyceny i dokumenty',
      'clients' => 'Leady i klienci',
      'vendors' => 'Dostawcy i zgodność',
      'planning' => 'Planowanie',
      'team' => 'Zespół i czas',
      'communication' => 'Komunikacja',
      'automation' => 'Automatyzacja i AI',
    ),
  ),
  'areas' => array(
    'finances' => array(
      'label' => 'Finanse',
      'eyebrow' => 'Finanse i księgowość',
      'grid_heading' => 'Wszystko w zestawie narzędzi finansowych',
      'cards' => array(
        'expenses' => array(
          'icon' => 'credit-card',
          'title' => 'Wydatki',
          'body' => 'Śledź każdy koszt według projektu i kategorii z załączonymi paragonami.',
          'hero' => 'Śledź koszt każdej pracy—aż do paragonu',
          'lead' => 'Zapisuj koszty do właściwego projektu i kategorii w kilka sekund, dołącz paragon i patrz, jak rzeczywisty koszt pracy buduje się sam w miarę wydawania.',
          'rows' => array(
            0 => array(
              'heading' => 'Każdy koszt na swoim miejscu',
              'text' => 'Zarejestruj wydatek w chwili, gdy się pojawia, i przypisz go do właściwej pracy i kategorii. Żadnego pudełka pełnego paragonów ani gorączkowego przypominania sobie na koniec miesiąca, czego dotyczyła dana opłata.',
              'points' => array(
                0 => 'Przypisuj koszty do projektu i kategorii',
                1 => 'Dołącz do każdego zdjęcie lub paragon PDF',
                2 => 'Podziel jedną opłatę na kilka prac',
                3 => 'Szukaj i filtruj według pracy, dostawcy lub daty',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Ostatnie wydatki · 123 Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'credit-card',
                    'label' => 'Home Depot · Drewno',
                    'sub' => '842,10 $ · Materiały',
                  ),
                  1 => array(
                    'icon' => 'credit-card',
                    'label' => 'Ferguson · Armatura',
                    'sub' => '1 260,00 $ · Hydraulika',
                  ),
                  2 => array(
                    'icon' => 'credit-card',
                    'label' => 'Paliwo · Ciężarówka 2',
                    'sub' => '88,40 $ · Pojazd',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Koszty, które zasilają Twoje liczby',
              'text' => 'Każdy wydatek trafia prosto do kalkulacji kosztów pracy i Twoich raportów, więc marża jest zawsze aktualna. Nigdy nie musisz wpisywać tej samej liczby dwa razy.',
              'points' => array(
                0 => 'Automatycznie zasila kalkulację kosztów pracy',
                1 => 'Wpływa do rachunku zysków i strat w czasie rzeczywistym',
                2 => 'Dopasowuje się do transakcji bankowych',
                3 => 'Utrzymuje czysty, gotowy do audytu zapis',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Gdy każdy koszt jest oznaczony w chwili wydania, Twój zysk na każdej pracy jest od razu widoczny—żadnego uzgadniania arkuszy o północy.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'folder',
              'title' => 'Według projektu',
              'body' => 'Powiąż każdy koszt z pracą, do której należy.',
            ),
            1 => array(
              'icon' => 'tag',
              'title' => 'Według kategorii',
              'body' => 'Spójne kategorie utrzymują raporty w porządku.',
            ),
            2 => array(
              'icon' => 'paper-clip',
              'title' => 'Załączone paragony',
              'body' => 'Zdjęcie lub PDF przy każdym wydatku.',
            ),
            3 => array(
              'icon' => 'arrows-pointing-out',
              'title' => 'Podział kosztów',
              'body' => 'Podziel jedną opłatę na kilka prac.',
            ),
            4 => array(
              'icon' => 'calculator',
              'title' => 'Zasila kalkulację kosztów',
              'body' => 'Marża aktualizuje się w miarę wydawania.',
            ),
            5 => array(
              'icon' => 'magnifying-glass',
              'title' => 'Z wyszukiwaniem',
              'body' => 'Znajdź każdy koszt w kilka sekund.',
            ),
          ),
          'cta' => array(
            'heading' => 'Poznaj swój rzeczywisty koszt na każdej pracy.',
            'sub' => 'Oznacz każdy wydatek raz i pozwól, by marże aktualizowały się same.',
          ),
        ),
        'auto-receipts' => array(
          'icon' => 'document-magnifying-glass',
          'title' => 'Auto-paragony',
          'body' => 'Paragony przesłane e-mailem lub sfotografowane są odczytywane, rozbijane na pozycje i porządkowane automatycznie.',
          'hero' => 'Paragony, które porządkują się same',
          'lead' => 'Prześlij paragon z e-maila lub zrób zdjęcie, a Hive odczyta dostawcę, sumę i pozycje, a następnie przypisze go do właściwej pracy—bez wpisywania.',
          'rows' => array(
            0 => array(
              'heading' => 'Zrób zdjęcie lub prześlij dalej',
              'text' => 'Wyślij zdjęcie SMS-em, prześlij e-mail od dostawcy lub pozwól, by konta w sklepach same przekazywały paragony. Nasza AI wyciągnie dostawcę, datę, sumę i każdą pozycję za Ciebie.',
              'points' => array(
                0 => 'Fotografuj papierowe paragony w terenie',
                1 => 'Przesyłaj paragony z e-maili do skrzynki Hive',
                2 => 'Rozbite na pozycje, aż do każdej linii produktu',
                3 => 'Dostawca i sumy odczytane automatycznie',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Odczytano · Home Depot',
                'rows' => array(
                  0 => array(
                    'icon' => 'document-magnifying-glass',
                    'label' => 'Deska 2x4 · szt. 40',
                    'sub' => '3,18 $/szt · 127,20 $',
                  ),
                  1 => array(
                    'icon' => 'document-magnifying-glass',
                    'label' => 'Wkręty tarasowe 5 lb',
                    'sub' => '42,97 $',
                  ),
                  2 => array(
                    'icon' => 'document-magnifying-glass',
                    'label' => 'Odczytana suma',
                    'sub' => '170,17 $',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Uporządkowane, zanim zapomnisz',
              'text' => 'Każdy paragon trafia jako wydatek do właściwego projektu, gotowy do dopasowania z Twoim kanałem bankowym. Sterta na Twoim pulpicie znika na dobre.',
              'points' => array(
                0 => 'Staje się wydatkiem na właściwej pracy',
                1 => 'Gotowy do dopasowania z transakcjami bankowymi',
                2 => 'Bez ręcznego wprowadzania danych',
                3 => 'Każda pozycja zachowana na potrzeby gwarancji i sporów',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Paragony, które kiedyś gubiłeś, są teraz przeszukiwalne, rozbite na pozycje i powiązane z pracą—bez dotykania klawiatury.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'camera',
              'title' => 'Rejestracja zdjęciem',
              'body' => 'Sfotografuj papierowy paragon w terenie.',
            ),
            1 => array(
              'icon' => 'envelope',
              'title' => 'Przesyłanie e-mailem',
              'body' => 'Przesyłaj e-maile od dostawców wprost do systemu.',
            ),
            2 => array(
              'icon' => 'list-bullet',
              'title' => 'Rozbite na pozycje',
              'body' => 'Każda linia produktu wyciągnięta.',
            ),
            3 => array(
              'icon' => 'sparkles',
              'title' => 'Odczyt AI',
              'body' => 'Dostawca i sumy wykryte za Ciebie.',
            ),
            4 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Gotowe do dopasowania',
              'body' => 'Zgadza się z Twoim kanałem bankowym.',
            ),
            5 => array(
              'icon' => 'folder',
              'title' => 'Auto-porządkowanie',
              'body' => 'Trafia do właściwego projektu.',
            ),
          ),
          'cta' => array(
            'heading' => 'Przestań przepisywać paragony.',
            'sub' => 'Prześlij dalej lub zrób zdjęcie, a Hive rozbije je na pozycje i uporządkuje za Ciebie.',
          ),
        ),
        'payments' => array(
          'icon' => 'banknotes',
          'title' => 'Płatności',
          'body' => 'Rejestruj, co płacisz i co Ci się należy, u dostawców i klientów.',
          'hero' => 'Wpływy i wydatki w jednym miejscu',
          'lead' => 'Śledź każdą wykonaną płatność i każdego należnego Ci dolara, powiązane z właściwą pracą i kontaktem—tak byś zawsze wiedział, na czym stoisz.',
          'rows' => array(
            0 => array(
              'heading' => 'Przejrzysta księga dla każdej pracy',
              'text' => 'Rejestruj płatności klientów i wypłaty dla dostawców na bieżąco. Każda łączy się z projektem, kontaktem i Twoimi księgami, więc nic nie umknie.',
              'points' => array(
                0 => 'Śledź płatności przychodzące i wychodzące',
                1 => 'Powiąż każdą płatność z pracą i kontaktem',
                2 => 'Sprawdzaj niespłacone salda jednym rzutem oka',
                3 => 'Zapisy zgodne z Twoim kanałem bankowym',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Księga projektu · Maple St',
                'rows' => array(
                  0 => array(
                    'label' => 'Klient zapłacił',
                    'value' => '31 200 $',
                  ),
                  1 => array(
                    'label' => 'Zapłacono dostawcom',
                    'value' => '18 940 $',
                  ),
                  2 => array(
                    'label' => 'Do zapłaty',
                    'value' => '16 800 $',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Nigdy nie trać rachuby, kto komu jest winien',
              'text' => 'Sprawdzaj, co wciąż należy się od klientów i co jesteś winien podwykonawcom i dostawcom, wszystko zebrane według pracy. Upominaj się pewnie, zamiast zgadywać.',
              'points' => array(
                0 => 'Wiedz, ile klienci wciąż są Ci winni',
                1 => 'Wiedz, ile jesteś winien dostawcom',
                2 => 'Zbieraj salda według projektu',
                3 => 'Wyprzedzaj przepływ gotówki',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Gdy każda płatność jest przypisana do zlecenia, Twoja sytuacja finansowa na koniec miesiąca nigdy nie jest zagadką.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'arrow-down-circle',
              'title' => 'Płatności przychodzące',
              'body' => 'Zapisuj, ile płacą Ci klienci.',
            ),
            1 => array(
              'icon' => 'arrow-up-circle',
              'title' => 'Płatności wychodzące',
              'body' => 'Rejestruj wypłaty dla dostawców i podwykonawców.',
            ),
            2 => array(
              'icon' => 'folder',
              'title' => 'Według zlecenia',
              'body' => 'Każda płatność powiązana z projektem.',
            ),
            3 => array(
              'icon' => 'scale',
              'title' => 'Salda',
              'body' => 'Widzisz zaległe kwoty jasno i wyraźnie.',
            ),
            4 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Zgodne z bankiem',
              'body' => 'Zgadza się z Twoimi transakcjami.',
            ),
            5 => array(
              'icon' => 'chart-bar',
              'title' => 'Jasność finansowa',
              'body' => 'Zawsze wiesz, na czym stoisz.',
            ),
          ),
          'cta' => array(
            'heading' => 'Zawsze wiedz, kto i ile jest winien.',
            'sub' => 'Śledź każdą płatność przychodzącą i wychodzącą przy właściwym zleceniu i kontakcie.',
          ),
        ),
        'vendor-payments' => array(
          'icon' => 'wallet',
          'title' => 'Płatności dla dostawców',
          'body' => 'Płać podwykonawcom i dostawcom, przypisując każdą płatność do właściwego zlecenia.',
          'hero' => 'Płać podwykonawcom — i miej porządek w księgach',
          'lead' => 'Rejestruj i śledź płatności dla podwykonawców i dostawców, przypisując każdą złotówkę do właściwego zlecenia, aby koszty robocizny i materiałów zawsze trafiały tam, gdzie powinny.',
          'rows' => array(
            0 => array(
              'heading' => 'Każda wypłata przy właściwym zleceniu',
              'text' => 'Gdy płacisz podwykonawcy lub dostawcy, koszt automatycznie przypisuje się do projektu. Koniec z zastanawianiem się, którego zlecenia naprawdę dotyczył dany czek.',
              'points' => array(
                0 => 'Płać podwykonawcom i dostawcom z jednego miejsca',
                1 => 'Koszty trafiają do właściwego projektu',
                2 => 'Śledź bieżące saldo dla każdego dostawcy',
                3 => 'Zachowaj czystą dokumentację dla formularzy 1099',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Dostawca · Rivera Plumbing',
                'rows' => array(
                  0 => array(
                    'label' => 'Zafakturowano',
                    'value' => '$6 400',
                  ),
                  1 => array(
                    'label' => 'Zapłacono',
                    'value' => '$4 000',
                  ),
                  2 => array(
                    'label' => 'Saldo',
                    'value' => '$2 400',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Powiązane z ubezpieczeniem i zgodnością',
              'text' => 'Hive łączy każdego dostawcę z jego certyfikatami ubezpieczenia i ubezpieczeniem od wypadków pracowniczych, dzięki czemu możesz nadal płacić podwykonawcom, którzy dbają o Twoje bezpieczeństwo.',
              'points' => array(
                0 => 'Powiązane z certyfikatami ubezpieczenia (COI) dostawców',
                1 => 'Sprawdź saldo, zanim znów zapłacisz',
                2 => 'Oznaczaj podwykonawców z nieaktualnymi dokumentami',
                3 => 'Zasila kalkulację kosztów zleceń i księgi',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Płacenie podwykonawcom przez Hive oznacza, że koszty robocizny, salda i zgodność są zawsze zsynchronizowane — bez osobnego arkusza do prowadzenia.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'user-group',
              'title' => 'Podwykonawcy i dostawcy',
              'body' => 'Płać wszystkim z jednego miejsca.',
            ),
            1 => array(
              'icon' => 'folder',
              'title' => 'Według zlecenia',
              'body' => 'Koszty trafiają do właściwego projektu.',
            ),
            2 => array(
              'icon' => 'scale',
              'title' => 'Bieżące salda',
              'body' => 'Wiedz, ile należy się każdemu dostawcy.',
            ),
            3 => array(
              'icon' => 'shield-check',
              'title' => 'Powiązane ze zgodnością',
              'body' => 'Powiązane z certyfikatami COI i ubezpieczeniem.',
            ),
            4 => array(
              'icon' => 'document-text',
              'title' => 'Gotowe pod 1099',
              'body' => 'Czysta dokumentacja na koniec roku.',
            ),
            5 => array(
              'icon' => 'calculator',
              'title' => 'Zasila kalkulację kosztów',
              'body' => 'Robocizna trafia do kalkulacji kosztów zleceń.',
            ),
          ),
          'cta' => array(
            'heading' => 'Płać podwykonawcom, nie tracąc wątku.',
            'sub' => 'Każda wypłata powiązana ze zleceniem, saldem i ubezpieczeniem.',
          ),
        ),
        'checks' => array(
          'icon' => 'pencil-square',
          'title' => 'Czeki',
          'body' => 'Drukuj i rejestruj czeki z już wypełnionym zleceniem i kategorią.',
          'hero' => 'Wystawiaj czeki bez zbędnej roboty',
          'lead' => 'Drukuj i rejestruj czeki z już wypełnionym zleceniem, kategorią i dostawcą — a potem patrz, jak same dopasowują się do Twojego rachunku bankowego.',
          'rows' => array(
            0 => array(
              'heading' => 'Drukuj i rejestruj w jednym kroku',
              'text' => 'Wystaw czek, a Hive od razu zapisze go jako koszt przy właściwym zleceniu. Papiery i księgi zawsze idealnie się zgadzają.',
              'points' => array(
                0 => 'Drukuj czeki na swoich blankietach',
                1 => 'Automatycznie zapisywane jako koszt',
                2 => 'Zlecenie i kategoria wypełnione z góry',
                3 => 'Numeracja sekwencyjna w porządku',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Czek nr 1042',
                'rows' => array(
                  0 => array(
                    'label' => 'Zapłać dla',
                    'value' => 'Rivera Plumbing',
                  ),
                  1 => array(
                    'label' => 'Zlecenie',
                    'value' => 'Maple St',
                  ),
                  2 => array(
                    'label' => 'Kwota',
                    'value' => '$2 400,00',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Rozlicza się samo',
              'text' => 'Gdy czek zostanie zrealizowany, transakcja bankowa dopasowuje się do zapisu, który już zrobiłeś. Uzgadnianie przestaje być mozołem.',
              'points' => array(
                0 => 'Dopasowuje się do zrealizowanej transakcji bankowej',
                1 => 'Bez podwójnego wprowadzania na koniec miesiąca',
                2 => 'Łatwo wychwyć niezrealizowane czeki',
                3 => 'Czysty ślad dla każdej płatności',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Wydrukowany czek już jest w Twoich księgach przy właściwym zleceniu — więc uzgadnianie to tylko potwierdzenie, a nie ponowne przepisywanie.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'printer',
              'title' => 'Gotowe do druku',
              'body' => 'Wystawiaj czeki na swoich blankietach.',
            ),
            1 => array(
              'icon' => 'folder',
              'title' => 'Zlecenie wstępnie wypełnione',
              'body' => 'Właściwy projekt, automatycznie.',
            ),
            2 => array(
              'icon' => 'tag',
              'title' => 'Kategoria ustawiona',
              'body' => 'Spójna dla czystych ksiąg.',
            ),
            3 => array(
              'icon' => 'hashtag',
              'title' => 'Ponumerowane',
              'body' => 'Sekwencyjnie i w porządku.',
            ),
            4 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Auto-dopasowanie',
              'body' => 'Uzgadnia się z rachunkiem bankowym.',
            ),
            5 => array(
              'icon' => 'document-check',
              'title' => 'Czysty ślad',
              'body' => 'Zapis dla każdego czeku.',
            ),
          ),
          'cta' => array(
            'heading' => 'Zamień czeki w jeden krok zamiast trzech.',
            'sub' => 'Drukuj, rejestruj i uzgadniaj jednym działaniem.',
          ),
        ),
        'banks' => array(
          'icon' => 'building-library',
          'title' => 'Banki',
          'body' => 'Podłącz konta, aby uzyskać bieżący podgląd transakcji i uzgadnianie.',
          'hero' => 'Twój rachunek bankowy pracujący dla Ciebie',
          'lead' => 'Podłącz konta, aby uzyskać bieżący podgląd transakcji, które same dopasowują się do kosztów, czeków i dostawców — dzięki czemu uzgadnianie zajmuje minuty.',
          'rows' => array(
            0 => array(
              'heading' => 'Bieżące transakcje, automatycznie',
              'text' => 'Połącz konta firmowe i karty raz. Nowe transakcje napływają same, gotowe do dopasowania do już zarejestrowanych kosztów.',
              'points' => array(
                0 => 'Bezpieczne połączenie z Twoimi kontami',
                1 => 'Transakcje aktualizują się automatycznie',
                2 => 'Karty i konto bieżące w jednym widoku',
                3 => 'Nic nie trzeba importować ręcznie',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Ostatnie transakcje · Operacyjne',
                'rows' => array(
                  0 => array(
                    'icon' => 'building-library',
                    'label' => 'Home Depot',
                    'sub' => '-$842,10 · dopasowano',
                  ),
                  1 => array(
                    'icon' => 'building-library',
                    'label' => 'Czek nr 1042',
                    'sub' => '-$2 400,00 · dopasowano',
                  ),
                  2 => array(
                    'icon' => 'building-library',
                    'label' => 'Wpłata klienta',
                    'sub' => '+$10 000 · do sprawdzenia',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Uzgadniaj w kilka minut',
              'text' => 'Hive dopasowuje każdą transakcję do właściwego kosztu, czeku lub płatności dla dostawcy. Potwierdzasz dopasowania i księgi są gotowe.',
              'points' => array(
                0 => 'Automatycznie dopasowane do Twoich zapisów',
                1 => 'Szybko wychwyć wszystko, czego brakuje',
                2 => 'Utrzymuj salda w porządku',
                3 => 'Bez uzgadniania w arkuszu',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Bieżący rachunek bankowy, który sam się dopasowuje, zamienia godziny uzgadniania na koniec miesiąca w szybki przegląd.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'link',
              'title' => 'Połączone',
              'body' => 'Połącz konta i karty raz.',
            ),
            1 => array(
              'icon' => 'bolt',
              'title' => 'Podgląd na żywo',
              'body' => 'Transakcje aktualizują się same.',
            ),
            2 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Auto-dopasowanie',
              'body' => 'Pasuje do Twoich zapisów.',
            ),
            3 => array(
              'icon' => 'shield-check',
              'title' => 'Bezpiecznie',
              'body' => 'Połączenie klasy bankowej.',
            ),
            4 => array(
              'icon' => 'check-circle',
              'title' => 'Łatwe uzgadnianie',
              'body' => 'Potwierdź i gotowe.',
            ),
            5 => array(
              'icon' => 'scale',
              'title' => 'Dokładnie',
              'body' => 'Salda, którym można ufać.',
            ),
          ),
          'cta' => array(
            'heading' => 'Niech Twój kanał bankowy sam dopasowuje transakcje.',
            'sub' => 'Połącz raz i uzgadniaj w minuty, nie godziny.',
          ),
        ),
        'transaction-matching' => array(
          'icon' => 'arrows-right-left',
          'title' => 'Dopasowywanie transakcji',
          'body' => 'Transakcje bankowe same dopasowują się do odpowiedniego dostawcy, wydatku i czeku.',
          'hero' => 'Transakcje, które dopasowują się same',
          'lead' => 'Hive automatycznie dopasowuje każdą transakcję bankową do właściwego dostawcy, wydatku i czeku — więc Twoje księgi są czyste bez ręcznego sortowania.',
          'rows' => array(
            0 => array(
              'heading' => 'Inteligentne dopasowywanie od razu',
              'text' => 'Nasze dopasowywanie uczy się Twoich dostawców i wzorców, a potem łączy każdą przychodzącą transakcję z zapisanym już kosztem — albo proponuje najbliższe dopasowanie.',
              'points' => array(
                0 => 'Dopasowuje do dostawcy, wydatku lub czeku',
                1 => 'Uczy się Twoich stałych wzorców',
                2 => 'Proponuje najlepsze dopasowanie do potwierdzenia',
                3 => 'Oznacza wszystko, co nieoczekiwane',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Dopasowane dziś',
                'rows' => array(
                  0 => array(
                    'icon' => 'arrows-right-left',
                    'label' => 'Ferguson → Wydatek',
                    'sub' => '1 260 $ · Hydraulika',
                  ),
                  1 => array(
                    'icon' => 'arrows-right-left',
                    'label' => 'Czek #1042 → Rivera',
                    'sub' => '2 400 $ · Maple St',
                  ),
                  2 => array(
                    'icon' => 'arrows-right-left',
                    'label' => 'Paliwo → Pojazd',
                    'sub' => '88,40 $',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Wyłap to, co nie pasuje',
              'text' => 'Niedopasowane lub zdublowane obciążenia pojawiają się od razu, więc błędy i podwójne fakturowanie wychwycisz, zanim wpłyną na Twoje liczby.',
              'points' => array(
                0 => 'Wyświetla niedopasowane transakcje',
                1 => 'Automatycznie wyłapuje duplikaty',
                2 => 'Utrzymuje dokładne koszty projektów',
                3 => 'Zaufaj swoim raportom',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Gdy dopasowywanie jest automatyczne, jedyne transakcje, którym się przyglądasz, to te, które naprawdę Cię potrzebują.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'sparkles',
              'title' => 'Inteligentne dopasowania',
              'body' => 'Uczy się Twoich dostawców i wzorców.',
            ),
            1 => array(
              'icon' => 'check-circle',
              'title' => 'Potwierdzenie jednym dotknięciem',
              'body' => 'Szybko zatwierdzaj sugerowane dopasowania.',
            ),
            2 => array(
              'icon' => 'document-duplicate',
              'title' => 'Deduplikacja',
              'body' => 'Wyłapuje podwójne obciążenia.',
            ),
            3 => array(
              'icon' => 'flag',
              'title' => 'Wyjątki',
              'body' => 'Oznacza tylko to, co Cię wymaga.',
            ),
            4 => array(
              'icon' => 'folder',
              'title' => 'Dokładne na projekcie',
              'body' => 'Utrzymuje koszty na właściwym projekcie.',
            ),
            5 => array(
              'icon' => 'chart-bar',
              'title' => 'Godne zaufania',
              'body' => 'Raporty, na których możesz polegać.',
            ),
          ),
          'cta' => array(
            'heading' => 'Przestań ręcznie sortować transakcje.',
            'sub' => 'Niech dopasowywanie łączy kropki i pokazuje Ci tylko wyjątki.',
          ),
        ),
        'reimbursements' => array(
          'icon' => 'arrow-uturn-left',
          'title' => 'Zwroty kosztów',
          'body' => 'Śledź, ile firma jest winna ekipie i właścicielom, i spłacaj to bez bałaganu.',
          'hero' => 'Oddawaj ludziom pieniądze — bez karteczek na lodówce',
          'lead' => 'Śledź każdy wydatek z własnej kieszeni, jaki pokrywa Twoja ekipa i właściciele, a potem zwracaj go czysto, z zapisem powiązanym z projektem.',
          'rows' => array(
            0 => array(
              'heading' => 'Wydatki z własnej kieszeni, uchwycone',
              'text' => 'Gdy ktoś kupuje materiały na własną kartę, zapisz to jako wydatek do zwrotu na projekcie. Nic nie zostaje zapomniane ani opłacone dwa razy.',
              'points' => array(
                0 => 'Zapisuj wydatki z kieszeni na projekcie',
                1 => 'Dołącz paragon jako dowód',
                2 => 'Śledź, komu i ile jesteś winien',
                3 => 'Unikaj podwójnej zapłaty za ten sam koszt',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Do zwrotu · Greg M.',
                'rows' => array(
                  0 => array(
                    'label' => 'Drewno (karta prywatna)',
                    'value' => '214,80 $',
                  ),
                  1 => array(
                    'label' => 'Materiały żelazne',
                    'value' => '63,20 $',
                  ),
                  2 => array(
                    'label' => 'Do zwrotu',
                    'value' => '278,00 $',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Rozlicz się czysto',
              'text' => 'Zwróć całe saldo w jednej płatności, a Hive zapisze je na właściwym projekcie i w kategorii, zachowując dokładność kosztów i ksiąg.',
              'points' => array(
                0 => 'Spłać bieżące saldo',
                1 => 'Zapisane na projekcie',
                2 => 'Utrzymuje dokładny koszt projektu',
                3 => 'Przejrzysta historia dla wszystkich',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Ekipa, której szybko i poprawnie zwracasz pieniądze, to ekipa, która dalej kupuje to, czego potrzebuje projekt.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'arrow-uturn-left',
              'title' => 'Do zwrotu',
              'body' => 'Oznacz wydatki z kieszeni.',
            ),
            1 => array(
              'icon' => 'paper-clip',
              'title' => 'Z paragonem',
              'body' => 'Dowód dołączony do każdego.',
            ),
            2 => array(
              'icon' => 'scale',
              'title' => 'Bieżące saldo',
              'body' => 'Wiedz, komu ile się należy.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Na projekcie',
              'body' => 'Koszt trafia na projekt.',
            ),
            4 => array(
              'icon' => 'banknotes',
              'title' => 'Spłać to',
              'body' => 'Rozlicz jedną płatnością.',
            ),
            5 => array(
              'icon' => 'clock',
              'title' => 'Historia',
              'body' => 'Przejrzysty ślad dla wszystkich.',
            ),
          ),
          'cta' => array(
            'heading' => 'Zwracaj koszty ekipie w prosty sposób.',
            'sub' => 'Uchwyć wydatki z kieszeni i rozlicz się z czystym zapisem.',
          ),
        ),
        'distributions' => array(
          'icon' => 'receipt-percent',
          'title' => 'Wypłaty',
          'body' => 'Utrzymuj wypłaty i podziały dla właścicieli uporządkowane i gotowe do raportowania.',
          'hero' => 'Wypłaty dla właścicieli, uporządkowane i gotowe do raportowania',
          'lead' => 'Zapisuj wypłaty i podziały dla właścicieli tak, by były czyste dla księgowego i jasne dla Ciebie w czasie rozliczeń podatkowych.',
          'rows' => array(
            0 => array(
              'heading' => 'Śledź każdą wypłatę',
              'text' => 'Zapisuj podziały na bieżąco, oddzielnie od kosztów projektów i wydatków, żeby liczby firmy i Twoja prywatna wypłata nigdy się nie mieszały.',
              'points' => array(
                0 => 'Zapisuj wypłaty dla właścicieli czysto',
                1 => 'Trzymaj je poza kosztami projektów',
                2 => 'Dziel między wielu właścicieli',
                3 => 'Powiązane z właściwymi kontami',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Wypłaty · od początku roku',
                'rows' => array(
                  0 => array(
                    'label' => 'Właściciel A',
                    'value' => '42 000 $',
                  ),
                  1 => array(
                    'label' => 'Właściciel B',
                    'value' => '38 500 $',
                  ),
                  2 => array(
                    'label' => 'Razem',
                    'value' => '80 500 $',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Gotowe dla Twojego księgowego',
              'text' => 'Wszystko jest skategoryzowane i gotowe do raportowania, więc przekazanie danych w czasie rozliczeń to pobranie pliku, a nie rekonstrukcja.',
              'points' => array(
                0 => 'Raportowalne wg właściciela i okresu',
                1 => 'Czyste kategorie przez cały rok',
                2 => 'Łatwe przekazanie księgowemu',
                3 => 'Bez gorączki w sezonie podatkowym',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Utrzymywanie czystych wypłat przez cały rok sprawia, że sezon podatkowy to szybki eksport, a nie bolesne porządki.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'receipt-percent',
              'title' => 'Wypłaty właścicieli',
              'body' => 'Zapisywane na bieżąco.',
            ),
            1 => array(
              'icon' => 'users',
              'title' => 'Wielu właścicieli',
              'body' => 'Dzielone między wspólników.',
            ),
            2 => array(
              'icon' => 'tag',
              'title' => 'Skategoryzowane',
              'body' => 'Czyste księgi przez cały rok.',
            ),
            3 => array(
              'icon' => 'folder-minus',
              'title' => 'Oddzielone',
              'body' => 'Poza kosztami zleceń.',
            ),
            4 => array(
              'icon' => 'document-currency-dollar',
              'title' => 'Gotowe do raportu',
              'body' => 'Wg właściciela i okresu.',
            ),
            5 => array(
              'icon' => 'arrow-down-tray',
              'title' => 'Eksport',
              'body' => 'Przekaż księgowemu.',
            ),
          ),
          'cta' => array(
            'heading' => 'Utrzymaj czyste wypłaty przez cały rok.',
            'sub' => 'Uporządkowane wypłaty gotowe do raportu, dzięki którym rozliczenie podatkowe jest proste.',
          ),
        ),
        'line-items' => array(
          'icon' => 'list-bullet',
          'title' => 'Pozycje i limity kosztów',
          'body' => 'Rozbij koszty na pozycje i uzgadniaj je z limitami klienta co do pozycji.',
          'hero' => 'Rozpisz wszystko na pozycje—i chroń swoje limity kosztów',
          'lead' => 'Rozbij koszty na pojedyncze pozycje i uzgadniaj je z każdym limitem klienta, aby wychwycić przekroczenia, zanim będą Cię kosztować.',
          'rows' => array(
            0 => array(
              'heading' => 'Szczegóły co do pozycji',
              'text' => 'Rejestruj koszty jako osobne pozycje, a nie kwoty ryczałtowe. Ty i klient widzicie dokładnie, na co idą pieniądze przy każdym wyborze i kategorii.',
              'points' => array(
                0 => 'Rozpisz koszty pozycja po pozycji',
                1 => 'Grupuj pozycje wg kategorii lub pomieszczenia',
                2 => 'Przypisz pozycje do właściwego zlecenia',
                3 => 'Krystalicznie jasne dla klientów',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Limit na płytki',
                'rows' => array(
                  0 => array(
                    'label' => 'Limit',
                    'value' => '$2 500',
                  ),
                  1 => array(
                    'label' => 'Koszt wg pozycji',
                    'value' => '$2 840',
                  ),
                  2 => array(
                    'label' => 'Przekroczenie',
                    'value' => '+$340',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Limity, które się trzymają',
              'text' => 'Hive uzgadnia Twoje pozycje z limitem klienta&rsquo;a i sygnalizuje przekroczenia, więc rozmowa toczy się przed fakturą, a nie po niej.',
              'points' => array(
                0 => 'Uzgadniaj pozycje z limitami',
                1 => 'Automatyczne sygnalizowanie przekroczeń',
                2 => 'Fakturuj przekroczenia z pewnością',
                3 => 'Żadnych straconych pieniędzy',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Pozycje uzgodnione z limitami oznaczają, że dostajesz zapłatę za wybrane przez klienta ulepszenia—za każdym razem.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'list-bullet',
              'title' => 'Rozpisane',
              'body' => 'Koszty rozbite na pozycje.',
            ),
            1 => array(
              'icon' => 'rectangle-group',
              'title' => 'Pogrupowane',
              'body' => 'Wg kategorii lub pomieszczenia.',
            ),
            2 => array(
              'icon' => 'scale',
              'title' => 'Uzgodnione',
              'body' => 'Pozycje vs limity.',
            ),
            3 => array(
              'icon' => 'exclamation-triangle',
              'title' => 'Alerty przekroczeń',
              'body' => 'Wychwycone przed fakturą.',
            ),
            4 => array(
              'icon' => 'banknotes',
              'title' => 'Fakturuj ulepszenia',
              'body' => 'Zarabiaj na zmianach.',
            ),
            5 => array(
              'icon' => 'eye',
              'title' => 'Przejrzyste',
              'body' => 'Jasne dla klientów.',
            ),
          ),
          'cta' => array(
            'heading' => 'Nigdy więcej nie dopłacaj do przekroczeń limitu.',
            'sub' => 'Rozpisz co do pozycji i uzgadniaj z każdym limitem.',
          ),
        ),
        'estimates-invoices' => array(
          'icon' => 'document-text',
          'title' => 'Kosztorysy i faktury',
          'body' => 'Wysyłaj markowe kosztorysy i faktury i zamieniaj akceptacje w zlecenia.',
          'hero' => 'Od kosztorysu przez fakturę po zapłatę',
          'lead' => 'Wysyłaj markowe kosztorysy, zamieniaj akceptacje w aktywne zlecenia i fakturuj za wykonaną pracę—wszystko bez wychodzenia z Hive.',
          'rows' => array(
            0 => array(
              'heading' => 'Markowe i profesjonalne',
              'text' => 'Wysyłaj przejrzyste, rozpisane kosztorysy, które podkreślają Twój profesjonalizm. Klienci akceptują online i zlecenie jest gotowe do startu.',
              'points' => array(
                0 => 'Markowe kosztorysy i faktury',
                1 => 'Rozpisane i czytelne',
                2 => 'Akceptacja online i podpis elektroniczny',
                3 => 'Akceptacje stają się aktywnymi zleceniami',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Kosztorys #1042',
                'rows' => array(
                  0 => array(
                    'label' => 'Zabudowa meblowa',
                    'value' => '$8 400',
                  ),
                  1 => array(
                    'label' => 'Blaty',
                    'value' => '$3 950',
                  ),
                  2 => array(
                    'label' => 'Razem',
                    'value' => '$14 450',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Fakturuj i otrzymuj zapłatę',
              'text' => 'Wystawiaj faktury częściowe lub końcowe wprost z zaakceptowanego zakresu. Wszystko wraca do kosztorysowania zleceń i Twoich ksiąg.',
              'points' => array(
                0 => 'Fakturuj z zaakceptowanego zakresu',
                1 => 'Rozliczenie częściowe lub końcowe',
                2 => 'Powiązane z kosztorysowaniem zleceń',
                3 => 'Przejrzysty zapis tego, co opłacone',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Gdy kosztorysy przechodzą w zlecenia i faktury, przestajesz przepisywać liczby i szybciej dostajesz zapłatę.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-text',
              'title' => 'Kosztorysy',
              'body' => 'Markowe i rozpisane.',
            ),
            1 => array(
              'icon' => 'pencil-square',
              'title' => 'Podpis elektroniczny',
              'body' => 'Akceptacja online w kilka sekund.',
            ),
            2 => array(
              'icon' => 'arrow-path',
              'title' => 'Na zlecenia',
              'body' => 'Akceptacje stają się projektami.',
            ),
            3 => array(
              'icon' => 'document-currency-dollar',
              'title' => 'Faktury',
              'body' => 'Rozliczaj wykonaną pracę.',
            ),
            4 => array(
              'icon' => 'calculator',
              'title' => 'Powiązane z kosztorysowaniem',
              'body' => 'Łączy się z kosztorysowaniem zleceń.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Jasność płatności',
              'body' => 'Wiedz, co jest rozliczone.',
            ),
          ),
          'cta' => array(
            'heading' => 'Wygraj przetarg i zafakturuj—w jednym miejscu.',
            'sub' => 'Markowe kosztorysy, które płynnie przechodzą w zlecenia i faktury.',
          ),
        ),
        'sheets' => array(
          'icon' => 'document-currency-dollar',
          'title' => 'Zestawienia',
          'body' => 'Bilanse oraz rachunek zysków i strat generowane z Twoich danych na żywo.',
          'hero' => 'Sprawozdania finansowe, które tworzą się same',
          'lead' => 'Twój bilans oraz rachunek zysków i strat są generowane z danych na żywo—zawsze aktualne, zawsze gotowe, bez mozolenia się z arkuszami.',
          'rows' => array(
            0 => array(
              'heading' => 'Rachunek zysków i strat na żywo',
              'text' => 'Każdy wydatek, płatność i faktura trafia do rachunku zysków i strat, który jest aktualny co do minuty—nie z ostatniego kwartału. Zobacz, jak naprawdę idzie firmie, kiedy tylko chcesz.',
              'points' => array(
                0 => 'Rachunek zysków i strat z danych na żywo',
                1 => 'Bilans zawsze aktualny',
                2 => 'Filtruj wg okresu i zlecenia',
                3 => 'Bez ręcznych eksportów księgowych',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Rachunek zysków i strat · Ten miesiąc',
                'rows' => array(
                  0 => array(
                    'label' => 'Przychód',
                    'value' => '$84 200',
                  ),
                  1 => array(
                    'label' => 'Koszty',
                    'value' => '$58 640',
                  ),
                  2 => array(
                    'label' => 'Netto',
                    'value' => '$25 560',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Gotowe dla każdego, kto zapyta',
              'text' => 'Gdy Twój księgowy, kredytodawca lub wspólnik potrzebuje liczb, są już gotowe. Wyeksportuj czyste zestawienie w kilka sekund.',
              'points' => array(
                0 => 'Szybko przekaż księgowemu',
                1 => 'Zestawienia, którym ufają kredytodawcy',
                2 => 'Zawsze uzgodnione z Twoim feedem',
                3 => 'Eksportuj, kiedy potrzebujesz',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Zawsze aktualne sprawozdania finansowe oznaczają, że decyzje podejmujesz na podstawie realnych liczb, a nie przeczucia.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-currency-dollar',
              'title' => 'Rachunek zysków i strat',
              'body' => 'Na żywo, nie z ostatniego kwartału.',
            ),
            1 => array(
              'icon' => 'scale',
              'title' => 'Bilans',
              'body' => 'Zawsze aktualny.',
            ),
            2 => array(
              'icon' => 'funnel',
              'title' => 'Z filtrami',
              'body' => 'Wg okresu i zlecenia.',
            ),
            3 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Uzgodnione',
              'body' => 'Powiązane z Twoim feedem bankowym.',
            ),
            4 => array(
              'icon' => 'arrow-down-tray',
              'title' => 'Eksportowalne',
              'body' => 'Przekaż w kilka sekund.',
            ),
            5 => array(
              'icon' => 'chart-bar',
              'title' => 'Gotowe do decyzji',
              'body' => 'Realne dane, o każdej porze.',
            ),
          ),
          'cta' => array(
            'heading' => 'Poznaj swoje liczby bez arkusza kalkulacyjnego.',
            'sub' => 'Rachunek zysków i strat oraz bilans na żywo, tworzone z Twoich rzeczywistych danych.',
          ),
        ),
        'categories' => array(
          'icon' => 'tag',
          'title' => 'Kategorie',
          'body' => 'Spójne kategorie sprawiają, że Twoje księgi i raporty są wiarygodne.',
          'hero' => 'Spójne kategorie, wiarygodne księgi',
          'lead' => 'Jeden porządny zestaw kategorii stosowany wszędzie sprawia, że Twoje raporty naprawdę coś znaczą — a rozliczenia podatkowe są dużo mniej bolesne.',
          'rows' => array(
            0 => array(
              'heading' => 'Jeden spójny zestaw',
              'text' => 'Zdefiniuj raz kategorie pasujące do Twojej firmy, a Hive stosuje je w wydatkach, czekach i płatnościach, żeby nic nie było źle zaksięgowane.',
              'points' => array(
                0 => 'Zdefiniuj kategorie pasujące do Twojej branży',
                1 => 'Stosowane w każdej transakcji',
                2 => 'Sugerowane automatycznie na bieżąco',
                3 => 'Koniec z pojedynczymi błędnymi opisami',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Główne kategorie · Ten miesiąc',
                'rows' => array(
                  0 => array(
                    'icon' => 'tag',
                    'label' => 'Materiały',
                    'sub' => '32 400 $',
                  ),
                  1 => array(
                    'icon' => 'tag',
                    'label' => 'Robocizna',
                    'sub' => '21 800 $',
                  ),
                  2 => array(
                    'icon' => 'tag',
                    'label' => 'Pojazd i paliwo',
                    'sub' => '3 140 $',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Raporty, którym możesz zaufać',
              'text' => 'Gdy wszystko jest zaksięgowane tak samo, Twój rachunek zysków i strat oraz koszty zleceń mówią prawdę — a Twój księgowy jest wdzięczny.',
              'points' => array(
                0 => 'Wiarygodny rachunek zysków i strat',
                1 => 'Dokładne koszty zleceń',
                2 => 'Sprawniejsze przygotowanie do podatków',
                3 => 'Wychwytuj trendy z pewnością',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Spójne kategorie to cichy fundament pod każdym raportem, któremu naprawdę ufasz.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'tag',
              'title' => 'Własny zestaw',
              'body' => 'Dopasuj do branży.',
            ),
            1 => array(
              'icon' => 'sparkles',
              'title' => 'Auto-sugestie',
              'body' => 'Kodowane na bieżąco.',
            ),
            2 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Wszędzie',
              'body' => 'We wszystkich transakcjach.',
            ),
            3 => array(
              'icon' => 'document-currency-dollar',
              'title' => 'Czysty rach. zysków i strat',
              'body' => 'Raporty, które się zgadzają.',
            ),
            4 => array(
              'icon' => 'calculator',
              'title' => 'Dokładne kosztorysowanie',
              'body' => 'Zlecenia dobrze zaksięgowane.',
            ),
            5 => array(
              'icon' => 'check-badge',
              'title' => 'Gotowe na podatki',
              'body' => 'Mniej porządków na koniec roku.',
            ),
          ),
          'cta' => array(
            'heading' => 'Buduj księgi, którym naprawdę możesz zaufać.',
            'sub' => 'Jeden spójny zestaw kategorii stosowany wszędzie.',
          ),
        ),
        'job-costing' => array(
          'icon' => 'calculator',
          'title' => 'Kosztorysowanie zleceń',
          'body' => 'Zobacz rzeczywisty koszt i marżę każdego projektu w miarę przepływu pieniędzy.',
          'hero' => 'Poznaj swoją marżę na każdym zleceniu',
          'lead' => 'Zobacz rzeczywisty koszt i marżę na żywo dla każdego projektu w miarę przepływu wydatków, robocizny i płatności — dzięki temu dowiadujesz się, że przekraczasz budżet, zanim będzie za późno.',
          'rows' => array(
            0 => array(
              'heading' => 'Koszt, który liczy się sam',
              'text' => 'Materiały, robocizna i płatności dla podwykonawców lądują na zleceniu automatycznie. Twój koszt na dziś jest zawsze aktualny bez ręcznego liczenia.',
              'points' => array(
                0 => 'Materiały, robocizna i podwykonawcy razem',
                1 => 'Koszt na dziś zawsze aktualny',
                2 => 'Porównaj z kosztorysem',
                3 => 'Bez ręcznego liczenia',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Maple St · Marża',
                'rows' => array(
                  0 => array(
                    'label' => 'Kontrakt',
                    'value' => '48 000 $',
                  ),
                  1 => array(
                    'label' => 'Koszt na dziś',
                    'value' => '30 100 $',
                  ),
                  2 => array(
                    'label' => 'Prognozowana marża',
                    'value' => '24%',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Wychwyć przekroczenia wcześnie',
              'text' => 'Gdy zlecenie zaczyna przekraczać budżet, widzisz to, kiedy jeszcze możesz coś z tym zrobić — a nie przy końcowej fakturze.',
              'points' => array(
                0 => 'Wychwytuj przekroczenia na bieżąco',
                1 => 'Chroń swoją marżę',
                2 => 'Decyduj, zanim będzie za późno',
                3 => 'Lepiej wyceniaj kolejne zlecenie',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Kosztorysowanie na żywo to różnica między dowiedzeniem się, że straciłeś pieniądze, a zapobiegnięciem temu.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'calculator',
              'title' => 'Rzeczywisty koszt',
              'body' => 'Materiały, robocizna, podwykonawcy.',
            ),
            1 => array(
              'icon' => 'bolt',
              'title' => 'Na żywo',
              'body' => 'Aktualizuje się z przepływem pieniędzy.',
            ),
            2 => array(
              'icon' => 'scale',
              'title' => 'Vs kosztorys',
              'body' => 'Porównaj z ofertą.',
            ),
            3 => array(
              'icon' => 'chart-bar',
              'title' => 'Marża',
              'body' => 'Zobacz zysk na zlecenie.',
            ),
            4 => array(
              'icon' => 'exclamation-triangle',
              'title' => 'Alerty o przekroczeniach',
              'body' => 'Wychwyć to wcześnie.',
            ),
            5 => array(
              'icon' => 'light-bulb',
              'title' => 'Lepsze oferty',
              'body' => 'Ucz się na realnych danych.',
            ),
          ),
          'cta' => array(
            'heading' => 'Przestań zgadywać, czy zlecenie przyniosło zysk.',
            'sub' => 'Koszt i marża na żywo dla każdego projektu, na bieżąco.',
          ),
        ),
        'lien-waivers' => array(
          'icon' => 'document-check',
          'title' => 'Zrzeczenia prawa zastawu',
          'body' => 'Wysyłaj i zbieraj podpisane zrzeczenia przez bezpieczne linki bez konta.',
          'hero' => 'Zbieraj zrzeczenia prawa zastawu bez ganiania',
          'lead' => 'Wysyłaj zrzeczenia i zbieraj podpisy przez bezpieczne linki — bez kont, bez drukowania — więc dokumenty chroniące Twoje płatności są zawsze gotowe.',
          'rows' => array(
            0 => array(
              'heading' => 'Wyślij w kilka sekund',
              'text' => 'Wygeneruj właściwe zrzeczenie i wyślij bezpieczny link do podwykonawcy lub dostawcy. Podpisze na dowolnym urządzeniu, bez logowania.',
              'points' => array(
                0 => 'Zrzeczenia warunkowe i bezwarunkowe',
                1 => 'Bezpieczne linki do podpisu bez konta',
                2 => 'Podpis na telefonie lub komputerze',
                3 => 'Powiązane ze zleceniem i płatnością',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Zrzeczenia · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'document-check',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Podpisano · 12.06',
                  ),
                  1 => array(
                    'icon' => 'document-check',
                    'label' => 'Apex Electric',
                    'sub' => 'Podpisano · 14.06',
                  ),
                  2 => array(
                    'icon' => 'clock',
                    'label' => 'Summit Drywall',
                    'sub' => 'Wysłano · oczekuje',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Zabezpieczone i uporządkowane',
              'text' => 'Każde podpisane zrzeczenie jest zapisane przy zleceniu i płatności, więc gdy generalny wykonawca lub bank zapyta, dowód jest o jedno kliknięcie.',
              'points' => array(
                0 => 'Zapisane przy zleceniu',
                1 => 'Jedno kliknięcie, by odnaleźć',
                2 => 'Śledź, kto podpisał, a kto nie',
                3 => 'Chroń swoje prawo do zapłaty',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Zrzeczenia zebrane na czas utrzymują płynność płatności i chronią projekty przed zastawami.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-check',
              'title' => 'Właściwe zrzeczenie',
              'body' => 'Warunkowe lub bezwarunkowe.',
            ),
            1 => array(
              'icon' => 'link',
              'title' => 'Bezpieczne linki',
              'body' => 'Podpis bez konta.',
            ),
            2 => array(
              'icon' => 'device-phone-mobile',
              'title' => 'Dowolne urządzenie',
              'body' => 'Podpisz z telefonu.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Przy zleceniu',
              'body' => 'Zapisane z projektem.',
            ),
            4 => array(
              'icon' => 'clock',
              'title' => 'Śledź status',
              'body' => 'Zobacz, kto zalega.',
            ),
            5 => array(
              'icon' => 'shield-check',
              'title' => 'Chronione',
              'body' => 'Chroń swoje płatności.',
            ),
          ),
          'cta' => array(
            'heading' => 'Koniec z gonieniem za zwolnieniem z zastawu.',
            'sub' => 'Wysyłaj bezpieczne linki i zbieraj podpisy bez wysiłku.',
          ),
        ),
        'insurance-certificates' => array(
          'icon' => 'shield-check',
          'title' => 'Certyfikaty ubezpieczenia',
          'body' => 'Przechowuj certyfikaty ubezpieczenia (COI) i otrzymuj alerty, zanim wygasną.',
          'hero' => 'Bądź chroniony — każdy COI w jednym miejscu',
          'lead' => 'Utrzymuj każdy certyfikat ubezpieczenia w porządku i otrzymuj alert, zanim któryś wygaśnie — dzięki temu nigdy nie jesteś odsłonięty na budowie.',
          'rows' => array(
            0 => array(
              'heading' => 'Każdy COI w jednym miejscu',
              'text' => 'Przechowuj certyfikaty każdego podwykonawcy i dostawcy, powiązane z firmą i pracami, które wykonują. Koniec z przeszukiwaniem poczty w poszukiwaniu dowodu ochrony.',
              'points' => array(
                0 => 'Przechowuj COI według dostawcy',
                1 => 'Powiązane z pracami, które wykonują',
                2 => 'Zobacz zakres ochrony jednym rzutem oka',
                3 => 'Poproś o aktualizacje jednym dotknięciem',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Ochrona · Podwykonawcy',
                'rows' => array(
                  0 => array(
                    'icon' => 'shield-check',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Ważne do 30.11',
                  ),
                  1 => array(
                    'icon' => 'shield-check',
                    'label' => 'Apex Electric',
                    'sub' => 'Ważne do 15.09',
                  ),
                  2 => array(
                    'icon' => 'exclamation-triangle',
                    'label' => 'Summit Drywall',
                    'sub' => 'Wygasa za 9 dni',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Alerty, zanim wygasną',
              'text' => 'Hive śledzi daty ważności i ostrzega z wyprzedzeniem, dzięki czemu zdobędziesz odnowiony certyfikat, zanim podwykonawca wejdzie na budowę bez ochrony.',
              'points' => array(
                0 => 'Automatyczne alerty o wygaśnięciu',
                1 => 'Wychwyć wygaśnięcia, zanim nastąpią',
                2 => 'Chroń się przed odpowiedzialnością',
                3 => 'Zadowoleni generalni wykonawcy i kredytodawcy',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Niezauważony wygasły COI to roszczenie, które tylko czeka, by na Ciebie spaść. Hive dopilnuje, byś je wychwycił.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'shield-check',
              'title' => 'COI w kartotece',
              'body' => 'Każdy certyfikat zapisany.',
            ),
            1 => array(
              'icon' => 'user-group',
              'title' => 'Według dostawcy',
              'body' => 'Powiązany z każdym podwykonawcą.',
            ),
            2 => array(
              'icon' => 'bell-alert',
              'title' => 'Alerty o wygaśnięciu',
              'body' => 'Ostrzeżenie z wyprzedzeniem.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Według pracy',
              'body' => 'Powiązane z projektami.',
            ),
            4 => array(
              'icon' => 'envelope',
              'title' => 'Poproś o aktualizacje',
              'body' => 'Szybko zapytaj agentów.',
            ),
            5 => array(
              'icon' => 'scale',
              'title' => 'Mniejsza odpowiedzialność',
              'body' => 'Nigdy bez ochrony.',
            ),
          ),
          'cta' => array(
            'heading' => 'Nigdy nie dopuść do wygaśnięcia ochrony na budowie.',
            'sub' => 'Każdy COI w kartotece z alertami przed wygaśnięciem.',
          ),
        ),
        'workers-comp' => array(
          'icon' => 'clipboard-document-check',
          'title' => 'Ubezpieczenie pracowników',
          'body' => 'Weryfikuj ochronę i otrzymuj alerty, zanim wygaśnie.',
          'hero' => 'Utrzymuj ubezpieczenie pracowników aktualne — automatycznie',
          'lead' => 'Sprawdź, czy każdy podwykonawca ma ubezpieczenie pracowników, i otrzymaj ostrzeżenie, zanim którakolwiek polisa wygaśnie — dzięki temu wypadek nigdy nie stanie się Twoim problemem.',
          'rows' => array(
            0 => array(
              'heading' => 'Weryfikuj, zanim zaczną pracę',
              'text' => 'Z góry potwierdź ochronę ubezpieczeniową każdego podwykonawcy i przechowuj dowód w kartotece, powiązany z firmą i pracą. Brak ochrony — brak niespodzianek.',
              'points' => array(
                0 => 'Weryfikuj ubezpieczenie każdego podwykonawcy',
                1 => 'Dowody przechowywane według dostawcy',
                2 => 'Powiązane z pracami, które wykonują',
                3 => 'Oznacz każdego bez ochrony',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Ubezpieczenie pracowników · Podwykonawcy',
                'rows' => array(
                  0 => array(
                    'icon' => 'clipboard-document-check',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Aktywne',
                  ),
                  1 => array(
                    'icon' => 'clipboard-document-check',
                    'label' => 'Apex Electric',
                    'sub' => 'Aktywne',
                  ),
                  2 => array(
                    'icon' => 'exclamation-triangle',
                    'label' => 'Summit Drywall',
                    'sub' => 'Wygasa 15.07',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Alerty, które Cię chronią',
              'text' => 'Hive śledzi daty polis i ostrzega Cię, zanim ochrona wygaśnie, dzięki czemu nigdy nie odpowiadasz za nieubezpieczoną ekipę na Twojej budowie.',
              'points' => array(
                0 => 'Wcześniejsze alerty o wygaśnięciu',
                1 => 'Chroń się przed roszczeniami',
                2 => 'Zawsze gotowy na audyt',
                3 => 'Spokój na każdej budowie',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Jeden nieubezpieczony wypadek może zatopić małego wykonawcę. Hive utrzymuje ubezpieczenie aktualne, by nigdy do tego nie doszło.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'clipboard-document-check',
              'title' => 'Zweryfikowane',
              'body' => 'Ochrona potwierdzona.',
            ),
            1 => array(
              'icon' => 'user-group',
              'title' => 'Według podwykonawcy',
              'body' => 'Dowód na dostawcę.',
            ),
            2 => array(
              'icon' => 'bell-alert',
              'title' => 'Alerty o wygaśnięciu',
              'body' => 'Wczesne ostrzeżenie.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Według pracy',
              'body' => 'Powiązane z projektami.',
            ),
            4 => array(
              'icon' => 'shield-check',
              'title' => 'Chroniony',
              'body' => 'Roszczenia pokryte.',
            ),
            5 => array(
              'icon' => 'check-badge',
              'title' => 'Gotowy na audyt',
              'body' => 'Dowód pod ręką.',
            ),
          ),
          'cta' => array(
            'heading' => 'Upewnij się, że każdy podwykonawca jest ubezpieczony.',
            'sub' => 'Weryfikuj ubezpieczenie pracowników i otrzymuj alerty, zanim wygaśnie.',
          ),
        ),
        'timesheets-payroll' => array(
          'icon' => 'clock',
          'title' => 'Ewidencja czasu i płace',
          'body' => 'Zatwierdzaj godziny ekipy i wypłacaj wynagrodzenia z tego samego miejsca.',
          'hero' => 'Od godzin do wypłaty — bez arkuszy kalkulacyjnych',
          'lead' => 'Ekipy rejestrują godziny z budowy, Ty zatwierdzasz jednym dotknięciem, a płace płyną z tego samego ekranu — z kosztem robocizny trafiającym na każdą pracę.',
          'rows' => array(
            0 => array(
              'heading' => 'Godziny prosto z budowy',
              'text' => 'Twoja ekipa rejestruje czas na odpowiednią pracę i zadanie z telefonu. Przeglądasz tydzień i zatwierdzasz bez gonienia za papierowymi kartami pracy.',
              'points' => array(
                0 => 'Mobilne śledzenie czasu według pracy',
                1 => 'Zatwierdzanie ewidencji jednym dotknięciem',
                2 => 'Robocizna trafia do kosztorysu prac',
                3 => 'Koniec z papierowymi kartami pracy',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Ten tydzień · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'clock',
                    'label' => 'Greg M. · Hydraulika',
                    'sub' => '32,5 godz.',
                  ),
                  1 => array(
                    'icon' => 'clock',
                    'label' => 'Tony R. · Szkielet',
                    'sub' => '28,0 godz.',
                  ),
                  2 => array(
                    'icon' => 'clock',
                    'label' => 'Sam K. · Płytki',
                    'sub' => '18,0 godz.',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Wypłacaj z zatwierdzonych godzin',
              'text' => 'Zatwierdzony czas przechodzi prosto w płatności, z bieżącym saldem dla każdego pracownika i zapisami zgodnymi z Twoimi księgami.',
              'points' => array(
                0 => 'Płace z zatwierdzonych godzin',
                1 => 'Bieżące saldo dla każdego pracownika',
                2 => 'Zapisy zgodne z Twoimi księgami',
                3 => 'Płać ekipie na czas',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Gdy godziny, kosztorys i płace dzielą jeden przepływ, Twoja ekipa dostaje właściwą wypłatę, a koszty prac pozostają rzetelne.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'clock',
              'title' => 'Mobilny czas',
              'body' => 'Rejestruj czas z budowy.',
            ),
            1 => array(
              'icon' => 'check-circle',
              'title' => 'Zatwierdzaj',
              'body' => 'Przejrzyj godziny jednym dotknięciem.',
            ),
            2 => array(
              'icon' => 'banknotes',
              'title' => 'Płace',
              'body' => 'Wypłacaj z zatwierdzonego czasu.',
            ),
            3 => array(
              'icon' => 'scale',
              'title' => 'Salda',
              'body' => 'Sumy dla każdego pracownika.',
            ),
            4 => array(
              'icon' => 'calculator',
              'title' => 'Ujęte w kosztach',
              'body' => 'Robocizna na właściwej pracy.',
            ),
            5 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Zsynchronizowane',
              'body' => 'Zgodne z Twoimi księgami.',
            ),
          ),
          'cta' => array(
            'heading' => 'Zabierz płace ze stołu w kuchni.',
            'sub' => 'Godziny z budowy, zatwierdzanie jednym dotknięciem i wypłata z tego samego miejsca.',
          ),
        ),
      ),
    ),
    'estimates' => array(
      'label' => 'Kosztorysy i dokumenty',
      'eyebrow' => 'Kosztorysy i dokumenty',
      'grid_heading' => 'Wszystko, czego trzeba, by domknąć zlecenie',
      'cards' => array(
        'ai-estimates' => array(
          'icon' => 'document-text',
          'title' => 'Kosztorysy AI',
          'body' => 'Twórz pozycjonowane kosztorysy w kilka minut i dopracuj po swojemu.',
          'hero' => 'Stwórz wygrywający kosztorys w kilka minut',
          'lead' => 'Opisz zlecenie, a AI stworzy pozycjonowany kosztorys, który dopracujesz, ubrandujesz i wyślesz — składasz więcej ofert w krótszym czasie.',
          'rows' => array(
            0 => array(
              'heading' => 'Od zakresu do kosztorysu, szybko',
              'text' => 'Wpisz zakres lub zacznij od dawnego zlecenia, a Hive stworzy pozycje z ilościami i cenami. Poprawiasz, brandujesz, wysyłasz.',
              'points' => array(
                0 => 'AI tworzy dla Ciebie pozycje kosztorysu',
                1 => 'Zacznij od zera lub od dawnego zlecenia',
                2 => 'Dowolnie zmieniaj ilości i ceny',
                3 => 'Wyślij z brandingiem, gotowe do podpisu',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Szkic · Remont kuchni',
                'rows' => array(
                  0 => array(
                    'label' => 'Szafki i montaż',
                    'value' => '$8,400',
                  ),
                  1 => array(
                    'label' => 'Blaty',
                    'value' => '$3,950',
                  ),
                  2 => array(
                    'label' => 'Płytki i lamperia',
                    'value' => '$2,100',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Więcej ofert, więcej wygranych',
              'text' => 'Szybsze kosztorysy oznaczają, że odpowiadasz, póki lead jest gorący. Elegancki wygląd wyróżnia Cię na tle konkurenta wciąż bazgrzącego w notesie.',
              'points' => array(
                0 => 'Odpowiadaj, póki leady są gorące',
                1 => 'Wyglądaj bardziej profesjonalnie niż reszta',
                2 => 'Wykorzystuj ponownie zwycięskie szablony',
                3 => 'Zamieniaj akceptacje w aktywne zlecenia',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Wykonawca, który pierwszy wyśle czysty kosztorys, zwykle wygrywa zlecenie. Hive pomaga Ci być tym wykonawcą.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'sparkles',
              'title' => 'Tworzone przez AI',
              'body' => 'Pozycje w kilka minut.',
            ),
            1 => array(
              'icon' => 'document-duplicate',
              'title' => 'Szablony',
              'body' => 'Wykorzystaj to, co wygrywa.',
            ),
            2 => array(
              'icon' => 'pencil',
              'title' => 'Edytowalne',
              'body' => 'Dopracuj każdą pozycję.',
            ),
            3 => array(
              'icon' => 'swatch',
              'title' => 'Z brandingiem',
              'body' => 'Wygląda jak Ty.',
            ),
            4 => array(
              'icon' => 'pencil-square',
              'title' => 'Gotowe do e-podpisu',
              'body' => 'Akceptacja online.',
            ),
            5 => array(
              'icon' => 'arrow-path',
              'title' => 'W zlecenia',
              'body' => 'Akceptacje ruszają pracę.',
            ),
          ),
          'cta' => array(
            'heading' => 'Składaj oferty szybciej i wygrywaj więcej.',
            'sub' => 'Niech AI stworzy kosztorys, byś wysłał go pierwszy.',
          ),
        ),
        'invoices' => array(
          'icon' => 'document-currency-dollar',
          'title' => 'Faktury',
          'body' => 'Wysyłaj markowe faktury i otrzymuj zapłatę za wykonaną pracę.',
          'hero' => 'Fakturuj pracę i otrzymuj zapłatę szybciej',
          'lead' => 'Wysyłaj czyste, markowe faktury prosto z zaakceptowanego zakresu — częściowe lub końcowe — a pieniądze wpłyną bez zbędnej korespondencji.',
          'rows' => array(
            0 => array(
              'heading' => 'Fakturuj to, na co się umówiliście',
              'text' => 'Fakturuj bezpośrednio z zaakceptowanego kosztorysu lub zleceń zmian. Bez przepisywania, bez sporów o zakres.',
              'points' => array(
                0 => 'Fakturuj z zaakceptowanego zakresu',
                1 => 'Fakturowanie częściowe lub końcowe',
                2 => 'Pozycjonowane i czytelne',
                3 => 'Powiązane ze zleceniem i księgami',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Faktura #318',
                'rows' => array(
                  0 => array(
                    'label' => 'Częściowa · stan surowy',
                    'value' => '$4,200',
                  ),
                  1 => array(
                    'label' => 'Materiały',
                    'value' => '$1,180',
                  ),
                  2 => array(
                    'label' => 'Kwota do zapłaty',
                    'value' => '$5,380',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Jasne dla klientów, czyste dla Ciebie',
              'text' => 'Klienci widzą dokładnie, za co płacą, z terminem płatności na wierzchu. Ty widzisz, ile pozostaje do zapłaty, jednym rzutem oka.',
              'points' => array(
                0 => 'Jasne terminy płatności, którym klienci ufają',
                1 => 'Śledź, co pozostaje do zapłaty',
                2 => 'Powiązane z kalkulacją kosztów zlecenia',
                3 => 'Zapis tego, co opłacone',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Profesjonalna faktura powiązana z uzgodnionym zakresem jest szybciej opłacana i rodzi mniej sporów.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-currency-dollar',
              'title' => 'Z brandingiem',
              'body' => 'Wyglądaj profesjonalnie.',
            ),
            1 => array(
              'icon' => 'arrow-path',
              'title' => 'Z zakresu',
              'body' => 'Bez przepisywania.',
            ),
            2 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Terminy płatności',
              'body' => 'Jasne dla klientów.',
            ),
            3 => array(
              'icon' => 'scale',
              'title' => 'Do zapłaty',
              'body' => 'Zobacz, ile się należy.',
            ),
            4 => array(
              'icon' => 'calculator',
              'title' => 'Powiązane z kosztami',
              'body' => 'Powiązane ze zleceniem.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Jasność płatności',
              'body' => 'Wiedz, co rozliczone.',
            ),
          ),
          'cta' => array(
            'heading' => 'Otrzymuj zapłatę za wykonaną pracę.',
            'sub' => 'Markowe faktury z uzgodnionego wcześniej zakresu.',
          ),
        ),
        'e-signatures' => array(
          'icon' => 'pencil-square',
          'title' => 'E-podpisy',
          'body' => 'Zbieraj prawnie wiążące podpisy klientów z dowolnego urządzenia.',
          'hero' => 'Akceptacja z dowolnego urządzenia, w kilka sekund',
          'lead' => 'Zbieraj prawnie wiążące podpisy na kosztorysach, zleceniach zmian i umowach z dowolnego telefonu lub komputera — bez drukowania i skanowania.',
          'rows' => array(
            0 => array(
              'heading' => 'Akceptacja bez papierologii',
              'text' => 'Wyślij dokument, a klient podpisze jednym dotknięciem, gdziekolwiek jest. Akceptacja jest zapisywana i oznaczana czasem natychmiast.',
              'points' => array(
                0 => 'Podpis na dowolnym urządzeniu',
                1 => 'Prawnie wiążący i oznaczony czasem',
                2 => 'Bez drukowania i skanowania',
                3 => 'Akceptacja zapisana natychmiast',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Podpis · Kosztorys #1042',
                'rows' => array(
                  0 => array(
                    'icon' => 'pencil-square',
                    'label' => 'Wysłano do klienta',
                    'sub' => 'Pon. 9:10',
                  ),
                  1 => array(
                    'icon' => 'eye',
                    'label' => 'Otwarto',
                    'sub' => 'Pon. 9:14',
                  ),
                  2 => array(
                    'icon' => 'check-badge',
                    'label' => 'Podpisano',
                    'sub' => 'Pon. 9:21',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Chroń każde uzgodnienie',
              'text' => 'Podpisane dokumenty są przechowywane przy zleceniu, więc zawsze masz dowód, na co i kiedy się umówiono — koniec z \'słowo przeciw słowu\'.',
              'points' => array(
                0 => 'Przechowywane przy zleceniu',
                1 => 'Dowód tego, co uzgodniono',
                2 => 'Łatwe do odnalezienia później',
                3 => 'Utrzymuje wszystkich w uczciwości',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Podpis, który możesz udowodnić, to różnica między otrzymaniem zapłaty a pokryciem kosztu z własnej kieszeni.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'pencil-square',
              'title' => 'E-podpis',
              'body' => 'Dotknij, by zaakceptować.',
            ),
            1 => array(
              'icon' => 'device-phone-mobile',
              'title' => 'Dowolne urządzenie',
              'body' => 'Telefon lub komputer.',
            ),
            2 => array(
              'icon' => 'shield-check',
              'title' => 'Wiążący',
              'body' => 'Prawnie ważny.',
            ),
            3 => array(
              'icon' => 'clock',
              'title' => 'Oznaczony czasem',
              'body' => 'Kiedy to nastąpiło.',
            ),
            4 => array(
              'icon' => 'folder',
              'title' => 'Przy zleceniu',
              'body' => 'Przechowywany z dowodem.',
            ),
            5 => array(
              'icon' => 'eye',
              'title' => 'Śledzenie otwarć',
              'body' => 'Zobacz, kiedy wyświetlono.',
            ),
          ),
          'cta' => array(
            'heading' => 'Uzyskaj akceptację bez papierologii.',
            'sub' => 'Prawnie wiążące podpisy z dowolnego urządzenia, przechowywane przy zleceniu.',
          ),
        ),
        'change-orders' => array(
          'icon' => 'arrows-right-left',
          'title' => 'Zlecenia zmian',
          'body' => 'Rejestruj zmiany zakresu i ceny, aby nic nie było robione za darmo.',
          'hero' => 'Otrzymuj zapłatę za każdą zmianę',
          'lead' => 'Rejestruj zmiany zakresu i ceny w chwili, gdy się pojawią, zatwierdzaj je i zadbaj, aby żadna dodatkowa praca nie została bez zapłaty.',
          'rows' => array(
            0 => array(
              'heading' => 'Udokumentuj zmianę',
              'text' => 'Gdy zmienia się zakres, spisz jasne zlecenie zmiany z dodatkową pracą i kosztem. Klient zatwierdza, zanim sięgniesz po narzędzia.',
              'points' => array(
                0 => 'Rejestruj dodatkowy zakres i koszt',
                1 => 'Zatwierdzone przed rozpoczęciem prac',
                2 => 'Jasny zapis tego, co się zmieniło',
                3 => 'Koniec z darmowymi ulepszeniami',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Zlecenie zmiany · Oświetlenie punktowe',
                'rows' => array(
                  0 => array(
                    'label' => '6 opraw podtynkowych',
                    'value' => '+1 250 $',
                  ),
                  1 => array(
                    'label' => 'Wpływ na harmonogram',
                    'value' => '+1 dzień',
                  ),
                  2 => array(
                    'label' => 'Status',
                    'value' => 'Zatwierdzone',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Trafia na fakturę',
              'text' => 'Zatwierdzone zlecenia zmian automatycznie trafiają do zadania i na kolejną fakturę, więc dodatkowa praca zawsze pojawia się na rachunku.',
              'points' => array(
                0 => 'Wlicza się w sumę zadania',
                1 => 'Rozliczone na kolejnej fakturze',
                2 => 'Chroni Twoją marżę',
                3 => 'Bez niespodzianek na koniec',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Marżę zabija ta praca, której nikt nie spisał. Hive dba o to, by została spisana—i rozliczona.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Rejestruj',
              'body' => 'Zakres i cena.',
            ),
            1 => array(
              'icon' => 'pencil-square',
              'title' => 'Zatwierdzone',
              'body' => 'Podpisane przed pracą.',
            ),
            2 => array(
              'icon' => 'document-text',
              'title' => 'Udokumentowane',
              'body' => 'Jasny zapis.',
            ),
            3 => array(
              'icon' => 'banknotes',
              'title' => 'Rozliczone',
              'body' => 'Na kolejnej fakturze.',
            ),
            4 => array(
              'icon' => 'calculator',
              'title' => 'Chroni marżę',
              'body' => 'Nic za darmo.',
            ),
            5 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Harmonogram',
              'body' => 'Pokazuje wpływ na czas.',
            ),
          ),
          'cta' => array(
            'heading' => 'Przestań wykonywać dodatkową pracę za darmo.',
            'sub' => 'Rejestruj każdą zmianę, zatwierdź ją i rozlicz.',
          ),
        ),
        'bids-proposals' => array(
          'icon' => 'clipboard-document-list',
          'title' => 'Oferty i propozycje',
          'body' => 'Śledź każdą ofertę od wysłania do podpisu i przypominaj o sobie na czas.',
          'hero' => 'Nie pozwól, by oferta ostygła',
          'lead' => 'Śledź każdą propozycję od wysłania do podpisu, sprawdzaj, co jest w toku, i przypominaj o sobie w odpowiednim momencie—aby więcej ofert zamieniało się w zlecenia.',
          'rows' => array(
            0 => array(
              'heading' => 'Cały lejek w zasięgu wzroku',
              'text' => 'Zobacz każdą wysłaną ofertę, na jakim jest etapie i jak długo już czeka. Te, które wymagają przypomnienia, są oczywiste.',
              'points' => array(
                0 => 'Śledź oferty od wysłania do podpisu',
                1 => 'Sprawdzaj, co jest w toku',
                2 => 'Wiedz, które wymagają przypomnienia',
                3 => 'Mierz swój wskaźnik wygranych',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Otwarte oferty',
                'rows' => array(
                  0 => array(
                    'icon' => 'clipboard-document-list',
                    'label' => 'Remont Maple St',
                    'sub' => 'Wysłano · 3 dni temu',
                  ),
                  1 => array(
                    'icon' => 'eye',
                    'label' => 'Rozbudowa Oak Ave',
                    'sub' => 'Wyświetlono · przypomnij',
                  ),
                  2 => array(
                    'icon' => 'check-badge',
                    'label' => 'Taras Pine Ct',
                    'sub' => 'Podpisano',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Przypominaj o sobie w odpowiednim momencie',
              'text' => 'Hive przypomina, gdy propozycja ucichła, aby praca, o którą złożyłeś ofertę, nie trafiła do wykonawcy, który po prostu oddzwonił.',
              'points' => array(
                0 => 'Przypomnienia o kontakcie w porę',
                1 => 'Zobacz, kiedy oferta została otwarta',
                2 => 'Zamykaj więcej z tego, co wysyłasz',
                3 => 'Przestań tracić pracę przez milczenie',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Większość ofert przegrywa przez milczenie, nie cenę. Kontakt w porę wygrywa zlecenia, które już zdobyłeś.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'clipboard-document-list',
              'title' => 'Lejek',
              'body' => 'Każda oferta śledzona.',
            ),
            1 => array(
              'icon' => 'eye',
              'title' => 'Śledzenie otwarć',
              'body' => 'Zobacz, kiedy wyświetlono.',
            ),
            2 => array(
              'icon' => 'bell-alert',
              'title' => 'Przypomnienia',
              'body' => 'Powiadamiane w porę.',
            ),
            3 => array(
              'icon' => 'check-badge',
              'title' => 'Wygrane',
              'body' => 'Podpisane w zlecenia.',
            ),
            4 => array(
              'icon' => 'chart-bar',
              'title' => 'Wskaźnik wygranych',
              'body' => 'Mierz sukcesy.',
            ),
            5 => array(
              'icon' => 'arrow-path',
              'title' => 'Do projektów',
              'body' => 'Ruszaj z pracą szybko.',
            ),
          ),
          'cta' => array(
            'heading' => 'Zamieniaj więcej ofert w podpisane zlecenia.',
            'sub' => 'Śledź każdą propozycję i przypomnij o sobie, zanim ostygnie.',
          ),
        ),
        'lien-waivers' => array(
          'icon' => 'document-check',
          'title' => 'Zrzeczenia praw zastawu',
          'body' => 'Wysyłaj i zbieraj podpisane zrzeczenia przez bezpieczne linki bez zakładania konta.',
          'hero' => 'Zrzeczenia praw zastawu — podpisane i w aktach',
          'lead' => 'Wysyłaj zrzeczenia i zbieraj podpisy przez bezpieczne linki, bez kont i drukowania—dbając, by dokumenty chroniące płatność zawsze były gotowe.',
          'rows' => array(
            0 => array(
              'heading' => 'Wyślij i podpisz w kilka sekund',
              'text' => 'Wygeneruj właściwe zrzeczenie, wyślij bezpieczny link i pozwól podwykonawcy podpisać na dowolnym urządzeniu. Wraca powiązane z zadaniem i płatnością.',
              'points' => array(
                0 => 'Zrzeczenia warunkowe i bezwarunkowe',
                1 => 'Bezpieczne linki bez konta',
                2 => 'Podpisz z dowolnego telefonu',
                3 => 'Powiązane z zadaniem i płatnością',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Zrzeczenia · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'document-check',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Podpisano',
                  ),
                  1 => array(
                    'icon' => 'document-check',
                    'label' => 'Apex Electric',
                    'sub' => 'Podpisano',
                  ),
                  2 => array(
                    'icon' => 'clock',
                    'label' => 'Summit Drywall',
                    'sub' => 'Oczekuje',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Dowód, gdy się liczy',
              'text' => 'Podpisane zrzeczenia są przechowywane przy zadaniu, więc gdy generalny wykonawca lub bank o nie poprosi, odpowiedź jest o jedno kliknięcie.',
              'points' => array(
                0 => 'Przechowywane przy zadaniu',
                1 => 'Jedno kliknięcie, by okazać',
                2 => 'Śledź, kto jeszcze zalega',
                3 => 'Chroń swoje prawo do zapłaty',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Zrzeczenia zebrane w porę utrzymują przepływ płatności i chronią projekty przed zastawem.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-check',
              'title' => 'Właściwe zrzeczenie',
              'body' => 'Warunkowe lub nie.',
            ),
            1 => array(
              'icon' => 'link',
              'title' => 'Bezpieczne linki',
              'body' => 'Konto niepotrzebne.',
            ),
            2 => array(
              'icon' => 'device-phone-mobile',
              'title' => 'Każde urządzenie',
              'body' => 'Podpisz z telefonu.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Przy zadaniu',
              'body' => 'Zapisane z dowodem.',
            ),
            4 => array(
              'icon' => 'clock',
              'title' => 'Śledź status',
              'body' => 'Kto jeszcze zalega.',
            ),
            5 => array(
              'icon' => 'shield-check',
              'title' => 'Chronione',
              'body' => 'Płatności bezpieczne.',
            ),
          ),
          'cta' => array(
            'heading' => 'Trzymaj każde zrzeczenie podpisane i w aktach.',
            'sub' => 'Bezpieczne linki, które Twoi podwykonawcy podpiszą z każdego miejsca.',
          ),
        ),
      ),
    ),
    'clients' => array(
      'label' => 'Kontakty i klienci',
      'eyebrow' => 'Kontakty i klienci',
      'grid_heading' => 'Od pierwszego telefonu do zadowolonego właściciela domu',
      'cards' => array(
        'lead-pipeline' => array(
          'icon' => 'magnifying-glass-plus',
          'title' => 'Lejek leadów',
          'body' => 'Zbieraj i śledź nowe okazje, aby żadna nie przepadła.',
          'hero' => 'Złap każdego leada, zanim ci ucieknie',
          'lead' => 'Zbieraj nowe okazje w jednym lejku, śledź, na jakim etapie jest każda, i kontaktuj się na czas, aby zdobyte z trudem telefony zamieniały się w zlecenia.',
          'rows' => array(
            0 => array(
              'heading' => 'Jedno miejsce na każdą okazję',
              'text' => 'Nowe zapytania trafiają do lejka z potrzebnymi szczegółami. Przesuwaj je przez etapy, by zawsze wiedzieć, co gorące, a co następne.',
              'points' => array(
                0 => 'Zbieraj leady z telefonów i formularzy',
                1 => 'Śledź każdego przez jasne etapy',
                2 => 'Dodawaj notatki, wartość i kolejne kroki',
                3 => 'Nic nie przepada bez śladu',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Lejek',
                'rows' => array(
                  0 => array(
                    'icon' => 'magnifying-glass-plus',
                    'label' => 'Remont Maple St',
                    'sub' => 'Nowy · szac. 48 tys. $',
                  ),
                  1 => array(
                    'icon' => 'phone',
                    'label' => 'Rozbudowa Oak Ave',
                    'sub' => 'Skontaktowano',
                  ),
                  2 => array(
                    'icon' => 'clipboard-document-list',
                    'label' => 'Taras Pine Ct',
                    'sub' => 'Oferta wysłana',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Kontaktuj się i wygrywaj',
              'text' => 'Przypomnienia trzymają cię na bieżąco z każdym leadem, więc odzywasz się, gdy zainteresowanie jest wysokie — a nie tydzień po tym, jak zatrudnili kogoś innego.',
              'points' => array(
                0 => 'Przypomnienia o kontakcie na czas',
                1 => 'Odzywaj się, gdy zainteresowanie jest wysokie',
                2 => 'Zobacz konwersję od razu',
                3 => 'Wygrywaj więcej tego, o co walczysz',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Leady szybko stygną. Lejek, który cię popycha, zamienia więcej pierwszych telefonów w podpisane zlecenia.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'magnifying-glass-plus',
              'title' => 'Zbieranie',
              'body' => 'Każda okazja w środku.',
            ),
            1 => array(
              'icon' => 'view-columns',
              'title' => 'Etapy',
              'body' => 'Śledź każdy krok.',
            ),
            2 => array(
              'icon' => 'bell-alert',
              'title' => 'Kontakty',
              'body' => 'Przypomnienie na czas.',
            ),
            3 => array(
              'icon' => 'pencil-square',
              'title' => 'Notatki',
              'body' => 'Kontekst przy każdej.',
            ),
            4 => array(
              'icon' => 'chart-bar',
              'title' => 'Konwersja',
              'body' => 'Zobacz skuteczność.',
            ),
            5 => array(
              'icon' => 'arrow-path',
              'title' => 'Na klientów',
              'body' => 'Wygrane jednym kliknięciem.',
            ),
          ),
          'cta' => array(
            'heading' => 'Zamień więcej pierwszych telefonów w zlecenia.',
            'sub' => 'Zbieraj każdego leada i kontaktuj się, zanim ostygnie.',
          ),
        ),
        'lead-to-client' => array(
          'icon' => 'arrow-path',
          'title' => 'Lead na klienta',
          'body' => 'Zamień wygrane leady w klientów i projekty jednym kliknięciem.',
          'hero' => 'Zdobyłeś zlecenie? Zacznij je jednym kliknięciem',
          'lead' => 'Zamień wygranego leada w klienta i aktywny projekt od razu — przenosząc kontakt, notatki i wycenę, by nic nie trzeba było wpisywać ponownie.',
          'rows' => array(
            0 => array(
              'heading' => 'Bez przepisywania, bez utraty kontekstu',
              'text' => 'Gdy lead mówi tak, zamień go w klienta i projekt jednym kliknięciem. Jego dane, historia i wycena przenoszą się automatycznie.',
              'points' => array(
                0 => 'Zamień leada w klienta od razu',
                1 => 'Utwórz projekt w tym samym momencie',
                2 => 'Przenieś kontakt, notatki i wycenę',
                3 => 'Zero podwójnego wpisywania danych',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Konwersja · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'user-plus',
                    'label' => 'Klient utworzony',
                    'sub' => 'Państwo Henderson',
                  ),
                  1 => array(
                    'icon' => 'folder-plus',
                    'label' => 'Projekt rozpoczęty',
                    'sub' => 'Remont kuchni',
                  ),
                  2 => array(
                    'icon' => 'document-text',
                    'label' => 'Wycena dołączona',
                    'sub' => '48 000 $',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Rusz pełną parą',
              'text' => 'Nowy projekt jest gotowy do planowania, kosztorysowania i informowania klienta od pierwszej minuty, więc zaczynasz mocno, zamiast wszystko konfigurować.',
              'points' => array(
                0 => 'Projekt gotowy do planowania',
                1 => 'Kosztorysowanie startuje od razu',
                2 => 'Portal klienta dostępny',
                3 => 'Mocny, uporządkowany start',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Chwila, gdy klient mówi tak, to chwila na uporządkowanie się — nie na wpisywanie danych.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'arrow-path',
              'title' => 'Jedno kliknięcie',
              'body' => 'Lead na klienta.',
            ),
            1 => array(
              'icon' => 'folder-plus',
              'title' => 'Projekt',
              'body' => 'Utworzony od razu.',
            ),
            2 => array(
              'icon' => 'document-text',
              'title' => 'Wycena',
              'body' => 'Przenosi się z leadem.',
            ),
            3 => array(
              'icon' => 'clipboard',
              'title' => 'Historia zachowana',
              'body' => 'Wszystkie notatki przenoszą się.',
            ),
            4 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Gotowy do planowania',
              'body' => 'Zacznij planować.',
            ),
            5 => array(
              'icon' => 'computer-desktop',
              'title' => 'Portal włączony',
              'body' => 'Klient może go zobaczyć.',
            ),
          ),
          'cta' => array(
            'heading' => 'Zacznij zlecenie w chwili, gdy mówią tak.',
            'sub' => 'Zamień leada w klienta i projekt jednym kliknięciem.',
          ),
        ),
        'client-directory' => array(
          'icon' => 'identification',
          'title' => 'Katalog klientów',
          'body' => 'Każdy właściciel domu z pełną historią zleceń i kontaktu.',
          'hero' => 'Każdy klient i cała jego historia',
          'lead' => 'Trzymaj każdego właściciela domu w jednym katalogu z danymi kontaktowymi, projektami, płatnościami i rozmowami — by zawsze mieć pełny obraz.',
          'rows' => array(
            0 => array(
              'heading' => 'Pełny obraz w jednym miejscu',
              'text' => 'Otwórz klienta i zobacz każde wykonane zlecenie, ile zapłacił i całą historię rozmów. Koniec z szukaniem po różnych aplikacjach.',
              'points' => array(
                0 => 'Wszystkie projekty na klienta',
                1 => 'Historia płatności i salda',
                2 => 'Pełny wątek rozmów',
                3 => 'Dane kontaktowe zawsze aktualne',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Państwo Henderson',
                'rows' => array(
                  0 => array(
                    'icon' => 'folder',
                    'label' => 'Remont kuchni',
                    'sub' => 'W toku',
                  ),
                  1 => array(
                    'icon' => 'folder',
                    'label' => 'Łazienka · 2024',
                    'sub' => 'Zakończone',
                  ),
                  2 => array(
                    'icon' => 'banknotes',
                    'label' => 'Rozliczono łącznie',
                    'sub' => '71 500 $',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Powtarzalne zlecenia bez wysiłku',
              'text' => 'Gdy dawny klient dzwoni ponownie, już znasz jego dom, preferencje i historię — więc kolejne zlecenie zaczyna się od właściwej stopy.',
              'points' => array(
                0 => 'Rozpoznaj powracających klientów od razu',
                1 => 'Odwołuj się do dawnych prac i notatek',
                2 => 'Personalizuj każdy kontakt',
                3 => 'Zdobywaj więcej powtarzalnych zleceń',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Twoje najlepsze leady to dawni klienci. Znajomość ich historii ułatwia zdobycie i prowadzenie kolejnego zlecenia.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'identification',
              'title' => 'Katalog',
              'body' => 'Każdy klient w jednym miejscu.',
            ),
            1 => array(
              'icon' => 'folder',
              'title' => 'Wszystkie projekty',
              'body' => 'Dawne i obecne.',
            ),
            2 => array(
              'icon' => 'banknotes',
              'title' => 'Płatności',
              'body' => 'Pełna historia finansów.',
            ),
            3 => array(
              'icon' => 'chat-bubble-left-right',
              'title' => 'Rozmowy',
              'body' => 'Każdy wątek.',
            ),
            4 => array(
              'icon' => 'phone',
              'title' => 'Kontakty',
              'body' => 'Zawsze aktualne.',
            ),
            5 => array(
              'icon' => 'arrow-path',
              'title' => 'Powtarzalne zlecenia',
              'body' => 'Szybki start.',
            ),
          ),
          'cta' => array(
            'heading' => 'Miej każdego klienta pod ręką.',
            'sub' => 'Pełna historia zleceń, płatności i rozmów w jednym katalogu.',
          ),
        ),
        'homeowner-portal' => array(
          'icon' => 'computer-desktop',
          'title' => 'Portal właściciela domu',
          'body' => 'Okno na projekt w czasie rzeczywistym, które klienci mogą sprawdzić o każdej porze.',
          'hero' => 'Daj klientom okno na ich projekt',
          'lead' => 'Prywatny portal w czasie rzeczywistym pozwala właścicielom domów zobaczyć status, harmonogram, zdjęcia, dokumenty i płatności w każdej chwili — więc dzwonią rzadziej i bardziej ci ufają.',
          'rows' => array(
            0 => array(
              'heading' => 'Zawsze na bieżąco',
              'text' => 'Klienci otwierają bezpieczny link i widzą dokładnie, na jakim etapie jest ich projekt. Mniej SMS-ów "jakieś nowości?", więcej zaufania do ciebie.',
              'points' => array(
                0 => 'Status i harmonogram na żywo',
                1 => 'Zdjęcia z budowy i postępy',
                2 => 'Dokumenty i płatności',
                3 => 'Bezpiecznie, bez aplikacji',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Widok klienta · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'eye',
                    'label' => 'Status',
                    'sub' => '62% · instalacja elektryczna',
                  ),
                  1 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Następna wizyta',
                    'sub' => 'wt. 30.06',
                  ),
                  2 => array(
                    'icon' => 'photo',
                    'label' => 'Nowe zdjęcia',
                    'sub' => 'dodano 4',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Mniej prowadzenia za rękę',
              'text' => 'Gdy klienci sami znajdują odpowiedzi, spędzasz mniej czasu przy telefonie, a więcej na budowie. Aktualizacje płyną bez dodatkowego wysiłku.',
              'points' => array(
                0 => 'Mniej telefonów i SMS-ów o status',
                1 => 'Aktualizacje wysyłane automatycznie',
                2 => 'Wyróżnia cię na tle konkurencji',
                3 => 'Zadowoleni, spokojniejsi klienci',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Klient, który widzi postępy, to klient, który ci ufa — i o wiele rzadziej przerywa ci dzień.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'eye',
              'title' => 'Status na żywo',
              'body' => 'Zawsze aktualny.',
            ),
            1 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Harmonogram',
              'body' => 'Co dalej.',
            ),
            2 => array(
              'icon' => 'photo',
              'title' => 'Zdjęcia',
              'body' => 'Zdjęcia postępów.',
            ),
            3 => array(
              'icon' => 'pencil-square',
              'title' => 'Dokumenty',
              'body' => 'Przejrzyj i podpisz.',
            ),
            4 => array(
              'icon' => 'banknotes',
              'title' => 'Płatności',
              'body' => 'Zobacz salda.',
            ),
            5 => array(
              'icon' => 'finger-print',
              'title' => 'Bezpieczne',
              'body' => 'Prywatny link.',
            ),
          ),
          'cta' => array(
            'heading' => 'Daj klientom portal, który pokochają.',
            'sub' => 'Dostęp do projektu w czasie rzeczywistym, który ogranicza telefony o status.',
          ),
        ),
        'schedule-sharing' => array(
          'icon' => 'paper-airplane',
          'title' => 'Udostępnianie harmonogramu',
          'body' => 'Wysyłaj aktualizacje "co dalej" na żywo bez kiwnięcia palcem.',
          'hero' => 'Udostępniaj "co dalej" — automatycznie',
          'lead' => 'Wyślij klientom link do harmonogramu na żywo, który zawsze pokazuje kolejną wizytę i kamień milowy, więc są na bieżąco, a ty nie wysyłasz żadnej aktualizacji.',
          'rows' => array(
            0 => array(
              'heading' => 'Link na żywo, a nie telefon',
              'text' => 'Klienci dostają harmonogram, który sam się aktualizuje. Gdy termin się przesuwa, ich widok też — bez nowego maila, bez niezręcznego telefonu.',
              'points' => array(
                0 => 'Widok "co dalej" na żywo dla klientów',
                1 => 'Aktualizacja w chwili zmiany terminów',
                2 => 'Bez ręcznych wiadomości o zmianach',
                3 => 'Działa na każdym urządzeniu',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Harmonogram klienta',
                'rows' => array(
                  0 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Instalacja elektryczna — surowa',
                    'sub' => 'wt. 30.06',
                  ),
                  1 => array(
                    'icon' => 'clipboard-document-check',
                    'label' => 'Inspekcja',
                    'sub' => 'czw. 02.07',
                  ),
                  2 => array(
                    'icon' => 'swatch',
                    'label' => 'Początek wykończeń',
                    'sub' => 'pon. 06.07',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Mniej niespodzianek, mniej telefonów',
              'text' => 'Gdy klienci widzą plan, przestają pytać, a zaczynają ufać. Zmiany są komunikowane w chwili, gdy się pojawiają.',
              'points' => array(
                0 => 'Klienci zawsze znają plan',
                1 => 'Zmiany komunikowane natychmiast',
                2 => 'Mniej telefonów "kiedy przyjeżdżacie?"',
                3 => 'Bardziej profesjonalne doświadczenie',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Większość frustracji klientów to po prostu brak wiedzy. Harmonogram na żywo rozwiązuje to, nie dokładając ci pracy.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'paper-airplane',
              'title' => 'Link na żywo',
              'body' => 'Zawsze aktualny.',
            ),
            1 => array(
              'icon' => 'bolt',
              'title' => 'Auto-aktualizacja',
              'body' => 'Gdy zmieniają się terminy.',
            ),
            2 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Co dalej',
              'body' => 'Nadchodzące wizyty.',
            ),
            3 => array(
              'icon' => 'flag',
              'title' => 'Kamienie milowe',
              'body' => 'Ważne momenty widoczne.',
            ),
            4 => array(
              'icon' => 'device-phone-mobile',
              'title' => 'Każde urządzenie',
              'body' => 'Bez aplikacji.',
            ),
            5 => array(
              'icon' => 'face-smile',
              'title' => 'Mniej telefonów',
              'body' => 'Klienci sami się obsługują.',
            ),
          ),
          'cta' => array(
            'heading' => 'Informuj klientów na autopilocie.',
            'sub' => 'Link do harmonogramu na żywo, który sam się aktualizuje.',
          ),
        ),
        'contact-sync' => array(
          'icon' => 'at-symbol',
          'title' => 'Synchronizacja kontaktów',
          'body' => 'Kontakty spływają z twojej poczty, więc dane są zawsze aktualne.',
          'hero' => 'Kontakty, które same się aktualizują',
          'lead' => 'Hive pobiera kontakty z twojej poczty, więc dane klientów i dostawców są aktualne, a ty nie musisz utrzymywać osobnej książki adresowej.',
          'rows' => array(
            0 => array(
              'heading' => 'Koniec z podwójnym wpisywaniem',
              'text' => 'Nowe osoby, do których piszesz maile, pojawiają się w Hive z danymi, gotowe do przypisania do potencjalnego klienta, klienta lub dostawcy. Twoje dane budują się same.',
              'points' => array(
                0 => 'Kontakty spływają z poczty',
                1 => 'Przypisz do leadów, klientów lub dostawców',
                2 => 'Dane pozostają aktualne automatycznie',
                3 => 'Brak osobnej książki adresowej',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Zsynchronizowane kontakty',
                'rows' => array(
                  0 => array(
                    'icon' => 'at-symbol',
                    'label' => 'J. Henderson',
                    'sub' => 'Klient · Maple St',
                  ),
                  1 => array(
                    'icon' => 'at-symbol',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Dostawca',
                  ),
                  2 => array(
                    'icon' => 'at-symbol',
                    'label' => 'Inspektor miejski',
                    'sub' => 'Kontakt',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Wszyscy powiązani z pracą',
              'text' => 'Ponieważ kontakty są powiązane z pracami i rozmowami, zawsze wiesz, jak każda osoba pasuje do całości — i sięgasz po nią jednym dotknięciem.',
              'points' => array(
                0 => 'Powiązane z pracami i wątkami',
                1 => 'Sięgnij po kogokolwiek jednym dotknięciem',
                2 => 'Dane pozostają dokładne',
                3 => 'Mniej administracji, więcej budowania',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Książka adresowa, której nigdy nie musisz utrzymywać, to ta, która jest naprawdę aktualna, gdy jej potrzebujesz.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'at-symbol',
              'title' => 'Synchronizacja poczty',
              'body' => 'Kontakty spływają.',
            ),
            1 => array(
              'icon' => 'user-plus',
              'title' => 'Auto-dodawanie',
              'body' => 'Nowe osoby zapisane.',
            ),
            2 => array(
              'icon' => 'arrow-path',
              'title' => 'Aktualne',
              'body' => 'Dane zawsze świeże.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Powiązane',
              'body' => 'Przypisane do prac.',
            ),
            4 => array(
              'icon' => 'phone',
              'title' => 'Kontakt jednym dotknięciem',
              'body' => 'Szybko zadzwoń lub napisz.',
            ),
            5 => array(
              'icon' => 'sparkles',
              'title' => 'Mniej administracji',
              'body' => 'Bez ręcznego wpisywania.',
            ),
          ),
          'cta' => array(
            'heading' => 'Przestań prowadzić książkę adresową.',
            'sub' => 'Pozwól kontaktom synchronizować się i aktualizować samodzielnie.',
          ),
        ),
      ),
    ),
    'vendors' => array(
      'label' => 'Dostawcy i zgodność',
      'eyebrow' => 'Dostawcy i zgodność',
      'grid_heading' => 'Miej podwykonawców w garści — i ubezpieczonych',
      'cards' => array(
        'vendor-directory' => array(
          'icon' => 'user-group',
          'title' => 'Katalog dostawców',
          'body' => 'Każdy podwykonawca i dostawca z branżą, stawkami i historią prac.',
          'hero' => 'Każdy podwykonawca i dostawca pod ręką',
          'lead' => 'Trzymaj każdego dostawcę w jednym katalogu — z branżą, stawkami, kontaktem i historią zleceń — więc zawsze wiesz, do kogo dzwonić i ile kosztuje.',
          'rows' => array(
            0 => array(
              'heading' => 'Cała Twoja ekipa, uporządkowana',
              'text' => 'Zapisz każdego podwykonawcę i dostawcę z ważnymi szczegółami: branża, typowe stawki, kontakty i każde zlecenie, które dla Ciebie wykonał.',
              'points' => array(
                0 => 'Branża, stawki i kontakty',
                1 => 'Pełna historia zleceń dla każdego dostawcy',
                2 => 'Notatki o jakości i niezawodności',
                3 => 'Szybko znajdź właściwego podwykonawcę',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Podwykonawcy · Hydraulika',
                'rows' => array(
                  0 => array(
                    'icon' => 'user-group',
                    'label' => 'Rivera Plumbing',
                    'sub' => '12 zleceń · 42 $/godz.',
                  ),
                  1 => array(
                    'icon' => 'user-group',
                    'label' => 'Apex Mechanical',
                    'sub' => '5 zleceń',
                  ),
                  2 => array(
                    'icon' => 'user-group',
                    'label' => 'BlueLine Plumbing',
                    'sub' => '2 zlecenia',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Powiązane ze wszystkim',
              'text' => 'Każdy dostawca jest połączony z płatnościami, ubezpieczeniem i zleceniami, na których pracuje, więc pełna relacja jest o jedno kliknięcie stąd.',
              'points' => array(
                0 => 'Powiązane z płatnościami i saldami',
                1 => 'Powiązane z certyfikatami ubezpieczenia (COI)',
                2 => 'Zobacz bieżące i przeszłe zlecenia',
                3 => 'Skontaktuj się jednym dotknięciem',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Gdy wiesz dokładnie, do kogo zadzwonić — i ile kosztuje — obsadzenie zlecenia zajmuje dwie minuty.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'user-group',
              'title' => 'Katalog',
              'body' => 'Każdy dostawca w jednym miejscu.',
            ),
            1 => array(
              'icon' => 'wrench',
              'title' => 'Branża i stawki',
              'body' => 'Wiedz kto i za ile.',
            ),
            2 => array(
              'icon' => 'folder',
              'title' => 'Historia zleceń',
              'body' => 'Wszystko, co wykonali.',
            ),
            3 => array(
              'icon' => 'wallet',
              'title' => 'Płatności',
              'body' => 'Powiązane salda.',
            ),
            4 => array(
              'icon' => 'shield-check',
              'title' => 'Zgodność',
              'body' => 'Dołączone COI.',
            ),
            5 => array(
              'icon' => 'phone',
              'title' => 'Kontakt jednym dotknięciem',
              'body' => 'Szybki telefon lub SMS.',
            ),
          ),
          'cta' => array(
            'heading' => 'Wiedz dokładnie, do kogo dzwonić.',
            'sub' => 'Każdy podwykonawca i dostawca ze stawkami, historią i ubezpieczeniem.',
          ),
        ),
        'vendor-payments' => array(
          'icon' => 'wallet',
          'title' => 'Płatności dla dostawców',
          'body' => 'Płać podwykonawcom i wiąż każdą płatność z właściwym zleceniem.',
          'hero' => 'Płać podwykonawcom i trzymaj zlecenie w ryzach',
          'lead' => 'Rejestruj i śledź płatności dla każdego podwykonawcy i dostawcy, z każdą złotówką powiązaną z właściwym zleceniem i saldem, więc koszt robocizny zawsze trafia tam, gdzie trzeba.',
          'rows' => array(
            0 => array(
              'heading' => 'Na właściwym zleceniu, za każdym razem',
              'text' => 'Gdy płacisz podwykonawcy, koszt automatycznie dopisuje się do projektu, a saldo dostawcy się aktualizuje. Koniec ze zgadywaniem, którego zlecenia dotyczyła płatność.',
              'points' => array(
                0 => 'Łatwo płać podwykonawcom i dostawcom',
                1 => 'Koszt trafia na właściwe zlecenie',
                2 => 'Bieżące saldo dla każdego dostawcy',
                3 => 'Czyste zapisy pod formularze 1099',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Rivera Plumbing',
                'rows' => array(
                  0 => array(
                    'label' => 'Zafakturowano',
                    'value' => '6 400 $',
                  ),
                  1 => array(
                    'label' => 'Zapłacono',
                    'value' => '4 000 $',
                  ),
                  2 => array(
                    'label' => 'Saldo',
                    'value' => '2 400 $',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Płać podwykonawcom, którzy chronią Twoje ubezpieczenie',
              'text' => 'Płatności łączą się z ubezpieczeniem i polisą każdego dostawcy, więc wychwycisz wygasłe dokumenty, zanim wystawisz kolejny czek.',
              'points' => array(
                0 => 'Powiązane z COI i polisą',
                1 => 'Najpierw oznacza wygasłe dokumenty',
                2 => 'Zasila kalkulację kosztów zlecenia',
                3 => 'Zgadza się z wyciągiem bankowym',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Płacenie podwykonawcom przez Hive trzyma koszty, salda i zgodność w jednym miejscu — bez osobnego arkusza.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'wallet',
              'title' => 'Płać podwykonawcom',
              'body' => 'Z jednego miejsca.',
            ),
            1 => array(
              'icon' => 'folder',
              'title' => 'Wg zlecenia',
              'body' => 'Koszt na projekcie.',
            ),
            2 => array(
              'icon' => 'scale',
              'title' => 'Salda',
              'body' => 'Dla każdego dostawcy.',
            ),
            3 => array(
              'icon' => 'shield-check',
              'title' => 'Zgodność',
              'body' => 'Powiązane COI.',
            ),
            4 => array(
              'icon' => 'document-text',
              'title' => 'Gotowe pod 1099',
              'body' => 'Czysty koniec roku.',
            ),
            5 => array(
              'icon' => 'calculator',
              'title' => 'Zasila kalkulację',
              'body' => 'Robocizna śledzona.',
            ),
          ),
          'cta' => array(
            'heading' => 'Płać podwykonawcom, nie gubiąc wątku.',
            'sub' => 'Każda wypłata powiązana ze zleceniem, saldem i ubezpieczeniem.',
          ),
        ),
        'coi-tracking' => array(
          'icon' => 'shield-check',
          'title' => 'Śledzenie COI',
          'body' => 'Przechowuj certyfikaty ubezpieczenia i pilnuj dat wygaśnięcia.',
          'hero' => 'Nigdy nie przegap żadnego certyfikatu',
          'lead' => 'Przechowuj każdy certyfikat ubezpieczenia, powiąż go z dostawcą i zleceniem, i otrzymuj alert, zanim wygaśnie — więc nigdy nie jesteś narażony.',
          'rows' => array(
            0 => array(
              'heading' => 'Każdy COI w kartotece',
              'text' => 'Trzymaj certyfikaty uporządkowane wg dostawcy i powiązane ze zleceniami, na których pracują. Dowód ubezpieczenia jest zawsze o jedno wyszukanie stąd.',
              'points' => array(
                0 => 'Przechowuj COI wg dostawcy',
                1 => 'Powiązane ze zleceniami, na których pracują',
                2 => 'Zobacz status ubezpieczenia od razu',
                3 => 'Szybko poproś agentów o aktualizację',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Status ubezpieczenia',
                'rows' => array(
                  0 => array(
                    'icon' => 'shield-check',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Ważne do 30.11',
                  ),
                  1 => array(
                    'icon' => 'shield-check',
                    'label' => 'Apex Electric',
                    'sub' => 'Ważne do 15.09',
                  ),
                  2 => array(
                    'icon' => 'exclamation-triangle',
                    'label' => 'Summit Drywall',
                    'sub' => 'Wygasa za 9 dni',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Alerty, zanim wygaśnie',
              'text' => 'Hive pilnuje dat wygaśnięcia i ostrzega Cię z wyprzedzeniem, więc zdążysz zebrać odnowiony certyfikat, zanim podwykonawca zacznie pracę bez ubezpieczenia.',
              'points' => array(
                0 => 'Automatyczne alerty o wygaśnięciu',
                1 => 'Wychwyć wygaśnięcia, zanim nastąpią',
                2 => 'Zmniejsz swoją odpowiedzialność',
                3 => 'Spełniaj wymogi generalnych wykonawców i banków',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Przeoczony wygasły COI to roszczenie, które czeka, by na Ciebie spaść. Hive dba, byś nigdy go nie przegapił.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'shield-check',
              'title' => 'COI przechowane',
              'body' => 'Każdy certyfikat.',
            ),
            1 => array(
              'icon' => 'user-group',
              'title' => 'Wg dostawcy',
              'body' => 'Uporządkowane wg podwykonawcy.',
            ),
            2 => array(
              'icon' => 'bell-alert',
              'title' => 'Alerty o wygaśnięciu',
              'body' => 'Ostrzeżenie z wyprzedzeniem.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Wg zlecenia',
              'body' => 'Powiązane z projektami.',
            ),
            4 => array(
              'icon' => 'envelope',
              'title' => 'Prośba',
              'body' => 'Szybko pytaj agentów.',
            ),
            5 => array(
              'icon' => 'scale',
              'title' => 'Mniejsze ryzyko',
              'body' => 'Nigdy bez ubezpieczenia.',
            ),
          ),
          'cta' => array(
            'heading' => 'Utrzymuj każdy COI aktualny.',
            'sub' => 'Przechowuj certyfikaty i otrzymuj alerty, zanim wygasną.',
          ),
        ),
        'workers-comp' => array(
          'icon' => 'clipboard-document-check',
          'title' => 'Ubezpieczenie pracownicze',
          'body' => 'Weryfikuj ubezpieczenie i otrzymuj alerty, zanim wygaśnie.',
          'hero' => 'Upewnij się, że każdy podwykonawca jest ubezpieczony',
          'lead' => 'Weryfikuj ubezpieczenie pracownicze każdego podwykonawcy, przechowuj dowód i otrzymuj ostrzeżenie, zanim wygaśnie polisa — więc kontuzja nigdy nie stanie się Twoją odpowiedzialnością.',
          'rows' => array(
            0 => array(
              'heading' => 'Weryfikuj, zanim wejdą na budowę',
              'text' => 'Potwierdź ubezpieczenie z góry i trzymaj dowód powiązany z dostawcą i zleceniem. Każdy bez ubezpieczenia jest oznaczany, zanim zacznie pracę.',
              'points' => array(
                0 => 'Weryfikuj ubezpieczenie każdego podwykonawcy',
                1 => 'Dowody przechowywane według dostawcy',
                2 => 'Powiązane z obsługiwanymi projektami',
                3 => 'Oznacz nieubezpieczonych',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Ubezpieczenie pracowników',
                'rows' => array(
                  0 => array(
                    'icon' => 'clipboard-document-check',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Aktywne',
                  ),
                  1 => array(
                    'icon' => 'clipboard-document-check',
                    'label' => 'Apex Electric',
                    'sub' => 'Aktywne',
                  ),
                  2 => array(
                    'icon' => 'exclamation-triangle',
                    'label' => 'Summit Drywall',
                    'sub' => 'Wygasa 15.07',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Chroniony przed roszczeniem, którego nie przewidziałeś',
              'text' => 'Wczesne alerty o wygaśnięciu sprawiają, że nigdy nie masz na budowie nieubezpieczonej ekipy — dzięki czemu nie ponosisz odpowiedzialności, gdy coś pójdzie nie tak.',
              'points' => array(
                0 => 'Wczesne alerty o wygaśnięciu',
                1 => 'Ochrona przed roszczeniami',
                2 => 'Gotowość na audyt',
                3 => 'Spokój ducha na budowie',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Jeden nieubezpieczony wypadek może pogrążyć małego wykonawcę. Hive utrzymuje ubezpieczenie aktualne, by tak się nie stało.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'clipboard-document-check',
              'title' => 'Zweryfikowane',
              'body' => 'Ubezpieczenie potwierdzone.',
            ),
            1 => array(
              'icon' => 'user-group',
              'title' => 'Wg podwykonawcy',
              'body' => 'Dowód na dostawcę.',
            ),
            2 => array(
              'icon' => 'bell-alert',
              'title' => 'Alerty wygaśnięcia',
              'body' => 'Ostrzeżenie z wyprzedzeniem.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Wg projektu',
              'body' => 'Powiązane z projektami.',
            ),
            4 => array(
              'icon' => 'shield-check',
              'title' => 'Chroniony',
              'body' => 'Roszczenia pokryte.',
            ),
            5 => array(
              'icon' => 'check-badge',
              'title' => 'Gotowość na audyt',
              'body' => 'Dowody pod ręką.',
            ),
          ),
          'cta' => array(
            'heading' => 'Utrzymuj ubezpieczenie pracowników aktualne.',
            'sub' => 'Weryfikuj ubezpieczenie i otrzymuj alerty, zanim wygaśnie.',
          ),
        ),
        'insurance-agents' => array(
          'icon' => 'building-office-2',
          'title' => 'Agenci ubezpieczeniowi',
          'body' => 'Miej kontakty do agentów pod ręką, by szybko poprosić o certyfikat.',
          'hero' => 'Zdobądź certyfikaty bez zbędnego biegania',
          'lead' => 'Trzymaj agenta ubezpieczeniowego każdego dostawcy w aktach, aby świeży certyfikat lub weryfikacja ubezpieczenia były szybkim zapytaniem, a nie tygodniem odbijania się od telefonów.',
          'rows' => array(
            0 => array(
              'heading' => 'Właściwy agent, pod ręką',
              'text' => 'Zapisuj agencję i agenta stojącego za ubezpieczeniem każdego dostawcy. Gdy potrzebujesz aktualnego COI, wiesz dokładnie, kogo zapytać.',
              'points' => array(
                0 => 'Kontakty agentów wg dostawcy',
                1 => 'Poproś o aktualizacje jednym dotknięciem',
                2 => 'Bez szukania, do kogo zadzwonić',
                3 => 'Szybsza realizacja COI',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Agenci',
                'rows' => array(
                  0 => array(
                    'icon' => 'building-office-2',
                    'label' => 'Coast Insurance',
                    'sub' => 'Rivera Plumbing',
                  ),
                  1 => array(
                    'icon' => 'building-office-2',
                    'label' => 'Summit Agency',
                    'sub' => 'Apex Electric',
                  ),
                  2 => array(
                    'icon' => 'building-office-2',
                    'label' => 'Harbor Group',
                    'sub' => 'Summit Drywall',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Odnowienia bez opóźnień',
              'text' => 'Gdy certyfikat ma wygasnąć, skontaktuj się z agentem bezpośrednio z Hive i utrzymaj ubezpieczenie projektu bez tracenia dni.',
              'points' => array(
                0 => 'Kontaktuj się z agentami z Hive',
                1 => 'Powiąż zapytania z dostawcą',
                2 => 'Utrzymuj projekty ubezpieczone',
                3 => 'Bez kosztownych luk w ubezpieczeniu',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Najszybszy sposób na wygasający COI to wiedzieć dokładnie, do którego agenta napisać — Hive trzyma to o jedno dotknięcie od Ciebie.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'building-office-2',
              'title' => 'Agenci w aktach',
              'body' => 'Na dostawcę.',
            ),
            1 => array(
              'icon' => 'envelope',
              'title' => 'Szybkie zapytanie',
              'body' => 'Poproś jednym dotknięciem.',
            ),
            2 => array(
              'icon' => 'shield-check',
              'title' => 'Powiązane z ubezpieczeniem',
              'body' => 'Powiązane z COI.',
            ),
            3 => array(
              'icon' => 'clock',
              'title' => 'Szybsze COI',
              'body' => 'Bez odbijania telefonów.',
            ),
            4 => array(
              'icon' => 'user-group',
              'title' => 'Wg dostawcy',
              'body' => 'Wiedz, kogo zapytać.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Bez luk',
              'body' => 'Bądź ubezpieczony.',
            ),
          ),
          'cta' => array(
            'heading' => 'Zdobądź certyfikaty bez zbędnego biegania.',
            'sub' => 'Agent każdego dostawcy w aktach dla szybkich zapytań.',
          ),
        ),
        'document-audits' => array(
          'icon' => 'document-magnifying-glass',
          'title' => 'Audyty dokumentów',
          'body' => 'Automatyczne kontrole, dzięki którym brakujące dokumenty wychodzą na jaw wcześnie.',
          'hero' => 'Wychwyć brakujące dokumenty, zanim narobią kłopotów',
          'lead' => 'Automatyczne audyty skanują Twoich dostawców i projekty w poszukiwaniu brakujących lub wygasających dokumentów, więc luki wychodzą na jaw wcześnie — a nie wtedy, gdy generalny wykonawca lub inspektor o nie zapyta.',
          'rows' => array(
            0 => array(
              'heading' => 'Stała kontrola Twoich akt',
              'text' => 'Hive nieustannie sprawdza brakujące COI, wygasłe ubezpieczenia, niepodpisane zwolnienia z zastawu i niekompletne dane dostawców, a następnie pokazuje Ci dokładnie, co jest nie tak.',
              'points' => array(
                0 => 'Skanuj w poszukiwaniu brakujących dokumentów',
                1 => 'Oznaczaj wygasające ubezpieczenia',
                2 => 'Wykrywaj niepodpisane zwolnienia',
                3 => 'Zobacz luki wg dostawcy i projektu',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Audyt · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'exclamation-triangle',
                    'label' => 'Summit Drywall',
                    'sub' => 'COI wygasa',
                  ),
                  1 => array(
                    'icon' => 'exclamation-triangle',
                    'label' => 'Apex Electric',
                    'sub' => 'Zwolnienie niepodpisane',
                  ),
                  2 => array(
                    'icon' => 'check-circle',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Wszystko w porządku',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Zawsze gotowy na inspekcję',
              'text' => 'Gdy generalny wykonawca, kredytodawca lub inspektor poprosi o dokumenty, jesteś gotowy — bo Hive już powiedział Ci, czego brakuje, i to naprawiłeś.',
              'points' => array(
                0 => 'Uzupełnij luki, zanim ktokolwiek zapyta',
                1 => 'Bądź gotowy na audyt i inspekcję',
                2 => 'Zmniejsz ryzyko niezgodności',
                3 => 'Chroń swoją reputację',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Brakujący dokument wykryty wcześnie to szybki e-mail. Znaleziony podczas audytu może zatrzymać budowę.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-magnifying-glass',
              'title' => 'Auto-audyt',
              'body' => 'Stałe kontrole.',
            ),
            1 => array(
              'icon' => 'shield-check',
              'title' => 'Brakujące COI',
              'body' => 'Wykryte wcześnie.',
            ),
            2 => array(
              'icon' => 'clipboard-document-check',
              'title' => 'Wygasłe ubezpieczenie',
              'body' => 'Szybko oznaczone.',
            ),
            3 => array(
              'icon' => 'document-check',
              'title' => 'Niepodpisane zwolnienia',
              'body' => 'Wychwycone na czas.',
            ),
            4 => array(
              'icon' => 'folder',
              'title' => 'Wg projektu',
              'body' => 'Luki na projekt.',
            ),
            5 => array(
              'icon' => 'check-badge',
              'title' => 'Gotowość na audyt',
              'body' => 'Zawsze przygotowany.',
            ),
          ),
          'cta' => array(
            'heading' => 'Znajdź lukę, zanim znajdzie ją audytor.',
            'sub' => 'Automatyczne kontrole ujawniają brakujące dokumenty wcześnie.',
          ),
        ),
      ),
    ),
    'planning' => array(
      'label' => 'Planowanie',
      'eyebrow' => 'Projekty i planowanie',
      'grid_heading' => 'Zaplanuj pracę i pracuj według planu',
      'cards' => array(
        'gantt' => array(
          'icon' => 'calendar-date-range',
          'title' => 'Oś czasu Gantta',
          'body' => 'Harmonogramowanie metodą przeciągnij i upuść z zależnościami między wszystkimi projektami.',
          'hero' => 'Zobacz każdy projekt na jednej osi czasu',
          'lead' => 'Harmonogramowanie metodą przeciągnij i upuść z zależnościami pozwala planować ekipy na wszystkich projektach naraz — więc przestajesz przeciążać grafik i zaczynasz kończyć na czas.',
          'rows' => array(
            0 => array(
              'heading' => 'Planuj przeciągając',
              'text' => 'Rozłóż zadania na wizualnej osi czasu, ustal, co od czego zależy, i przesuwaj daty przeciągając. Cały plan dostosowuje się do zmiany.',
              'points' => array(
                0 => 'Planowanie zadań metodą przeciągnij i upuść',
                1 => 'Zależności, które przesuwają się automatycznie',
                2 => 'Zobacz wszystkie zlecenia naraz',
                3 => 'Wykryj konflikty, zanim się pojawią',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Harmonogram · Ten tydzień',
                'rows' => array(
                  0 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Rozbiórka · Maple St',
                    'sub' => 'Pon–wt',
                  ),
                  1 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Instalacje wstępne · Oak Ave',
                    'sub' => 'Śr–pt',
                  ),
                  2 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Inspekcja · Pine Ct',
                    'sub' => 'Czw',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Kończ zlecenia na czas',
              'text' => 'Gdy termin się przesuwa, zależności przesuwają się razem z nim, a Ty od razu widzisz efekt domina — więc możesz zareagować, zanim opóźnienie urośnie.',
              'points' => array(
                0 => 'Opóźnienia widoczne jak efekt domina',
                1 => 'Reaguj, zanim urosną',
                2 => 'Utrzymuj pełne obłożenie ekip',
                3 => 'Dotrzymuj terminów zakończenia',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Harmonogram, który pokazuje efekt każdego opóźnienia, to sposób, w jaki mali wykonawcy panują nad wieloma zleceniami naraz.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Harmonogram',
              'body' => 'Wszystkie zlecenia naraz.',
            ),
            1 => array(
              'icon' => 'arrows-pointing-out',
              'title' => 'Przeciągnij i upuść',
              'body' => 'Szybkie planowanie.',
            ),
            2 => array(
              'icon' => 'link',
              'title' => 'Zależności',
              'body' => 'Automatyczne terminy.',
            ),
            3 => array(
              'icon' => 'exclamation-triangle',
              'title' => 'Konflikty',
              'body' => 'Wykryte wcześnie.',
            ),
            4 => array(
              'icon' => 'user-group',
              'title' => 'Widok ekipy',
              'body' => 'Kto gdzie jest.',
            ),
            5 => array(
              'icon' => 'flag',
              'title' => 'Kamienie milowe',
              'body' => 'Śledź kluczowe daty.',
            ),
          ),
          'cta' => array(
            'heading' => 'Trzymaj każde zlecenie w harmonogramie.',
            'sub' => 'Jeden harmonogram przeciągnij-i-upuść dla wszystkich projektów.',
          ),
        ),
        'kanban' => array(
          'icon' => 'view-columns',
          'title' => 'Tablica kanban',
          'body' => 'Przesuwaj pracę przez etapy na tablicy, którą rozumie cała ekipa.',
          'hero' => 'Przesuwaj pracę na tablicy, którą rozumieją wszyscy',
          'lead' => 'Prosta tablica przesuwa zadania przez etapy, które cała ekipa rozumie od pierwszego spojrzenia — więc każdy wie, co dalej, bez zebrania.',
          'rows' => array(
            0 => array(
              'heading' => 'Etapy zrozumiałe dla każdego',
              'text' => 'Przeciągaj karty z „do zrobienia” do „w toku” i „gotowe”. Tablica jasno pokazuje stan pracy zarówno biuru, jak i ekipie w terenie.',
              'points' => array(
                0 => 'Wizualne etapy dla każdego zadania',
                1 => 'Przeciągaj karty w miarę postępu',
                2 => 'Przypisuj osoby i terminy',
                3 => 'Jasne dla biura i terenu',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Tablica · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'view-columns',
                    'label' => 'Do zrobienia',
                    'sub' => 'Płytki, malowanie',
                  ),
                  1 => array(
                    'icon' => 'view-columns',
                    'label' => 'W toku',
                    'sub' => 'Elektryka',
                  ),
                  2 => array(
                    'icon' => 'view-columns',
                    'label' => 'Gotowe',
                    'sub' => 'Rozbiórka, hydraulika',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Mniej dopytywania o status',
              'text' => 'Gdy tablica jest źródłem prawdy, nikt nie musi pytać, jak stoją sprawy. Aktualizacje dzieją się w miarę pracy, nie na zebraniu.',
              'points' => array(
                0 => 'Jedno źródło prawdy',
                1 => 'Mniej zebrań o statusie',
                2 => 'Wszyscy działają zgodnie',
                3 => 'Nic nie umknie',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Tablica, którą ekipa naprawdę rozumie, zastępuje kilkanaście SMS-ów „na czym stoimy?” dziennie.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'view-columns',
              'title' => 'Etapy',
              'body' => 'Od „do zrobienia” do „gotowe”.',
            ),
            1 => array(
              'icon' => 'arrows-pointing-out',
              'title' => 'Przeciągaj karty',
              'body' => 'W miarę postępu.',
            ),
            2 => array(
              'icon' => 'user-plus',
              'title' => 'Przypisuj',
              'body' => 'Osoby do zadań.',
            ),
            3 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Terminy',
              'body' => 'Na każdej karcie.',
            ),
            4 => array(
              'icon' => 'eye',
              'title' => 'Jasność',
              'body' => 'Teren i biuro.',
            ),
            5 => array(
              'icon' => 'bell-alert',
              'title' => 'Bez niespodzianek',
              'body' => 'Nic nie umknie.',
            ),
          ),
          'cta' => array(
            'heading' => 'Spraw, by praca była jasna dla wszystkich.',
            'sub' => 'Tablica, którą cała ekipa rozumie od pierwszego spojrzenia.',
          ),
        ),
        'projects' => array(
          'icon' => 'folder',
          'title' => 'Projekty',
          'body' => 'Każde zlecenie trzyma razem zakres, dokumenty, koszty i historię.',
          'hero' => 'Wszystko o zleceniu w jednym miejscu',
          'lead' => 'Każdy projekt trzyma razem zakres, harmonogram, dokumenty, koszty, zdjęcia i rozmowy — więc cała historia zlecenia jest o jedno kliknięcie od Ciebie.',
          'rows' => array(
            0 => array(
              'heading' => 'Koniec z rozproszonymi danymi zlecenia',
              'text' => 'Otwórz projekt i znajdź kosztorys, harmonogram, wydatki, zdjęcia i wiadomości w jednym miejscu. Nic nie żyje w osobnej aplikacji ani wątku SMS.',
              'points' => array(
                0 => 'Zakres, harmonogram i dokumenty',
                1 => 'Koszty i zdjęcia razem',
                2 => 'Rozmowy przypięte do zlecenia',
                3 => 'Pełna historia w jednym miejscu',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Projekt · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'document-text',
                    'label' => 'Zakres i kosztorys',
                    'sub' => '48 000 $',
                  ),
                  1 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Harmonogram',
                    'sub' => 'Gotowe w 62%',
                  ),
                  2 => array(
                    'icon' => 'photo',
                    'label' => 'Zdjęcia',
                    'sub' => '24 w archiwum',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Jedno źródło prawdy',
              'text' => 'Ponieważ wszystko łączy się z projektem, kosztorysowanie, portal klienta i raporty czerpią z tego samego miejsca — i pozostają spójne.',
              'points' => array(
                0 => 'Jedno źródło każdego szczegółu',
                1 => 'Zasila kosztorysowanie i raporty',
                2 => 'Zasila portal klienta',
                3 => 'Spójne wszędzie',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Gdy zlecenie żyje w jednym miejscu, przestajesz tracić czas na szukanie i zaczynasz ufać swoim liczbom.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'folder',
              'title' => 'Jedno miejsce',
              'body' => 'Wszystko razem.',
            ),
            1 => array(
              'icon' => 'document-text',
              'title' => 'Dokumenty',
              'body' => 'Zakres i pliki.',
            ),
            2 => array(
              'icon' => 'calculator',
              'title' => 'Koszty',
              'body' => 'Kosztorys na żywo.',
            ),
            3 => array(
              'icon' => 'photo',
              'title' => 'Zdjęcia',
              'body' => 'Postęp w archiwum.',
            ),
            4 => array(
              'icon' => 'chat-bubble-left-right',
              'title' => 'Wiadomości',
              'body' => 'Przypięte do zlecenia.',
            ),
            5 => array(
              'icon' => 'clock',
              'title' => 'Historia',
              'body' => 'Pełna historia.',
            ),
          ),
          'cta' => array(
            'heading' => 'Trzymaj każde zlecenie w jednym miejscu.',
            'sub' => 'Zakres, koszty, zdjęcia i historia — razem.',
          ),
        ),
        'crew-scheduling' => array(
          'icon' => 'user-group',
          'title' => 'Planowanie ekip',
          'body' => 'Przypisuj ludzi do zadań i sprawdzaj, kto jest dostępny i kiedy.',
          'hero' => 'Odpowiedni ludzie na odpowiednie zlecenie',
          'lead' => 'Przypisuj ekipę do zadań i sprawdzaj, kto jest dostępny i kiedy, by przestać rezerwować ludzi podwójnie i prowadzić bardziej zwarty, dochodowy harmonogram.',
          'rows' => array(
            0 => array(
              'heading' => 'Dostępność na pierwszy rzut oka',
              'text' => 'Zobacz, kto jest wolny, kto zajęty, a kto przeciążony, zanim ustalisz termin. Przypisuj właściwych ludzi bez zgadywania.',
              'points' => array(
                0 => 'Zobacz dostępność we wszystkich zleceniach',
                1 => 'Przypisuj ludzi do zadań',
                2 => 'Unikaj podwójnych rezerwacji',
                3 => 'Równoważ obciążenie pracą',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Ekipa · wtorek',
                'rows' => array(
                  0 => array(
                    'icon' => 'user',
                    'label' => 'Greg M.',
                    'sub' => 'Maple St · stan surowy',
                  ),
                  1 => array(
                    'icon' => 'user',
                    'label' => 'Tony R.',
                    'sub' => 'Oak Ave · konstrukcja',
                  ),
                  2 => array(
                    'icon' => 'user',
                    'label' => 'Sam K.',
                    'sub' => 'Dostępny',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Każdy wie, gdzie ma być',
              'text' => 'Przydziały trafiają do ekipy, więc stawiają się na właściwym placu gotowi do pracy. Koniec porannych telefonów, by ustalić plan dnia.',
              'points' => array(
                0 => 'Ekipa widzi swoje przydziały',
                1 => 'Stawiają się gotowi na właściwym placu',
                2 => 'Mniej porannego zamieszania',
                3 => 'Bardziej produktywny dzień',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Ekipa, która o 7:00 wie, gdzie ma być, to ekipa, która rozlicza więcej godzin i marnuje mniej paliwa.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'user-group',
              'title' => 'Przydzielaj',
              'body' => 'Ludzi do zadań.',
            ),
            1 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Dostępność',
              'body' => 'Kto jest wolny.',
            ),
            2 => array(
              'icon' => 'exclamation-triangle',
              'title' => 'Bez nakładek',
              'body' => 'Konflikty oznaczone.',
            ),
            3 => array(
              'icon' => 'scale',
              'title' => 'Zrównoważone',
              'body' => 'Równe obciążenie.',
            ),
            4 => array(
              'icon' => 'device-phone-mobile',
              'title' => 'Ekipa to widzi',
              'body' => 'Na telefonie.',
            ),
            5 => array(
              'icon' => 'bolt',
              'title' => 'Produktywnie',
              'body' => 'Gotowi o 7:00.',
            ),
          ),
          'cta' => array(
            'heading' => 'Przestań podwójnie rezerwować ekipę.',
            'sub' => 'Sprawdzaj dostępność i za każdym razem przydzielaj właściwych ludzi.',
          ),
        ),
        'shared-schedules' => array(
          'icon' => 'paper-airplane',
          'title' => 'Wspólne harmonogramy',
          'body' => 'Aktywne linki do harmonogramu automatycznie zgrywają klientów i ekipy.',
          'hero' => 'Jeden harmonogram widoczny dla wszystkich',
          'lead' => 'Aktywne linki do harmonogramu sprawiają, że klienci i ekipy patrzą na ten sam aktualny plan — zmiany docierają do wszystkich w chwili, gdy zachodzą.',
          'rows' => array(
            0 => array(
              'heading' => 'Zgrani bez zbędnej roboty',
              'text' => 'Udostępnij aktywny link klientom i ekipie. Gdy termin się zmienia, ich widok też — bez zbiorowych SMS-ów i nieaktualnych wydruków.',
              'points' => array(
                0 => 'Aktywne linki dla klientów i ekipy',
                1 => 'Aktualizacja w chwili zmiany terminu',
                2 => 'Bez masowych SMS-ów i wydruków',
                3 => 'Wszyscy na tej samej stronie',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Udostępnione · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'paper-airplane',
                    'label' => 'Link dla klienta',
                    'sub' => 'Co dalej',
                  ),
                  1 => array(
                    'icon' => 'user-group',
                    'label' => 'Link dla ekipy',
                    'sub' => 'Pełny harmonogram',
                  ),
                  2 => array(
                    'icon' => 'bolt',
                    'label' => 'Auto-aktualizacja',
                    'sub' => 'Przy każdej zmianie',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Mniej nieporozumień',
              'text' => 'Gdy wszyscy widzą ten sam plan, telefony o terminy ustają, a praca płynie. Zmiany są komunikowane domyślnie.',
              'points' => array(
                0 => 'Koniec telefonów o terminy',
                1 => 'Zmiany komunikowane domyślnie',
                2 => 'Klienci i ekipa zgrani',
                3 => 'Sprawniej idąca robota',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Jeden wspólny harmonogram to najtańszy sposób, by ograniczyć codzienne ustalanie, kto co robi i kiedy.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'paper-airplane',
              'title' => 'Aktywne linki',
              'body' => 'Klienci i ekipa.',
            ),
            1 => array(
              'icon' => 'bolt',
              'title' => 'Auto-aktualizacja',
              'body' => 'Przy każdej zmianie.',
            ),
            2 => array(
              'icon' => 'users',
              'title' => 'Zgrani',
              'body' => 'Jeden plan dla wszystkich.',
            ),
            3 => array(
              'icon' => 'device-phone-mobile',
              'title' => 'Każde urządzenie',
              'body' => 'Bez aplikacji.',
            ),
            4 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Aktualne',
              'body' => 'Nigdy nieaktualne.',
            ),
            5 => array(
              'icon' => 'face-smile',
              'title' => 'Mniej telefonów',
              'body' => 'Mniej ustalania.',
            ),
          ),
          'cta' => array(
            'heading' => 'Zbierz wszystkich na jednym harmonogramie.',
            'sub' => 'Aktywne linki, które zgrywają klientów i ekipę.',
          ),
        ),
        'reminders' => array(
          'icon' => 'bell-alert',
          'title' => 'Przypomnienia',
          'body' => 'Automatyczne przypomnienia przed zaplanowaną pracą, by nic nie umknęło.',
          'hero' => 'Przypomnienia, żeby nic nie umknęło',
          'lead' => 'Automatyczne przypomnienia przed zaplanowaną pracą, odbiorami i kamieniami milowymi trzymają Ciebie i ekipę o krok przed każdym terminem — nic nie umknie.',
          'rows' => array(
            0 => array(
              'heading' => 'Przypomnienie, zanim będzie ważne',
              'text' => 'Hive przypomina właściwym osobom przed wizytą, odbiorem czy terminem, więc przygotowania idą na czas, a daty nie umykają.',
              'points' => array(
                0 => 'Przypomnienia przed zaplanowaną pracą',
                1 => 'Uprzedzenie o odbiorach',
                2 => 'Alerty o kamieniach milowych i terminach',
                3 => 'Wysyłane do właściwych osób',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Przypomnienia',
                'rows' => array(
                  0 => array(
                    'icon' => 'bell-alert',
                    'label' => 'Odbiór jutro',
                    'sub' => 'Maple St · 9:00',
                  ),
                  1 => array(
                    'icon' => 'bell-alert',
                    'label' => 'Dostawa płytek',
                    'sub' => 'Pon. rano',
                  ),
                  2 => array(
                    'icon' => 'bell-alert',
                    'label' => 'Pozwolenie wygasa',
                    'sub' => 'Za 5 dni',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Bądź o krok przed, nie za',
              'text' => 'Zamiast reagować na przegapione daty, wyprzedzasz je. Mniej niezdanych odbiorów, mniej bezczynnych ekip, mniej kosztownych niespodzianek.',
              'points' => array(
                0 => 'Wyprzedź każdą datę',
                1 => 'Mniej niezdanych odbiorów',
                2 => 'Mniej poranków z bezczynną ekipą',
                3 => 'Mniej kosztownych niespodzianek',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Przypomnienie dzień wcześniej jest znacznie tańsze niż przegapiony odbiór czy ekipa stojąca bezczynnie.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'bell-alert',
              'title' => 'Auto-przypomnienia',
              'body' => 'Przed pracą.',
            ),
            1 => array(
              'icon' => 'clipboard-document-check',
              'title' => 'Odbiory',
              'body' => 'Nigdy przegapione.',
            ),
            2 => array(
              'icon' => 'flag',
              'title' => 'Kamienie milowe',
              'body' => 'Bądź o krok przed.',
            ),
            3 => array(
              'icon' => 'truck',
              'title' => 'Dostawy',
              'body' => 'Bądź gotowy.',
            ),
            4 => array(
              'icon' => 'users',
              'title' => 'Właściwi ludzie',
              'body' => 'Celowane alerty.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Nic nie umyka',
              'body' => 'Panuj nad wszystkim.',
            ),
          ),
          'cta' => array(
            'heading' => 'Nigdy więcej nie przegap terminu.',
            'sub' => 'Automatyczne przypomnienia przed każdą wizytą i terminem.',
          ),
        ),
      ),
    ),
    'team' => array(
      'label' => 'Zespół i Czas',
      'eyebrow' => 'Zespół i Czas',
      'grid_heading' => 'Czas i płace w zgrze',
      'cards' => array(
        'time-tracking' => array(
          'icon' => 'clock',
          'title' => 'Mobilne rejestrowanie czasu',
          'body' => 'Ekipy rejestrują godziny według zlecenia i zadania wprost z telefonu.',
          'hero' => 'Godziny rejestrowane z placu budowy',
          'lead' => 'Twoja ekipa rejestruje czas na właściwe zlecenie i zadanie z telefonu — dzięki temu koszt robocizny jest dokładny, zapisany na żywo i nigdy nie odtwarzany w piątek.',
          'rows' => array(
            0 => array(
              'heading' => 'Rejestruj czas prosto z terenu',
              'text' => 'Koniec papierowych kart pracy i zgadywania na koniec tygodnia. Członkowie ekipy dotykają, by rozpocząć i zakończyć czas na zleceniu i zadaniu, nad którym pracują.',
              'points' => array(
                0 => 'Śledź czas według zlecenia i zadania',
                1 => 'Start i stop z dowolnego telefonu',
                2 => 'Działa na placu, biuro niepotrzebne',
                3 => 'Dokładność co do minuty',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'W tym tygodniu · Maple St',
                'rows' => array(
                  0 => array(
                    'icon' => 'clock',
                    'label' => 'Greg M. · Hydraulika',
                    'sub' => '32,5 godz.',
                  ),
                  1 => array(
                    'icon' => 'clock',
                    'label' => 'Tony R. · Konstrukcja',
                    'sub' => '28,0 godz.',
                  ),
                  2 => array(
                    'icon' => 'clock',
                    'label' => 'Sam K. · Płytki',
                    'sub' => '18,0 godz.',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Robocizna trafia na projekt',
              'text' => 'Każda godzina trafia prosto do rozliczania kosztów, więc koszt robocizny pojawia się na właściwym projekcie w miarę postępu prac.',
              'points' => array(
                0 => 'Godziny zasilają rozliczanie kosztów',
                1 => 'Koszt robocizny na właściwym projekcie',
                2 => 'Widoczne na żywo, nie po fakcie',
                3 => 'Bez przepisywania do arkuszy',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Robocizna to Twój największy koszt. Śledzenie jej na żywo według projektu pokazuje, które prace naprawdę zarabiają.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'clock',
              'title' => 'Wg projektu i zadania',
              'body' => 'Czas na właściwej pracy.',
            ),
            1 => array(
              'icon' => 'device-phone-mobile',
              'title' => 'Z telefonu',
              'body' => 'Odbij kartę z każdego miejsca.',
            ),
            2 => array(
              'icon' => 'bolt',
              'title' => 'Na żywo',
              'body' => 'Rejestrowane na bieżąco.',
            ),
            3 => array(
              'icon' => 'calculator',
              'title' => 'Rozliczane na projekt',
              'body' => 'Zasila rozliczanie kosztów.',
            ),
            4 => array(
              'icon' => 'document-text',
              'title' => 'Bez papieru',
              'body' => 'Koniec kart pracy.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Dokładne',
              'body' => 'Co do minuty.',
            ),
          ),
          'cta' => array(
            'heading' => 'Zbieraj dokładne godziny z terenu.',
            'sub' => 'Ekipy odbijają kartę wg projektu, a robocizna trafia tam, gdzie trzeba.',
          ),
        ),
        'timesheet-approval' => array(
          'icon' => 'check-circle',
          'title' => 'Zatwierdzanie kart pracy',
          'body' => 'Przejrzyj i zatwierdź godziny, zanim trafią do wypłat.',
          'hero' => 'Zatwierdź cały tydzień w kilka dotknięć',
          'lead' => 'Przejrzyj godziny ekipy, popraw, co trzeba, i zatwierdź, zanim wyjdzie choćby dolar wypłaty — płacisz za faktycznie przepracowany czas.',
          'rows' => array(
            0 => array(
              'heading' => 'Sprawdź, zanim zapłacisz',
              'text' => 'Zobacz cały tydzień wg osoby i projektu, wyłap wszystko, co wygląda nie tak, i zatwierdź z pewnością. Żadnych niespodzianek w dniu wypłaty.',
              'points' => array(
                0 => 'Godziny wg osoby i projektu',
                1 => 'Wyłap błędy przed wypłatą',
                2 => 'Edytuj i zatwierdź jednym dotknięciem',
                3 => 'Przejrzysta ścieżka zatwierdzeń',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Oczekuje zatwierdzenia',
                'rows' => array(
                  0 => array(
                    'icon' => 'clock',
                    'label' => 'Greg M.',
                    'sub' => '40,0 godz. · do sprawdzenia',
                  ),
                  1 => array(
                    'icon' => 'clock',
                    'label' => 'Tony R.',
                    'sub' => '38,5 godz. · do sprawdzenia',
                  ),
                  2 => array(
                    'icon' => 'check-circle',
                    'label' => 'Sam K.',
                    'sub' => '36,0 godz. · zatwierdzone',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Prosto do wypłat',
              'text' => 'Zatwierdzone godziny trafiają od razu do płatności i rozliczania kosztów, więc to, co zatwierdzasz, jest dokładnie tym, co płacisz i czym obciążany jest projekt.',
              'points' => array(
                0 => 'Zatwierdzone godziny zasilają wypłaty',
                1 => 'I zasilają rozliczanie kosztów',
                2 => 'Wypłata zgodna z przepracowanym czasem',
                3 => 'Jeden spójny zapis',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Szybki krok zatwierdzenia wyłapuje błędy, które po cichu kosztują Cię pieniądze tydzień po tygodniu.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'check-circle',
              'title' => 'Zatwierdź',
              'body' => 'Przed wypłatą.',
            ),
            1 => array(
              'icon' => 'eye',
              'title' => 'Sprawdź',
              'body' => 'Wg osoby i projektu.',
            ),
            2 => array(
              'icon' => 'pencil',
              'title' => 'Edytuj',
              'body' => 'Popraw, co nie gra.',
            ),
            3 => array(
              'icon' => 'banknotes',
              'title' => 'Do wypłat',
              'body' => 'Trafia prosto tam.',
            ),
            4 => array(
              'icon' => 'calculator',
              'title' => 'Do kosztów',
              'body' => 'Na właściwy projekt.',
            ),
            5 => array(
              'icon' => 'clipboard-document-check',
              'title' => 'Ścieżka',
              'body' => 'Jasne zatwierdzenia.',
            ),
          ),
          'cta' => array(
            'heading' => 'Płać za godziny, które przepracowano.',
            'sub' => 'Przejrzyj i zatwierdź, zanim ruszą wypłaty.',
          ),
        ),
        'payroll-payments' => array(
          'icon' => 'banknotes',
          'title' => 'Wypłaty wynagrodzeń',
          'body' => 'Zapłać zespołowi z zatwierdzonych godzin w jednym przepływie.',
          'hero' => 'Zapłać ekipie z tego samego ekranu',
          'lead' => 'Zatwierdzone godziny wpadają prosto do płatności, więc płacenie zespołowi to jeden czysty przepływ — zapisany na projekcie i dopasowany do ksiąg.',
          'rows' => array(
            0 => array(
              'heading' => 'Od zatwierdzenia do wypłaty',
              'text' => 'Bez przepisywania godzin do innego systemu. Zatwierdzony czas staje się wypłatą, którą sprawdzisz i wyślesz — z gotowymi obliczeniami.',
              'points' => array(
                0 => 'Wypłaty z zatwierdzonych godzin',
                1 => 'Stawka i sumy obliczone',
                2 => 'Sprawdź i wyślij',
                3 => 'Zapisane na projekcie',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Wypłata · Greg M.',
                'rows' => array(
                  0 => array(
                    'label' => '32,5 godz. × $42',
                    'value' => '$1 365,00',
                  ),
                  1 => array(
                    'label' => 'Saldo poprzednie',
                    'value' => '$0,00',
                  ),
                  2 => array(
                    'label' => 'Wypłata w tym tygodniu',
                    'value' => '$1 365,00',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Czyste księgi za każdym razem',
              'text' => 'Każda wypłata jest zapisywana i synchronizowana z księgami i rozliczaniem kosztów, więc wypłaty nigdy nie wytrącają Twoich liczb z równowagi.',
              'points' => array(
                0 => 'Zsynchronizowane z księgami',
                1 => 'Trafia do rozliczania kosztów',
                2 => 'Dokładny koszt na projekt',
                3 => 'Bez osobnego silosu wypłat',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Wypłaty płynące z zatwierdzonych godzin prosto do ksiąg oznaczają, że koszt robocizny jest zawsze poprawny — automatycznie.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'banknotes',
              'title' => 'Z godzin',
              'body' => 'Od zatwierdzenia do wypłaty.',
            ),
            1 => array(
              'icon' => 'calculator',
              'title' => 'Obliczone',
              'body' => 'Rachunki gotowe.',
            ),
            2 => array(
              'icon' => 'eye',
              'title' => 'Sprawdź',
              'body' => 'Zanim wyślesz.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Na projekcie',
              'body' => 'Koszt zapisany.',
            ),
            4 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Zsynchronizowane',
              'body' => 'Zgodne z księgami.',
            ),
            5 => array(
              'icon' => 'check-badge',
              'title' => 'Na czas',
              'body' => 'Ekipa opłacona jak trzeba.',
            ),
          ),
          'cta' => array(
            'heading' => 'Rozliczaj wypłaty bez arkusza kalkulacyjnego.',
            'sub' => 'Zatwierdzone godziny stają się wypłatami w jednym przepływie.',
          ),
        ),
        'running-balances' => array(
          'icon' => 'scale',
          'title' => 'Bieżące salda',
          'body' => 'Zawsze wiedz, ile jesteś winien każdemu pracownikowi do tej pory.',
          'hero' => 'Zawsze wiedz, ile jesteś winien',
          'lead' => 'Śledź bieżące saldo dla każdego pracownika i podwykonawcy, więc zawsze wiesz dokładnie, ile jesteś winien, i nigdy nie zgubisz zaliczki ani częściowej wypłaty.',
          'rows' => array(
            0 => array(
              'heading' => 'Saldo na żywo dla każdej osoby',
              'text' => 'Każda godzina, wypłata i zaliczka koryguje saldo, więc liczba, którą widzisz, to zawsze to, ile faktycznie jesteś winien — co do dolara.',
              'points' => array(
                0 => 'Saldo na żywo dla pracownika',
                1 => 'Uwzględnia zaliczki',
                2 => 'Obsługuje częściowe płatności',
                3 => 'Zawsze dokładne',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Salda',
                'rows' => array(
                  0 => array(
                    'label' => 'Greg M.',
                    'value' => '$0,00',
                  ),
                  1 => array(
                    'label' => 'Tony R.',
                    'value' => '$420,00',
                  ),
                  2 => array(
                    'label' => 'Sam K.',
                    'value' => '$1 365,00',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Żadnych niezręcznych rozmów',
              'text' => 'Gdy pracownik pyta, ile mu się należy, masz odpowiedź od razu—bez szukania, bez sporów, bez utraty zaufania.',
              'points' => array(
                0 => 'Odpowiedz „ile mi się należy?” od razu',
                1 => 'Unikaj sporów o wypłaty',
                2 => 'Utrzymuj wysokie zaufanie ekipy',
                3 => 'Jasna historia dla obu stron',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Ekipa, która ufa, że dostanie właściwą wypłatę—i może to zobaczyć—to ekipa, która zostaje na dłużej.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'scale',
              'title' => 'Na osobę',
              'body' => 'Saldo na żywo dla każdego.',
            ),
            1 => array(
              'icon' => 'arrow-trending-up',
              'title' => 'Zaliczki',
              'body' => 'Śledzone przejrzyście.',
            ),
            2 => array(
              'icon' => 'banknotes',
              'title' => 'Częściowa wypłata',
              'body' => 'Rozliczona poprawnie.',
            ),
            3 => array(
              'icon' => 'bolt',
              'title' => 'Zawsze na żywo',
              'body' => 'Aktualne co do minuty.',
            ),
            4 => array(
              'icon' => 'chat-bubble-left-right',
              'title' => 'Bez sporów',
              'body' => 'Jasne odpowiedzi.',
            ),
            5 => array(
              'icon' => 'clock',
              'title' => 'Historia',
              'body' => 'Dla obu stron.',
            ),
          ),
          'cta' => array(
            'heading' => 'Wiedz, ile należy się każdemu pracownikowi.',
            'sub' => 'Bieżące saldo na żywo dla całej ekipy.',
          ),
        ),
        'roles-permissions' => array(
          'icon' => 'lock-closed',
          'title' => 'Role i uprawnienia',
          'body' => 'Kontroluj, kto widzi finanse, klientów i ustawienia.',
          'hero' => 'Daj ludziom dokładnie taki dostęp, jakiego potrzebują',
          'lead' => 'Role i uprawnienia pozwalają zespołowi wykonywać pracę bez wglądu w Twoje finanse, listę klientów czy ustawienia—więc możesz delegować bez obaw.',
          'rows' => array(
            0 => array(
              'heading' => 'Właściwy dostęp dla każdej roli',
              'text' => 'Brygadzista widzi grafiki i czas; biuro widzi klientów i faktury; tylko Ty widzisz pełny obraz finansów. Ustaw raz i śpij spokojnie.',
              'points' => array(
                0 => 'Kontroluj dostęp według roli',
                1 => 'Ukryj finanse przed terenem',
                2 => 'Ogranicz, kto edytuje ustawienia',
                3 => 'Deleguj z pewnością',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Role',
                'rows' => array(
                  0 => array(
                    'icon' => 'user',
                    'label' => 'Brygadzista',
                    'sub' => 'Grafik i czas',
                  ),
                  1 => array(
                    'icon' => 'user',
                    'label' => 'Biuro',
                    'sub' => 'Klienci i faktury',
                  ),
                  2 => array(
                    'icon' => 'lock-closed',
                    'label' => 'Właściciel',
                    'sub' => 'Pełny dostęp',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Rozwijaj zespół bezpiecznie',
              'text' => 'Dodając ludzi, przyznajesz tylko taki dostęp, jakiego potrzebują. Twoje wrażliwe liczby pozostają prywatne, nawet gdy z systemu korzysta więcej osób.',
              'points' => array(
                0 => 'Wdrażaj nowych ludzi bezpiecznie',
                1 => 'Chroń wrażliwe dane',
                2 => 'Ogranicz kosztowne błędy',
                3 => 'Skaluj bez utraty kontroli',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Rośniesz tylko tak szybko, jak potrafisz delegować. Role pozwalają oddać pracę bez oddawania ksiąg.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'lock-closed',
              'title' => 'Według roli',
              'body' => 'Dostosowany dostęp.',
            ),
            1 => array(
              'icon' => 'eye-slash',
              'title' => 'Ukryj finanse',
              'body' => 'Przed terenem.',
            ),
            2 => array(
              'icon' => 'cog-6-tooth',
              'title' => 'Blokada ustawień',
              'body' => 'Ogranicz edycje.',
            ),
            3 => array(
              'icon' => 'user-plus',
              'title' => 'Wdrażanie',
              'body' => 'Dodaj ludzi bezpiecznie.',
            ),
            4 => array(
              'icon' => 'shield-check',
              'title' => 'Prywatne',
              'body' => 'Wrażliwe dane bezpieczne.',
            ),
            5 => array(
              'icon' => 'arrow-trending-up',
              'title' => 'Skaluj',
              'body' => 'Rośnij pod kontrolą.',
            ),
          ),
          'cta' => array(
            'heading' => 'Deleguj bez oddawania ksiąg.',
            'sub' => 'Daj każdej osobie dokładnie taki dostęp, jakiego potrzebuje.',
          ),
        ),
        'job-costing' => array(
          'icon' => 'calculator',
          'title' => 'Kosztorysowanie robót',
          'body' => 'Koszt robocizny trafia na właściwy projekt automatycznie.',
          'hero' => 'Koszt robocizny na właściwej robocie, automatycznie',
          'lead' => 'Każda zatwierdzona godzina trafia na projekt, przy którym była przepracowana, więc robocizna—Twój największy koszt—pojawia się w kosztorysie bez zliczania kart pracy.',
          'rows' => array(
            0 => array(
              'heading' => 'Robocizna, która sama się kosztorysuje',
              'text' => 'Ponieważ ekipy śledzą czas według roboty, ich godziny i wypłata wpływają do kosztu każdego projektu automatycznie. Bez arkuszy podziału, bez szacunków.',
              'points' => array(
                0 => 'Godziny trafiają na właściwą robotę',
                1 => 'Stawki wliczają się w koszt',
                2 => 'Bez ręcznego podziału',
                3 => 'Zawsze aktualne',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'stat',
                'title' => 'Maple St · Robocizna',
                'rows' => array(
                  0 => array(
                    'label' => 'Godziny do tej pory',
                    'value' => '186',
                  ),
                  1 => array(
                    'label' => 'Koszt robocizny',
                    'value' => '$7 940',
                  ),
                  2 => array(
                    'label' => '% budżetu',
                    'value' => '71%',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Zobacz, która praca się opłaca',
              'text' => 'Gdy robocizna jest wyceniona dokładnie, wreszcie widzisz, które roboty i zadania naprawdę zarabiają—i wyceniasz kolejne mądrzej.',
              'points' => array(
                0 => 'Prawdziwy koszt robocizny na robotę',
                1 => 'Wychwyć opłacalną pracę',
                2 => 'Wychwyć przekroczenia wcześnie',
                3 => 'Wyceń następną robotę lepiej',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Większość wykonawców zaniża wycenę robocizny, bo nigdy nie śledzi jej według roboty. Hive pokazuje Ci prawdziwą liczbę.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'calculator',
              'title' => 'Auto-kosztorys',
              'body' => 'Robocizna na robocie.',
            ),
            1 => array(
              'icon' => 'clock',
              'title' => 'Z godzin',
              'body' => 'Śledzone według roboty.',
            ),
            2 => array(
              'icon' => 'bolt',
              'title' => 'Na żywo',
              'body' => 'Zawsze aktualne.',
            ),
            3 => array(
              'icon' => 'chart-bar',
              'title' => 'Widok zysku',
              'body' => 'Zobacz, co się opłaca.',
            ),
            4 => array(
              'icon' => 'exclamation-triangle',
              'title' => 'Przekroczenia',
              'body' => 'Wychwycone wcześnie.',
            ),
            5 => array(
              'icon' => 'light-bulb',
              'title' => 'Lepsze wyceny',
              'body' => 'Wyceń to trafnie.',
            ),
          ),
          'cta' => array(
            'heading' => 'Kosztorysuj robociznę bez liczenia.',
            'sub' => 'Zatwierdzone godziny trafiają na właściwą robotę automatycznie.',
          ),
        ),
      ),
    ),
    'communication' => array(
      'label' => 'Komunikacja',
      'eyebrow' => 'Komunikacja',
      'grid_heading' => 'Każda rozmowa zapisana',
      'cards' => array(
        'shared-inbox' => array(
          'icon' => 'chat-bubble-left-right',
          'title' => 'Wspólna skrzynka',
          'body' => 'Cały zespół pracuje na jednym zestawie rozmów—bez potrzeby prywatnych numerów.',
          'hero' => 'Jedna skrzynka dla całego zespołu',
          'lead' => 'Połączenia i SMS-y idą przez jedną wspólną linię firmową, więc zespół pracuje na tych samych rozmowach, a żaden wątek z klientem nie zostaje na prywatnym telefonie.',
          'rows' => array(
            0 => array(
              'heading' => 'Koniec z prywatnymi numerami',
              'text' => 'Klienci i podwykonawcy piszą na jeden numer firmowy. Każdy z zespołu może podjąć wątek, a rozmowa zostaje w firmie—nie na telefonie pracownika.',
              'points' => array(
                0 => 'Jedna wspólna linia firmowa',
                1 => 'Zespół pracuje na tych samych wątkach',
                2 => 'Żaden klient na prywatnym telefonie',
                3 => 'Ciągłość przy zmianach kadrowych',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Wspólna skrzynka',
                'rows' => array(
                  0 => array(
                    'icon' => 'chat-bubble-left-right',
                    'label' => 'Państwo Henderson',
                    'sub' => 'Pytanie o płytki',
                  ),
                  1 => array(
                    'icon' => 'chat-bubble-left-right',
                    'label' => 'Rivera Plumbing',
                    'sub' => 'Harmonogram',
                  ),
                  2 => array(
                    'icon' => 'chat-bubble-left-right',
                    'label' => 'Inspektor miejski',
                    'sub' => 'Potwierdzone',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Powiązane z pracą',
              'text' => 'Każda rozmowa łączy się z właściwym klientem i projektem, więc kontekst nigdy nie ginie, a każdy, kto się włącza, od razu jest na bieżąco.',
              'points' => array(
                0 => 'Wątki powiązane z pracami',
                1 => 'Pełny kontekst dla każdego, kto odpowiada',
                2 => 'Nic nie ginie w prywatnej wiadomości',
                3 => 'Historia z wyszukiwaniem',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Gdy rozmowy z klientami są własnością firmy, nigdy nie tracisz relacji z powodu odejścia pracownika.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'chat-bubble-left-right',
              'title' => 'Wspólna linia',
              'body' => 'Jeden numer firmowy.',
            ),
            1 => array(
              'icon' => 'users',
              'title' => 'Dostęp zespołu',
              'body' => 'Każdy może odpowiedzieć.',
            ),
            2 => array(
              'icon' => 'folder',
              'title' => 'Powiązane z pracą',
              'body' => 'Przypisane do projektów.',
            ),
            3 => array(
              'icon' => 'eye-slash',
              'title' => 'Bez numeru osobistego',
              'body' => 'Prywatność zachowana.',
            ),
            4 => array(
              'icon' => 'magnifying-glass',
              'title' => 'Z wyszukiwaniem',
              'body' => 'Znajdź każdy wątek.',
            ),
            5 => array(
              'icon' => 'shield-check',
              'title' => 'Ciągłość',
              'body' => 'Zostaje z tobą.',
            ),
          ),
          'cta' => array(
            'heading' => 'Zatrzymaj wątki z klientami w firmie.',
            'sub' => 'Jedna wspólna skrzynka, bez numerów osobistych.',
          ),
        ),
        'translations' => array(
          'icon' => 'language',
          'title' => 'Tłumaczenia',
          'body' => 'Pisz do ekip w ich języku i czytaj odpowiedzi w swoim.',
          'hero' => 'Rozmawiaj z każdą ekipą w jej języku',
          'lead' => 'Napisz do podwykonawcy lub członka ekipy w jego języku i czytaj odpowiedzi w swoim — automatycznie — więc język nigdy nie spowalnia pracy.',
          'rows' => array(
            0 => array(
              'heading' => 'Dwa języki, jeden wątek',
              'text' => 'Ty piszesz po angielsku, oni czytają po hiszpańsku; odpowiadają po hiszpańsku, ty czytasz po angielsku. Tłumaczenie dzieje się w wiadomości, w obie strony.',
              'points' => array(
                0 => 'Wysyłaj i odbieraj z tłumaczeniem',
                1 => 'Działa w obie strony',
                2 => 'W tym samym wątku',
                3 => 'Bez osobnej aplikacji',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Wątek · Tony R.',
                'rows' => array(
                  0 => array(
                    'icon' => 'language',
                    'label' => 'Ty (EN)',
                    'sub' => 'Zacznij płytki w poniedziałek',
                  ),
                  1 => array(
                    'icon' => 'language',
                    'label' => 'Tony (ES)',
                    'sub' => 'Entendido, lunes',
                  ),
                  2 => array(
                    'icon' => 'check-badge',
                    'label' => 'Ty czytasz',
                    'sub' => 'Zrozumiałem, poniedziałek',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Mniej błędów na budowie',
              'text' => 'Jasne instrukcje w języku, który ktoś naprawdę czyta, oznaczają mniej poprawek, mniej pomyłek i bezpieczniejszą pracę.',
              'points' => array(
                0 => 'Jaśniejsze instrukcje',
                1 => 'Mniej poprawek i pomyłek',
                2 => 'Bezpieczniejsza budowa',
                3 => 'Silniejsze relacje z ekipą',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Źle zrozumiana instrukcja może kosztować cały dzień. Tłumaczenie w wątku sprawia, że wszyscy są dosłownie na tej samej stronie.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'language',
              'title' => 'W obie strony',
              'body' => 'Wysyłaj i czytaj.',
            ),
            1 => array(
              'icon' => 'chat-bubble-left-right',
              'title' => 'W wątku',
              'body' => 'Ta sama rozmowa.',
            ),
            2 => array(
              'icon' => 'bolt',
              'title' => 'Automatycznie',
              'body' => 'Bez dodatkowych kroków.',
            ),
            3 => array(
              'icon' => 'shield-check',
              'title' => 'Mniej błędów',
              'body' => 'Jasne instrukcje.',
            ),
            4 => array(
              'icon' => 'users',
              'title' => 'Każda ekipa',
              'body' => 'Dotrzyj do wszystkich.',
            ),
            5 => array(
              'icon' => 'face-smile',
              'title' => 'Lepsze więzi',
              'body' => 'Silniejsze zespoły.',
            ),
          ),
          'cta' => array(
            'heading' => 'Nie pozwól, by język spowalniał pracę.',
            'sub' => 'Pisz do ekip w ich języku, czytaj odpowiedzi w swoim.',
          ),
        ),
        'text-to-task' => array(
          'icon' => 'calendar-date-range',
          'title' => 'Wiadomość w zadanie',
          'body' => 'Zamień przychodzącą wiadomość w zaplanowane zadanie z pomocą AI, sprawdzone przed zapisaniem.',
          'hero' => 'Zamień wiadomość w zadanie — natychmiast',
          'lead' => 'Gdy klient lub podwykonawca wyśle coś do zrobienia, AI tworzy z tego szkic zaplanowanego zadania, gotowy do sprawdzenia i zapisania jednym dotknięciem.',
          'rows' => array(
            0 => array(
              'heading' => 'Wyłap prośbę, utwórz zadanie',
              'text' => 'Wiadomość typu „możesz też naprawić tylną bramę w czwartek?” staje się szkicem zadania z właściwą pracą i datą — więc nigdy nie ginie w wątku.',
              'points' => array(
                0 => 'AI czyta wiadomość',
                1 => 'Tworzy szkic zadania z pracą i datą',
                2 => 'Sprawdzasz przed zapisaniem',
                3 => 'Nic nie umyka',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Proponowane zadanie',
                'rows' => array(
                  0 => array(
                    'icon' => 'chat-bubble-left-right',
                    'label' => 'Od: Henderson',
                    'sub' => 'Napraw tylną bramę w czw.',
                  ),
                  1 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Zadanie utworzone',
                    'sub' => 'Maple St · czw.',
                  ),
                  2 => array(
                    'icon' => 'check-circle',
                    'label' => 'Sprawdź i zapisz',
                    'sub' => 'Jedno dotknięcie',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Sprawdzone, nigdy w ciemno',
              'text' => 'AI proponuje, ty decydujesz. Każde zadanie jest pokazywane do zatwierdzenia, zanim trafi do harmonogramu, więc masz pełną kontrolę.',
              'points' => array(
                0 => 'Zatwierdzasz każde zadanie',
                1 => 'Edytuj przed zapisaniem',
                2 => 'Zachowaj pełną kontrolę',
                3 => 'Bez niespodzianek w kalendarzu',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'To właśnie drobne prośby ukryte w wiadomościach najłatwiej zapomnieć. Wiadomość w zadanie sprawia, że trafiają do harmonogramu.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'sparkles',
              'title' => 'AI czyta',
              'body' => 'Wychwytuje prośbę.',
            ),
            1 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Tworzy zadanie',
              'body' => 'Praca i data.',
            ),
            2 => array(
              'icon' => 'check-circle',
              'title' => 'Sprawdzone',
              'body' => 'Ty zatwierdzasz.',
            ),
            3 => array(
              'icon' => 'pencil',
              'title' => 'Edytowalne',
              'body' => 'Popraw najpierw.',
            ),
            4 => array(
              'icon' => 'folder',
              'title' => 'Na właściwej pracy',
              'body' => 'Właściwy projekt.',
            ),
            5 => array(
              'icon' => 'bell-alert',
              'title' => 'Nic nie ginie',
              'body' => 'Zawsze zapisane.',
            ),
          ),
          'cta' => array(
            'heading' => 'Przestań gubić prośby w wątku.',
            'sub' => 'Zamień wiadomość w zaplanowane zadanie jednym dotknięciem.',
          ),
        ),
        'recorded-calls' => array(
          'icon' => 'microphone',
          'title' => 'Nagrane rozmowy',
          'body' => 'Każda rozmowa nagrana, transkrybowana i podsumowana automatycznie.',
          'hero' => 'Każda rozmowa nagrana i podsumowana',
          'lead' => 'Rozmowy są nagrywane, transkrybowane i podsumowywane wraz z zadaniami — więc nigdy więcej nie zgubisz tego, co obiecano przez telefon.',
          'rows' => array(
            0 => array(
              'heading' => 'Nigdy nie polegaj na pamięci',
              'text' => 'Każda rozmowa jest nagrywana i transkrybowana, a następnie podsumowywana do kluczowych punktów i zadań, przypisana do właściwego klienta i pracy.',
              'points' => array(
                0 => 'Rozmowy nagrane i transkrybowane',
                1 => 'Podsumowane wraz z zadaniami',
                2 => 'Przypisane do klienta i pracy',
                3 => 'Do wyszukania później',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Rozmowa · Henderson',
                'rows' => array(
                  0 => array(
                    'icon' => 'microphone',
                    'label' => 'Nagrane',
                    'sub' => '6:42',
                  ),
                  1 => array(
                    'icon' => 'document-text',
                    'label' => 'Transkrypcja',
                    'sub' => 'Gotowe',
                  ),
                  2 => array(
                    'icon' => 'check-circle',
                    'label' => 'Zadanie',
                    'sub' => 'Wyślij wycenę płytek',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Rozstrzygaj spory "tak ustaliliśmy"',
              'text' => 'Gdy pojawia się pytanie, co ustalono przez telefon, masz nagranie i podsumowanie — koniec z "słowo przeciwko słowu".',
              'points' => array(
                0 => 'Dowód tego, co powiedziano',
                1 => 'Koniec sporów telefonicznych',
                2 => 'Rozliczaj podwykonawców',
                3 => 'Chroń swoją firmę',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Obietnice składane przez telefon najczęściej ulatują z pamięci. Ich nagrywanie chroni wszystkich.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'microphone',
              'title' => 'Nagrane',
              'body' => 'Każda rozmowa.',
            ),
            1 => array(
              'icon' => 'document-text',
              'title' => 'Transkrybowane',
              'body' => 'Pełny tekst.',
            ),
            2 => array(
              'icon' => 'sparkles',
              'title' => 'Podsumowane',
              'body' => 'Kluczowe punkty.',
            ),
            3 => array(
              'icon' => 'check-circle',
              'title' => 'Zadania',
              'body' => 'Wyodrębnione.',
            ),
            4 => array(
              'icon' => 'folder',
              'title' => 'Przypisane',
              'body' => 'Do projektu.',
            ),
            5 => array(
              'icon' => 'magnifying-glass',
              'title' => 'Przeszukiwalne',
              'body' => 'Znajdź później.',
            ),
          ),
          'cta' => array(
            'heading' => 'Nigdy nie zgub telefonicznej obietnicy.',
            'sub' => 'Rozmowy nagrywane, transkrybowane i podsumowywane za Ciebie.',
          ),
        ),
        'email-tracking' => array(
          'icon' => 'envelope',
          'title' => 'Śledzenie e-maili',
          'body' => 'Wiedz, kiedy ważne e-maile zostają otwarte, i przechowuj zapisy przy projekcie.',
          'hero' => 'Wiedz, kiedy Twoje e-maile docierają',
          'lead' => 'Zobacz, kiedy ważne e-maile zostają otwarte, i trzymaj każdą wiadomość przy projekcie — dzięki temu wiesz, czy klient naprawdę zobaczył wycenę.',
          'rows' => array(
            0 => array(
              'heading' => 'Koniec z domysłami',
              'text' => 'Wyślij wycenę lub aktualizację i zobacz, kiedy zostanie otwarta. Wiesz, czy się przypomnieć, czy dać klientowi czas — zamiast zgadywać.',
              'points' => array(
                0 => 'Zobacz, kiedy e-maile są otwierane',
                1 => 'Wiedz, czy się przypomnieć',
                2 => 'Wyczuj właściwy moment na kontakt',
                3 => 'Przestań zgadywać',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Wysłano · Wycena',
                'rows' => array(
                  0 => array(
                    'icon' => 'envelope',
                    'label' => 'Dostarczono',
                    'sub' => 'Pon. 9:10',
                  ),
                  1 => array(
                    'icon' => 'eye',
                    'label' => 'Otwarto',
                    'sub' => 'Pon. 9:14',
                  ),
                  2 => array(
                    'icon' => 'eye',
                    'label' => 'Otwarto ponownie',
                    'sub' => 'Wt. 7:02',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'W dokumentacji, przy projekcie',
              'text' => 'Ważne e-maile są przechowywane przy kliencie i projekcie, więc dokumentacja żyje tam, gdzie praca — a nie zakopana w prywatnej skrzynce.',
              'points' => array(
                0 => 'E-maile trzymane przy projekcie',
                1 => 'Przejrzysta dokumentacja',
                2 => 'Poza prywatnymi skrzynkami',
                3 => 'Łatwe do odnalezienia',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Wiedza, że klient otworzył Twoją wycenę trzy razy, mówi dokładnie, kiedy zadzwonić.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'eye',
              'title' => 'Śledzenie otwarć',
              'body' => 'Wiedz, kiedy przeczytano.',
            ),
            1 => array(
              'icon' => 'clock',
              'title' => 'Wyczucie czasu',
              'body' => 'Kontaktuj się w porę.',
            ),
            2 => array(
              'icon' => 'folder',
              'title' => 'Przy projekcie',
              'body' => 'W dokumentacji.',
            ),
            3 => array(
              'icon' => 'document-text',
              'title' => 'Dokumentacja',
              'body' => 'Przejrzysta historia.',
            ),
            4 => array(
              'icon' => 'envelope',
              'title' => 'Dostarczenie',
              'body' => 'Potwierdzone wysłanie.',
            ),
            5 => array(
              'icon' => 'magnifying-glass',
              'title' => 'Odniesienie',
              'body' => 'Znajdź szybko.',
            ),
          ),
          'cta' => array(
            'heading' => 'Wiedz, czy naprawdę to zobaczyli.',
            'sub' => 'Śledzenie otwarć i zapisy, które żyją przy projekcie.',
          ),
        ),
        'client-updates' => array(
          'icon' => 'users',
          'title' => 'Aktualizacje dla klienta',
          'body' => 'Wysyłaj właścicielom aktualizacje harmonogramu i statusu bez dodatkowego wysiłku.',
          'hero' => 'Informuj klientów — bez wysiłku',
          'lead' => 'Wysyłaj właścicielom aktualizacje harmonogramu i statusu automatycznie, aby byli poinformowani i spokojni, a Ty skupiony na budowie.',
          'rows' => array(
            0 => array(
              'heading' => 'Aktualizacje, które wysyłają się same',
              'text' => 'W miarę postępu prac i zmian terminów klienci otrzymują aktualizację przez portal i powiadomienia — bez osobnej wiadomości do napisania.',
              'points' => array(
                0 => 'Aktualizacje statusu i harmonogramu',
                1 => 'Wysyłane przez portal',
                2 => 'Bez dodatkowych wiadomości',
                3 => 'Klienci zawsze spokojni',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Wysłane do klienta',
                'rows' => array(
                  0 => array(
                    'icon' => 'eye',
                    'label' => 'Aktualizacja statusu',
                    'sub' => 'Ruszyła elektryka',
                  ),
                  1 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Harmonogram',
                    'sub' => 'Płytki pon. 7/6',
                  ),
                  2 => array(
                    'icon' => 'photo',
                    'label' => 'Nowe zdjęcia',
                    'sub' => 'Dodano 4',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Zadowoleni klienci, mniej telefonów',
              'text' => 'Poinformowani klienci dzwonią rzadziej i bardziej ufają. Stały strumień aktualizacji sprawia, że wyglądasz na zorganizowanego i panującego nad każdym projektem.',
              'points' => array(
                0 => 'Mniej telefonów "jakieś wieści?"',
                1 => 'Większe zaufanie klienta',
                2 => 'Wyglądaj na zorganizowanego i profesjonalnego',
                3 => 'Lepsze opinie i polecenia',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Proaktywne aktualizacje to najtańszy marketing, jaki masz — zamieniają klientów w polecenia.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'bolt',
              'title' => 'Automatyczne',
              'body' => 'Wysyła się samo.',
            ),
            1 => array(
              'icon' => 'eye',
              'title' => 'Status',
              'body' => 'Postęp na bieżąco.',
            ),
            2 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Harmonogram',
              'body' => 'Co dalej.',
            ),
            3 => array(
              'icon' => 'photo',
              'title' => 'Zdjęcia',
              'body' => 'Ujęcia z postępu.',
            ),
            4 => array(
              'icon' => 'face-smile',
              'title' => 'Mniej telefonów',
              'body' => 'Klienci spokojni.',
            ),
            5 => array(
              'icon' => 'star',
              'title' => 'Polecenia',
              'body' => 'Lepsze opinie.',
            ),
          ),
          'cta' => array(
            'heading' => 'Informuj klientów na autopilocie.',
            'sub' => 'Aktualizacje statusu i harmonogramu, które wysyłają się same.',
          ),
        ),
      ),
    ),
    'automation' => array(
      'label' => 'Automatyzacja i AI',
      'eyebrow' => 'Automatyzacja i AI',
      'grid_heading' => 'Niech rutyna działa sama',
      'cards' => array(
        'receipt-ai' => array(
          'icon' => 'document-magnifying-glass',
          'title' => 'AI do paragonów',
          'body' => 'Odczytuje dostawców, kwoty i pozycje z każdego paragonu.',
          'hero' => 'Paragony, które czytają się same',
          'lead' => 'Zrób zdjęcie lub prześlij paragon, a AI wyciągnie dostawcę, kwotę, datę i każdą pozycję — Twoje księgi wypełnią się bez jednego naciśnięcia klawisza.',
          'rows' => array(
            0 => array(
              'heading' => 'Od zdjęcia do zaksięgowania',
              'text' => 'Czy to zmięty papierowy paragon, czy PDF z maila, AI odczyta go i utworzy czysty wydatek z pozycjami, gotowy do przypisania do projektu.',
              'points' => array(
                0 => 'Odczytuje dostawcę, kwotę i datę',
                1 => 'Ujmuje każdą pozycję',
                2 => 'Działa na zdjęciach i plikach PDF',
                3 => 'Tworzy czysty wydatek',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Paragon · Menards',
                'rows' => array(
                  0 => array(
                    'icon' => 'document-magnifying-glass',
                    'label' => 'Dostawca',
                    'sub' => 'Menards',
                  ),
                  1 => array(
                    'icon' => 'banknotes',
                    'label' => 'Suma',
                    'sub' => '312,84 $',
                  ),
                  2 => array(
                    'icon' => 'list-bullet',
                    'label' => 'Pozycje',
                    'sub' => '11 zarejestrowanych',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Godziny odzyskane co tydzień',
              'text' => 'Koniec z wieczornym przepisywaniem paragonów do arkusza. Wprowadzanie danych dzieje się w momencie, gdy paragon trafia do systemu — ze szczegółami każdej pozycji.',
              'points' => array(
                0 => 'Bez ręcznego wprowadzania danych',
                1 => 'Zachowany szczegół pozycji',
                2 => 'Godziny oszczędzone co tydzień',
                3 => 'Księgi zawsze aktualne',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Sterta paragonów to miejsce, gdzie umiera księgowość. Automatyczne odczytywanie ich to sposób, by być na bieżąco.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-magnifying-glass',
              'title' => 'Odczytuje',
              'body' => 'Dostawca i suma.',
            ),
            1 => array(
              'icon' => 'list-bullet',
              'title' => 'Pozycje',
              'body' => 'Każda pozycja.',
            ),
            2 => array(
              'icon' => 'camera',
              'title' => 'Dowolny format',
              'body' => 'Zdjęcie lub PDF.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Do zlecenia',
              'body' => 'Szybkie przypisanie.',
            ),
            4 => array(
              'icon' => 'bolt',
              'title' => 'Natychmiast',
              'body' => 'Bez pisania.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Dokładnie',
              'body' => 'Czysty wydatek.',
            ),
          ),
          'cta' => array(
            'heading' => 'Przestań przepisywać paragony.',
            'sub' => 'AI odczytuje dostawcę, sumę i każdą pozycję za Ciebie.',
          ),
        ),
        'vendor-matching' => array(
          'icon' => 'arrows-right-left',
          'title' => 'Dopasowanie dostawców',
          'body' => 'Transakcje same dopasowują się do właściwego dostawcy i zlecenia.',
          'hero' => 'Transakcje, które porządkują się same',
          'lead' => 'Transakcje bankowe i kartowe same dopasowują się do właściwego dostawcy i zlecenia, więc uzgadnianie przestaje być weekendowym obowiązkiem.',
          'rows' => array(
            0 => array(
              'heading' => 'Dopasowanie zrobione za Ciebie',
              'text' => 'AI rozpoznaje dostawców z pomieszanych opisów bankowych i łączy każdą transakcję z właściwym dostawcą i zleceniem — ucząc się Twoich schematów na bieżąco.',
              'points' => array(
                0 => 'Rozpoznaje pomieszane opisy',
                1 => 'Łączy z dostawcą i zleceniem',
                2 => 'Uczy się Twoich schematów',
                3 => 'Mniej pracy co tydzień',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Dopasowano automatycznie',
                'rows' => array(
                  0 => array(
                    'icon' => 'arrows-right-left',
                    'label' => 'SQ *RIVERA PLB',
                    'sub' => 'Rivera Plumbing',
                  ),
                  1 => array(
                    'icon' => 'arrows-right-left',
                    'label' => 'MENARDS #214',
                    'sub' => 'Menards · Maple St',
                  ),
                  2 => array(
                    'icon' => 'check-badge',
                    'label' => 'Pewność',
                    'sub' => 'Wysoka',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Księgi, które szybko się uzgadniają',
              'text' => 'Gdy transakcje są wstępnie dopasowane, tylko potwierdzasz, zamiast kategoryzować. Uzgadnianie zajmuje minuty zamiast godzin.',
              'points' => array(
                0 => 'Potwierdzaj zamiast kategoryzować',
                1 => 'Godziny stają się minutami',
                2 => 'Mniej źle skategoryzowanych kosztów',
                3 => 'Kosztorys zlecenia pozostaje dokładny',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Ręczne dopasowywanie transakcji to najwolniejsza część księgowości. Automatyzacja tego to godziny odzyskane co miesiąc.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'arrows-right-left',
              'title' => 'Auto-dopasowanie',
              'body' => 'Dostawca i zlecenie.',
            ),
            1 => array(
              'icon' => 'sparkles',
              'title' => 'Uczy się',
              'body' => 'Twoich schematów.',
            ),
            2 => array(
              'icon' => 'check-badge',
              'title' => 'Pewne',
              'body' => 'Ocenione dopasowania.',
            ),
            3 => array(
              'icon' => 'calculator',
              'title' => 'Rozliczone na zlecenie',
              'body' => 'Właściwy projekt.',
            ),
            4 => array(
              'icon' => 'clock',
              'title' => 'Szybciej',
              'body' => 'Minuty, nie godziny.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Dokładnie',
              'body' => 'Czyste księgi.',
            ),
          ),
          'cta' => array(
            'heading' => 'Niech uzgadnianie zrobi się samo.',
            'sub' => 'Transakcje dopasowują się do właściwego dostawcy i zlecenia.',
          ),
        ),
        'retailer-scraping' => array(
          'icon' => 'globe-alt',
          'title' => 'Pobieranie od sprzedawców',
          'body' => 'Pobieraj wyszczególnione paragony wprost z kont dostawców.',
          'hero' => 'Wyszczególnione paragony, pobrane za Ciebie',
          'lead' => 'Połącz konta dostawców, a Hive automatycznie pobiera pełne wyszczególnione paragony — dostajesz szczegół każdej pozycji bez zapisywania choćby jednego kwitka.',
          'rows' => array(
            0 => array(
              'heading' => 'Prosto ze źródła',
              'text' => 'W sklepach, w których kupujesz najczęściej, Hive pobiera kompletny wyszczególniony paragon wprost z Twojego konta — każdy SKU, ilość i cenę.',
              'points' => array(
                0 => 'Pobiera z kont dostawców',
                1 => 'Pełny szczegół na poziomie SKU',
                2 => 'Bez zapisywania kwitków',
                3 => 'Nic nie umyka',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Pobrano · Home Depot',
                'rows' => array(
                  0 => array(
                    'icon' => 'globe-alt',
                    'label' => 'Zamówienie #4821',
                    'sub' => '14 pozycji',
                  ),
                  1 => array(
                    'icon' => 'list-bullet',
                    'label' => 'Szczegół pozycji',
                    'sub' => 'SKU i ilość',
                  ),
                  2 => array(
                    'icon' => 'folder',
                    'label' => 'Przypisano',
                    'sub' => 'Maple St',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Lepsze niż zdjęcie',
              'text' => 'Pobrane paragony niosą szczegóły, które na zdjęciu mogą wyblaknąć lub zostać ucięte — dają czystsze zapisy, dokładniejszy kosztorys i łatwiejsze zwroty.',
              'points' => array(
                0 => 'Więcej szczegółów niż zdjęcie',
                1 => 'Czystsze trwałe zapisy',
                2 => 'Dokładniejszy kosztorys zlecenia',
                3 => 'Łatwiejsze zwroty i gwarancje',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Szczegóły na wyblakłym papierowym paragonie znikają w kilka miesięcy. Pobranie ich ze źródła zachowuje je na zawsze.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'globe-alt',
              'title' => 'Ze źródła',
              'body' => 'Konta dostawców.',
            ),
            1 => array(
              'icon' => 'list-bullet',
              'title' => 'Szczegół SKU',
              'body' => 'Każda pozycja.',
            ),
            2 => array(
              'icon' => 'bolt',
              'title' => 'Automatycznie',
              'body' => 'Bez kwitków.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Przypisane',
              'body' => 'Do zlecenia.',
            ),
            4 => array(
              'icon' => 'arrow-uturn-left',
              'title' => 'Zwroty',
              'body' => 'Łatwy dowód.',
            ),
            5 => array(
              'icon' => 'check-circle',
              'title' => 'Trwałe',
              'body' => 'Nigdy nie blaknie.',
            ),
          ),
          'cta' => array(
            'heading' => 'Miej pełny paragon automatycznie.',
            'sub' => 'Wyszczególniony detal pobrany z Twoich kont dostawców.',
          ),
        ),
        'text-to-task' => array(
          'icon' => 'calendar-date-range',
          'title' => 'Wiadomość w zadanie',
          'body' => 'Zamień przychodzącą wiadomość w zaplanowane zadanie, najpierw sprawdzone.',
          'hero' => 'AI zamienia wiadomości w zaplanowaną pracę',
          'lead' => 'Przychodzące SMS-y zawierające zadanie do wykonania stają się szkicami zadań w Twoim grafiku — napisane przez AI, sprawdzone przez Ciebie — więc nic nie umyka.',
          'rows' => array(
            0 => array(
              'heading' => 'AI robi pisanie za Ciebie',
              'text' => 'Wiadomość wspominająca o pracy staje się szkicem zadania z uzupełnionym właściwym zleceniem, datą i szczegółami. Ty tylko rzucasz okiem i potwierdzasz.',
              'points' => array(
                0 => 'AI czyta przychodzące wiadomości',
                1 => 'Tworzy kompletne zadanie',
                2 => 'Zlecenie, data i szczegóły ustawione',
                3 => 'Potwierdzasz jednym dotknięciem',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Utworzono z SMS-a',
                'rows' => array(
                  0 => array(
                    'icon' => 'chat-bubble-left-right',
                    'label' => 'Przychodzące',
                    'sub' => 'Załatać płytę g-k w pt',
                  ),
                  1 => array(
                    'icon' => 'calendar-date-range',
                    'label' => 'Zadanie',
                    'sub' => 'Oak Ave · pt',
                  ),
                  2 => array(
                    'icon' => 'check-circle',
                    'label' => 'Potwierdź',
                    'sub' => 'Jedno dotknięcie',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Zawsze najpierw sprawdzone',
              'text' => 'AI nigdy nie planuje za Twoimi plecami. Każde proponowane zadanie czeka na Twoją akceptację, więc zachowujesz pełną kontrolę nad kalendarzem.',
              'points' => array(
                0 => 'Nic nie planowane w ciemno',
                1 => 'Najpierw zatwierdź lub edytuj',
                2 => 'Pełna kontrola nad kalendarzem',
                3 => 'Automatyzacja godna zaufania',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Automatyzacja, której możesz zaufać, to automatyzacja, którą możesz sprawdzić. Hive proponuje; Ty decydujesz.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'sparkles',
              'title' => 'AI tworzy szkic',
              'body' => 'Z wiadomości.',
            ),
            1 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Zaplanowane',
              'body' => 'Właściwa data.',
            ),
            2 => array(
              'icon' => 'check-circle',
              'title' => 'Sprawdzone',
              'body' => 'Ty zatwierdzasz.',
            ),
            3 => array(
              'icon' => 'pencil',
              'title' => 'Do edycji',
              'body' => 'Najpierw popraw.',
            ),
            4 => array(
              'icon' => 'folder',
              'title' => 'Na budowie',
              'body' => 'Właściwy projekt.',
            ),
            5 => array(
              'icon' => 'bell-alert',
              'title' => 'Zapisane',
              'body' => 'Nigdy nie zgubione.',
            ),
          ),
          'cta' => array(
            'heading' => 'Zamień wiadomości w zaplanowaną pracę.',
            'sub' => 'AI tworzy szkic zadania; Ty potwierdzasz jednym dotknięciem.',
          ),
        ),
        'call-summaries' => array(
          'icon' => 'microphone',
          'title' => 'Podsumowania rozmów',
          'body' => 'Każda nagrana rozmowa transkrybowana i podsumowana z listą działań.',
          'hero' => 'Każda rozmowa, podsumowana dla Ciebie',
          'lead' => 'Nagrane rozmowy są transkrybowane i skracane do zwięzłego podsumowania z listą działań — dzięki temu poznajesz sedno i zadania bez odtwarzania czegokolwiek.',
          'rows' => array(
            0 => array(
              'heading' => 'Wnioski, a nie ponowne odsłuchanie',
              'text' => 'AI zamienia długą rozmowę w kilka jasnych punktów i listę działań, przypisanych do właściwego klienta i projektu, gotowych do realizacji.',
              'points' => array(
                0 => 'Pełna transkrypcja zapisana',
                1 => 'Zwięzłe, jasne podsumowanie',
                2 => 'Działania wyodrębnione',
                3 => 'Powiązane z klientem i projektem',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Podsumowanie · Henderson',
                'rows' => array(
                  0 => array(
                    'icon' => 'sparkles',
                    'label' => 'Podsumowanie',
                    'sub' => '3 kluczowe punkty',
                  ),
                  1 => array(
                    'icon' => 'check-circle',
                    'label' => 'Działanie',
                    'sub' => 'Wyślij wycenę płytek',
                  ),
                  2 => array(
                    'icon' => 'check-circle',
                    'label' => 'Działanie',
                    'sub' => 'Potwierdź start w pon.',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Nic nie umyka po rozmowie',
              'text' => 'Obietnice i kolejne kroki z rozmowy stają się zadaniami do wykonania — dzięki temu praca uzgodniona przez telefon faktycznie zostaje zrobiona.',
              'points' => array(
                0 => 'Obietnice stają się zadaniami',
                1 => 'Kolejne kroki nigdy zapomniane',
                2 => 'Realizacja za każdym razem',
                3 => 'Zapis, któremu możesz zaufać',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Większość zaniedbań zaczyna się od rozmowy, której nikt nie zapisał. Podsumowania z listą działań zamykają tę lukę.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'document-text',
              'title' => 'Transkrybowane',
              'body' => 'Pełny tekst.',
            ),
            1 => array(
              'icon' => 'sparkles',
              'title' => 'Podsumowane',
              'body' => 'Kluczowe punkty.',
            ),
            2 => array(
              'icon' => 'check-circle',
              'title' => 'Lista działań',
              'body' => 'Wyodrębnione.',
            ),
            3 => array(
              'icon' => 'folder',
              'title' => 'Przypisane',
              'body' => 'Do projektu.',
            ),
            4 => array(
              'icon' => 'calendar-date-range',
              'title' => 'Na zadania',
              'body' => 'Działaj.',
            ),
            5 => array(
              'icon' => 'magnifying-glass',
              'title' => 'Wyszukiwalne',
              'body' => 'Znajdź później.',
            ),
          ),
          'cta' => array(
            'heading' => 'Poznaj sedno każdej rozmowy.',
            'sub' => 'Transkrybowane, podsumowane, z gotową listą działań.',
          ),
        ),
        'maps-autocomplete' => array(
          'icon' => 'map-pin',
          'title' => 'Mapy i autouzupełnianie',
          'body' => 'Autouzupełnianie adresów i mapy budów wbudowane w system.',
          'hero' => 'Adresy i mapy, wbudowane',
          'lead' => 'Autouzupełnianie adresów wpisuje poprawne adresy budów w trakcie pisania, a wbudowane mapy doprowadzają Twoją ekipę pod właściwe drzwi za każdym razem.',
          'rows' => array(
            0 => array(
              'heading' => 'Poprawne adresy za każdym razem',
              'text' => 'Zacznij pisać i wybierz zweryfikowany adres. Bez literówek, bez błędnych numerów lokali, bez ekipy jadącej na złą ulicę.',
              'points' => array(
                0 => 'Autouzupełnianie w trakcie pisania',
                1 => 'Zweryfikowane, ustandaryzowane adresy',
                2 => 'Bez literówek i błędnych lokali',
                3 => 'Spójne dane',
              ),
              'panel' => array(
                'style' => 'gray',
                'type' => 'list',
                'title' => 'Nowa budowa',
                'rows' => array(
                  0 => array(
                    'icon' => 'map-pin',
                    'label' => 'Wpisane',
                    'sub' => '142 Maple...',
                  ),
                  1 => array(
                    'icon' => 'check-badge',
                    'label' => 'Zweryfikowane',
                    'sub' => '142 Maple St',
                  ),
                  2 => array(
                    'icon' => 'map',
                    'label' => 'Na mapie',
                    'sub' => 'Trasa gotowa',
                  ),
                ),
              ),
            ),
            1 => array(
              'heading' => 'Ekipy trafiają pod drzwi',
              'text' => 'Każdy projekt zawiera mapę i trasę, więc ekipa szybko dociera na właściwą budowę — mniej straconego czasu, mniej spalonego paliwa, mniej spóźnionych startów.',
              'points' => array(
                0 => 'Mapy w każdym projekcie',
                1 => 'Trasa jednym dotknięciem',
                2 => 'Mniej jazdy pod zły adres',
                3 => 'Starty na czas',
              ),
              'panel' => array(
                'style' => 'indigo',
                'type' => 'note',
                'label' => 'Dlaczego to ważne',
                'note' => 'Zły adres to stracony poranek. Poprawne adresy i wbudowane mapy utrzymują ekipy w ruchu.',
              ),
            ),
          ),
          'features' => array(
            0 => array(
              'icon' => 'map-pin',
              'title' => 'Autouzupełnianie',
              'body' => 'W trakcie pisania.',
            ),
            1 => array(
              'icon' => 'check-badge',
              'title' => 'Zweryfikowane',
              'body' => 'Bez literówek.',
            ),
            2 => array(
              'icon' => 'map',
              'title' => 'Wbudowane mapy',
              'body' => 'W każdym projekcie.',
            ),
            3 => array(
              'icon' => 'arrow-top-right-on-square',
              'title' => 'Trasa',
              'body' => 'Jedno dotknięcie.',
            ),
            4 => array(
              'icon' => 'clock',
              'title' => 'Na czas',
              'body' => 'Szybko znajdź.',
            ),
            5 => array(
              'icon' => 'bolt',
              'title' => 'Mniej paliwa',
              'body' => 'Bez zbędnych tras.',
            ),
          ),
          'cta' => array(
            'heading' => 'Doprowadź ekipy pod właściwe drzwi.',
            'sub' => 'Autouzupełnianie adresów i mapy wbudowane w system.',
          ),
        ),
      ),
    ),
  ),
);
