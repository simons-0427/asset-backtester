const DISPLAY_CURRENCIES = new Set(['EUR', 'GBP', 'USD', 'JPY', 'CNY']);
const moneyFormatters = new Map();
const compactMoneyFormatters = new Map();

let displayCurrency = 'EUR';
let eurConversionRate = 1;

function moneyFormatter(currency) {
  if (!moneyFormatters.has(currency)) {
    moneyFormatters.set(currency, new Intl.NumberFormat('de-DE', {
      style: 'currency',
      currency,
      currencyDisplay: 'narrowSymbol',
      minimumFractionDigits: currency === 'JPY' ? 0 : 2,
      maximumFractionDigits: currency === 'JPY' ? 0 : 2,
    }));
  }
  return moneyFormatters.get(currency);
}

function compactMoneyFormatter(currency) {
  if (!compactMoneyFormatters.has(currency)) {
    compactMoneyFormatters.set(currency, new Intl.NumberFormat('de-DE', {
      style: 'currency',
      currency,
      currencyDisplay: 'narrowSymbol',
      notation: 'compact',
      maximumFractionDigits: 1,
    }));
  }
  return compactMoneyFormatters.get(currency);
}

const sourceNumber = new Intl.NumberFormat('de-DE', {
  maximumFractionDigits: 4,
});

const date = new Intl.DateTimeFormat('de-DE', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  timeZone: 'UTC',
});

const integer = new Intl.NumberFormat('de-DE', {
  maximumFractionDigits: 0,
});

function isFiniteValue(value) {
  return value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value));
}

export function formatEuro(value) {
  return isFiniteValue(value)
    ? moneyFormatter(displayCurrency).format(Number(value) * eurConversionRate)
    : '–';
}

export function formatCompactMoney(value) {
  return isFiniteValue(value)
    ? compactMoneyFormatter(displayCurrency).format(Number(value) * eurConversionRate)
    : '–';
}

export function setDisplayCurrency(currency, rate) {
  const normalizedCurrency = String(currency || '').toUpperCase();
  const normalizedRate = Number(rate);
  if (!DISPLAY_CURRENCIES.has(normalizedCurrency) || !Number.isFinite(normalizedRate) || normalizedRate <= 0) {
    return false;
  }
  displayCurrency = normalizedCurrency;
  eurConversionRate = normalizedRate;
  return true;
}

export function getDisplayCurrency() {
  return displayCurrency;
}

export function formatSourceValue(value, currency = 'EUR') {
  if (!isFiniteValue(value)) return '–';
  if (currency === 'PTS') return `${sourceNumber.format(Number(value))} Pkt.`;
  return `${sourceNumber.format(Number(value))} ${currency === 'EUR' ? '€' : currency}`;
}

export function formatPercent(value, digits = 2) {
  if (!isFiniteValue(value)) return '–';
  const number = Number(value);
  const sign = number > 0 ? '+' : '';
  return `${sign}${number.toLocaleString('de-DE', { minimumFractionDigits: digits, maximumFractionDigits: digits })} %`;
}

export function formatPlainPercent(value, digits = 2) {
  if (!isFiniteValue(value)) return '–';
  return `${Number(value).toLocaleString('de-DE', { minimumFractionDigits: digits, maximumFractionDigits: digits })} %`;
}

export function formatRatio(value, digits = 2) {
  if (!isFiniteValue(value)) return '–';
  return Number(value).toLocaleString('de-DE', { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

export function formatInteger(value) {
  return isFiniteValue(value) ? integer.format(Number(value)) : '–';
}

export function formatDate(value) {
  if (!value) return '–';
  const parsed = new Date(`${value}T00:00:00Z`);
  return Number.isNaN(parsed.getTime()) ? '–' : date.format(parsed);
}

export function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
