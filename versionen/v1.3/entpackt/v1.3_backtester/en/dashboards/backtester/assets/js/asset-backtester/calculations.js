export const PRESETS = [
  ['1w', '1W'],
  ['1m', '1M'],
  ['6m', '6M'],
  ['1y', '1Y'],
  ['3y', '3Y'],
  ['5y', '5Y'],
  ['10y', '10Y'],
  ['max', 'Max'],
  ['custom', 'Custom'],
];

export function isoDate(value) {
  const date = value instanceof Date ? new Date(value) : new Date(`${value}T00:00:00Z`);
  if (Number.isNaN(date.getTime())) throw new TypeError('Invalid date');
  return date.toISOString().slice(0, 10);
}

export function todayIso(now = new Date()) {
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

export function yesterdayIso(now = new Date()) {
  const day = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
  day.setUTCDate(day.getUTCDate() - 1);
  return isoDate(day);
}

const DAY_IN_MS = 24 * 60 * 60 * 1000;

export function isoToDayNumber(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) throw new TypeError('Invalid ISO date');
  const timestamp = Date.parse(`${value}T00:00:00Z`);
  if (!Number.isFinite(timestamp) || new Date(timestamp).toISOString().slice(0, 10) !== value) {
    throw new TypeError('Invalid ISO date');
  }
  return Math.floor(timestamp / DAY_IN_MS);
}

export function dayNumberToIso(value) {
  const dayNumber = Number(value);
  if (!Number.isInteger(dayNumber)) throw new TypeError('Invalid day number');
  return new Date(dayNumber * DAY_IN_MS).toISOString().slice(0, 10);
}

export function constrainDayRange(startValue, endValue, minValue, maxValue, moved = 'both') {
  const values = [startValue, endValue, minValue, maxValue].map(Number);
  if (!values.every(Number.isInteger)) throw new TypeError('Invalid day range');

  const [rawStart, rawEnd, min, max] = values;
  if (max - min < 1) throw new RangeError('The date range must include at least two days.');

  let start = Math.min(Math.max(rawStart, min), max - 1);
  let end = Math.min(Math.max(rawEnd, min + 1), max);

  if (start >= end) {
    if (moved === 'start') start = end - 1;
    else end = start + 1;
  }

  return { start, end };
}

export function subtractCalendar(endIso, preset) {
  const end = new Date(`${endIso}T00:00:00Z`);
  if (preset === '1w') {
    end.setUTCDate(end.getUTCDate() - 7);
    return isoDate(end);
  }

  const match = /^(\d+)(m|y)$/.exec(preset);
  if (!match) throw new TypeError('Unknown interval');
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
  if (preset === 'custom') throw new TypeError('Custom data must be provided explicitly.');
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

export function classifyReturn(value) {
  if (!Number.isFinite(Number(value)) || Math.abs(Number(value)) < 0.00005) return 'is-neutral';
  return Number(value) > 0 ? 'is-positive' : 'is-negative';
}

export function sortSummaryRows(rows, period, direction = 'desc') {
  const multiplier = direction === 'asc' ? 1 : -1;
  return [...rows]
    .map((row, index) => ({ row, index }))
    .sort((left, right) => {
      const a = Number(left.row?.periods?.[period]?.returnPct);
      const b = Number(right.row?.periods?.[period]?.returnPct);
      const aValid = left.row?.periods?.[period]?.returnPct != null && Number.isFinite(a);
      const bValid = right.row?.periods?.[period]?.returnPct != null && Number.isFinite(b);
      if (!aValid && !bValid) return left.index - right.index;
      if (!aValid) return 1;
      if (!bValid) return -1;
      if (a === b) return left.index - right.index;
      return (a - b) * multiplier;
    })
    .map(({ row }) => row);
}

export function debounce(callback, delay = 300) {
  let timer = null;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => callback(...args), delay);
  };
}
