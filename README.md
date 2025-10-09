# Phone Click Tracker

Prosta i lekka wtyczka WordPress do śledzenia kliknięć w linki `tel:` oraz `mailto:`. Zdarzenia są wysyłane z frontendu przez AJAX do jednego, bezpiecznego endpointu w WordPressie i dalej forwardowane do webhooka Zapier.

## Spis treści
- [Funkcje](#funkcje)
- [Wymagania](#wymagania)
- [Instalacja](#instalacja)
- [Konfiguracja](#konfiguracja)
- [Jak to działa](#jak-to-działa)
- [Schemat payloadu](#schemat-payloadu)
  - [Kliknięcia w telefon (tel:)](#kliknięcia-w-telefon-tel)
  - [Kliknięcia w e‑mail (mailto:)](#kliknięcia-w-e-mail-mailto)
- [Akcje AJAX i backend](#akcje-ajax-i-backend)
- [Bezpieczeństwo i prywatność](#bezpieczeństwo-i-prywatność)
- [Testy i DoD](#testy-i-dod)
- [Rozwiązywanie problemów](#rozwiazywanie-problemow)
- [Changelog](#changelog)
- [FAQ](#faq)

## Funkcje
- Delegowane śledzenie kliknięć w `a[href^="tel:"]` oraz `mailto:` (odporne na wielkość liter).
- Fire‑and‑forget: brak ingerencji w UX (bez `preventDefault`), klient telefonu/poczty otwiera się normalnie.
- Cooldown 60 s dla tego samego numeru/adresu e‑mail (odfiltrowanie przypadkowych podwójnych kliknięć).
- Zaawansowana atrybucja źródła (Google/FB CPC, Instagram/FB Organic, Newsletter/SMS) na podstawie ciasteczek `pysTrafficSource`, `pys_utm_medium` oraz `pys_utm_source`.
- Mapowanie kampanii po domenie (spójne z istniejącymi zasadami).
- Wspólny backendowy handler (weryfikacja nonce, wykluczanie IP, forward do Zapiera).

## Wymagania
- WordPress 5.0+
- jQuery na froncie (ładowane domyślnie przez WordPress)
- Dostępny `admin-ajax.php`

## Instalacja
1. Skopiuj katalog wtyczki do `wp-content/plugins/phone-click-tracker/`.
2. W panelu WP włącz wtyczkę „Phone Click Tracker”.
3. Upewnij się, że na froncie ładuje się `js/tracker.js` (wtyczka ładuje go automatycznie dla IP niewykluczonych).

## Konfiguracja
- Lista wykluczonych IP (np. biuro, dev): rozszerz filtrem `pct_excluded_ips` w motywie/wtyczce:
```php
add_filter('pct_excluded_ips', function(array $ips) {
    $ips[] = '203.0.113.10';
    return $ips;
});
```
- **Webhook Zapier**: Aby zapewnić maksymalne bezpieczeństwo, adres URL webhooka Zapier **musi** być zdefiniowany w pliku `wp-config.php`. Dodaj poniższą linię:
```php
define('PCT_ZAPIER_WEBHOOK_URL', 'https://hooks.zapier.com/hooks/catch/xxxx/yyyy/');
```
Wtyczka odczyta tę stałą i bezpiecznie prześle dane.

### Tryb debugowania (NOWOŚĆ w 1.6.0)
Aby włączyć szczegółowe logowanie błędów i kroków działania wtyczki, dodaj poniższą linię w pliku `phone-click-tracker.php` na samej górze, tuż pod informacjami o wtyczce:
```php
define('PCT_DEBUG_MODE', true);
```
Gdy tryb jest włączony (`true`):
- **Logi serwera**: Szczegółowe informacje o działaniu wtyczki będą zapisywane w pliku `/wp-content/debug.log`.
- **Logi przeglądarki**: Informacje o zdarzeniach po stronie klienta (kliknięcia, wysyłki AJAX) będą widoczne w konsoli deweloperskiej przeglądarki (F12).

Ustawienie `false` wyłącza całe logowanie. **Uwaga**: W trybie debugowania adresy IP `194.11.13.241` oraz `171.25.230.115` są automatycznie przepuszczane (nie są blokowane), aby umożliwić testowanie.

## Jak to działa
- Frontend (`js/tracker.js`):
  - Nasłuchuje kliknięć na całym `document` i reaguje, gdy kliknięto link `tel:` lub `mailto:`.
  - Buduje `payload` z informacjami o zdarzeniu, atrybucji i urządzeniu.
  - Wysyła AJAX POST do WordPressa z nonce i odpowiednią akcją (`track_phone_click` lub `track_email_click`).
- Backend (`phone-click-tracker.php`):
  - Odrzuca ruch z wykluczonych IP.
  - Weryfikuje nonce `phone_tracker_nonce`.
  - Forwarduje payload do Zapier i zwraca `wp_send_json_success`.

## Schemat payloadu
Wspólne pola dla obu typów zdarzeń:
- `Data` – lokalna data i czas (YYYY-MM-DD HH:mm:ss)
- `Źródło` – atrybucja pozyskana z ciasteczek i reguł. Logika:
  - **CPC**: `google cpc`, `facebook cpc`
  - **Newsletter**: `newsletter` (dla medium `email`), `Kampania SMS` (dla medium `sms`), `Newsletter Inne`
  - **Organic Social**: `instagram organic`, `facebook organic` (dla FB, LI, Messenger)
  - **Organic Search**: `organic` (dla Google, Bing, Yahoo itp.)
  - **Inne**: `direct`, `referral`
- `URL na którym kliknięto`
- `Szerokość ekranu`, `Wysokość ekranu`
- `Urządzenie` – desktop/mobile/tablet
- `Domena` – `hostname` bez prefiksu `www.`
- `pys_traffic_source`, `pys_utm_medium`, `pys_utm_source`, `pys_landing_page`
- `Spółka (kampania)` – z `getCampaignName(domena, ...)`
- `Wersja skryptu` – `1.6.0`

### Kliknięcia w telefon (tel:)
Dodatkowe pola:
- `Numer w który kliknięto` – numer po normalizacji

AJAX:
- `action: track_phone_click`

### Kliknięcia w e‑mail (mailto:)
Dodatkowe pola:
- `Typ zdarzenia` = `email`
- `Adres email w który kliknięto` – adres z `mailto:` (po `decodeURIComponent`, obcięciu parametrów i normalizacji do lower‑case)

AJAX:
- `action: track_email_click`

## Akcje AJAX i backend
- `wp_ajax_nopriv_track_phone_click` / `wp_ajax_track_phone_click` → `pct_handle_phone_click_ajax`
- `wp_ajax_nopriv_track_email_click` / `wp_ajax_track_email_click` → `pct_handle_phone_click_ajax`

Handler robi:
1. Sprawdzenie IP (`pct_is_ip_excluded`).
2. Weryfikację nonce: `check_ajax_referer('phone_tracker_nonce', 'security')`.
3. Forward do Zapiera tym samym webhookiem.

## Bezpieczeństwo i prywatność
- Nonce: `phone_tracker_nonce` przekazywany do JS przez `wp_localize_script`.
- IP‑bypass: JS nie jest w ogóle enqueue’owany dla wykluczonych IP.
- Brak przechowywania danych po stronie WP – dane są forwardowane do Zapier.
- Brak wpływu na UX (nie blokujemy akcji kliknięcia).

## Testy i DoD
- Kliknięcie w `mailto:` generuje jedno żądanie z `action=track_email_click`, poprawnym nonce i payloadem zawierającym:
  - `Typ zdarzenia`=`email`, `Adres email w który kliknięto`, `Data`, `Źródło`, `URL na którym kliknięto`, `Szerokość/Wysokość ekranu`, `Urządzenie`, `Domena`, `pys_*` cookies, `Spółka (kampania)`, `Wersja skryptu`=`1.6.0`.
- Powtórne kliknięcia tego samego adresu w ≤60 s nie generują kolejnych żądań; >60 s są zliczane ponownie.
- Backend zwraca JSON success i forwarduje payload do Zapiera; istniejące `tel:` działa bez regresji.
- `pys_landing_page` jest spójne w payloadach telefonu i e‑maila.

### Jak przetestować ręcznie
1. Otwórz dowolną podstronę z linkiem `mailto:`.
2. Otwórz DevTools → Network → `Fetch/XHR`.
3. Kliknij link `mailto:`; sprawdź, że pojawia się żądanie `admin-ajax.php` z `action=track_email_click` i prawidłowym `payload`.
4. Powtórz kliknięcie w ≤60 s – brak kolejnego żądania; po 60 s żądanie pojawi się ponownie.
5. Zweryfikuj w Zapier, że zdarzenie dotarło na webhook.

## Rozwiązywanie problemów
- **Brak żądania AJAX w zakładce Network:**
  - **Włącz tryb debugowania:** Ustaw `define('PCT_DEBUG_MODE', true);` w pliku `phone-click-tracker.php`.
  - **Sprawdź logi**: Otwórz plik `/wp-content/debug.log` i konsolę przeglądarki (F12). Powinny się tam znajdować szczegółowe informacje, np. o wykluczeniu IP lub braku obiektu konfiguracyjnego.
  - Sprawdź, czy Twoje IP nie jest na liście wykluczonych.
  - Upewnij się, że `js/tracker.js` jest załadowany na stronie.
  - Zweryfikuj, że obiekt `phone_tracker_config` (z `nonce` i `ajax_url`) jest obecny w `window`.
- Błędy lintera PHP w środowisku lokalnym bez WP (undefined `add_action`, `admin_url` itp.) są oczekiwane – to funkcje WordPressa.

## Changelog
### 1.6.0
- **Feature**: Dodano zaawansowany tryb debugowania, aktywowany flagą `PCT_DEBUG_MODE`.
- **Feature**: Wprowadzono szczegółowe logowanie po stronie serwera (PHP) do pliku `/wp-content/debug.log`.
- **Feature**: Dodano logowanie po stronie klienta (JavaScript) do konsoli przeglądarki, zsynchronizowane z flagą debugowania.
- **Chore**: Ujednolicono wersjonowanie wtyczki i skryptu do `1.6.0`.

### 1.5.0
- **Refactor**: Zsynchronizowano i rozbudowano logikę atrybucji źródeł ruchu.
- **Feature**: Wydzielono `instagram organic` jako osobne źródło.
- **Feature**: Dodano szczegółowe śledzenie kampanii newsletterowych na podstawie `utm_medium` (email, SMS, inne).
- **Chore**: Ujednolicono wersjonowanie wtyczki i skryptu do `1.5.0`.

### 1.4.0
- Dodano śledzenie `mailto:` (fire‑and‑forget, delegowane, odporne na wielkość liter).
- Dekodowanie adresów e‑mail (`decodeURIComponent`).
- Ujednolicono `pys_landing_page` w payloadzie telefonu.

### 1.3.0
- Bazowe śledzenie `tel:`.

## FAQ
- **Jak skonfigurować webhook Zapier?**
  - Wklej swój adres URL webhooka do pliku `wp-config.php` w głównym katalogu WordPressa, używając stałej `define('PCT_ZAPIER_WEBHOOK_URL', 'TWOJ_WEBHOOK_URL');`.
- **Czy mogę zmienić webhook Zapier?**
  - Tak, edytując wartość stałej `PCT_ZAPIER_WEBHOOK_URL` w pliku `wp-config.php`.
- **Czy można wyłączyć trackowanie dla określonych użytkowników/IP?**
  - Tak, użyj filtra `pct_excluded_ips`.
- **Czy wtyczka zbiera dane osobowe?**
  - Wysyła dane o kliknięciach (w tym adresy e‑mail z `mailto:`). Upewnij się, że posiadasz podstawę prawną i informację w polityce prywatności.