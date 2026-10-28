import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('MSCI-World-CSV-Dateien enthalten die geprüften Monats-, Tages- und EUR-Reihen', () => {
  const monthly = read('var/import/msci_world_monthly.csv').trim().split(/\r?\n/);
  const daily = read('var/import/msci_world_daily.csv').trim().split(/\r?\n/);
  const merged = read('var/import/msci_world.csv').trim().split(/\r?\n/);

  assert.equal(monthly.length - 1, 73);
  assert.equal(daily.length - 1, 1042);
  assert.equal(merged.length - 1, 1115);
  assert.equal(monthly[1], '2016-07-31,1716.000,USD,valid,msci-world-price-manual-monthly:2016-07-31,2016-07-31T23:59:59Z');
  assert.equal(monthly.at(-1).split(',')[0], '2022-07-29');
  assert.equal(daily[1].split(',')[0], '2022-08-15');
  assert.equal(daily.at(-1).split(',')[0], '2026-08-14');
  assert.equal(merged[0], 'date,value,currency,quality_status,source_revision,source_timestamp,msci_world_usd_close,usd_per_eur,fx_observation_date');

  const rows = merged.slice(1).map((line) => line.split(','));
  const dates = rows.map(([date]) => date);
  assert.equal(new Set(dates).size, 1115);
  assert.deepEqual(dates, [...dates].sort());
  rows.forEach((row) => {
    assert.ok(Number(row[1]) > 0);
    assert.equal(row[2], 'EUR');
    assert.ok(Number(row[6]) > 0);
    assert.ok(Number(row[7]) > 0);
    assert.ok(row[8] <= row[0]);
  });
});

test('MSCI-World-Dividendenplan enthält 2016 bis 2030 ohne Schätzkennzeichen', () => {
  const sql = read('sql/backtester/010_msci_world.sql');
  const rows = [...sql.matchAll(/'((?:20)\d{2})-12-01', '\1-12-31', ([0-9.]+), 0,/g)]
    .map(([, year, value]) => [Number(year), Number(value)]);

  assert.deepEqual(rows, [
    [2016, 2.69], [2017, 2.46], [2018, 2.50], [2019, 2.56], [2020, 2.14],
    [2021, 1.84], [2022, 2.15], [2023, 2.18], [2024, 1.87], [2025, 1.77],
    [2026, 1.65], [2027, 1.70], [2028, 1.75], [2029, 1.80], [2030, 1.85],
  ]);
  assert.match(sql, /'2016-07-31', 40, 0, 1\)/);
});

test('Gesamtvergleich zeigt Hauptklassen und bündelt alle Linien sowie Inflation in Einstellungen', () => {
  const html = read('index.html');
  const app = read('assets/js/asset-backtester/app.js');
  const api = read('assets/js/asset-backtester/api.js');
  const css = read('assets/css/asset-backtester.css');
  const backtestService = read('includes/backtester/Services/BacktestService.php');
  const config = read('config/backtester.example.php');
  const checkbox = (value) => html.match(new RegExp(`<input[^>]+value="${value}"[^>]*>`))?.[0] || '';
  const mainOptions = html.match(/asset-backtester__asset-selector-main">([\s\S]*?)<\/div>\s*<details/)?.[1] || '';
  const configOptions = html.match(/id="comparison-config">([\s\S]*?)<\/details>/)?.[1] || '';

  assert.match(checkbox('sp500'), /\bchecked\b/);
  assert.match(checkbox('bitcoin'), /\bchecked\b/);
  assert.match(checkbox('gold'), /\bchecked\b/);
  assert.match(checkbox('us_real_estate'), /\bchecked\b/);
  assert.match(checkbox('euro_money_market'), /\bchecked\b/);
  assert.doesNotMatch(checkbox('sp500_price'), /\bchecked\b/);
  assert.doesNotMatch(checkbox('msci_world_price'), /\bchecked\b/);
  assert.doesNotMatch(checkbox('dax'), /\bchecked\b/);
  assert.doesNotMatch(checkbox('eurostoxx50'), /\bchecked\b/);
  assert.doesNotMatch(checkbox('de_real_estate'), /\bchecked\b/);
  assert.doesNotMatch(checkbox('msci_world'), /\bchecked\b/);
  ['sp500', 'bitcoin', 'gold', 'de_real_estate', 'msci_world', 'us_real_estate',
    'global_ig_bonds', 'commodities', 'euro_money_market', 'us_money_market'].forEach((value) => {
    assert.match(mainOptions, new RegExp(`value="${value}"`));
  });
  ['dax', 'eurostoxx50', 'global_ig_bonds_unhedged'].forEach((value) => {
    assert.doesNotMatch(mainOptions, new RegExp(`value="${value}"`));
    assert.match(configOptions, new RegExp(`value="${value}"`));
  });
  [
    'sp500_price', 'msci_world_price', 'dax_price', 'eurostoxx50_price',
  ].forEach((value) => {
    assert.doesNotMatch(mainOptions, new RegExp(`value="${value}"`));
    assert.match(configOptions, new RegExp(`value="${value}"`));
  });
  assert.doesNotMatch(html, /de_real_estate_price|us_real_estate_price/);
  assert.match(html, /Einstellungen <span data-comparison-config-count>5\/17</);
  assert.match(html, /data-select-all>Alle auswählen</);
  assert.match(html, /data-clear-all>Alle abwählen</);
  assert.match(html, /id="comparison-inflation"/);
  assert.match(html, /value="none" selected/);
  assert.doesNotMatch(html, /value="inflation_de" selected/);
  assert.match(html, /value="custom"/);
  assert.match(html, /id="comparison-taxes-enabled" type="checkbox" autocomplete="off"/);
  assert.doesNotMatch(html, /id="comparison-taxes-enabled" type="checkbox" checked/);
  assert.match(html, /data-tax-column hidden>Modellsteuer/);
  assert.match(configOptions, /data-tax-settings hidden/);
  assert.match(configOptions, /id="comparison-ter-enabled" type="checkbox"/);
  assert.doesNotMatch(configOptions, /id="comparison-ter-enabled" type="checkbox" checked/);
  assert.match(configOptions, /id="comparison-ter-pct"[^>]+value="0\.10"[^>]+disabled/);
  assert.equal((html.match(/data-summary-sort=/g) || []).length, 7);
  assert.match(html, /data-single-chart="msci_world"/);
  assert.match(html, /id="chart-msci_world"/);
  assert.match(html, /data-single-chart="de_real_estate"/);
  assert.match(html, /data-single-chart="dax"/);
  assert.match(html, /data-single-chart="eurostoxx50"/);
  assert.match(html, /data-single-chart="us_real_estate"/);
  assert.match(html, /data-single-chart="global_ig_bonds"/);
  assert.match(html, /data-single-chart="commodities"/);
  assert.equal((html.match(/data-net-rent-input="us_real_estate"[^>]+value="5"/g) || []).length, 1);
  assert.equal((html.match(/data-net-rent-input="de_real_estate"[^>]+value="3"/g) || []).length, 1);
  assert.match(app, /netRentPct:\s*5/);
  assert.match(app, /deNetRentPct:\s*3/);
  assert.match(app, /netRentValue !== null && netRentValue !== ''/);
  assert.match(api, /netRentPct = 0/);
  assert.match(api, /deNetRentPct = 0/);
  assert.match(app, /includeRentalCashflow:\s*true/);
  assert.match(app, /includeRentalCashflow:\s*false/);
  assert.doesNotMatch(app, /singleTerEnabled/);
  assert.equal((app.match(/includeTer:\s*state\.comparisonTerEnabled/g) || []).length, 2);
  assert.match(app, /comparisonTerEnabled:\s*false/);
  assert.match(app, /taxesEnabled:\s*false/);
  assert.match(app, /inflation:\s*'none'/);
  assert.doesNotMatch(app, /params\.get\('inflation'\)/);
  assert.doesNotMatch(app, /params\.set\('inflation'/);
  assert.doesNotMatch(app, /params\.has\('taxes'\)/);
  assert.doesNotMatch(app, /params\.set\('taxes'/);
  assert.match(app, /comparisonTerValue !== null && comparisonTerValue !== ''/);
  assert.match(app, /bitcoin:\s*0\.15/);
  assert.match(app, /gold:\s*0\.12/);
  assert.match(app, /us_real_estate:\s*0\.40/);
  assert.match(app, /control\.hidden = !state\.comparisonTerEnabled/);
  assert.match(css, /asset-backtester__ter-control[\s\S]*?width:\s*100%/);
  assert.match(css, /asset-backtester__ter-copy[\s\S]*?justify-content:\s*space-between/);
  assert.match(css, /asset-backtester__percent-input--ter-wide input[\s\S]*?width:\s*100%/);
  assert.match(css, /asset-backtester__details-body a\s*\{\s*color:\s*var\(--accent\)/);
  assert.doesNotMatch(css, /asset-backtester__knowledge-link:hover\s*\{\s*color:\s*var\(--accent2\)/);
  assert.doesNotMatch(backtestService, /supportsTer/);
  assert.match(backtestService, /priceOnlyBeforeTaxNominal\s*=\s*\$amount[\s\S]*?\$terFactor/);
  assert.match(app, /includeTaxes:\s*false/);
  assert.match(app, /applySaleTaxes\(series,state/);
  assert.match(config, /default_net_rental_cashflow_pct'\s*=>\s*5\.0/);
  assert.match(config, /default_de_net_rental_cashflow_pct'\s*=>\s*3\.0/);
});

test('FHFA-Immobilien-Backfill enthält nur Monatswerte 2016 bis heute', () => {
  const csv = read('var/import/us_real_estate.csv').trim().split(/\r?\n/);
  assert.equal(csv[0], 'date,value,currency,quality_status,source_revision');
  assert.equal(csv.length - 1, 125);
  assert.match(csv[1], /^2016-01-01,221\.59,PTS,/);
  assert.match(csv.at(-1), /^2026-05-01,442\.42,PTS,/);
  csv.slice(1).forEach((line) => {
    const [date, value, currency] = line.split(',');
    assert.match(date, /^\d{4}-\d{2}-01$/);
    assert.ok(Number(value) > 0);
    assert.equal(currency, 'PTS');
  });
});

test('Projektseite ergänzt die neuen Funktionen ohne ihr Format umzubauen', () => {
  const project = read('../../projekte/backtester.html');

  assert.match(project, /Aktienindizes, Bitcoin, Gold, Immobilien, Anleihen, Rohstoffe und Geldmarkt/);
  assert.match(project, /Der Vergleich startet nominal und ohne Steuermodell/);
  assert.match(project, /CoinGecko liefert Bitcoin in Euro, FRED den S&amp;P 500 und GoldAPI den Goldpreis/);
  assert.match(project, /US-Immobilienpreise kommen von der FHFA über FRED/);
  assert.match(project, /MySQL-Upsert mit Revisionen, Fälligkeiten und Locks/);
  assert.doesNotMatch(project, /Drei Reihen auf derselben Kapitalbasis|Vier Diagramme/);
  assert.equal((project.match(/<section class="data-card/g) || []).length, 3);
  assert.equal((project.match(/<section class="skill-card/g) || []).length, 8);
  assert.equal((project.match(/<div class="sidebar-card"/g) || []).length, 2);
  assert.equal((project.match(/<li><strong>/g) || []).length, 13);
  assert.equal((project.match(/<style>/g) || []).length, 1);
  assert.match(project, /<link rel="stylesheet" href="project-responsive\.css">/);
  assert.doesNotMatch(project, /\b(?:delve|foster|leverage|utilize|facilitate|empower|streamline|robust|cutting-edge|transformative|supercharge|harness)\b/i);
});
