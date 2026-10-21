const euro = new Intl.NumberFormat('de-DE', {
  style: 'currency',
  currency: 'EUR',
  maximumFractionDigits: 2,
});

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
  return isFiniteValue(value) ? euro.format(Number(value)) : '–';
}

export function formatSourceValue(value, currency = 'EUR') {
  if (!isFiniteValue(value)) return '–';
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
