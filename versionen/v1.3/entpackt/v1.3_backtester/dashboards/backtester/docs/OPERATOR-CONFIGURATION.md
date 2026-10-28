# Betreiberkonfiguration für die kostenlosen Live-Daten

Stand: 15. August 2026

Die historischen Reihen liegen bereits in der Datenbank. Nur die zukünftige Goldfortschreibung wird auf GoldAPI XAU/USD umgestellt. Die laufende Fortschreibung verwendet:

- S&P 500: FRED-Serie `SP500`, täglicher Preisindexschluss ohne Dividenden
- Gold: GoldAPI `XAU/USD`, historischer Tageswert pro Feinunze
- EUR-Umrechnung: EZB-Reihe `EXR.D.USD.EUR.SP00.A`, gleicher Tag oder letzter zuvor veröffentlichter Referenzkurs
- Beginn des geschützten Gold-Live-Bereichs: 08.08.2026

## Zukünftige Goldfortschreibung über GoldAPI XAU/USD

GoldAPI liefert `XAU`-Spotpreise pro Feinunze. Die bereits importierten Werte
bis 07.08.2026 bleiben unverändert. Neue XAU-Werte werden mit dem festen
Verhältnis `398,47 / 4.341,91 = 0,091772975488` in die bestehende Goldreihe
überführt. Ein historischer Backfill ist nicht erforderlich.

Der GoldAPI-Key wird serverseitig als `BACKTESTER_GOLDAPI_API_KEY` oder im
Provider-Block `goldapi` hinterlegt und über den Header `x-access-token`
gesendet, nicht als URL-Parameter.

## Noch durch den Betreiber zu erledigen

1. Einen FRED-API-Key für die Reihe `SP500` erstellen.
2. Den GoldAPI-Key aus dem Dashboard serverseitig als `BACKTESTER_GOLDAPI_API_KEY` setzen.
3. Laufzeitdateien hochladen und `sql/backtester/007_goldapi_forward_calibrated.sql` importieren.
4. Den normalen Backtester einmal öffnen oder den vorhandenen täglichen Cronlauf abwarten. Das löst den Gold-Sync automatisch aus; vor dem ersten abgeschlossenen Handelstag bleibt die Reihe unverändert.
5. Danach den Status prüfen und den täglichen Cronlauf für Gold freigeben.
6. S&P 500 und Gold einzeln testen.

## Multpl-Dividendenrendite

Der S&P-Preisindex bleibt unverändert. Die zusätzliche Monatsreihe kommt aus
`https://www.multpl.com/s-p-500-dividend-yield/table/by-month` und benötigt
keinen API-Key. Konfiguration:

```php
'multpl_dividend_yield' => [
    'enabled' => true,
    'url' => 'https://www.multpl.com/s-p-500-dividend-yield/table/by-month',
    'source_slug' => 'multpl-sp500-dividend-yield',
    'backfill_from' => '2016-07-01',
    'recent_months' => 3,
    'timeout_seconds' => 20,
    'max_retries' => 2,
    'retry_base_ms' => 750,
],
```

Unter `features` aktiviert
`dividend_adjustments = true` die Dividendenberechnung. Die vollständige
Backfill-Anleitung steht in `DIVIDEND-YIELD-SCRAPER.md`.

## FRED-Konfiguration

In `config/backtester.php` unter `providers` ergänzen:

```php
'fred' => [
    'base_url' => 'https://api.stlouisfed.org/fred/series/observations',
    'api_key' => getenv('BACKTESTER_FRED_API_KEY') ?: 'REDACTED_PRIVATE_VALUE',
    'timeout_seconds' => 20,
    'max_retries' => 3,
    'retry_base_ms' => 500,
    'backfill_chunk_days' => 7300,
    'live_updates_from' => '2026-08-03',
],
```

Der FRED-Key besteht aus 32 kleingeschriebenen Buchstaben und Ziffern. Der Provider lehnt ein anderes Format ab, bevor eine Anfrage gesendet wird.

## GoldAPI-Konfiguration

Der vorhandene Block muss so aussehen:

```php
'goldapi' => [
    'base_url' => 'https://www.goldapi.io/api',
    'api_key' => getenv('BACKTESTER_GOLDAPI_API_KEY') ?: 'REDACTED_PRIVATE_VALUE',
    'timeout_seconds' => 20,
    'max_retries' => 3,
    'retry_base_ms' => 500,
    'backfill_chunk_days' => 30,
    'recent_window_days' => 3,
    'live_updates_from' => '2026-08-03',
],
```

Der Key wird niemals in die URL, in JavaScript, SQL, Logs oder Screenshots geschrieben.

## EZB-Konfiguration

Der Block ist optional, weil der Code die offizielle URL als sicheren Standard kennt:

```php
'ecb' => [
    'base_url' => 'https://data-api.ecb.europa.eu/service/data/EXR/D.USD.EUR.SP00.A',
    'timeout_seconds' => 20,
    'max_retries' => 3,
    'retry_base_ms' => 500,
],
```

## Datenbankwerte

Die Migration `007_goldapi_forward_calibrated.sql` setzt:

| Asset | Quelle | Symbol/Reihe | Rohwährung | gespeicherter Vergleichswert |
|---|---|---|---|---|
| S&P 500 | FRED + EZB + Multpl | `SP500` | USD | Preisindex in EUR plus reinvestierte monatliche Dividendenrechnung |
| Gold | GoldAPI.io | `XAU/USD` | USD | XAU-Spotwert in EUR |

Für den S&P 500 wird `identifier_type = fred_series_id` gespeichert; für Gold `identifier_type = metal_pair` und `identifier_value = XAU/USD`. Die bestehenden Goldbeobachtungen werden nicht verändert.

## Geheimnisse

API-Keys, Datenbankpasswort und Cron-Geheimnis gehören ausschließlich in `config/backtester.php`, eine serverseitige Umgebungsvariable oder die geschützte Konfiguration des Zeitplaners. Sie dürfen nicht in SQL, Dokumentation, URLs, Logs oder Screenshots erscheinen.
