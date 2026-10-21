import { BacktesterApi } from './api.js';
import {
  PRESETS,
  chooseLongestCommonPreset,
  classifyReturn,
  debounce,
  keepAtLeastOne,
  rangeForPreset,
  yesterdayIso,
} from './calculations.js';
import { ChartManager } from './charts.js';
import {
  escapeHtml,
  formatDate,
  formatEuro,
  formatInteger,
  formatPercent,
  formatPlainPercent,
  formatRatio,
  formatSourceValue,
} from './formatters.js';

const ASSET_SLUGS = ['sp500', 'bitcoin', 'gold'];
const VALID_PRESETS = new Set(PRESETS.map(([value]) => value));
const api = new BacktesterApi();
const yesterday = yesterdayIso();

const state = {
  assets: new Map(),
  selected: new Set(ASSET_SLUGS),
  amount: 10000,
  comparisonPreset: '10y',
  comparisonCustom: null,
  comparisonData: null,
  singlePresets: new Map(ASSET_SLUGS.map((slug) => [slug, '10y'])),
  singleCustom: new Map(),
  singleData: new Map(),
  controllers: new Map(),
};

let charts = null;

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

  const assets = (params.get('assets') || '').split(',').filter((slug) => ASSET_SLUGS.includes(slug));
  if (assets.length) state.selected = new Set(assets);

  const start = params.get('start');
  const end = params.get('end');
  if (state.comparisonPreset === 'custom' && /^\d{4}-\d{2}-\d{2}$/.test(start || '') && /^\d{4}-\d{2}-\d{2}$/.test(end || '')) {
    state.comparisonCustom = { start, end };
  }
}

function syncControlsFromState() {
  document.getElementById('backtest-amount').value = String(state.amount);
  document.querySelectorAll('.asset-backtester__asset-selector input').forEach((input) => {
    input.checked = state.selected.has(input.value);
  });
  setActiveInterval(document.getElementById('comparison-intervals'), state.comparisonPreset);
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
    const custom = document.getElementById('comparison-custom-dates');
    custom.hidden = preset !== 'custom';
    if (preset !== 'custom') loadComparison();
  });
}

function showSingleCustomDates(slug) {
  const card = document.querySelector(`[data-single-chart="${slug}"]`);
  let custom = card.querySelector('[data-single-custom]');
  if (!custom) {
    custom = document.createElement('div');
    custom.className = 'asset-backtester__custom-dates';
    custom.dataset.singleCustom = slug;
    custom.innerHTML = `
      <label>Von <input type="date" data-start max="${yesterday}"></label>
      <label>Bis <input type="date" data-end max="${yesterday}" value="${yesterday}"></label>
      <button type="button">Anwenden</button>`;
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
  const firstDate = state.assets.get(slug)?.firstDate;
  custom.querySelector('[data-start]').value = current?.start || firstDate || '';
  custom.querySelector('[data-end]').value = current?.end || yesterday;
  custom.hidden = false;
}

function hideSingleCustomDates(slug) {
  const custom = document.querySelector(`[data-single-custom="${slug}"]`);
  if (custom) custom.hidden = true;
}

function installComparisonControls() {
  document.querySelectorAll('.asset-backtester__asset-selector input').forEach((input) => {
    input.addEventListener('change', () => {
      const result = keepAtLeastOne(state.selected, input.value, input.checked);
      if (!result.accepted) {
        input.checked = true;
        announce('Mindestens eine Anlageklasse muss aktiv bleiben.');
        return;
      }
      state.selected = result.selected;
      loadComparison();
    });
  });

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

  document.getElementById('comparison-end').max = yesterday;
  document.getElementById('comparison-start').max = yesterday;
  if (state.comparisonCustom) {
    document.getElementById('comparison-start').value = state.comparisonCustom.start;
    document.getElementById('comparison-end').value = state.comparisonCustom.end;
    document.getElementById('comparison-custom-dates').hidden = false;
  } else {
    document.getElementById('comparison-end').value = yesterday;
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
    const firstDates = [...state.selected].map((slug) => state.assets.get(slug)?.firstDate).filter(Boolean);
    const start = firstDates.sort().at(-1);
    if (!start) return;
    state.comparisonPreset = 'custom';
    state.comparisonCustom = { start, end: yesterday };
    document.getElementById('comparison-start').value = start;
    document.getElementById('comparison-end').value = yesterday;
    document.getElementById('comparison-custom-dates').hidden = false;
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
  if (end > yesterday) {
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
  const range = rangeForPreset(preset, yesterday, firstDates);
  if (!range.start) return rangeForPreset('10y', yesterday, []);
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
    const firstDates = ASSET_SLUGS.map((slug) => state.assets.get(slug)?.firstDate).filter(Boolean);
    state.comparisonPreset = chooseLongestCommonPreset(firstDates, yesterday);
    ASSET_SLUGS.forEach((slug) => state.singlePresets.set(slug, state.comparisonPreset));
    setActiveInterval(document.getElementById('comparison-intervals'), state.comparisonPreset);
    ASSET_SLUGS.forEach((slug) => setActiveInterval(document.querySelector(`[data-intervals="${slug}"]`), state.singlePresets.get(slug)));
  }
}

async function loadSummary() {
  const controller = abortPrevious('summary');
  try {
    const payload = await api.summary({ amount: 10000, end: yesterday }, controller.signal);
    renderSummary(payload.summary);
    updateGlobalStatus(payload.summary, payload.dataStatus);
  } catch (error) {
    if (error.name === 'AbortError') return;
    renderSummaryError(error.message);
    setGlobalError('Marktübersicht noch nicht verfügbar');
  }
}

async function loadInitialSingles() {
  const preset = state.singlePresets.get('sp500');
  const range = requestRange(preset, ASSET_SLUGS, null);
  const controller = abortPrevious('singles-initial');
  ASSET_SLUGS.forEach((slug) => setCardBusy(slug, true));
  try {
    const payload = await api.series({ assets: ASSET_SLUGS, ...range, amount: 10000 }, controller.signal);
    const bySlug = new Map(payload.series.map((series) => [series.slug, series]));
    ASSET_SLUGS.forEach((slug) => {
      const series = bySlug.get(slug);
      if (series) renderSingle(series);
      else renderSingleUnavailable(slug, payload.warnings.find((warning) => warning.asset === slug)?.message);
    });
  } catch (error) {
    if (error.name === 'AbortError') return;
    ASSET_SLUGS.forEach((slug) => renderSingleUnavailable(slug, error.message));
  }
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
    const payload = await api.series({ assets: [slug], ...range, amount: 10000 }, controller.signal);
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
  const slugs = [...state.selected];
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
  try {
    const payload = await api.series({ assets: slugs, ...range, amount: state.amount }, controller.signal);
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
  const rows = new Map(summary.rows.map((row) => [row.slug, row]));
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
      const detail = `${row.displayName}, ${label}: ${formatPercent(value.returnPct)}. Effektiver Start ${formatDate(value.effectiveStartDate)}, Ende ${formatDate(value.effectiveEndDate)}, Startwert ${formatSourceValue(value.startValue)}, Schlusswert ${formatSourceValue(value.endValue)}, aus 10.000 Euro wurden ${formatEuro(value.hypotheticalValue)}.`;
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
  card.querySelector('[data-raw-date]').textContent = `Echter Wert ${formatDate(series.lastRawObservationDate)}`;
  card.querySelector('[data-chart-summary]').textContent = `${series.displayName}: Aus 10.000 Euro wurden zwischen ${formatDate(series.effectiveStartDate)} und ${formatDate(series.effectiveEndDate)} ${formatEuro(series.metrics.finalValue)}. Gesamtrendite ${formatPercent(series.metrics.totalReturnPct)}.`;
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
  charts.comparison(document.getElementById('chart-comparison'), payload.series);

  const summary = payload.series.map((series) => `${series.displayName}: ${formatEuro(series.metrics.finalValue)} und ${formatPercent(series.metrics.totalReturnPct)}`).join('; ');
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
  (payload.series || []).forEach((series) => {
    const resultRow = document.createElement('tr');
    resultRow.innerHTML = `
      <th scope="row"><span class="asset-backtester__asset-mark asset-backtester__asset-mark--${escapeHtml(series.slug)}"></span>${escapeHtml(series.displayName)}</th>
      <td><strong>${formatEuro(series.metrics.finalValue)}</strong></td>
      <td class="${classifyReturn(series.metrics.absoluteReturn)}">${formatEuro(series.metrics.absoluteReturn)}</td>
      <td class="${classifyReturn(series.metrics.totalReturnPct)}">${formatPercent(series.metrics.totalReturnPct)}</td>
      <td>${formatPercent(series.metrics.cagrPct)}</td>
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
    ? `${formatDate(request.start)} – ${formatDate(request.end)}`
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
  ASSET_SLUGS.forEach((slug) => {
    const asset = state.assets.get(slug);
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
  if (state.comparisonPreset === 'custom') {
    params.set('start', range.start);
    params.set('end', range.end);
  }
  history.replaceState(null, '', `${location.pathname}?${params.toString()}${location.hash}`);
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
  installIntervalControls();
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
