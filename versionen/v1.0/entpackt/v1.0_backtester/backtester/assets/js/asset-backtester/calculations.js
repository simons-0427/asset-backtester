export const PRESETS = [
  ['1w', '1W'],
  ['1m', '1M'],
  ['6m', '6M'],
  ['1y', '1Y'],
  ['3y', '3Y'],
  ['5y', '5Y'],
  ['10y', '10Y'],
  ['max', 'Max'],
  ['custom', 'Individuell'],
];

export function isoDate(value) {
  const date = value instanceof Date ? new Date(value) : new Date(`${value}T00:00:00Z`);
  if (Number.isNaN(date.getTime())) throw new TypeError('Ungültiges Datum');
  return date.toISOString().slice(0, 10);
}

export function yesterdayIso(now = new Date()) {
  const day = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
  day.setUTCDate(day.getUTCDate() - 1);
  return isoDate(day);
}

export function subtractCalendar(endIso, preset) {
  const end = new Date(`${endIso}T00:00:00Z`);
  if (preset === '1w') {
    end.setUTCDate(end.getUTCDate() - 7);
    return isoDate(end);
  }

  const match = /^(\d+)(m|y)$/.exec(preset);
  if (!match) throw new TypeError('Unbekanntes Intervall');
  const amount = Number(match[1]);
  const unit = match[2];
  const originalDay = end.getUTCDate();
  end.setUTCDate(1);
  if (unit === 'm') end.setUTCMonth(end.getUTCMonth() - amount);
  else end.setUTCFullYear(end.getUTCFullYear() - amount);
  const lastDay = new Date(Date.UTC(end.getUTCFullYear(), end.getUTCMonth() + 1, 0)).getUTCDate();
  end.setUTCDate(Math.min(originalDay, lastDay));
  return isoDate(end);
}

export function rangeForPreset(preset, end, firstDates = []) {
  if (preset === 'custom') throw new TypeError('Benutzerdefinierte Daten müssen explizit angegeben werden.');
  const start = preset === 'max'
    ? latestIso(firstDates.filter(Boolean))
    : subtractCalendar(end, preset);
  return { start, end };
}

export function latestIso(values) {
  if (!values.length) return null;
  return [...values].sort().at(-1);
}

export function chooseLongestCommonPreset(firstDates, end) {
  const firstCommon = latestIso(firstDates.filter(Boolean));
  if (!firstCommon) return '10y';
  for (const preset of ['10y', '5y', '3y', '1y', '6m', '1m', '1w']) {
    if (subtractCalendar(end, preset) >= firstCommon) return preset;
  }
  return 'max';
}

export function keepAtLeastOne(selected, changedSlug, checked) {
  const next = new Set(selected);
  if (checked) next.add(changedSlug);
  else next.delete(changedSlug);
  if (next.size === 0) return { selected: new Set(selected), accepted: false };
  return { selected: next, accepted: true };
}

export function classifyReturn(value) {
  if (!Number.isFinite(Number(value)) || Math.abs(Number(value)) < 0.00005) return 'is-neutral';
  return Number(value) > 0 ? 'is-positive' : 'is-negative';
}

export function debounce(callback, delay = 300) {
  let timer = null;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => callback(...args), delay);
  };
}

