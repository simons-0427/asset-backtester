import { BacktesterApi } from './api.js?v=20260821-global-model-toggles-v1';
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
} from './calculations.js?v=20260821-global-model-toggles-v1';
import { ChartManager } from './charts.js?v=20260821-global-model-toggles-v1';
import {
  escapeHtml,
  formatDate,
  formatEuro,
  formatInteger,
  formatPercent,
  formatPlainPercent,
  formatRatio,
  formatSourceValue,
} from './formatters.js?v=20260821-global-model-toggles-v1';

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
  summarySort: null,
  comparisonPreset: '10y',
  comparisonCustom: null,
  comparisonData: null,
  singlePresets: new Map(ASSET_SLUGS.map((slug) => [slug, slug === 'msci_world' ? 'max' : '10y'])),
  singleCustom: new Map(),
  singleData: new Map(),
  controllers: new Map(),
};

let charts = null;
const dateRangeSliders = new WeakMap();

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

  const personalIncomeTaxPct = Number(params.get('personalIncomeTaxPct'));
  if (Number.isFinite(personalIncomeTaxPct) && personalIncomeTaxPct >= 0 && personalIncomeTaxPct <= 45) {
    state.personalIncomeTaxPct = personalIncomeTaxPct;
  }
  const capitalAllowance = Number(params.get('capitalAllowance'));
  if (Number.isFinite(capitalAllowance) && capitalAllowance >= 0 && capitalAllowance <= 10000) {
    state.capitalAllowance = capitalAllowance;
  }
  const churchTaxPct = Number(params.get('churchTaxPct'));
  if ([0, 8, 9].includes(churchTaxPct)) state.churchTaxPct = churchTaxPct;

  const start = params.get('start');
  const end = params.get('end');
  if (state.comparisonPreset === 'custom' && /^\d{4}-\d{2}-\d{2}$/.test(start || '') && /^\d{4}-\d{2}-\d{2}$/.test(end || '')) {
    state.comparisonCustom = { start, end };
  }
}

function syncControlsFromState() {
  document.getElementById('backtest-amount').value = String(state.amount);
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
  document.querySelectorAll('[data-tax-dependent]').forEach((element) => {
    element.disabled = !state.taxesEnabled;
  });
  syncOptionalModelVisibility();
  setCustomInflationVisibility();
  updateInflationStatus();
  updateComparisonConfigCount();
  setActiveInterval(document.getElementById('comparison-intervals'), state.comparisonPreset);
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
  output.textContent = `${state.selected.size}/${COMPARISON_OPTIONS.length}`;
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

function installComparisonControls() {
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
    syncComparisonCheckboxes();
    loadComparison();
  });
  document.querySelector('[data-clear-all]')?.addEventListener('click', () => {
    state.selected.clear();
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

  const taxesInput = document.getElementById('comparison-taxes-enabled');
  taxesInput?.addEventListener('change', () => {
    state.taxesEnabled = taxesInput.checked;
    document.querySelectorAll('[data-tax-dependent]').forEach((element) => {
      element.disabled = !state.taxesEnabled;
    });
    syncOptionalModelVisibility();
    updateInflationStatus();
    loadComparison();
  });
  const personalTaxInput = document.getElementById('personal-income-tax-pct');
  personalTaxInput?.addEventListener('input', debounce(() => {
    const value = Number(personalTaxInput.value);
    if (!Number.isFinite(value) || value < 0 || value > 45) {
      announce('Der persönliche Einkommensteuersatz muss zwischen 0 % und 45 % liegen.');
      return;
    }
    state.personalIncomeTaxPct = value;
    if (state.taxesEnabled) loadComparison();
  }, 400));
  const allowanceInput = document.getElementById('capital-allowance');
  allowanceInput?.addEventListener('input', debounce(() => {
    const value = Number(allowanceInput.value);
    if (!Number.isFinite(value) || value < 0 || value > 10000) {
      announce('Der Sparer-Pauschbetrag muss zwischen 0 € und 10.000 € liegen.');
      return;
    }
    state.capitalAllowance = value;
    if (state.taxesEnabled) loadComparison();
  }, 400));
  const churchTaxInput = document.getElementById('church-tax-pct');
  churchTaxInput?.addEventListener('change', () => {
    state.churchTaxPct = Number(churchTaxInput.value);
    if (state.taxesEnabled) loadComparison();
  });

  const configMenu = document.getElementById('comparison-config');
  if (configMenu) {
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
    if (!Number.isFinite(amount) || amount < 100 || amount > 10000000) {
      announce('Das Startkapital muss zwischen 100 € und 10.000.000 € liegen.');
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

async function loadSummary() {
  const controller = abortPrevious('summary');
  try {
    const payload = await api.summary({
      amount: 10000,
      end: today,
    }, controller.signal);
    state.summaryData = payload.summary;
    renderSummary(payload.summary);
    updateGlobalStatus(payload.summary, payload.dataStatus);
  } catch (error) {
    if (error.name === 'AbortError') return;
    renderSummaryError(error.message);
    setGlobalError('Marktübersicht noch nicht verfügbar');
  }
}

async function loadInitialSingles() {
  await Promise.allSettled(ASSET_SLUGS.map((slug) => loadSingle(slug)));
}

async function loadSingle(slug) {
  let range;
  try {
    range = requestRange(state.singlePresets.get(slug), [slug], state.singleCustom.get(slug));
  } catch (error) {
    announce(error.message);
    return;
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
    }, controller.signal);
    const series = payload.series[0];
    if (!series) renderSingleUnavailable(slug, payload.warnings[0]?.message);
    else renderSingle(series);
  } catch (error) {
    if (error.name === 'AbortError') return;
    if (state.singleData.has(slug)) {
      setCardBusy(slug, false);
      announce(`${state.assets.get(slug)?.displayName || slug}: ${error.message} Die zuletzt geladenen Werte bleiben sichtbar.`);
    } else renderSingleUnavailable(slug, error.message);
  }
}

async function loadComparison() {
  const slugs = comparisonAssetSlugs();
  let range;
  try {
    range = requestRange(state.comparisonPreset, slugs, state.comparisonCustom);
  } catch (error) {
    announce(error.message);
    return;
  }

  const controller = abortPrevious('comparison');
  const frame = document.querySelector('.asset-backtester__chart-frame--comparison');
  frame.setAttribute('aria-busy', 'true');
  if (slugs.length === 0) {
    charts.comparison(document.getElementById('chart-comparison'), []);
    state.comparisonData = { series: [], request: range, warnings: [] };
    const chartState = document.getElementById('comparison-chart-state');
    chartState.textContent = 'Wähle mindestens eine Linie in den Vergleichsreihen oder Einstellungen aus.';
    chartState.hidden = false;
    frame.classList.add('is-loaded');
    renderMetrics(state.comparisonData);
    renderWarnings([]);
    updateUrl(range);
    frame.removeAttribute('aria-busy');
    return;
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
      includeTaxes: state.taxesEnabled,
      personalIncomeTaxPct: state.personalIncomeTaxPct,
      capitalAllowance: state.capitalAllowance,
      churchTaxPct: state.churchTaxPct,
    }, controller.signal);
    state.comparisonData = payload;
    renderComparison(payload);
    renderMetrics(payload);
    renderWarnings(payload.warnings);
    updateUrl(range);
    updateStatusFromSeries(payload);
  } catch (error) {
    if (error.name === 'AbortError') return;
    if (state.comparisonData) {
      announce(`${error.message} Die zuletzt geladenen Daten bleiben sichtbar.`);
    } else {
      showComparisonError(error.message);
      renderMetrics({ series: [], request: range });
    }
  } finally {
    frame.removeAttribute('aria-busy');
  }
}

function renderSummary(summary) {
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
      const detail = `${row.displayName}, ${label}: ${formatPercent(value.returnPct)}. Effektiver Start ${formatDate(value.effectiveStartDate)}, Ende ${formatDate(value.effectiveEndDate)}, Startwert ${formatSourceValue(value.startValue, row.currency)}, Schlusswert ${formatSourceValue(value.endValue, row.currency)}, aus 10.000 Euro wurden ${formatEuro(value.hypotheticalValue)}.`;
      button.title = detail;
      button.setAttribute('aria-label', detail);
      button.innerHTML = `<span class="asset-backtester__period-return ${classifyReturn(value.returnPct)}">${formatPercent(value.returnPct)}</span><span class="asset-backtester__period-value">${formatEuro(value.hypotheticalValue)} aus 10.000 €</span>`;
      cell.append(button);
    });
  });
  document.getElementById('summary-note').textContent = `Gemeinsames Vergleichsende: ${formatDate(summary.globalEndDate)}. Effektive Markttage stehen in den Zelldetails.`;
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
  const card = document.querySelector(`[data-single-chart="${series.slug}"]`);
  const frame = card.querySelector('.asset-backtester__chart-frame');
  const last = series.points.at(-1);
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
  card.querySelector('[data-chart-summary]').textContent = `${series.displayName}: Aus 10.000 Euro wurden zwischen ${formatDate(series.effectiveStartDate)} und ${formatDate(series.effectiveEndDate)} ${formatEuro(series.metrics.finalValue)}. Gesamtrendite ${formatPercent(series.metrics.totalReturnPct)}${series.terAdjustment?.applied ? ` nach ${formatPlainPercent(series.terAdjustment.annualTerPct)} TER pro Jahr` : ''}.`;
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
  const frame = document.querySelector('.asset-backtester__chart-frame--comparison');
  const chartState = document.getElementById('comparison-chart-state');
  frame.classList.add('is-loaded');
  chartState.hidden = true;
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
  const resultBody = document.querySelector('#metrics-table tbody');
  const riskBody = document.querySelector('#risk-metrics-table tbody');
  resultBody.replaceChildren();
  riskBody.replaceChildren();
  document.querySelectorAll('[data-tax-column]').forEach((element) => {
    element.hidden = !state.taxesEnabled;
  });
  (payload.series || [])
    .filter((series) => !DIVIDEND_COMPARISON[series.slug] || state.selected.has(series.slug))
    .sort((a, b) => Number(b.metrics.totalReturnPct) - Number(a.metrics.totalReturnPct))
    .forEach((series) => {
    const resultRow = document.createElement('tr');
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
    const riskRow = document.createElement('tr');
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
  params.set('personalIncomeTaxPct', String(state.personalIncomeTaxPct));
  params.set('capitalAllowance', String(state.capitalAllowance));
  params.set('churchTaxPct', String(state.churchTaxPct));
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
  prepareSummarySkeleton();
  installSummarySorting();
  installIntervalControls();
  installTerControls();
  installComparisonControls();
  syncControlsFromState();

  try {
    charts = new ChartManager();
  } catch (error) {
    setGlobalError(error.message);
    ASSET_SLUGS.forEach((slug) => renderSingleUnavailable(slug, error.message));
    showComparisonError(error.message);
    return;
  }

  try {
    await loadAssets();
  } catch (error) {
    if (error.name !== 'AbortError') {
      setGlobalError('Betreiberkonfiguration oder Datenbank fehlt');
      announce(error.message);
    }
  }

  await Promise.allSettled([loadSummary(), loadInitialSingles(), loadComparison()]);
  api.syncIfStale().catch(() => {});
}

start();
