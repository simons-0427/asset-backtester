import { readFile, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const importDirectory = join(root, 'var', 'import');
const monthNumbers = new Map([
  ['Jan', 1], ['Feb', 2], ['Mar', 3], ['Apr', 4], ['May', 5], ['Jun', 6],
  ['Jul', 7], ['Aug', 8], ['Sep', 9], ['Oct', 10], ['Nov', 11], ['Dec', 12],
]);

function parseEnglishDate(value) {
  const match = /^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) (\d{1,2}), (\d{4})$/.exec(value);
  if (!match) return null;
  const month = monthNumbers.get(match[1]);
  const day = Number(match[2]);
  const year = Number(match[3]);
  const date = new Date(Date.UTC(year, month - 1, day));
  if (date.getUTCFullYear() !== year || date.getUTCMonth() + 1 !== month || date.getUTCDate() !== day) return null;
  return date.toISOString().slice(0, 10);
}

function extractRows(buffer, label) {
  const strings = (buffer.toString('latin1').match(/[ -~]{3,}/g) || [])
    .map((value) => value.trim())
    .filter(Boolean);
  if (!strings.includes('WORLD Standard (Large+Mid Cap)') || !strings.includes('USD')) {
    throw new Error(`${label}: MSCI-World-Preisindex in USD wurde nicht eindeutig erkannt.`);
  }

  const rows = [];
  for (let index = 0; index < strings.length - 1; index += 1) {
    const date = parseEnglishDate(strings[index]);
    if (!date) continue;
    const valueText = strings.slice(index + 1, index + 5)
      .find((value) => /^\d{1,3}(,\d{3})*(\.\d+)?$/.test(value));
    if (!valueText) throw new Error(`${label}: Kein Indexwert nach ${strings[index]} gefunden.`);
    const value = Number(valueText.replaceAll(',', ''));
    if (!Number.isFinite(value) || value <= 0) throw new Error(`${label}: Ungültiger Wert am ${date}.`);
    rows.push({ date, value, valueText: value.toFixed(3) });
  }

  const dates = new Set();
  for (let index = 0; index < rows.length; index += 1) {
    const row = rows[index];
    if (dates.has(row.date)) throw new Error(`${label}: Doppeltes Datum ${row.date}.`);
    if (index > 0 && rows[index - 1].date >= row.date) throw new Error(`${label}: Datumsfolge ist nicht aufsteigend.`);
    dates.add(row.date);
  }
  return rows;
}

function parseCsvLine(line) {
  const values = [];
  let value = '';
  let quoted = false;
  for (let index = 0; index < line.length; index += 1) {
    const character = line[index];
    if (character === '"') {
      if (quoted && line[index + 1] === '"') {
        value += '"';
        index += 1;
      } else quoted = !quoted;
    } else if (character === ',' && !quoted) {
      values.push(value);
      value = '';
    } else value += character;
  }
  values.push(value);
  return values;
}

function parseEcbRates(csv) {
  const lines = csv.trim().split(/\r?\n/);
  const headers = parseCsvLine(lines.shift());
  const dateIndex = headers.indexOf('TIME_PERIOD');
  const valueIndex = headers.indexOf('OBS_VALUE');
  const currencyIndex = headers.indexOf('CURRENCY');
  const denominatorIndex = headers.indexOf('CURRENCY_DENOM');
  if ([dateIndex, valueIndex, currencyIndex, denominatorIndex].some((index) => index < 0)) {
    throw new Error('EZB-Datei enthält nicht alle benötigten Spalten.');
  }

  const rates = [];
  for (const line of lines) {
    if (!line.trim()) continue;
    const values = parseCsvLine(line);
    const date = values[dateIndex];
    const rate = Number(values[valueIndex]);
    if (values[currencyIndex] !== 'USD' || values[denominatorIndex] !== 'EUR') continue;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !Number.isFinite(rate) || rate <= 0) continue;
    rates.push({ date, rate });
  }
  rates.sort((left, right) => left.date.localeCompare(right.date));
  if (!rates.length) throw new Error('EZB-Datei enthält keine gültigen USD/EUR-Kurse.');
  return rates;
}

function rawCsv(rows, frequency) {
  const lines = ['date,value,currency,quality_status,source_revision,source_timestamp'];
  for (const row of rows) {
    lines.push([
      row.date,
      row.valueText,
      'USD',
      'valid',
      `msci-world-price-${row.revisionType ?? frequency}:${row.date}`,
      `${row.date}T23:59:59Z`,
    ].join(','));
  }
  return `${lines.join('\n')}\n`;
}

function mergedCsv(rows, rates) {
  const lines = [
    'date,value,currency,quality_status,source_revision,source_timestamp,msci_world_usd_close,usd_per_eur,fx_observation_date',
  ];
  let rateIndex = 0;
  let currentRate = null;
  for (const row of rows) {
    while (rateIndex < rates.length && rates[rateIndex].date <= row.date) {
      currentRate = rates[rateIndex];
      rateIndex += 1;
    }
    if (!currentRate) throw new Error(`Vor ${row.date} fehlt ein EZB-Referenzkurs.`);
    const revisionType = row.revisionType ?? row.frequency;
    const eurValue = (row.value / currentRate.rate).toFixed(10);
    lines.push([
      row.date,
      eurValue,
      'EUR',
      'valid',
      `msci-world-price-${revisionType}:${row.date};ecb-usd-eur:${currentRate.date}`,
      `${row.date}T23:59:59Z`,
      row.valueText,
      String(currentRate.rate),
      currentRate.date,
    ].join(','));
  }
  return `${lines.join('\n')}\n`;
}

const extractedMonthly = extractRows(await readFile(join(root, 'olddata.xls')), 'olddata.xls');
const daily = extractRows(await readFile(join(root, 'recentdata.xls')), 'recentdata.xls');

const expected = {
  monthly: { count: 72, first: '2016-08-31', last: '2022-07-29' },
  daily: { count: 1042, first: '2022-08-15', last: '2026-08-14' },
};
for (const [name, rows] of [['monthly', extractedMonthly], ['daily', daily]]) {
  const check = expected[name];
  if (rows.length !== check.count || rows[0]?.date !== check.first || rows.at(-1)?.date !== check.last) {
    throw new Error(`${name}: Unerwarteter Datenumfang; Konvertierung abgebrochen.`);
  }
}
const monthly = [
  {
    date: '2016-07-31',
    value: 1716,
    valueText: '1716.000',
    revisionType: 'manual-monthly',
  },
  ...extractedMonthly,
];
if (monthly.at(-1).date >= daily[0].date) throw new Error('Monats- und Tagesdaten überschneiden sich unerwartet.');

const rates = parseEcbRates(await readFile(join(importDirectory, 'ecb-usd-eur-msci.csv'), 'utf8'));
const combined = [
  ...monthly.map((row) => ({ ...row, frequency: 'monthly' })),
  ...daily.map((row) => ({ ...row, frequency: 'daily' })),
];

await writeFile(join(importDirectory, 'msci_world_monthly.csv'), rawCsv(monthly, 'monthly'), 'utf8');
await writeFile(join(importDirectory, 'msci_world_daily.csv'), rawCsv(daily, 'daily'), 'utf8');
await writeFile(join(importDirectory, 'msci_world.csv'), mergedCsv(combined, rates), 'utf8');

console.log(JSON.stringify({
  monthly: { rows: monthly.length, first: monthly[0].date, last: monthly.at(-1).date },
  daily: { rows: daily.length, first: daily[0].date, last: daily.at(-1).date },
  merged: { rows: combined.length, first: combined[0].date, last: combined.at(-1).date },
}, null, 2));
