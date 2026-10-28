import { TAX_DEFAULTS,taxSettingsFromQuery,writeTaxQuery,applySaleTaxes } from './tax-model.js?v=20261028-v13y';
import { mountTaxControls,syncTaxControls,bindTaxControls,TaxOverview } from './tax-ui.js?v=20261028-v13y';
import { initialRanking, nextRanking, compareRankedMetrics } from './ranking.js?v=20261028-v13y';
import { BacktesterApi } from './api.js?v=20261028-v13y';
import { LoadingProgress, nextPaint, loadChartLibrary } from './loading.js?v=20261028-v13y';
import {
  PRESETS,
  chooseLongestCommonPreset,
  classifyReturn,
  constrainDayRange,
  dayNumberToIso,
  debounce,
  isoToDayNumber,
  rangeForPreset,
  sortSummaryRows,
  todayIso,
} from './calculations.js?v=20261028-v13y';
import { ChartManager } from './charts.js?v=20261028-v13y';
import {
  applyHistoricalCurrencyToSeries,
  applyHistoricalCurrencyToSummary,
  DISPLAY_CURRENCIES,
  loadStoredCurrencyHistory,
  loadStoredCurrencyRates,
} from './currency.js?v=20261028-v13y';
import {
  escapeHtml,
  formatDate,
  formatEuro,
  formatInteger,
  formatPercent,
  formatPlainPercent,
  formatRatio,
  formatSourceValue,
  setDisplayCurrency,
} from './formatters.js?v=20261028-v13y';

const ASSET_SLUGS = [
  'sp500', 'bitcoin', 'gold', 'msci_world', 'de_real_estate',
  'us_real_estate', 'dax', 'eurostoxx50',
  'global_ig_bonds', 'global_ig_bonds_unhedged', 'commodities',
  'euro_money_market', 'us_money_market',
];
const DEFAULT_COMPARISON_SLUGS = ['sp500', 'bitcoin', 'gold', 'us_real_estate', 'euro_money_market'];
const TER_DEFAULTS = {
  sp500: 0.07,
  bitcoin: 0.15,
  gold: 0.12,
  msci_world: 0.12,
  de_real_estate: 0.40,
  us_real_estate: 0.40,
  dax: 0.08,
  eurostoxx50: 0.10,
  global_ig_bonds: 0.10,
  global_ig_bonds_unhedged: 0.10,
  commodities: 0.19,
  euro_money_market: 0.10,
  us_money_market: 0.10,
};
const DIVIDEND_COMPARISON = {
  sp500: { priceOption: 'sp500_price', totalLabel: 'S&P 500', priceLabel: 'S&P500 ohne Div.' },
  msci_world: { priceOption: 'msci_world_price', totalLabel: 'MSCI World', priceLabel: 'MSCI World ohne Div.' },
  dax: { priceOption: 'dax_price', totalLabel: 'DAX', priceLabel: 'DAX ohne Div.' },
  eurostoxx50: {
    priceOption: 'eurostoxx50_price',
    totalLabel: 'EURO STOXX 50',
    priceLabel: 'EURO STOXX 50 ohne Div.',
  },
};
const COMPARISON_OPTIONS = [
  ...ASSET_SLUGS,
  ...Object.values(DIVIDEND_COMPARISON).map(({ priceOption }) => priceOption),
];
const VALID_PRESETS = new Set(PRESETS.map(([value]) => value));
const api = new BacktesterApi();
const today = todayIso();

const state = {
  ...TAX_DEFAULTS, taxProfiles:{},
  assets: new Map(),
  selected: new Set(DEFAULT_COMPARISON_SLUGS),
  amount: 10000,
  netRentPct: 5,
  deNetRentPct: 3,
  inflation: 'none',
  customInflationPct: 2,
  comparisonTerEnabled: false,
  comparisonTerPct: 0.10,
  taxesEnabled: false,
  personalIncomeTaxPct: 42,
  capitalAllowance: 1000,
  churchTaxPct: 0,
  singleTerPct: new Map(Object.entries(TER_DEFAULTS)),
  summaryData: null,
  summarySort: {period:'1Y',direction:'desc'},
  resultRanking:initialRanking(),
  comparisonPreset: '10y',
  comparisonCustom: null,
  comparisonData: null,
  comparisonRawData: null,
  singlePresets: new Map(ASSET_SLUGS.map((slug) => [slug, slug === 'msci_world' ? 'max' : '10y'])),
  singleCustom: new Map(),
  singleData: new Map(),
  controllers: new Map(),
  displayCurrency: 'EUR',
  currencyRates: { EUR: 1 },
  currencyHistories: new Map([['EUR', []]]),
  currencyHistoryLoads: new Map(),
};

let charts = null;
let portfolioLab = null;
let taxOverview = null;
let loading = null;
let currencyReady = Promise.resolve();
let chartReady = Promise.resolve();
function nearViewport(element) {
  if (!element) return false;
  const rect = element.getBoundingClientRect();
  return rect.bottom > 0 && rect.top < innerHeight + 250;
}
function loadSummary() { return loading.track('summary', fetchSummary); }
function loadSingle(slug, initial=false) {
  return loading.track('single-'+slug, () => fetchSingle(slug, {priority: initial ? () => nearViewport(document.querySelector('[data-single-chart="'+slug+'"]')) ? 2 : 10 : 0}));
}
function loadComparison(initial=false) {
  return loading.track('comparison', () => fetchComparison({priority: initial ? () => nearViewport(document.querySelector('#comparison-title')) ? 2 : 10 : 0}));
}
const dateRangeSliders = new WeakMap();
const AMOUNT_MIN = 100;
const AMOUNT_MAX = 10000000;
const CURRENCY_SYMBOLS = Object.freeze({ EUR: '€', GBP: '£', USD: '$', JPY: '¥', CNY: '¥' });

function initNavigation() {
  const toggle = document.querySelector('.nav-toggle');
  const menu = document.getElementById('mobile-menu');
  if (!toggle || !menu) return;
  toggle.addEventListener('click', () => {
    const open = toggle.classList.toggle('open');
    menu.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Menü schließen' : 'Menü öffnen');
  });
  menu.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
    toggle.classList.remove('open');
    menu.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Menü öffnen');
  }));
}

function readUrlState() {
  const params = new URLSearchParams(location.search);
  const preset = params.get('range');
  if (preset && VALID_PRESETS.has(preset)) state.comparisonPreset = preset;

  const currency = String(params.get('currency') || '').toUpperCase();
  if (DISPLAY_CURRENCIES.includes(currency)) state.displayCurrency = currency;

  const amount = Number(params.get('amount'));
  if (Number.isFinite(amount) && amount >= 100 && amount <= 10000000) state.amount = amount;

  const netRentValue = params.get('netRentPct');
  const netRentPct = Number(netRentValue);
  if (netRentValue !== null && netRentValue !== ''
    && Number.isFinite(netRentPct) && netRentPct >= 0 && netRentPct <= 15) {
    state.netRentPct = netRentPct;
  }

  const deNetRentValue = params.get('deNetRentPct');
  const deNetRentPct = Number(deNetRentValue);
  if (deNetRentValue !== null && deNetRentValue !== ''
    && Number.isFinite(deNetRentPct) && deNetRentPct >= 0 && deNetRentPct <= 15) {
    state.deNetRentPct = deNetRentPct;
  }

  if (params.has('assets')) {
    const assets = (params.get('assets') || '').split(',').filter((slug) => COMPARISON_OPTIONS.includes(slug));
    state.selected = new Set(assets);
  }

  const customInflationPct = Number(params.get('customInflationPct'));
  if (Number.isFinite(customInflationPct) && customInflationPct >= -5 && customInflationPct <= 30) {
    state.customInflationPct = customInflationPct;
  }

  if (params.has('comparisonTer')) state.comparisonTerEnabled = params.get('comparisonTer') === '1';
  const comparisonTerValue = params.get('comparisonTerPct');
  const comparisonTerPct = Number(comparisonTerValue);
  if (comparisonTerValue !== null && comparisonTerValue !== ''
    && Number.isFinite(comparisonTerPct) && comparisonTerPct >= 0 && comparisonTerPct <= 5) {
    state.comparisonTerPct = comparisonTerPct;
  }

  Object.assign(state,taxSettingsFromQuery(params));

  const start = params.get('start');
  const end = params.get('end');
  if (state.comparisonPreset === 'custom' && /^\d{4}-\d{2}-\d{2}$/.test(start || '') && /^\d{4}-\d{2}-\d{2}$/.test(end || '')) {
    state.comparisonCustom = { start, end };
  }
}

function syncControlsFromState() {
  syncStartCapitalControl();
  document.querySelectorAll('[data-net-rent-input]').forEach((input) => {
    input.value = String(input.dataset.netRentInput === 'de_real_estate'
      ? state.deNetRentPct
      : state.netRentPct);
  });
  document.querySelectorAll('[data-comparison-option]').forEach((input) => {
    input.checked = state.selected.has(input.value);
  });
  const inflationSelect = document.getElementById('comparison-inflation');
  if (inflationSelect) inflationSelect.value = state.inflation;
  const customInflationInput = document.getElementById('custom-inflation-pct');
  if (customInflationInput) customInflationInput.value = String(state.customInflationPct);
  const comparisonTerInput = document.getElementById('comparison-ter-enabled');
  if (comparisonTerInput) comparisonTerInput.checked = state.comparisonTerEnabled;
  const comparisonTerPctInput = document.getElementById('comparison-ter-pct');
  if (comparisonTerPctInput) comparisonTerPctInput.value = String(state.comparisonTerPct);
  document.querySelectorAll('[data-comparison-ter-dependent]').forEach((element) => {
    element.disabled = !state.comparisonTerEnabled;
  });
  const taxesInput = document.getElementById('comparison-taxes-enabled');
  if (taxesInput) taxesInput.checked = state.taxesEnabled;
  const personalTaxInput = document.getElementById('personal-income-tax-pct');
  if (personalTaxInput) personalTaxInput.value = String(state.personalIncomeTaxPct);
  const allowanceInput = document.getElementById('capital-allowance');
  if (allowanceInput) allowanceInput.value = String(state.capitalAllowance);
  const churchInput = document.getElementById('church-tax-pct');
  if (churchInput) churchInput.value = String(state.churchTaxPct);
  const currencySelect = document.getElementById('display-currency');
  if (currencySelect) currencySelect.value = state.displayCurrency;
  document.querySelectorAll('[data-tax-dependent]').forEach((element) => {
    element.disabled = !state.taxesEnabled;
  });
  syncTaxControls(state);
  syncOptionalModelVisibility();
  setCustomInflationVisibility();
  updateInflationStatus();
  updateComparisonConfigCount();
  setActiveInterval(document.getElementById('comparison-intervals'), state.comparisonPreset);
  renderFixedCurrencyLabels();
}

function installCurrencyControl() {
  const select = document.getElementById('display-currency');
  if (!select) return;
  select.addEventListener('change', () => {
    void applyDisplayCurrency(select.value);
  });
}

async function loadDisplayCurrencyRates() {
  const select = document.getElementById('display-currency');
  if (!select) return;

  try {
    state.currencyRates = await loadStoredCurrencyRates();
  } catch {
    state.currencyRates = { EUR: 1 };
  }

  [...select.options].forEach((option) => {
    option.disabled = !Number.isFinite(state.currencyRates[option.value]);
  });
  select.disabled = Object.keys(state.currencyRates).length <= 1;

  const currency = Number.isFinite(state.currencyRates[state.displayCurrency])
    ? state.displayCurrency
    : 'EUR';
  await applyDisplayCurrency(currency, { announceChange: false, updateHistory: false });
}

async function ensureCurrencyHistory(currency) {
  if (state.currencyHistories.has(currency)) return state.currencyHistories.get(currency);
  if (!state.currencyHistoryLoads.has(currency)) {
    const request = loadStoredCurrencyHistory(currency)
      .then((history) => {
        state.currencyHistories.set(currency, history);
        state.currencyHistoryLoads.delete(currency);
        return history;
      })
      .catch((error) => {
        state.currencyHistoryLoads.delete(currency);
        throw error;
      });
    state.currencyHistoryLoads.set(currency, request);
  }
  return state.currencyHistoryLoads.get(currency);
}

async function applyDisplayCurrency(currency, { announceChange = true, updateHistory = true } = {}) {
  const normalized = String(currency || '').toUpperCase();
  const rate = state.currencyRates[normalized];
  if (!DISPLAY_CURRENCIES.includes(normalized) || !Number.isFinite(rate) || rate <= 0) return;

  const select = document.getElementById('display-currency');
  const previousCurrency = state.displayCurrency;
  if (select) select.disabled = true;
  try {
    await ensureCurrencyHistory(normalized);
  } catch {
    const fallbackCurrency = state.currencyHistories.has(previousCurrency) ? previousCurrency : 'EUR';
    state.displayCurrency = fallbackCurrency;
    setDisplayCurrency(fallbackCurrency, 1);
    syncStartCapitalControl();
    renderFixedCurrencyLabels();
    if (select) {
      select.value = fallbackCurrency;
      const option = [...select.options].find((entry) => entry.value === normalized);
      if (option) option.disabled = true;
    }
    announce('Für diese Währung ist keine gespeicherte Historie verfügbar.');
    return;
  } finally {
    if (select) select.disabled = Object.keys(state.currencyRates).length <= 1;
  }

  state.displayCurrency = normalized;
  setDisplayCurrency(normalized, 1);
  if (select) select.value = normalized;
  syncStartCapitalControl();
  renderFixedCurrencyLabels();

  if (state.summaryData) renderSummary(state.summaryData);
  state.singleData.forEach((series) => renderSingle(series));
  if (state.comparisonData) {
    renderComparison(state.comparisonData);
    renderMetrics(state.comparisonData);
  }

  if (updateHistory) {
    const url = new URL(location.href);
    url.searchParams.set('currency', normalized);
    history.replaceState(null, '', `${url.pathname}?${url.searchParams.toString()}${url.hash}`);
  }
  if (announceChange) announce(`Anzeigewährung: ${normalized}`);
}

function displayedCurrencyHistory() {
  return state.currencyHistories.get(state.displayCurrency) || [];
}

function displayedSeries(series) {
  return applyHistoricalCurrencyToSeries(series, state.displayCurrency, displayedCurrencyHistory());
}

function displayedSummary(summary) {
  return applyHistoricalCurrencyToSummary(summary, state.displayCurrency, displayedCurrencyHistory());
}

function displayedPayload(payload) {
  if (!payload || state.displayCurrency === 'EUR') return payload;
  return {
    ...payload,
    request: { ...(payload.request || {}), currency: state.displayCurrency },
    series: (payload.series || []).map((series) => displayedSeries(series)),
  };
}

function syncStartCapitalControl() {
  const input = document.getElementById('backtest-amount');
  if (!input) return;
  input.value = String(state.amount);
  input.min = String(AMOUNT_MIN);
  input.max = String(AMOUNT_MAX);
  input.step = state.displayCurrency === 'JPY' ? '1' : '0.01';
  const symbol = document.getElementById('start-capital-currency-symbol');
  if (symbol) symbol.textContent = CURRENCY_SYMBOLS[state.displayCurrency] || state.displayCurrency;
}

function renderFixedCurrencyLabels() {
  document.querySelectorAll('[data-fixed-start]').forEach((element) => {
    element.textContent = formatEuro(10000);
  });
}

function syncOptionalModelVisibility() {
  document.querySelectorAll('[data-comparison-ter-settings]').forEach((element) => {
    element.hidden = !state.comparisonTerEnabled;
  });
  document.querySelectorAll('[data-ter-control]').forEach((element) => {
    element.hidden = !state.comparisonTerEnabled;
  });
  document.querySelectorAll('[data-ter-input]').forEach((element) => {
    element.disabled = !state.comparisonTerEnabled;
  });
  document.querySelectorAll('[data-tax-settings], [data-tax-column]').forEach((element) => {
    element.hidden = !state.taxesEnabled;
  });
}

function updateComparisonConfigCount() {
  const output = document.querySelector('[data-comparison-config-count]');
  if (!output) return;
  const portfolios = portfolioLab?.comparisonOptions() || [];
  const selectedPortfolios = portfolios.filter(({key}) => portfolioLab.comparisonSelection.has(key)).length;
  output.textContent = String(state.selected.size + selectedPortfolios) + '/' + (COMPARISON_OPTIONS.length + portfolios.length);
}

function syncComparisonCheckboxes() {
  document.querySelectorAll('[data-comparison-option]').forEach((input) => {
    input.checked = state.selected.has(input.value);
  });
  updateComparisonConfigCount();
}

function setCustomInflationVisibility() {
  document.querySelectorAll('[data-custom-inflation-control]').forEach((element) => {
    element.hidden = state.inflation !== 'custom';
  });
}

function inflationLabel() {
  const label = ({
    none: 'Nominale Rendite | ohne Inflationsbereinigung',
    inflation_de: 'Reale Rendite | Inflation Deutschland',
    inflation_euro_area: 'Reale Rendite | Inflation Euroraum',
    inflation_us: 'Reale Rendite | Inflation USA',
    custom: `Reale Rendite | eigene Inflation ${formatPlainPercent(state.customInflationPct)} p.a.`,
  })[state.inflation] || 'Nominale Rendite | ohne Inflationsbereinigung';
  const terLabel = state.comparisonTerEnabled
    ? ` | TER ${formatPlainPercent(state.comparisonTerPct)} p.a.`
    : '';
  return `${label}${state.taxesEnabled ? ' | nach Steuern' : ''}${terLabel}`;
}

function updateInflationStatus() {
  const status = document.getElementById('real-return-status');
  if (!status) return;
  status.textContent = inflationLabel();
  status.classList.toggle('is-real', state.inflation !== 'none');
}

function prepareSummarySkeleton() {
  document.querySelectorAll('[data-summary-row]').forEach((row) => {
    while (row.cells.length > 1) row.deleteCell(-1);
    for (let i = 0; i < 7; i += 1) {
      const cell = row.insertCell();
      cell.innerHTML = '<span class="asset-backtester__period-return is-neutral">–</span><span class="asset-backtester__period-value">wird geladen</span>';
    }
  });
}

function installSummarySorting() {
  document.querySelectorAll('[data-summary-sort]').forEach((button) => {
    const initial=button.dataset.summarySort===state.summarySort.period;button.dataset.sortDirection=initial?state.summarySort.direction:'';button.closest('th')?.setAttribute('aria-sort',initial?'descending':'none');
    button.addEventListener('click', () => {
      const period = button.dataset.summarySort;
      const direction = state.summarySort?.period === period && state.summarySort.direction === 'desc'
        ? 'asc'
        : 'desc';
      state.summarySort = { period, direction };
      document.querySelectorAll('[data-summary-sort]').forEach((other) => {
        const active = other === button;
        other.dataset.sortDirection = active ? direction : '';
        other.closest('th')?.setAttribute('aria-sort', active
          ? (direction === 'desc' ? 'descending' : 'ascending')
          : 'none');
      });
      if (state.summaryData) renderSummary(state.summaryData);
    });
  });
}

function installTerControls() {
  ASSET_SLUGS.forEach((slug) => {
    const card = document.querySelector(`[data-single-chart="${slug}"]`);
    const head = card?.querySelector('.asset-backtester__chart-head');
    if (!card || !head || card.querySelector('[data-ter-control]')) return;
    const control = document.createElement('div');
    control.className = 'asset-backtester__ter-control';
    control.dataset.terControl = slug;
    control.hidden = !state.comparisonTerEnabled;
    control.innerHTML = `
      <div class="asset-backtester__ter-copy">
        <label for="ter-${slug}">TER p.a.</label>
        <span>Jährliche Produktkosten für diesen Einzelverlauf</span>
      </div>
      <div class="asset-backtester__percent-input asset-backtester__percent-input--ter-wide">
        <input id="ter-${slug}" data-ter-input="${slug}" type="number" min="0" max="5" step="0.01" value="${TER_DEFAULTS[slug]}" inputmode="decimal" disabled aria-label="TER für ${slug} in Prozent pro Jahr">
        <span>%</span>
      </div>`;
    head.after(control);
    const input = control.querySelector('[data-ter-input]');
    input.addEventListener('input', debounce(() => {
      const value = Number(input.value);
      if (!Number.isFinite(value) || value < 0 || value > 5) {
        announce('Die TER muss zwischen 0 % und 5 % pro Jahr liegen.');
        return;
      }
      state.singleTerPct.set(slug, value);
      if (state.comparisonTerEnabled) loadSingle(slug);
    }, 400));
  });
}

function createIntervalButtons(container, activePreset, onSelect) {
  container.replaceChildren();
  PRESETS.forEach(([value, label]) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'asset-backtester__interval-button';
    button.dataset.preset = value;
    button.textContent = label;
    button.setAttribute('aria-pressed', String(value === activePreset));
    button.addEventListener('click', () => onSelect(value));
    container.append(button);
  });
}

function setActiveInterval(container, preset) {
  if (!container) return;
  container.querySelectorAll('[data-preset]').forEach((button) => {
    button.setAttribute('aria-pressed', String(button.dataset.preset === preset));
  });
}

function installIntervalControls() {
  ASSET_SLUGS.forEach((slug) => {
    const container = document.querySelector(`[data-intervals="${slug}"]`);
    createIntervalButtons(container, state.singlePresets.get(slug), (preset) => {
      state.singlePresets.set(slug, preset);
      setActiveInterval(container, preset);
      if (preset === 'custom') showSingleCustomDates(slug);
      else {
        hideSingleCustomDates(slug);
        loadSingle(slug);
      }
    });
  });

  const comparison = document.getElementById('comparison-intervals');
  createIntervalButtons(comparison, state.comparisonPreset, (preset) => {
    state.comparisonPreset = preset;
    setActiveInterval(comparison, preset);
    if (preset === 'custom') showComparisonCustomDates();
    else {
      document.getElementById('comparison-custom-dates').hidden = true;
      loadComparison();
    }
  });
}

function dateSliderMarkup(name) {
  const safeName = escapeHtml(name);
  return `
    <div class="asset-backtester__date-slider" data-date-slider>
      <div class="asset-backtester__date-slider-head">
        <span>Zeitraum per Regler</span>
        <output data-range-output></output>
      </div>
      <div class="asset-backtester__date-slider-control">
        <div class="asset-backtester__date-slider-track" aria-hidden="true"><span data-range-fill></span></div>
        <input class="asset-backtester__date-slider-input" type="range" data-range-start aria-label="Startdatum für ${safeName}">
        <input class="asset-backtester__date-slider-input" type="range" data-range-end aria-label="Enddatum für ${safeName}">
      </div>
      <div class="asset-backtester__date-slider-bounds" aria-hidden="true">
        <span data-range-min-label></span>
        <span data-range-max-label></span>
      </div>
    </div>`;
}

function installDateRangeSlider(container) {
  if (dateRangeSliders.has(container)) return dateRangeSliders.get(container);

  const startInput = container.querySelector('[data-start], #comparison-start');
  const endInput = container.querySelector('[data-end], #comparison-end');
  const startRange = container.querySelector('[data-range-start]');
  const endRange = container.querySelector('[data-range-end]');
  const fill = container.querySelector('[data-range-fill]');
  const output = container.querySelector('[data-range-output]');
  const minLabel = container.querySelector('[data-range-min-label]');
  const maxLabel = container.querySelector('[data-range-max-label]');
  let bounds = null;

  const paint = (startDay, endDay) => {
    const span = bounds.max - bounds.min;
    const startPercent = ((startDay - bounds.min) / span) * 100;
    const endPercent = ((endDay - bounds.min) / span) * 100;
    fill.style.left = `${startPercent}%`;
    fill.style.right = `${100 - endPercent}%`;

    const startDate = dayNumberToIso(startDay);
    const endDate = dayNumberToIso(endDay);
    output.textContent = `${formatDate(startDate)} – ${formatDate(endDate)}`;
    startRange.setAttribute('aria-valuetext', formatDate(startDate));
    endRange.setAttribute('aria-valuetext', formatDate(endDate));
    startRange.style.zIndex = startDay >= bounds.max - 2 ? '5' : '3';
    endRange.style.zIndex = '4';
  };

  const setDays = (startDay, endDay, moved = 'both') => {
    const range = constrainDayRange(startDay, endDay, bounds.min, bounds.max, moved);
    startRange.value = String(range.start);
    endRange.value = String(range.end);
    startInput.value = dayNumberToIso(range.start);
    endInput.value = dayNumberToIso(range.end);
    paint(range.start, range.end);
    return { start: startInput.value, end: endInput.value };
  };

  const syncFromDates = (moved) => {
    if (!bounds) return null;
    let startDay;
    let endDay;
    try {
      startDay = isoToDayNumber(startInput.value);
      endDay = isoToDayNumber(endInput.value);
    } catch {
      return null;
    }
    return setDays(startDay, endDay, moved);
  };

  startRange.addEventListener('input', () => setDays(Number(startRange.value), Number(endRange.value), 'start'));
  endRange.addEventListener('input', () => setDays(Number(startRange.value), Number(endRange.value), 'end'));
  startInput.addEventListener('input', () => syncFromDates('start'));
  endInput.addEventListener('input', () => syncFromDates('end'));

  const controller = {
    update({ minDate, maxDate, startDate, endDate }) {
      const min = isoToDayNumber(minDate);
      const max = isoToDayNumber(maxDate);
      bounds = { min, max };
      [startRange, endRange].forEach((input) => {
        input.min = String(min);
        input.max = String(max);
        input.step = '1';
      });
      startInput.min = minDate;
      startInput.max = dayNumberToIso(max - 1);
      endInput.min = dayNumberToIso(min + 1);
      endInput.max = maxDate;
      minLabel.textContent = formatDate(minDate);
      maxLabel.textContent = formatDate(maxDate);

      let start = min;
      let end = max;
      try { start = isoToDayNumber(startDate); } catch {}
      try { end = isoToDayNumber(endDate); } catch {}
      return setDays(start, end);
    },
    value() {
      return syncFromDates('both');
    },
  };

  dateRangeSliders.set(container, controller);
  return controller;
}

function sliderMinimum(slugs, preferredStart = '') {
  const dates = slugs.map((slug) => state.assets.get(slug)?.firstDate).filter(Boolean).sort();
  return dates.at(-1) || preferredStart || rangeForPreset('10y', today, []).start;
}

function showComparisonCustomDates() {
  const custom = document.getElementById('comparison-custom-dates');
  const startInput = document.getElementById('comparison-start');
  const endInput = document.getElementById('comparison-end');
  const current = state.comparisonCustom;
  const controller = installDateRangeSlider(custom);
  const range = controller.update({
    minDate: sliderMinimum(comparisonAssetSlugs(), current?.start),
    maxDate: today,
    startDate: current?.start || startInput.value,
    endDate: current?.end || endInput.value || today,
  });
  if (current) state.comparisonCustom = range;
  custom.hidden = false;
  return range;
}

function showSingleCustomDates(slug) {
  const card = document.querySelector(`[data-single-chart="${slug}"]`);
  let custom = card.querySelector('[data-single-custom]');
  if (!custom) {
    custom = document.createElement('div');
    custom.className = 'asset-backtester__custom-dates';
    custom.dataset.singleCustom = slug;
    custom.innerHTML = `
      <label>Von <input type="date" data-start max="${today}"></label>
      <label>Bis <input type="date" data-end max="${today}" value="${today}"></label>
      <button type="button">Anwenden</button>
      ${dateSliderMarkup(state.assets.get(slug)?.displayName || slug)}`;
    card.querySelector(`[data-intervals="${slug}"]`).after(custom);
    custom.querySelector('button').addEventListener('click', () => {
      const start = custom.querySelector('[data-start]').value;
      const end = custom.querySelector('[data-end]').value;
      if (!validCustomRange(start, end)) return;
      state.singleCustom.set(slug, { start, end });
      loadSingle(slug);
    });
  }
  const current = state.singleCustom.get(slug);
  const controller = installDateRangeSlider(custom);
  const range = controller.update({
    minDate: sliderMinimum([slug]),
    maxDate: today,
    startDate: current?.start || custom.querySelector('[data-start]').value,
    endDate: current?.end || custom.querySelector('[data-end]').value || today,
  });
  if (current) state.singleCustom.set(slug, range);
  custom.hidden = false;
}

function hideSingleCustomDates(slug) {
  const custom = document.querySelector(`[data-single-custom="${slug}"]`);
  if (custom) custom.hidden = true;
}

function renderRankingControls(){
  for(const button of document.querySelectorAll('[data-result-rank]')){
    const index=state.resultRanking.findIndex(c=>c.key===button.dataset.resultRank);
    delete button.dataset.rankOrder;button.dataset.sortDirection=index<0?'':state.resultRanking[index].direction;
    button.setAttribute('aria-pressed',String(index===0));
    button.closest('th')?.setAttribute('aria-sort',index===0?(state.resultRanking[0].direction==='desc'?'descending':'ascending'):'none');
    const indicator=button.querySelector('[data-rank-indicator]');
    if(indicator)indicator.textContent=index<0?'↕':(state.resultRanking[index].direction==='desc'?'↓':'↑');
  }
}
function installRankingControls(){
  document.querySelectorAll('[data-result-rank]').forEach(button=>button.addEventListener('click',()=>{
    state.resultRanking=nextRanking(state.resultRanking,button.dataset.resultRank);renderRankingControls();
    if(state.comparisonData)renderMetrics(state.comparisonData);
  }));
  renderRankingControls();
}
function installComparisonControls() {
  installRankingControls();
  document.querySelectorAll('[data-comparison-option]').forEach((input) => {
    input.addEventListener('change', () => {
      if (input.checked) state.selected.add(input.value);
      else state.selected.delete(input.value);
      document.querySelectorAll(`[data-comparison-option][value="${input.value}"]`).forEach((other) => {
        other.checked = input.checked;
      });
      updateComparisonConfigCount();
      if (state.comparisonPreset === 'custom') {
        state.comparisonCustom = showComparisonCustomDates();
      }
      loadComparison();
    });
  });

  document.querySelector('[data-select-all]')?.addEventListener('click', () => {
    state.selected = new Set(COMPARISON_OPTIONS);
    portfolioLab?.selectAllComparisons(true);
    syncComparisonCheckboxes();
    loadComparison();
  });
  document.querySelector('[data-clear-all]')?.addEventListener('click', () => {
    state.selected.clear();
    portfolioLab?.selectAllComparisons(false);
    syncComparisonCheckboxes();
    loadComparison();
  });

  const inflationSelect = document.getElementById('comparison-inflation');
  inflationSelect?.addEventListener('change', () => {
    state.inflation = inflationSelect.value;
    setCustomInflationVisibility();
    updateInflationStatus();
    loadComparison();
  });
  const customInflationInput = document.getElementById('custom-inflation-pct');
  customInflationInput?.addEventListener('input', debounce(() => {
    const value = Number(customInflationInput.value);
    if (!Number.isFinite(value) || value < -5 || value > 30) {
      announce('Die eigene Inflation muss zwischen -5 % und 30 % pro Jahr liegen.');
      return;
    }
    state.customInflationPct = value;
    if (state.inflation === 'custom') {
      updateInflationStatus();
      loadComparison();
    }
  }, 400));

  const comparisonTerInput = document.getElementById('comparison-ter-enabled');
  comparisonTerInput?.addEventListener('change', () => {
    state.comparisonTerEnabled = comparisonTerInput.checked;
    document.querySelectorAll('[data-comparison-ter-dependent]').forEach((element) => {
      element.disabled = !state.comparisonTerEnabled;
    });
    syncOptionalModelVisibility();
    updateInflationStatus();
    ASSET_SLUGS.forEach((slug) => loadSingle(slug));
    loadComparison();
  });
  const comparisonTerPctInput = document.getElementById('comparison-ter-pct');
  comparisonTerPctInput?.addEventListener('input', debounce(() => {
    const value = Number(comparisonTerPctInput.value);
    if (!Number.isFinite(value) || value < 0 || value > 5) {
      announce('Die TER muss zwischen 0 % und 5 % pro Jahr liegen.');
      return;
    }
    state.comparisonTerPct = value;
    if (state.comparisonTerEnabled) {
      updateInflationStatus();
      loadComparison();
    }
  }, 400));

  bindTaxControls(state,()=>{
    document.querySelectorAll('[data-tax-dependent]').forEach(element=>{element.disabled=!state.taxesEnabled;});
    syncOptionalModelVisibility();updateInflationStatus();
    if(!state.taxesEnabled && taxOverview)taxOverview.host.hidden=true;
    if(taxOverview && state.taxesEnabled && !state.comparisonData)taxOverview.render([],state);
    if(state.comparisonRawData){
      state.comparisonData={...state.comparisonRawData,series:state.comparisonRawData.series.map(series=>applySaleTaxes(series,state,false))};
      renderComparison(state.comparisonData);renderMetrics(state.comparisonData);updateUrl(state.comparisonData.request);
    }else loadComparison();
  },false);

  const configMenu = document.getElementById('comparison-config');
  if (configMenu) {
    configMenu.querySelector('#comparison-config-close')?.addEventListener('click',()=>{configMenu.open=false;configMenu.querySelector('summary')?.focus();});
    const positionMenu=()=>{
      if(!configMenu.open)return;const panel=configMenu.querySelector('.asset-backtester__comparison-config-menu'),anchor=configMenu.querySelector('summary').getBoundingClientRect();
      panel.style.position='fixed';panel.style.maxHeight=Math.max(180,window.innerHeight-110)+'px';panel.style.right='auto';
      const width=panel.offsetWidth,height=panel.offsetHeight;panel.style.left=Math.max(12,Math.min(anchor.right-width,window.innerWidth-width-12))+'px';
      panel.style.top=Math.max(78,Math.min(anchor.bottom+8,window.innerHeight-height-12))+'px';
    };
    configMenu.addEventListener('toggle',positionMenu);window.addEventListener('resize',positionMenu);window.addEventListener('scroll',positionMenu,{passive:true});
    document.addEventListener('click', (event) => {
      if (configMenu.open && !configMenu.contains(event.target)) configMenu.open = false;
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && configMenu.open) {
        configMenu.open = false;
        configMenu.querySelector('summary')?.focus();
      }
    });
  }

  const amountInput = document.getElementById('backtest-amount');
  amountInput.addEventListener('input', debounce(() => {
    const amount = Number(amountInput.value);
    if (!Number.isFinite(amount) || amount < AMOUNT_MIN || amount > AMOUNT_MAX) {
      announce(`Das Startkapital muss zwischen ${formatEuro(AMOUNT_MIN)} und ${formatEuro(AMOUNT_MAX)} liegen.`);
      return;
    }
    state.amount = amount;
    loadComparison();
  }, 400));

  document.querySelectorAll('[data-net-rent-input]').forEach((input) => {
    input.addEventListener('input', debounce(() => {
      const value = Number(input.value);
      if (!Number.isFinite(value) || value < 0 || value > 15) {
        announce('Der Netto-Mietcashflow muss zwischen 0 % und 15 % pro Jahr liegen.');
        return;
      }
      const rentalAsset = input.dataset.netRentInput === 'de_real_estate'
        ? 'de_real_estate'
        : 'us_real_estate';
      if (rentalAsset === 'de_real_estate') state.deNetRentPct = value;
      else state.netRentPct = value;
      document.querySelectorAll(`[data-net-rent-input="${rentalAsset}"]`).forEach((other) => {
        if (other !== input) other.value = String(value);
      });
      loadSingle(rentalAsset);
    }, 400));
  });

  if (state.comparisonCustom) {
    document.getElementById('comparison-start').value = state.comparisonCustom.start;
    document.getElementById('comparison-end').value = state.comparisonCustom.end;
    showComparisonCustomDates();
  } else {
  document.getElementById('comparison-end').value = today;
  }

  document.getElementById('apply-custom-range').addEventListener('click', () => {
    const start = document.getElementById('comparison-start').value;
    const end = document.getElementById('comparison-end').value;
    if (!validCustomRange(start, end)) return;
    state.comparisonPreset = 'custom';
    state.comparisonCustom = { start, end };
    setActiveInterval(document.getElementById('comparison-intervals'), 'custom');
    loadComparison();
  });

  document.getElementById('use-common-start').addEventListener('click', () => {
    const firstDates = comparisonAssetSlugs().map((slug) => state.assets.get(slug)?.firstDate).filter(Boolean);
    const start = firstDates.sort().at(-1);
    if (!start) return;
    state.comparisonPreset = 'custom';
    state.comparisonCustom = { start, end: today };
    document.getElementById('comparison-start').value = start;
  document.getElementById('comparison-end').value = today;
    showComparisonCustomDates();
    setActiveInterval(document.getElementById('comparison-intervals'), 'custom');
    loadComparison();
  });
}

function validCustomRange(start, end) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(start) || !/^\d{4}-\d{2}-\d{2}$/.test(end)) {
    announce('Bitte ein gültiges Start- und Enddatum wählen.');
    return false;
  }
  if (start >= end) {
    announce('Das Startdatum muss vor dem Enddatum liegen.');
    return false;
  }
  if (end > today) {
    announce('Das Enddatum darf nicht in der Zukunft liegen.');
    return false;
  }
  return true;
}

function requestRange(preset, slugs, custom) {
  if (preset === 'custom') {
    if (!custom) throw new Error('Bitte zuerst einen benutzerdefinierten Zeitraum festlegen.');
    return custom;
  }
  const firstDates = slugs.map((slug) => state.assets.get(slug)?.firstDate).filter(Boolean);
  const range = rangeForPreset(preset, today, firstDates);
  if (!range.start) return rangeForPreset('10y', today, []);
  return range;
}

function abortPrevious(key) {
  state.controllers.get(key)?.abort();
  const controller = new AbortController();
  state.controllers.set(key, controller);
  return controller;
}

async function loadAssets() {
  const controller = abortPrevious('assets');
  const payload = await api.assets(controller.signal);
  payload.assets.forEach((asset) => state.assets.set(asset.slug, asset));
  renderSources();
  updateProxyLabels();

  if (!new URLSearchParams(location.search).has('range')) {
    const firstDates = DEFAULT_COMPARISON_SLUGS.map((slug) => state.assets.get(slug)?.firstDate).filter(Boolean);
    state.comparisonPreset = chooseLongestCommonPreset(firstDates, today);
    DEFAULT_COMPARISON_SLUGS.forEach((slug) => state.singlePresets.set(slug, state.comparisonPreset));
    state.singlePresets.set('msci_world', 'max');
    setActiveInterval(document.getElementById('comparison-intervals'), state.comparisonPreset);
    ASSET_SLUGS.forEach((slug) => setActiveInterval(document.querySelector(`[data-intervals="${slug}"]`), state.singlePresets.get(slug)));
  }

  if (state.comparisonPreset === 'custom') showComparisonCustomDates();
  ASSET_SLUGS.forEach((slug) => {
    if (state.singlePresets.get(slug) === 'custom') showSingleCustomDates(slug);
  });
}

async function fetchSummary() {
  const controller = abortPrevious('summary');
  try {
    const payload = await api.summary({
      amount: 10000,
      end: today,
    }, controller.signal);
    await currencyReady;
    state.summaryData = payload.summary;
    renderSummary(payload.summary);
    updateGlobalStatus(payload.summary, payload.dataStatus);
    return true;
  } catch (error) {
    if (error.name === 'AbortError') return false;
    renderSummaryError(error.message);
    setGlobalError('Marktübersicht noch nicht verfügbar');
    return false;
  }
}

async function loadInitialSingles() {
  await Promise.allSettled(ASSET_SLUGS.map((slug) => loadSingle(slug, true)));
}

async function fetchSingle(slug, options) {
  let range;
  try {
    range = requestRange(state.singlePresets.get(slug), [slug], state.singleCustom.get(slug));
  } catch (error) {
    announce(error.message);
    return false;
  }
  const controller = abortPrevious(`single-${slug}`);
  setCardBusy(slug, true);
  try {
    const payload = await api.series({
      assets: [slug],
      ...range,
      amount: 10000,
      netRentPct: state.netRentPct,
      deNetRentPct: state.deNetRentPct,
      includeRentalCashflow: true,
      includeTer: state.comparisonTerEnabled,
      terPct: state.singleTerPct.get(slug) || 0,
      includeTaxes: false,
    }, controller.signal, options);
    await currencyReady;
    await chartReady;
    if (controller.signal.aborted) return false;
    await nextPaint();
    if (controller.signal.aborted) return false;
    const series = payload.series[0];
    if (!series) renderSingleUnavailable(slug, payload.warnings[0]?.message);
    else renderSingle(series);
    return true;
  } catch (error) {
    if (error.name === 'AbortError') return false;
    if (state.singleData.has(slug)) {
      setCardBusy(slug, false);
      announce(`${state.assets.get(slug)?.displayName || slug}: ${error.message} Die zuletzt geladenen Werte bleiben sichtbar.`);
    } else renderSingleUnavailable(slug, error.message);
    return false;
  }
}

async function fetchComparison(options) {
  const slugs = comparisonAssetSlugs();
  let range;
  try {
    range = requestRange(state.comparisonPreset, slugs, state.comparisonCustom);
  } catch (error) {
    announce(error.message);
    return false;
  }

  const controller = abortPrevious('comparison');
  const frame = document.querySelector('.asset-backtester__chart-frame--comparison');
  frame.setAttribute('aria-busy', 'true');
  if (slugs.length === 0) {
    try { await chartReady; } catch(error) { showComparisonError(error.message);frame.removeAttribute('aria-busy');return false; }
    charts.comparison(document.getElementById('chart-comparison'), []);
    state.comparisonData = { series: [], request: range, warnings: [] };
    state.comparisonRawData=state.comparisonData;
    const chartState = document.getElementById('comparison-chart-state');
    chartState.textContent = 'Wähle mindestens eine Linie in den Vergleichsreihen oder Einstellungen aus.';
    chartState.hidden = false;
    frame.classList.add('is-loaded');
    renderComparison(state.comparisonData);
    if (!portfolioLab?.comparisonSeries(range).length) chartState.hidden = false;
    renderMetrics(state.comparisonData);
    renderWarnings([]);
    updateUrl(range);
    frame.removeAttribute('aria-busy');
    return true;
  }
  try {
    const payload = await api.series({
      assets: slugs,
      ...range,
      amount: state.amount,
      netRentPct: 0,
      deNetRentPct: 0,
      includeRentalCashflow: false,
      inflation: state.inflation,
      customInflationPct: state.inflation === 'custom' ? state.customInflationPct : null,
      includeTer: state.comparisonTerEnabled,
      terPct: state.comparisonTerPct,
      includeTaxes: false,
    }, controller.signal, options);
    await currencyReady;
    await chartReady;
    if (controller.signal.aborted) return false;
    await nextPaint();
    if (controller.signal.aborted) return false;
    state.comparisonRawData={...payload,series:payload.series};
    payload.series=payload.series.map(series=>applySaleTaxes(series,state,false));
    state.comparisonData = payload;
    renderComparison(payload);
    renderMetrics(payload);
    renderWarnings(payload.warnings);
    updateUrl(range);
    updateStatusFromSeries(payload);
    return true;
  } catch (error) {
    if (error.name === 'AbortError') return false;
    if (state.comparisonData) {
      announce(`${error.message} Die zuletzt geladenen Daten bleiben sichtbar.`);
    } else {
      showComparisonError(error.message);
      renderMetrics({ series: [], request: range });
    }
    return false;
  } finally {
    frame.removeAttribute('aria-busy');
  }
}

function renderSummary(summary) {
  summary = displayedSummary(summary);
  const orderedRows = state.summarySort
    ? sortSummaryRows(summary.rows, state.summarySort.period, state.summarySort.direction)
    : summary.rows;
  const rows = new Map(orderedRows.map((row) => [row.slug, row]));
  const tbody = document.querySelector('#summary-table tbody');
  orderedRows.forEach((row) => {
    const tr = document.querySelector(`[data-summary-row="${row.slug}"]`);
    if (tr) tbody.append(tr);
  });
  document.querySelectorAll('[data-summary-row]').forEach((tr) => {
    while (tr.cells.length > 1) tr.deleteCell(-1);
    const row = rows.get(tr.dataset.summaryRow);
    tr.dataset.summaryRank=JSON.stringify(Object.fromEntries(summary.periodLabels.map(p=>[p,row?.periods?.[p]?.returnPct ?? null])));
    summary.periodLabels.forEach((label) => {
      const cell = tr.insertCell();
      const value = row?.periods?.[label];
      if (!value) {
        cell.innerHTML = '<span class="asset-backtester__period-return is-neutral">–</span><span class="asset-backtester__period-value">Nicht verfügbar</span>';
        cell.setAttribute('aria-label', `${label}: Nicht verfügbar`);
        return;
      }
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'asset-backtester__period-cell';
      const detail = `${row.displayName}, ${label}: ${formatPercent(value.returnPct)}. Effektiver Start ${formatDate(value.effectiveStartDate)}, Ende ${formatDate(value.effectiveEndDate)}, Startwert ${formatSourceValue(value.startValue, row.currency)}, Schlusswert ${formatSourceValue(value.endValue, row.currency)}, aus ${formatEuro(10000)} wurden ${formatEuro(value.hypotheticalValue)}.`;
      button.title = detail;
      button.setAttribute('aria-label', detail);
      button.innerHTML = `<span class="asset-backtester__period-return ${classifyReturn(value.returnPct)}">${formatPercent(value.returnPct)}</span><span class="asset-backtester__period-value">${formatEuro(value.hypotheticalValue)} aus ${formatEuro(10000)}</span>`;
      cell.append(button);
    });
  });
  document.getElementById('summary-note').textContent = `Gemeinsames Vergleichsende: ${formatDate(summary.globalEndDate)}. Effektive Markttage stehen in den Zelldetails.`;
  portfolioLab?.renderLinked();
}

function renderSummaryError(message) {
  document.querySelectorAll('[data-summary-row]').forEach((tr) => {
    while (tr.cells.length > 1) tr.deleteCell(-1);
    for (let i = 0; i < 7; i += 1) {
      const cell = tr.insertCell();
      cell.innerHTML = '<span class="asset-backtester__period-return is-neutral">–</span><span class="asset-backtester__period-value">Nicht verfügbar</span>';
    }
  });
  document.getElementById('summary-note').textContent = message;
}

function renderSingle(series) {
  state.singleData.set(series.slug, series);
  series = displayedSeries(series);
  const card = document.querySelector(`[data-single-chart="${series.slug}"]`);
  const frame = card.querySelector('.asset-backtester__chart-frame');
  const last = series.points.at(-1);
  const proxyLabel = card.querySelector('[data-proxy]');
  const asset = state.assets.get(series.slug);
  if (proxyLabel && asset?.proxyDescription) {
    const displayLabel = series.currency === 'PTS' || state.displayCurrency === 'EUR'
      ? asset.proxyDescription
      : asset.proxyDescription
        .replaceAll('EUR', state.displayCurrency)
        .replaceAll('Euro-Anlegersicht', `${state.displayCurrency}-Anlegersicht`);
    proxyLabel.textContent = displayLabel;
  }
  card.querySelector('[data-last-value]').textContent = formatSourceValue(last.sourceValue, series.currency);
  const returnNode = card.querySelector('[data-interval-return]');
  returnNode.textContent = `${formatPercent(series.metrics.totalReturnPct)} im Zeitraum`;
  returnNode.className = classifyReturn(series.metrics.totalReturnPct);
  card.querySelector('[data-hypothetical]').textContent = formatEuro(series.metrics.finalValue);
  const lastRawPoint = [...series.points].reverse().find((point) => !point.isCarriedForward);
  const rawValuePrefix = lastRawPoint?.qualityStatus === 'provisional' ? 'Vorläufiger' : 'Echter';
  const propertyPeriod = series.nativeFrequency === 'quarterly' ? 'Quartalswert' : 'Monatswert';
  const terSuffix = series.terAdjustment?.applied
    ? `; nach ${formatPlainPercent(series.terAdjustment.annualTerPct)} TER p.a.`
    : '';
  card.querySelector('[data-raw-date]').textContent = (series.dividendAdjustment?.applied
    ? `${rawValuePrefix} Preisindex ${formatDate(series.lastRawObservationDate)}; Rendite inkl. Dividenden`
    : (series.rentalCashflowAdjustment?.applied
        ? `${rawValuePrefix} ${propertyPeriod} ${formatDate(series.lastRawObservationDate)}; Rendite inkl. Netto-Mietcashflow`
        : `${rawValuePrefix} Wert ${formatDate(series.lastRawObservationDate)}`)) + terSuffix;
  card.querySelector('[data-chart-summary]').textContent = `${series.displayName}: Aus ${formatEuro(10000)} wurden zwischen ${formatDate(series.effectiveStartDate)} und ${formatDate(series.effectiveEndDate)} ${formatEuro(series.metrics.finalValue)}. Gesamtrendite ${formatPercent(series.metrics.totalReturnPct)}${series.terAdjustment?.applied ? ` nach ${formatPlainPercent(series.terAdjustment.annualTerPct)} TER pro Jahr` : ''}.`;
  card.querySelector('[data-chart-state]').hidden = true;
  frame.classList.add('is-loaded');
  frame.removeAttribute('aria-busy');
  charts.single(card.querySelector('canvas'), series);
}

function renderSingleUnavailable(slug, message = 'Für diesen Zeitraum liegen keine gespeicherten Daten vor.') {
  const card = document.querySelector(`[data-single-chart="${slug}"]`);
  const frame = card.querySelector('.asset-backtester__chart-frame');
  frame.classList.add('is-loaded');
  frame.removeAttribute('aria-busy');
  const chartState = card.querySelector('[data-chart-state]');
  chartState.textContent = message || 'Zeitraum nicht verfügbar.';
  chartState.hidden = false;
  card.querySelector('[data-chart-summary]').textContent = message || 'Keine Daten verfügbar.';
}

function setCardBusy(slug, busy) {
  const frame = document.querySelector(`[data-single-chart="${slug}"] .asset-backtester__chart-frame`);
  if (busy) frame.setAttribute('aria-busy', 'true');
  else frame.removeAttribute('aria-busy');
}

function renderComparison(payload) {
  portfolioLab?.setComparisonRange(payload.request?.start, payload.request?.end);
  const portfolioSeries = portfolioLab?.comparisonSeries(payload.request) || [];
  updateComparisonConfigCount();
  const taxLines=payload.series.flatMap(series=>{
    const variant=DIVIDEND_COMPARISON[series.slug];
    if(!variant || !series.dividendAdjustment?.applied)return [series];
    const lines=[];if(state.selected.has(series.slug))lines.push(series);
    if(state.selected.has(variant.priceOption))lines.push({...series,slug:variant.priceOption,displayName:variant.priceLabel,points:series.points.map(p=>({...p,taxDetail:p.priceOnlyTaxDetail}))});
    return lines;
  });
  taxOverview?.render([...taxLines,...portfolioSeries],state);
  payload = displayedPayload({ ...payload, series: [...payload.series, ...portfolioSeries] });
  const note = document.getElementById('portfolio-comparison-note') || document.createElement('p');
  note.id = 'portfolio-comparison-note'; note.className = 'portfolio-comparison-note'; note.hidden = !portfolioSeries.length;
  note.textContent = "Portfolios starten immer mit 10.000 € und werden an jedem Kalendertag mit den jeweils zuletzt verfügbaren Kursen bewertet. Ihre eigenen Hebel- und Kosteneinstellungen gelten; Inflation und Vergleichs-TER werden nicht übertragen. Der globale Steuerschalter gilt für Portfolios einschließlich Rebalancing-Verkäufen. Renditen nur über gleiche Zeiträume vergleichen. Portfolio-Risikokennzahlen bleiben in EUR.";
  document.getElementById('comparison-chart-summary').after(note);
  const frame = document.querySelector('.asset-backtester__chart-frame--comparison');
  const chartState = document.getElementById('comparison-chart-state');
  frame.classList.add('is-loaded');
  chartState.hidden = payload.series.length > 0;
  if (!payload.series.length) chartState.textContent = 'Wähle mindestens eine Linie in den Vergleichsreihen oder Einstellungen aus.';
  charts.comparison(document.getElementById('chart-comparison'), payload.series, {
    showSp500Dividend: state.selected.has('sp500'),
    showSp500Price: state.selected.has('sp500_price'),
    showMsciWorldDividend: state.selected.has('msci_world'),
    showMsciWorldPrice: state.selected.has('msci_world_price'),
    showDaxDividend: state.selected.has('dax'),
    showDaxPrice: state.selected.has('dax_price'),
    showEuroStoxx50Dividend: state.selected.has('eurostoxx50'),
    showEuroStoxx50Price: state.selected.has('eurostoxx50_price'),
  });

  const summary = payload.series.flatMap((series) => {
    const dividendConfig = DIVIDEND_COMPARISON[series.slug];
    const dividendApplied = series.dividendAdjustment?.applied;
    if (!dividendConfig || !dividendApplied) {
      return [`${series.displayName}: ${formatEuro(series.metrics.finalValue)} und ${formatPercent(series.metrics.totalReturnPct)}`];
    }
    const parts = [];
    if (state.selected.has(series.slug)) {
      parts.push(`${dividendConfig.totalLabel}: ${formatEuro(series.metrics.finalValue)} und ${formatPercent(series.metrics.totalReturnPct)}`);
    }
    if (state.selected.has(dividendConfig.priceOption)) {
      const firstPoint = series.points[0];
      const lastPoint = series.points.at(-1);
        const startPortfolio = Number(firstPoint?.priceOnlyPortfolioValue);
        const endPortfolio = Number(lastPoint?.priceOnlyPortfolioValue);
        if (startPortfolio > 0 && endPortfolio > 0) {
          const priceFactor = endPortfolio / startPortfolio;
          parts.push(`${dividendConfig.priceLabel}: ${formatEuro(endPortfolio)} und ${formatPercent((priceFactor - 1) * 100)}`);
      }
    }
    return parts;
  }).join('; ');
  document.getElementById('comparison-chart-summary').textContent = `Normierter Vergleich von ${formatDate(payload.request.start)} bis ${formatDate(payload.request.end)}. ${summary}.`;
}

function showComparisonError(message) {
  const frame = document.querySelector('.asset-backtester__chart-frame--comparison');
  frame.classList.add('is-loaded');
  const chartState = document.getElementById('comparison-chart-state');
  chartState.textContent = message;
  chartState.hidden = false;
}

function renderMetrics(payload) {
  payload = displayedPayload(payload);
  const resultBody = document.querySelector('#metrics-table tbody');
  const riskBody = document.querySelector('#risk-metrics-table tbody');
  resultBody.replaceChildren();
  riskBody.replaceChildren();
  document.querySelectorAll('[data-tax-column]').forEach((element) => {
    element.hidden = !state.taxesEnabled;
  });
  (payload.series || [])
    .filter((series) => !DIVIDEND_COMPARISON[series.slug] || state.selected.has(series.slug))
    .sort((a,b)=>compareRankedMetrics(a.metrics,b.metrics,state.resultRanking))
    .forEach((series) => {
    const resultRow = document.createElement('tr');resultRow.dataset.rankMetrics=JSON.stringify(series.metrics);
    resultRow.innerHTML = `
      <th scope="row"><span class="asset-backtester__asset-mark asset-backtester__asset-mark--${escapeHtml(series.slug)}"></span>${escapeHtml(series.displayName)}</th>
      <td><strong>${formatEuro(series.metrics.finalValue)}</strong></td>
      <td class="${classifyReturn(series.metrics.absoluteReturn)}">${formatEuro(series.metrics.absoluteReturn)}</td>
      <td class="${classifyReturn(series.metrics.totalReturnPct)}">${formatPercent(series.metrics.totalReturnPct)}</td>
      <td>${formatPercent(series.metrics.cagrPct)}</td>
      ${state.taxesEnabled ? `<td data-tax-column title="${escapeHtml(series.metrics.taxRule || '')}">${series.metrics.taxAdjustmentApplied ? formatEuro(series.metrics.taxAmount) : '–'}</td>` : ''}
      <td class="is-negative">${formatPercent(series.metrics.maxDrawdownPct)}</td>
      <td>${formatDate(series.metrics.lastRawObservationDate)}</td>`;
    resultBody.append(resultRow);

    const volatilityTitle = `${formatInteger(series.metrics.observedReturnCount)} echte Tagesänderungen, annualisiert mit ${formatRatio(series.metrics.annualizationPeriods, series.metrics.annualizationPeriods === 252 ? 0 : 2)} Perioden pro Jahr`;
    const riskRow = document.createElement('tr');riskRow.dataset.rankMetrics=JSON.stringify(series.metrics);
    riskRow.innerHTML = `
      <th scope="row"><span class="asset-backtester__asset-mark asset-backtester__asset-mark--${escapeHtml(series.slug)}"></span>${escapeHtml(series.displayName)}</th>
      <td title="${escapeHtml(volatilityTitle)}">${formatPlainPercent(series.metrics.annualizedVolatilityPct)}</td>
      <td class="${classifyReturn(series.metrics.sharpeRatio)}">${formatRatio(series.metrics.sharpeRatio)}</td>
      <td class="${classifyReturn(series.metrics.calmarRatio)}">${formatRatio(series.metrics.calmarRatio)}</td>
      <td class="${classifyReturn(series.metrics.bestDayPct)}">${formatPercent(series.metrics.bestDayPct)}<span class="asset-backtester__metric-detail">${formatDate(series.metrics.bestDayDate)}</span></td>
      <td class="${classifyReturn(series.metrics.worstDayPct)}">${formatPercent(series.metrics.worstDayPct)}<span class="asset-backtester__metric-detail">${formatDate(series.metrics.worstDayDate)}</span></td>
      <td>${formatPlainPercent(series.metrics.positiveDaysPct)}</td>
      <td>${formatInteger(series.metrics.observedReturnCount)}</td>`;
    riskBody.append(riskRow);
  });
  const request = payload.request || {};
  document.getElementById('metrics-period').textContent = request.start && request.end
    ? `${formatDate(request.start)} – ${formatDate(request.end)} | ${state.inflation === 'none' ? 'nominal' : 'real'}${state.taxesEnabled ? ' | nach Steuern' : ''}${state.comparisonTerEnabled ? ` | TER ${formatPlainPercent(state.comparisonTerPct)} p.a.` : ''}`
    : 'Keine Daten geladen';
  portfolioLab?.renderLinked();
}

function renderWarnings(warnings = []) {
  const notice = document.getElementById('comparison-warning');
  const button = document.getElementById('use-common-start');
  if (!warnings.length) {
    notice.hidden = true;
    button.hidden = true;
    return;
  }
  notice.querySelector('span').textContent = warnings.map((warning) => warning.message).join(' ');
  button.hidden = !warnings.some((warning) => warning.code === 'ASSET_UNAVAILABLE_AT_START');
  notice.hidden = false;
}

function renderSources() {
  const grid = document.getElementById('source-grid');
  grid.replaceChildren();
  state.assets.forEach((asset) => {
    if (!asset) return;
    const article = document.createElement('article');
    article.className = 'asset-backtester__source-card';
    const sourceLink = asset.source?.url
      ? `<a href="${escapeHtml(asset.source.url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(asset.source.name)}</a>`
      : escapeHtml(asset.source?.name || '–');
    article.innerHTML = `
      <h3>${escapeHtml(asset.displayName)}</h3>
      <dl>
        <dt>Datenreihe</dt><dd>${escapeHtml(asset.proxyDescription || '–')}</dd>
        <dt>Provider</dt><dd>${sourceLink}</dd>
        <dt>Symbol</dt><dd>${escapeHtml(asset.providerSymbol || '–')}</dd>
        <dt>Währung</dt><dd>${escapeHtml(asset.currency)}</dd>
        <dt>Frequenz</dt><dd>${escapeHtml(asset.nativeFrequency)}</dd>
        <dt>Zeitzone</dt><dd>${escapeHtml(asset.timezone || '–')}</dd>
        <dt>Erster Wert</dt><dd>${formatDate(asset.firstDate)}</dd>
        <dt>Letzter Wert</dt><dd>${formatDate(asset.lastDate)}</dd>
        <dt>Letzter Sync</dt><dd>${asset.source?.lastSuccessfulSyncAt ? escapeHtml(asset.source.lastSuccessfulSyncAt) : '–'}</dd>
        <dt>Hinweis</dt><dd>${escapeHtml(asset.methodologyNote || '–')}</dd>
      </dl>`;
    grid.append(article);
  });
}

function updateProxyLabels() {
  ASSET_SLUGS.forEach((slug) => {
    const asset = state.assets.get(slug);
    const label = document.querySelector(`[data-single-chart="${slug}"] [data-proxy]`);
    if (asset && label) label.textContent = asset.proxyDescription || asset.source?.name || '';
  });
}

function updateGlobalStatus(summary) {
  const rawDates = summary.rows.map((row) => row.lastRawObservationDate).filter(Boolean).sort();
  const badge = document.getElementById('global-data-status');
  if (!rawDates.length) {
    setGlobalError('Noch keine historischen Daten gespeichert');
    return;
  }
  badge.classList.remove('is-error');
  badge.classList.add('is-current');
  badge.querySelector('span:last-child').textContent = `Bewertung bis ${formatDate(summary.globalEndDate)}, echte Werte bis ${formatDate(rawDates[0])}`;
}

function updateStatusFromSeries(payload) {
  const dates = payload.series.map((series) => series.lastRawObservationDate).filter(Boolean).sort();
  if (!dates.length) return;
  const badge = document.getElementById('global-data-status');
  badge.classList.remove('is-error');
  badge.classList.add('is-current');
  badge.querySelector('span:last-child').textContent = `Gewählter Zeitraum, echte Werte bis ${formatDate(dates[0])}`;
}

function setGlobalError(message) {
  const badge = document.getElementById('global-data-status');
  badge.classList.remove('is-current');
  badge.classList.add('is-error');
  badge.querySelector('span:last-child').textContent = message;
}

function updateUrl(range) {
  const params = new URLSearchParams();
  params.set('assets', [...state.selected].join(','));
  params.set('range', state.comparisonPreset);
  params.set('amount', String(state.amount));
  params.set('netRentPct', String(state.netRentPct));
  params.set('deNetRentPct', String(state.deNetRentPct));
  params.set('comparisonTer', state.comparisonTerEnabled ? '1' : '0');
  params.set('comparisonTerPct', String(state.comparisonTerPct));
  writeTaxQuery(params,state);
  params.set('currency', state.displayCurrency);
  if (state.inflation === 'custom') params.set('customInflationPct', String(state.customInflationPct));
  if (state.comparisonPreset === 'custom') {
    params.set('start', range.start);
    params.set('end', range.end);
  }
  history.replaceState(null, '', `${location.pathname}?${params.toString()}${location.hash}`);
}

function comparisonAssetSlugs() {
  return [...new Set(
    [...state.selected].map((option) => {
      const dividendEntry = Object.entries(DIVIDEND_COMPARISON)
        .find(([, config]) => config.priceOption === option);
      return dividendEntry?.[0] || option;
    }),
  )];
}

function announce(message) {
  const live = document.getElementById('app-live-region');
  live.textContent = '';
  requestAnimationFrame(() => { live.textContent = message; });
}

async function start() {
  initNavigation();
  readUrlState();
  mountTaxControls(false);
  taxOverview=new TaxOverview(false);
  prepareSummarySkeleton();
  installSummarySorting();
  installIntervalControls();
  installTerControls();
  installComparisonControls();
  installCurrencyControl();
  syncControlsFromState();

  loading = new LoadingProgress(document.getElementById('loading-progress'), ['summary','assets','currency','charts','comparison',...ASSET_SLUGS.map(slug=>'single-'+slug),'portfolio-ui','reference','portfolios'], false);
  // Start the first visible table and metadata independently of full histories.
  let priorityFrame=null;
  const reprioritize=()=>{
    if(priorityFrame!==null)return;
    priorityFrame=requestAnimationFrame(()=>{priorityFrame=null;api.queue.drain();});
  };
  window.addEventListener('scroll',reprioritize,{passive:true});
  window.addEventListener('resize',reprioritize,{passive:true});
  const requestedCurrency=state.displayCurrency;
  const overviewTask=loadSummary();
  const assetsTask=loading.track('assets',async()=>{
    const hadData=state.singleData.size || state.comparisonData;
    await loadAssets();
    if(hadData){void loadInitialSingles();void loadComparison();}
  });
  const currencyTask=requestedCurrency==='EUR'
    ? overviewTask.then(nextPaint).then(()=>loading.track('currency',loadDisplayCurrencyRates))
    : loading.track('currency',loadDisplayCurrencyRates);
  currencyReady=requestedCurrency==='EUR'?Promise.resolve():currencyTask;
  const chartTask=loading.track('charts',()=>{
    chartReady=loadChartLibrary().then(()=>{charts=new ChartManager();});
    return chartReady;
  });
  try { await assetsTask; } catch(error) { announce(error.message); }

  const singlesTask=loadInitialSingles();
  const comparisonTask=loadComparison(true);
  const portfoliosTask=loading.track('portfolio-ui',async()=>{
    await nextPaint();
    const { PortfolioLab }=await import('./portfolio-ui.js?v=20261028-v13y');
    await chartReady;
  portfolioLab ??= new PortfolioLab({ deferInitialReference:true, api, assets: state.assets, getTaxSettings:()=>state, getRanking:()=>state.resultRanking,getSummarySort:()=>state.summarySort,getSummaryEnd:()=>state.summaryData?.globalEndDate,getComparisonStart: () => {try {return state.comparisonData?.request?.start || requestRange(state.comparisonPreset,comparisonAssetSlugs(),state.comparisonCustom).start;} catch {return rangeForPreset('10y',today,[]).start;}}, getComparisonEnd: () => {try {return state.comparisonData?.request?.end || requestRange(state.comparisonPreset,comparisonAssetSlugs(),state.comparisonCustom).end;} catch {return today;}}, onChange: () => {
    if (state.comparisonData) { renderComparison(state.comparisonData); renderMetrics(state.comparisonData); }
  } });

    if(!portfolioLab.loadingBound){
      const restore=portfolioLab.restore.bind(portfolioLab);
      portfolioLab.restore=(options)=>loading.track('portfolios',()=>restore(options));
      const reference=portfolioLab.refreshVolatilityReference.bind(portfolioLab);
      portfolioLab.refreshVolatilityReference=(...args)=>loading.track('reference',()=>reference(...args));
      portfolioLab.loadingBound=true;
    }
    // Only the comparison range is required here, never all thirteen charts.
    await comparisonTask;
    portfolioLab.deferInitialReference=false;
    await Promise.allSettled([
      portfolioLab.refreshVolatilityReference(false,{priority:20}),
      portfolioLab.restore({priority:20})
    ]);
    return true;
  });
  await Promise.allSettled([overviewTask,currencyTask,chartTask,singlesTask,comparisonTask,portfoliosTask]);
  api.syncIfStale().catch(() => {});
}

start();
