import { nextPaint } from './loading.js?v=20261028-v13y';
import { TAX_DEFAULTS } from './tax-model.js?v=20261028-v13y';
import { simulatePortfolioTaxes,portfolioTaxKey } from './portfolio-tax.js?v=20261028-v13y';
import { PortfolioSingles, portfolioSingleRecords } from './portfolio-singles.js?v=20261028-v13y';
import { initialRanking, sortMetricRows, sortSummaryTable } from './ranking.js?v=20261028-v13y';
import { ASSETS, REFERENCE, OPTIONAL_RULES, OBJECTIVES, optimizationConfig, objectiveValue, assetLeverage, assetTer, limitEnabled, defaults, standardPortfolios, validateConfig,
  prepareMarket, evaluate, optimize, diagnostics, portfolioPeriod, referenceVolatility, volatilityLimit } from './portfolio-engine.js?v=20261028-v13y';
import { buildPortfolioComparison } from './portfolio-comparison.js?v=20261028-v13y';
import { PortfolioStore, exportPortfolios } from './portfolio-store.js?v=20261028-v13y';
import { escapeHtml as esc } from './formatters.js?v=20261028-v13y';

const number = (v, digits = 2) => Number.isFinite(v) ? new Intl.NumberFormat('en-GB', { maximumFractionDigits: digits }).format(v) : 'Not calculable';
const pct = (v) => Number.isFinite(v) ? `${number(v)} %` : 'Not calculable';
const eur = (v) => new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(v);
const labels = { daily: 'Daily ', monthly: 'Monthly ', quarterly: 'Quarterly ' };
const rebalancingLabels = {quarterly:'Quarterly',monthly:'Monthly',yearly:'Yearly',threshold:'By weight band',none:'Off (Buy & Hold)'};
const colors = ['#6ee7b7', '#818cf8', '#f6c85f', '#f472b6', '#38bdf8', '#fb923c', '#a3e635'];
const copy = (v) => JSON.parse(JSON.stringify(v));
const pause = () => new Promise((resolve) => setTimeout(resolve, 25));
function slider(id, label, value, min, max, step = 0.5, attributes = '') {
  return `<div class="portfolio-lab__slider"><input type="range" min="${min}" max="${max}" step="${step}" value="${value}" data-pf-slider-for="${id}" aria-label="${esc(label)} slider"><div><input id="${id}" type="number" min="${min}" max="${max}" step="${step}" value="${value}" data-pf-number-slider="${id}" ${attributes}><span>%</span></div></div>`;
}

const BASE_RULE_HELP = {"alternatives":"Maximum combined exposure to Bitcoin, gold, broad commodities and real estate. Position leverage counts towards this limit. The slider allows 0–100%.","position":"Maximum exposure to any single alternative asset class, such as Bitcoin or gold. Position leverage counts towards this limit. The slider allows 0–100%.","volatility":"Maximum annualized portfolio volatility in percent per year. Its default is calculated from the 60/40 portfolio over the current period and risk grid. The reference holds 60% MSCI World and 40% EUR-hedged global IG bonds, rebalanced quarterly. A manual entry is an absolute limit."};
function ruleInfo(label,help,key) {
  return '<button type="button" class="portfolio-lab__rule-info" data-rule-info="'+esc(key)+'" aria-label="'+esc(label+': '+help)+'"><span aria-hidden="true">i</span><span class="portfolio-lab__rule-tooltip" role="tooltip">'+esc(help)+'</span></button>';
}
function positionRuleTooltip(button) {
  const tip=button.querySelector('[role="tooltip"]');if(!tip)return;
  const box=button.getBoundingClientRect(),width=Math.min(280,window.innerWidth-32);
  tip.style.width=width+'px';tip.style.left=Math.max(16,Math.min(box.left,window.innerWidth-width-16))+'px';
  const height=tip.getBoundingClientRect().height;tip.style.top=(box.bottom+8+height>window.innerHeight?Math.max(8,box.top-height-8):box.bottom+8)+'px';
}
export class PortfolioLab {
  constructor({ deferInitialReference=false, api, assets, onChange = () => {}, getComparisonStart = () => null, getComparisonEnd = () => null, getRanking = initialRanking, getSummarySort = () => ({period:'1Y',direction:'desc'}), getSummaryEnd = () => null, getTaxSettings = () => ({taxesEnabled:false}) }) {
    this.deferInitialReference=deferInitialReference;
    this.getTaxSettings=getTaxSettings;this.taxViewCache=new WeakMap();this.taxErrors=new Map();
    this.getRanking=getRanking;this.getSummarySort=getSummarySort;this.getSummaryEnd=getSummaryEnd;this.api = api; this.assets = assets; this.onChange = onChange; this.getComparisonStart = getComparisonStart; this.comparisonStart = getComparisonStart(); this.comparisonEnd = getComparisonEnd();
    this.volatilityReferenceRevision = 0; this.volatilityReferencePct = null; this.comparisonSelection = new Set(); this.results = new Map(); this.cache = new Map(); this.revision = 0; this.sequence = 0; this.dirtyIds = new Set(); this.jobs = new Set();
    this.singleErrors = new Map(); this.enabledLeverage = new Set(); this.chart = null; this.drawdownChart = null; this.activeKey = ''; this.config = defaults(); this.saved = [];
    this.config = this.draftConfig(this.config);
    this.templates = standardPortfolios().map((c) => this.draftConfig(c));
    try { this.store = new PortfolioStore(window.localStorage); this.saved = this.store.load(); }
    catch (error) { this.storageError = error.message; }
    this.mount(); this.renderForm(); this.renderLibrary(); this.renderLinked();
    if (this.storageError) this.message(this.storageError, 'error');
  }
  draftConfig(config) {
    return {...copy(config),amount:10000,start:this.comparisonStart || config.start,end:this.comparisonEnd || config.end,optimizationAssets:config.optimizationAssets ?? Object.keys(ASSETS)};
  }
  setComparisonRange(start, end) {
    if (!start || !end || (start === this.comparisonStart && end === this.comparisonEnd)) return;
    this.comparisonStart = start; this.comparisonEnd = end;
    this.config.start = start; this.config.end = end;
    this.templates = this.templates.map((c) => ({...c,start,end}));
    this.results.clear(); this.invalidate({removeActive:false}); this.renderLibrary();
    void this.restore();
  }
  mount() {
    this.root = document.getElementById('portfolio-lab');
    const showInfo=(event)=>{const button=event.target.closest('.portfolio-lab__rule-info');if(button)positionRuleTooltip(button);};
    this.root.addEventListener('pointerover',showInfo);this.root.addEventListener('focusin',showInfo);
    window.addEventListener('resize',()=>{const button=this.root.querySelector('.portfolio-lab__rule-info:hover, .portfolio-lab__rule-info:focus-visible');if(button)positionRuleTooltip(button);});
    document.querySelector('.asset-backtester__comparison-config-menu .asset-backtester__config-options').insertAdjacentHTML('afterend', "<section class=\"portfolio-lab__comparison-options\" aria-label=\"Portfolios in the overall comparison\"><h4>Portfolios</h4><p>Select portfolios for the comparison. Each starts with €10,000.</p><div id=\"comparison-portfolios\" class=\"asset-backtester__config-options\"></div></section>");
    document.querySelector('#comparison-portfolios').addEventListener('change', (event) => {
      const input = event.target.closest('[data-portfolio-option]'); if (!input) return;
      if (input.checked) this.comparisonSelection.add(input.dataset.portfolioOption); else this.comparisonSelection.delete(input.dataset.portfolioOption);
      this.renderLinked(); this.onChange();
    });
    this.root.innerHTML = `
      <div class="portfolio-lab__intro"><div>
        <h2>Build a portfolio</h2><p>Set weights, check rules and compare portfolios.</p></div>
        <span class="portfolio-lab__storage">Saved portfolios <b id="pf-slot-count">0 / 5</b></span></div>
      <div class="portfolio-lab__library"><div><span class="portfolio-lab__step">STARTING POINT</span><div id="pf-templates" class="portfolio-lab__templates"></div></div>
        <div><label for="pf-saved">Your portfolios</label><div class="portfolio-lab__saved"><select id="pf-saved"><option value="">Choose a saved portfolio</option></select>
          <button type="button" data-pf-action="load">Load</button><button type="button" data-pf-action="delete">Delete</button></div>
          <p class="portfolio-lab__hint">Two templates and five portfolios of your own.</p></div></div>
      <form id="pf-form" novalidate>
        <div class="portfolio-lab__editor"><div class="portfolio-lab__composition"><div class="portfolio-lab__section-title"><h4>Composition</h4><button type="button" data-pf-action="new">New portfolio</button></div>
          <label for="pf-name">Portfolio name</label><input id="pf-name" maxlength="80" required>
          <label for="pf-description">Description <small>optional</small></label><input id="pf-description" maxlength="500">
          <div class="portfolio-lab__allocation" id="pf-allocation" aria-hidden="true"></div>
          <div class="portfolio-lab__weights" id="pf-weights"></div>
          <div class="portfolio-lab__weight-total" id="pf-weight-total" aria-live="polite"></div>
          <div class="portfolio-lab__add"><label class="sr-only" for="pf-add-asset">Add asset class</label><select id="pf-add-asset"></select><button type="button" data-pf-action="add-asset">Add</button></div>
        </div><div class="portfolio-lab__settings"><h4>Rules &amp; dates</h4>
          <div id="pf-mandatory"></div>
          <div id="pf-rules"></div><div class="portfolio-lab__add"><label class="sr-only" for="pf-add-rule">Additional rule</label><select id="pf-add-rule"></select><button type="button" data-pf-action="add-rule">Add rule</button></div>
          <p class="portfolio-lab__hint">Portfolios always start with €10,000.</p><div class="portfolio-lab__fields">
            
            <div class="portfolio-lab__strategy-row"><label>Time grid<select id="pf-frequency"><option value="auto">Automatic</option><option value="daily">Daily</option><option value="monthly">Monthly</option><option value="quarterly">Quarterly</option></select></label><label>Rebalancing<select id="pf-rebalancing"><option value="none">Off</option><option value="monthly">Monthly</option><option value="quarterly">Quarterly</option><option value="yearly">Yearly</option><option value="threshold">Weight band</option></select></label></div>
            <label data-pf-threshold-field>Weight band in percentage points<input id="pf-threshold" type="number" min="0.1" max="100" step="0.5" data-pf-number-slider="pf-threshold"><input type="range" min="0.5" max="100" step="0.5" value="5" data-pf-slider-for="pf-threshold" aria-label="Weight band slider"></label>
            <label>Rolling risk window (months)<input id="pf-rollingMonths" type="number" min="12" max="120" step="3" data-pf-number-slider="pf-rollingMonths"><input type="range" min="12" max="120" step="3" value="12" data-pf-slider-for="pf-rollingMonths" aria-label="Rolling risk window slider"></label></div>
          <details class="portfolio-lab__leverage"><summary>Additional costs <small>optional</small></summary>
            <div class="portfolio-lab__fields"><label>Additional portfolio TER (% p.a.)<input id="pf-terPct" type="number" min="0" max="5" step="0.01"></label>
              <label>Financing rate (% p.a.)<input id="pf-financingPct" type="number" min="0" max="20" step="0.1"></label></div>
            <p class="portfolio-lab__hint">Set leverage and TER for each asset class. Each position follows its own observed prices. Between observations its last price is held; costs accrue over time. Financing applies only to the borrowed portion of each position. Do not double-count costs already included in prices. The 60/40 reference remains unleveraged with no additional costs.</p>
          </details>
        </div></div>
        <section class="portfolio-lab__optimizer" aria-label="Optimise portfolio from rules"><div class="portfolio-lab__optimizer-head"><div><span class="portfolio-lab__step">OPTIMISE FROM RULES</span><h3>Find portfolio weights</h3><p>Choose your objective and rules. The search builds the portfolio automatically.</p></div><button class="portfolio-lab__primary" type="button" data-pf-action="optimize">Optimise portfolio from rules</button></div>
          <label for="pf-objective">Optimisation objective</label><select id="pf-objective"></select><p class="portfolio-lab__hint" id="pf-objective-help"></p>
          <details class="portfolio-lab__universe"><summary>Eligible asset classes <span id="pf-pool-count"></span></summary><div class="portfolio-lab__pool-actions"><button type="button" data-pf-action="pool-all">Select all</button><button type="button" data-pf-action="pool-editor">Use editor assets</button></div><div id="pf-pool" class="portfolio-lab__pool"></div></details>
          <p class="portfolio-lab__hint">Starting weights are not fixed. Leverage and TER from the editor still apply; additional asset classes start at 1× and 0% TER. Slower series determine the shared time grid. The result is the best historical allocation found, not a proven global optimum.</p>
          <div id="pf-optimization-result" class="portfolio-lab__optimization-result" hidden aria-live="polite"></div>
        </section>
        <div class="portfolio-lab__actions"><div><span class="portfolio-lab__step">FINISH MANUALLY</span><p id="pf-draft-state">Draft. No portfolio results yet.</p></div>
          <div><button class="portfolio-lab__primary" type="submit">Finish portfolio</button></div></div>
      </form>
      <div id="pf-message" class="portfolio-lab__message" role="status" aria-live="polite" hidden></div>
      <div class="portfolio-lab__save-actions"><button class="portfolio-lab__primary" type="button" data-pf-action="save" disabled>Save portfolio</button><button type="button" data-pf-action="duplicate" disabled>Save a copy</button>
        <button type="button" data-pf-action="export">Export JSON</button><button type="button" data-pf-action="import">Import JSON</button><input id="pf-import-file" type="file" accept="application/json,.json" hidden></div>
      <details class="portfolio-lab__method"><summary>Model and data</summary><p>Fully invested underlying portfolio with optional leverage and costs. Bitcoin, gold, commodities and real estate are classified as alternatives. Reference: 60% MSCI World and 40% EUR-hedged global IG bonds, rebalanced quarterly.</p>
        <p>Portfolios are calculated in nominal EUR using return series supplied by the API. Currency, inflation and comparison TER are not transferred. The global tax switch also applies to portfolios. Rules and optimisation remain before personal taxes. Real estate uses price indices without rental cash flow. Equity distribution models remain labelled.</p>
        <p>Valuation uses the latest available prices on every calendar day. The data grid controls risk measurement only; automatic mode accounts for slower series. Enabled risk checks apply to the selected historical period and do not guarantee future limits. Market-driven allocation breaches are reported until the next rebalance.</p>
        <p>Optimisation uses a bounded search for a feasible historical allocation for each objective; it does not prove a global optimum.</p></details>`;

    this.riskOutput = document.createElement('div'); this.riskOutput.id = 'pf-risk-results'; this.riskOutput.className = 'portfolio-lab__results'; this.riskOutput.hidden = true;
    document.querySelector('#risk-metrics-table').closest('.asset-backtester__table-scroll').insertAdjacentElement('afterend', this.riskOutput);
    this.root.querySelector('#pf-form').addEventListener('submit', (event) => { event.preventDefault(); this.run(false); });
    this.root.addEventListener('input', (event) => {
      if (event.target.closest('#pf-form')) { this.syncSlider(event.target); if (event.target.id === 'pf-limit-volatility' || event.target.dataset.pfSliderFor === 'pf-limit-volatility') { const value=this.root.querySelector('#pf-limit-volatility').value; this.config.volatilityLimitPct=value===''?NaN:Number(value); } this.readForm(); this.invalidate(); this.renderTotals(); }
    });
    this.root.addEventListener('change', (event) => {
      if (event.target.closest('#pf-form')) {
        this.readForm(); this.invalidate();
        if(event.target.id==='pf-rebalancing')this.renderForm();
      }
    });
    this.root.addEventListener('click', (event) => {
      const button = event.target.closest('[data-pf-action]'); if (!button) return;
      this.action(button.dataset.pfAction, button.dataset.key);
    });
    this.root.querySelector('#pf-import-file').addEventListener('change', async (event) => {
      const file = event.target.files[0]; if (!file) return;
      try { if (file.size > 200000) throw Error('The file is too large.'); this.requireStore(); this.saved = this.store.import(this.saved, await file.text());
        if (this.saved.length >= 5) for (const key of this.results.keys()) if (key.startsWith('preview-')) this.results.delete(key);
        this.renderLibrary(); this.message('Portfolios imported. They will be checked again before evaluation.'); await this.restore(); }
      catch (error) { this.message(error.message, 'error'); } finally { event.target.value = ''; }
    });
  }
  requireStore() { if (!this.store || this.storageError) throw Error(this.storageError || 'Storage is unavailable. Export JSON.'); }
  message(text, kind = '') { const el = this.root.querySelector('#pf-message'); el.hidden = false; el.textContent = text; el.dataset.kind = kind; }
  invalidate({ removeActive = true } = {}) {
    this.setBusy(false);
    this.root.querySelector('#pf-optimization-result').hidden = true;
    this.root.querySelector('#pf-message').hidden = true;
    for (const cancel of this.jobs) cancel(); this.jobs.clear();
    this.revision++; if (removeActive && this.activeKey) this.results.delete(this.activeKey); this.activeKey = '';
    if (removeActive && this.config.id) { this.results.delete(this.config.id); this.dirtyIds.add(this.config.id); }
    this.root.querySelector('#pf-draft-state').textContent = 'Draft changed. Finish it again to see results.';
    for (const a of ['save', 'duplicate']) this.root.querySelector(`[data-pf-action="${a}"]`).disabled = true;
    this.renderLinked(); this.onChange(); void this.refreshVolatilityReference();
  }
  readForm() {
    const get = (key) => this.root.querySelector(`#pf-${key}`).value;
    Object.assign(this.config, { name: get('name'), description: get('description'), start: this.comparisonStart || this.config.start, end: this.comparisonEnd || this.config.end,
      amount: 10000, frequency: get('frequency'), rebalancing: get('rebalancing'), threshold: Number(get('threshold')), rollingMonths: Number(get('rollingMonths')), terPct: Number(get('terPct')), financingPct: Number(get('financingPct')) });
    this.config.objective = get('objective');
    this.config.optimizationAssets = Array.from(this.root.querySelectorAll('[data-pf-pool]:checked')).map((el) => el.dataset.pfPool);
    this.config.leverage = 1;
    this.config.enabledLimits = {alternatives:true,position:true,volatility:true,...this.config.enabledLimits};
    this.config.assetSettings = {...this.config.assetSettings,...Object.fromEntries(Object.keys(this.config.weights).map((slug) => [slug,{leverage:Number(get('leverage-'+slug)),terPct:Number(get('ter-'+slug))}]))};
    this.config.limits = Object.fromEntries(['alternatives', 'position', 'volatility'].map((key) => [key, key!=='volatility' && this.root.querySelector(`#pf-limit-${key}`) ? Number(get(`limit-${key}`)) : this.config.limits[key]]));
    this.root.querySelectorAll('[data-pf-weight]').forEach((el) => { this.config.weights[el.dataset.pfWeight] = el.value === '' ? NaN : Number(el.value); });
    this.root.querySelectorAll('[data-pf-rule]').forEach((el) => {
      this.config.rules[el.dataset.pfRule] = { value: el.value === '' ? NaN : Number(el.value), mode: this.config.rules[el.dataset.pfRule]?.mode || 'absolute' };
    });
  }
  syncSlider(input) {
    if (input.dataset.pfSliderFor) this.root.querySelector(`#${input.dataset.pfSliderFor}`).value = input.value;
    if (input.dataset.pfNumberSlider) {
      const range = this.root.querySelector(`[data-pf-slider-for="${input.dataset.pfNumberSlider}"]`);
      if (range) range.value = input.value;
    }
  }
  leverageEnabled(slug) { return this.enabledLeverage.has(slug) || assetLeverage(this.config,slug) > 1 || assetTer(this.config,slug) > 0; }
  renderForm() {
    const c = this.config;
    for (const key of ['name', 'description', 'frequency', 'rebalancing', 'threshold', 'rollingMonths', 'terPct', 'financingPct']) {
      const input = this.root.querySelector(`#pf-${key}`); input.value = c[key] ?? (key === 'leverage' ? 1 : 0); this.syncSlider(input);
    }
    this.root.querySelector('#pf-weights').innerHTML = Object.entries(c.weights).map(([slug,weight],i) => {
      const leverageEnabled=this.leverageEnabled(slug);
      const max = ASSETS[slug][1] && limitEnabled(c,'position') ? c.limits.position / assetLeverage(c,slug) : 100;
      return `<div class="portfolio-lab__weight-row"><label for="pf-weight-${slug}"><i style="background:${colors[i % colors.length]}"></i>${esc(ASSETS[slug][0])}${ASSETS[slug][1] ? '<small>Alternative</small>' : ''}</label>
        <div><input id="pf-weight-${slug}" data-pf-weight="${slug}" data-pf-number-slider="pf-weight-${slug}" type="number" min="0.01" max="${max}" step="0.01" value="${weight}"><span>%</span><button type="button" data-pf-action="remove-asset" data-key="${slug}" aria-label="${esc(ASSETS[slug][0])} remove">×</button></div>
        <input type="range" min="0" max="${max}" step="0.5" value="${weight}" data-pf-slider-for="pf-weight-${slug}" aria-label="Weight ${esc(ASSETS[slug][0])} slider">
        <div class="portfolio-lab__position-settings ${leverageEnabled?'is-leveraged':'is-unleveraged'}"><button type="button" class="portfolio-lab__leverage-toggle" data-pf-action="toggle-leverage" data-key="${slug}" aria-pressed="${leverageEnabled}" aria-controls="pf-leverage-label-${slug} pf-leverage-slider-${slug} pf-ter-label-${slug}">${leverageEnabled?'Disable leverage':'Enable leverage'}</button><label id="pf-leverage-label-${slug}" data-pf-leverage-field ${leverageEnabled?'':'hidden'} for="pf-leverage-${slug}">Leverage <span>×</span><input id="pf-leverage-${slug}" ${leverageEnabled?'':'disabled'} type="number" min="1" max="3" step="0.1" value="${assetLeverage(c,slug)}" data-pf-number-slider="pf-leverage-${slug}" aria-label="Leverage ${esc(ASSETS[slug][0])}"></label>
          <input id="pf-leverage-slider-${slug}" data-pf-leverage-field ${leverageEnabled?'':'hidden disabled'} type="range" min="1" max="3" step="0.1" value="${assetLeverage(c,slug)}" data-pf-slider-for="pf-leverage-${slug}" aria-label="Leverage ${esc(ASSETS[slug][0])} slider">
          <label id="pf-ter-label-${slug}" data-pf-leverage-field ${leverageEnabled?'':'hidden'} for="pf-ter-${slug}" title="Additional annual leverage costs, as a percentage of this position’s value. Charged only when leverage is enabled.">TER <span>% p.a.</span><input id="pf-ter-${slug}" ${leverageEnabled?'':'disabled'} title="Additional annual leverage costs, as a percentage of this position’s value. Charged only when leverage is enabled." type="number" min="0" max="5" step="0.01" value="${assetTer(c,slug)}" aria-label="Additional leverage costs (TER) ${esc(ASSETS[slug][0])}"></label></div></div>`;
    }).join('');
    this.root.querySelector('#pf-add-asset').innerHTML = Object.entries(ASSETS).filter(([slug]) => !Object.hasOwn(c.weights, slug)).map(([slug, [name]]) => `<option value="${slug}">${esc(name)}</option>`).join('');
    const baseRules = [
      ['alternatives',"Maximum total allocation to alternative assets",100],['position',"Maximum allocation per alternative asset",100],['volatility','Volatility p.a.',Math.max(100,this.volatilityInputValue() || 0)],
    ];
    this.root.querySelector('#pf-mandatory').innerHTML = baseRules.filter(([key]) => limitEnabled(c,key)).map(([key,label,max]) => `<div class="portfolio-lab__rule"><div class="portfolio-lab__rule-header"><label for="pf-limit-${key}">${label}</label>${ruleInfo(label,BASE_RULE_HELP[key],key)}<button type="button" data-pf-action="remove-base" data-key="${key}" aria-label="Remove rule: ${esc(label)}">×</button></div>${slider(`pf-limit-${key}`,label,key==='volatility'?this.volatilityInputValue():c.limits[key],0,max,key==='volatility'?0.01:0.5,'aria-label="'+esc(label)+' limit"')}</div>`).join('');
    this.root.querySelector('[data-pf-threshold-field]').hidden = c.rebalancing !== 'threshold';
    this.root.querySelector('#pf-rules').innerHTML = Object.entries(c.rules).map(([key, rule]) => `<div class="portfolio-lab__rule"><div class="portfolio-lab__rule-header"><label for="pf-rule-${key}" title="${esc(OPTIONAL_RULES[key][1])}">${esc(OPTIONAL_RULES[key][0])}</label>${ruleInfo(OPTIONAL_RULES[key][0],OPTIONAL_RULES[key][1]+(rule.mode==='reference'?" The limit is a percentage of the corresponding 60/40 risk measure.":''),key)}<button type="button" data-pf-action="remove-rule" data-key="${key}" aria-label="Remove rule">×</button></div>
      ${slider(`pf-rule-${key}`, OPTIONAL_RULES[key][0], rule.value, OPTIONAL_RULES[key][2], 100, 0.1, `data-pf-rule="${key}" aria-label="Limit in percent"`)}</div>`).join('');
    this.root.querySelector('#pf-add-rule').innerHTML = baseRules.filter(([key]) => !limitEnabled(c,key)).map(([key,label]) => '<option value="base:'+key+'">'+esc(label)+'</option>').join('') + Object.entries(OPTIONAL_RULES).filter(([key]) => !c.rules[key]).map(([key, [name]]) => `<option value="${key}">${esc(name)}</option>`).join('');
    this.root.querySelector('#pf-objective').innerHTML = Object.entries(OBJECTIVES).map(([key,spec]) => '<option value="'+key+'">'+esc(spec[0])+'</option>').join('');
    this.root.querySelector('#pf-objective').value = c.objective ?? 'cagr';
    const pool = c.optimizationAssets ?? Object.keys(ASSETS);
    this.root.querySelector('#pf-pool').innerHTML = Object.entries(ASSETS).map(([slug,[name]]) => '<label><input type="checkbox" data-pf-pool="'+slug+'" aria-label="For optimisation: '+esc(name)+'" '+(pool.includes(slug)?'checked':'')+'><span>'+esc(name)+'</span></label>').join('');
    this.renderTotals(); void this.refreshVolatilityReference();
  }
  renderTotals() {
    this.root.querySelector('#pf-objective-help').textContent = OBJECTIVES[this.config.objective ?? 'cagr'][1];
    this.root.querySelector('#pf-pool-count').textContent = '('+(this.config.optimizationAssets ?? Object.keys(ASSETS)).length+')';
    const entries = Object.entries(this.config.weights); const total = entries.reduce((sum, [, w]) => sum + (Number.isFinite(w) ? w : 0), 0);
    const alt = entries.reduce((sum, [s, w]) => sum + (ASSETS[s][1] && Number.isFinite(w) ? w * assetLeverage(this.config,s) : 0), 0);
    const el = this.root.querySelector('#pf-weight-total'); el.textContent = `${number(total)}% allocated / ${number(alt)}% alternative market exposure`;
    el.dataset.valid = String(Math.abs(total - 100) < 0.00001 && (!limitEnabled(this.config,'alternatives') || alt <= this.config.limits.alternatives));
    for (const [slug] of entries) {
      const max = ASSETS[slug][1] && limitEnabled(this.config,'position') ? this.config.limits.position / assetLeverage(this.config,slug) : 100;
      this.root.querySelector('#pf-weight-'+slug).max = max;
      this.root.querySelector('[data-pf-slider-for="pf-weight-'+slug+'"]').max = max;
    }
    this.root.querySelector('#pf-allocation').innerHTML = entries.map(([slug, w], i) => `<span title="${esc(ASSETS[slug][0])}" style="flex:${Math.max(0, Number.isFinite(w) ? w : 0)};background:${colors[i % colors.length]}"></span>`).join('');
  }
  renderLibrary() {
    this.root.querySelector('#pf-slot-count').textContent = `${this.saved.length} / 5`;
    this.root.querySelector('#pf-templates').innerHTML = this.templates.map((p, i) => `<button type="button" data-pf-action="template" data-key="${i}"><strong>${esc(p.name)}</strong><span>${i ? 'Stocks / bonds / money market' : 'Stocks / bonds'}</span></button>`).join('');
    const selected = this.root.querySelector('#pf-saved').value;
    this.root.querySelector('#pf-saved').innerHTML = '<option value="">Choose a saved portfolio</option>' + this.saved.map((p) => `<option value="${esc(p.id)}">${esc(p.name)}</option>`).join('');
    this.root.querySelector('#pf-saved').value = this.saved.some((p) => p.id === selected) ? selected : '';
  }
  action(action, key) {
    try {
      if (action === 'toggle-leverage') {
        this.readForm();
        if(this.leverageEnabled(key)){
          this.enabledLeverage.delete(key);
          this.config.assetSettings[key]={...this.config.assetSettings[key],leverage:1,terPct:0};
        } else this.enabledLeverage.add(key);
        this.invalidate();this.renderForm();return;
      }
      if (action === 'remove-base') {this.readForm();this.config.enabledLimits[key]=false;this.invalidate();this.renderForm();return;}
      if (action === 'pool-all' || action === 'pool-editor') {this.readForm();this.config.optimizationAssets=action==='pool-all'?Object.keys(ASSETS):Object.keys(this.config.weights);this.invalidate();this.renderForm();return;}
      if (action === 'optimize') { this.run(true); return; }
      if (action === 'template' || action === 'new' || action === 'load') {
        const selected = this.saved.find((p) => p.id === this.root.querySelector('#pf-saved').value);
        if (action === 'load' && !selected) throw Error('Choose a saved portfolio first.');
        this.invalidate({ removeActive: false }); this.enabledLeverage.clear(); this.config = this.draftConfig(action === 'template' ? this.templates[Number(key)] : action === 'load' ? selected : defaults());
        this.renderForm(); this.message('Composition loaded. Select “Finish portfolio” to validate and evaluate it.'); return;
      }
      if (action === 'add-asset') { const s = this.root.querySelector('#pf-add-asset').value; if (s) {this.config.weights[s]=5;if(Array.isArray(this.config.optimizationAssets)&&!this.config.optimizationAssets.includes(s))this.config.optimizationAssets.push(s);} }
      if (action === 'remove-asset') {delete this.config.weights[key];if(Array.isArray(this.config.optimizationAssets))this.config.optimizationAssets=this.config.optimizationAssets.filter((s)=>s!==key);}
      if (action === 'add-rule') { const r = this.root.querySelector('#pf-add-rule').value; if (r.startsWith('base:')) {this.readForm();this.config.enabledLimits[r.slice(5)]=true;this.invalidate();this.renderForm();return;} if (r) this.config.rules[r] = { mode: r==='shortfall' ? 'reference' : 'absolute', value: { drawdown:25, contribution:80, tracking:5, turnover:20 }[r] || 100 }; }
      if (action === 'remove-rule') delete this.config.rules[key];
      if (['add-asset', 'remove-asset', 'add-rule', 'remove-rule'].includes(action)) { this.invalidate(); this.renderForm(); return; }
      if (action === 'save' || action === 'duplicate') {
        this.requireStore(); const entry = this.results.get(this.activeKey); if (!entry || !entry.result.valid) throw Error('Finish a valid portfolio first.');
        const saved = this.store.save(this.saved, this.config, { duplicate: action === 'duplicate' }); this.saved = saved.portfolios;
        if (action === 'save' || !this.config.id) { this.results.delete(this.activeKey); this.activeKey = saved.item.id; this.config = saved.item; this.results.set(this.activeKey, { ...entry, config: copy(saved.item) }); }
        else this.results.set(saved.item.id, { ...entry, config: copy(saved.item) });
        this.renderLibrary(); this.renderLinked(); this.onChange(); this.message('Portfolio saved.'); return;
      }
      if (action === 'delete') {
        this.requireStore(); const id = this.root.querySelector('#pf-saved').value; const p = this.saved.find((s) => s.id === id);
        if (!p) throw Error('Choose a saved portfolio first.');
        if (!window.confirm(`„${p.name}“ delete?`)) return;
        this.saved = this.store.remove(this.saved, id); this.results.delete(id);
        if (this.config.id === id) { this.config.id = ''; this.activeKey = ''; for (const a of ['save', 'duplicate']) this.root.querySelector(`[data-pf-action="${a}"]`).disabled = true; }
        this.renderLibrary(); this.renderLinked(); this.onChange(); return;
      }
      if (action === 'export') {
        const text = exportPortfolios(this.saved.length ? this.saved : [this.config]);
        const url = URL.createObjectURL(new Blob([text], { type: 'application/json' })); const a = document.createElement('a');
        a.href = url; a.download = 'my-portfolios.json'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); return;
      }
      if (action === 'import') this.root.querySelector('#pf-import-file').click();
    } catch (error) { this.message(error.message, 'error'); }
  }
  volatilityInputValue() {
    const target = volatilityLimit(this.config, this.volatilityReferencePct);
    return Number.isFinite(target) && (this.config.volatilityLimitPct != null || this.volatilityReferencePct != null) ? Math.round(target * 100) / 100 : 0;
  }
  renderVolatilityControl() {
    const input=this.root.querySelector('#pf-limit-volatility'); if (!input) return;
    const range=this.root.querySelector('[data-pf-slider-for="pf-limit-volatility"]');
    const ready=Number.isFinite(this.volatilityReferencePct) || this.config.volatilityLimitPct != null;
    const value=this.volatilityInputValue(); const max=Math.max(100,value);
    for (const control of [input,range]) { control.disabled=!ready; control.max=String(Math.min(1000,max)); control.step='0.01'; if(document.activeElement!==control)control.value=String(value); }
    input.closest('.portfolio-lab__slider').classList.add('portfolio-lab__volatility-slider');
    input.closest('.portfolio-lab__slider').querySelector('span').textContent='% p.a.';
    const info=this.root.querySelector('[data-rule-info="volatility"]');
    const text=BASE_RULE_HELP.volatility+(Number.isFinite(this.volatilityReferencePct)?' 60/40: '+pct(this.volatilityReferencePct)+' p.a.':'');
    if(info){info.setAttribute('aria-label',text);info.querySelector('[role="tooltip"]').textContent=text;}
    input.title=this.volatilityReferenceError || '';
    if(!ready)input.value='';
  }
  async refreshVolatilityReference(forOptimization=Boolean(this.config.dataSnapshot?.optimization), options={}) {
    if(this.deferInitialReference)return;
    if(!limitEnabled(this.config,'volatility'))return true;
    const config=copy(this.config);
    const key=JSON.stringify([config.start,config.end,config.frequency,Object.keys(config.weights).sort(),forOptimization?config.optimizationAssets:null]);
    if(key===this.volatilityReferenceKey){this.renderVolatilityControl();return this.volatilityReferencePromise;}
    const revision=++this.volatilityReferenceRevision; this.volatilityReferenceKey=key;
    this.volatilityReferencePct=null;this.volatilityReferenceError='';this.renderVolatilityControl();
    const pending=(async()=>{
      try {
        const market=await this.marketFor(config,forOptimization,options);const value=referenceVolatility(market,config);
        if(revision!==this.volatilityReferenceRevision)return null;
        if(!Number.isFinite(value))throw Error("Reference volatility is unavailable for these data.");
        this.volatilityReferencePct=value;this.volatilityReferenceFrequency=market.frequency;this.renderVolatilityControl();return market;
      } catch(error){if(revision===this.volatilityReferenceRevision){this.volatilityReferenceKey=null;this.volatilityReferenceError=error.message;this.renderVolatilityControl();}return false;}
    })();
    this.volatilityReferencePromise=pending;return pending;
  }
  async marketFor(config, forOptimization = Boolean(config.dataSnapshot?.optimization), options={}) {
    if (forOptimization) config = optimizationConfig(config);
    const slugs = [...new Set([...Object.keys(config.weights), ...Object.keys(REFERENCE)])];
    const firstDates = slugs.map((s) => this.assets.get(s)?.firstDate).filter(Boolean);
    const start = [config.start, ...firstDates].sort().at(-1);
    if (start >= config.end) throw Error('These asset classes have no common date range.');
    const key = JSON.stringify([slugs.slice().sort(), start, config.end]);
    if (!this.cache.has(key)) this.cache.set(key, Promise.all(slugs.map(async (slug) => {
      const payload = await this.api.series({ assets: [slug], start, end: config.end, amount: 10000,
        includeRentalCashflow: false, includeTer: false, includeTaxes: false, inflation: 'none' }, undefined, options);
      const s = payload.series.find((s) => s.slug === slug); if (!s) throw Error(payload.warnings?.[0]?.message || `No data for ${ASSETS[slug][0]}.`); return s;
    })).catch((error) => { this.cache.delete(key); throw error; }));
    const series = await this.cache.get(key); const market = prepareMarket(series, { ...config, start });
    market.adjustments = series.filter((s) => s.dividendAdjustment?.applied).map((s) => s.displayName);
    return market;
  }
  async run(model) {
    this.readForm(); this.invalidate(); if(this.config.volatilityLimitPct != null && !Number.isFinite(this.config.volatilityLimitPct)){this.message('Enter a valid volatility limit in % per year.','error');return;} const revision = this.revision; let config = copy(this.config);
    if (model) {try {config=optimizationConfig(config);} catch(error) {this.message(error.message,'error');return;}}
    if (!this.saved.some((p) => p.id === config.id) && this.saved.length >= 5) { this.message('All five portfolio slots are full. Load a saved portfolio to edit it or free a slot first.', 'error'); return; }
    const errors = validateConfig(config, { allocation: !model }); if (errors.length) { this.message(errors.join(' '), 'error'); return; }
    this.setBusy(true); this.message(model ? 'Searching for feasible historical allocations …' : 'Loading price series and checking enabled rules …');
    try {
      await this.refreshVolatilityReference(model);
      const market = await this.marketFor(config, model); await pause();
      const found = await this.compute(model ? 'optimize' : 'evaluate', market, config);
      if (revision !== this.revision) return;
      if (!found.result.valid) throw Error(found.result.checks.filter((c) => !c.passed).map((c) => `${c.label}: ${pct(c.value)} / limit ${pct(c.limit)}${c.available ? '' : ' (insufficient data)'}`).join('. '));
      this.config = copy(found.config); this.activeKey = this.config.id || `preview-${++this.sequence}`;
      this.config.dataSnapshot = { calculatedAt: new Date().toISOString(), method: 'v1.3m', firstDate: market.calendar?.dates[0] || market.dates[0],
        lastDate: market.calendar?.dates.at(-1) || market.dataEnd, riskFirstDate:market.dates[0], riskLastDate:market.dates.at(-1), frequency: market.frequency, sourceDates: market.calendar?.sourceDates || market.sourceDates, dividendModels: market.adjustments || [], optimization: found.optimization || null };
      this.dirtyIds.delete(this.config.id);
      // Unsaved drafts share one temporary result; five saved portfolios remain independent.
      for (const key of this.results.keys()) if (key.startsWith('preview-')) this.results.delete(key);
      this.results.set(this.activeKey, { config: copy(this.config), market, result: found.result, model,
        diagnostics: diagnostics(market, this.config, found.result) });
      this.renderForm(); this.renderLinked(); this.onChange();
      if (model) {
        const summary = this.root.querySelector('#pf-optimization-result'); summary.hidden = false;
        summary.innerHTML = `<h4>Allocation found</h4><p>${esc(OBJECTIVES[this.config.objective ?? 'cagr'][0])}: <strong>${number(found.optimization.value,4)}${['sharpe','calmar'].includes(this.config.objective) ? '' : ' %'}</strong></p><ul>${Object.entries(this.config.weights).map(([slug,w]) => `<li><span>${esc(ASSETS[slug][0])}</span><strong title="${esc(w)} %">${pct(w)}</strong></li>`).join('')}</ul>`;
      }
      this.root.querySelector('#pf-draft-state').textContent = 'Validated. Shown in individual charts and available under Settings in the overall comparison.';
      for (const a of ['save', 'duplicate']) this.root.querySelector(`[data-pf-action="${a}"]`).disabled = false;
      this.message(model ? `Best allocation found: ${OBJECTIVES[this.config.objective ?? 'cagr'][0]}. Objective value ${number(found.optimization.value,4)}${['sharpe','calmar'].includes(this.config.objective) ? '' : ' %'}. ${found.optimization.feasible} feasible out of ${found.optimization.candidates} candidates checked. All enabled rules satisfied. Historical optimisation, not a forecast.` : 'Portfolio finished. All enabled rules are satisfied over the selected historical period.', 'success');
    } catch (error) { if (revision === this.revision) this.message(error.message, 'error'); }
    finally { if(revision === this.revision) this.setBusy(false); }
  }
  setBusy(busy) {
    this.root.setAttribute('aria-busy', String(busy));
    this.root.querySelectorAll('button[type="submit"], [data-pf-action="optimize"]').forEach((b) => { b.disabled = busy; });
  }
  compute(type, market, config) {
    const settings=this.getTaxSettings();
    const taxSettings=Object.fromEntries(Object.keys(TAX_DEFAULTS).map(key=>[key,settings[key]??TAX_DEFAULTS[key]]));
    if (!globalThis.Worker) return Promise.resolve(type === 'optimize' ? optimize(market, config) : { config, result: evaluate(market, config) });
    return new Promise((resolve, reject) => {
      const worker = new Worker(new URL('./portfolio-worker.js?v=20261028-v13y', import.meta.url), { type: 'module' });
      const done = (error, result) => { worker.terminate(); this.jobs.delete(cancel); if (error) reject(error); else resolve(result); };
      const cancel = () => done(new Error('Calculation cancelled after a draft change.'));
      this.jobs.add(cancel);
      worker.onmessage = ({ data }) => done(data.success ? null : new Error(data.message), data.result);
      worker.onerror = () => done(new Error('Portfolio calculation could not start. Reload the page.'));
      worker.postMessage({ type, market, config, taxSettings });
    });
  }
  async restore(options={}) {
    const failed = []; const revision = this.revision;
    for (const saved of this.saved) {
      this.singleErrors.delete(saved.id);
      const c = {...saved,amount:10000,start:this.comparisonStart || saved.start,end:this.comparisonEnd || saved.end};
      try { const market = await this.marketFor(c, undefined, options); if(revision!==this.revision)return false;
        await nextPaint();
        const found=await this.compute('restore',market,c);const result=found.result;
        if(revision!==this.revision)return false;
        if (!result.valid) throw Error('Rules are not satisfied with the current data.');
        if (!this.dirtyIds.has(c.id)) {
          const entry={config:copy(c),market,result,diagnostics:found.diagnostics||diagnostics(market,c,result)};
          this.results.set(c.id,entry);
          if(found.taxView)this.taxViewCache.set(entry,{key:found.taxKey,entry:{...entry,...found.taxView}});
          if(found.taxError){this.taxViewCache.set(entry,{key:found.taxKey,error:found.taxError});failed.push(c.name+': '+found.taxError);}
          this.renderLinked();this.onChange();await nextPaint();
        }
      } catch (error) {if(revision!==this.revision)return; this.singleErrors.set(c.id,error.message);failed.push(`${c.name}: ${error.message}`);}
    }
    this.renderLinked(); this.onChange();
    if (failed.length) this.message(`Saved configurations are preserved. No results for: ${failed.join(' / ')}`, 'error');
    return failed.length===0;
  }
  comparisonOptions() {
    const options = [...this.results.entries()].map(([key,e],i) => ({key,name:e.config.name,color:colors[i % colors.length]}));
    if (options.length) options.push({key:'reference',name:"60/40 reference",color:'#94a3b8'});
    return options;
  }
  selectAllComparisons(selected) {
    this.comparisonSelection = new Set(selected ? this.comparisonOptions().map((option) => option.key) : []);
    this.renderComparisonOptions();
  }
  renderComparisonOptions() {
    const output = document.querySelector('#comparison-portfolios'); if (!output) return;
    const focused = output.contains(document.activeElement) ? document.activeElement.dataset.portfolioOption : null;
    const options = this.comparisonOptions();
    const html = options.map(({key,name,color}) => '<label><input type="checkbox" data-portfolio-option="'+esc(key)+'" '+(this.comparisonSelection.has(key)?'checked':'')+'><span><i class="asset-backtester__line-key" style="background:'+color+'"></i>'+esc(name)+'</span></label>').join('') || '<small>Finish or load a portfolio to select it here.</small>';
    if (output.innerHTML !== html) {
      output.innerHTML = html;
      if (focused) [...output.querySelectorAll('[data-portfolio-option]')].find((input) => input.dataset.portfolioOption === focused)?.focus();
    }
  }
  taxEntries() {
    const settings=this.getTaxSettings(),key=portfolioTaxKey(settings);this.taxErrors.clear();
    if(!settings.taxesEnabled)return [...this.results.entries()];
    const entries=[];
    for(const [id,entry] of this.results){
      try {
        let cached=this.taxViewCache.get(entry);
        if(cached?.key===key && cached.error)throw Error(cached.error);
        if(cached?.key!==key){
          const result=simulatePortfolioTaxes(entry.market,entry.config,settings,entry.result);
          const referenceConfig={...entry.config,weights:REFERENCE,assetSettings:{},leverage:1,terPct:0,financingPct:0,rebalancing:'quarterly',rules:{}};
          if(entry.result.reference)result.reference=simulatePortfolioTaxes(entry.market,referenceConfig,settings,entry.result.reference);
          cached={key,entry:{...entry,result,diagnostics:diagnostics(entry.market,entry.config,result)}};this.taxViewCache.set(entry,cached);
        }
        entries.push([id,cached.entry]);
      }catch(error){this.taxErrors.set(id,error.message);}
    }
    return entries;
  }
  comparisonSeries(range) {
    return buildPortfolioComparison(this.taxEntries(), range, 10000, 'en').filter((series) => this.comparisonSelection.has(series.slug.slice('portfolio-'.length)));
  }
  summaryPeriod(entry,period){
    const end=this.getSummaryEnd();
    const result=end?{...entry.result,points:entry.result.points.filter(p=>p.date<=end)}:entry.result;
    return result.points.length?portfolioPeriod(result,entry.market.frequency,period):null;
  }
  sortLinkedTables(){
    sortMetricRows(document.querySelector('#metrics-table tbody'),this.getRanking());
    sortMetricRows(document.querySelector('#risk-metrics-table tbody'),this.getRanking());
    const summary=this.getSummarySort() || {period:'1Y',direction:'desc'};sortSummaryTable(document.querySelector('#summary-table tbody'),summary.period,summary.direction);
  }
  renderLinked() {
    this.singleViews ??= new PortfolioSingles(document.querySelector('#portfolio-single-charts'),'en');
    const taxEntries=this.taxEntries(),errors=new Map([...this.singleErrors,...this.taxErrors]);
    this.singleViews.render(portfolioSingleRecords(this.saved,new Map(taxEntries),errors));
    let status=this.root.querySelector('#pf-tax-status');if(!status){status=document.createElement('p');status.id='pf-tax-status';status.className='portfolio-lab__hint';this.root.querySelector('h2').after(status);}
    status.hidden=!this.getTaxSettings().taxesEnabled;status.textContent=this.taxErrors.size?[...this.taxErrors.values()].join(' / '):"After-tax portfolio view active: FIFO, shared annual allowance and losses, actual rebalancing tax and hypothetical liquidation on each chart date. Allocation rules and optimisation are checked before personal taxes.";
    this.renderComparisonOptions();
    document.querySelectorAll('[data-portfolio-row]').forEach((el) => el.remove());
    const entries = taxEntries.filter(([key]) => this.comparisonSelection.has(key)); this.riskOutput.hidden = !entries.length;
    if (!entries.length) {this.sortLinkedTables(); this.chart?.destroy(); this.drawdownChart?.destroy(); this.chart = null; this.drawdownChart = null; this.riskOutput.replaceChildren(); return; }
    const summaryEnd=this.getSummaryEnd();
    const periods = ['1W', '1M', '6M', '1Y', '3Y', '5Y', '10Y'];
    entries.forEach(([key, e], index) => {
      const name = esc(e.config.name); const m = e.result.metrics; const frequency = labels[e.market.frequency];
      const row = document.createElement('tr');row.dataset.summaryRank=JSON.stringify({}); row.dataset.portfolioRow = key; row.className = 'portfolio-lab__table-row';
      row.innerHTML = `<th scope="row"><span style="color:${colors[index % colors.length]}">◈</span> ${name}<small>Portfolio / EUR / through ${e.result.metrics.lastRawObservationDate}</small></th>`;
      for (const period of periods) {
        const comparison = this.summaryPeriod(this.results.get(key)||e,period);
        const value = comparison?.factor ?? null;
        const returns=JSON.parse(row.dataset.summaryRank);returns[period]=value===null?null:(value-1)*100;row.dataset.summaryRank=JSON.stringify(returns);
        const cell = row.insertCell(); cell.title = comparison ? `${comparison.start} to ${comparison.end}; actual portfolio performance in EUR` : 'Insufficient common history on this time grid.';
        cell.innerHTML = value !== null ? `<span class="asset-backtester__period-return ${(value >= 1) ? 'is-positive' : 'is-negative'}">${pct((value - 1) * 100)}</span><span class="asset-backtester__period-value">${eur(value * 10000)} from EUR 10,000</span>` : '<span class="asset-backtester__period-return">–</span><span class="asset-backtester__period-value">Not available</span>';
      }
      document.querySelector('#summary-table tbody').append(row);
      const metricRow = document.createElement('tr'); metricRow.dataset.rankMetrics=JSON.stringify(m);metricRow.dataset.portfolioRow = key; metricRow.className = 'portfolio-lab__table-row';
      metricRow.innerHTML = `<th scope="row">◈ ${name}<small>Portfolio / EUR</small></th><td><strong>${eur(m.finalValue)}</strong></td><td>${eur(m.absoluteReturn)}</td><td>${pct(m.totalReturnPct)}</td><td>${pct(m.cagrPct)}</td>${document.querySelector('[data-tax-column]:not([hidden])') ? `<td>${eur(m.taxAmount??0)}</td>` : ''}<td class="is-negative">${pct(m.maxDrawdownPct)}</td><td>${m.lastRawObservationDate || e.market.dataEnd}</td>`;
      document.querySelector('#metrics-table tbody').append(metricRow);
      const risk = document.createElement('tr'); risk.dataset.rankMetrics=JSON.stringify(m);risk.dataset.portfolioRow = key; risk.className = 'portfolio-lab__table-row';
      risk.innerHTML = `<th scope="row">◈ ${name}<small>${frequency}returns / EUR</small></th><td>${pct(m.annualizedVolatilityPct)}</td><td>${number(m.sharpeRatio)}</td><td>${number(m.calmarRatio)}</td><td>${pct(m.bestDayPct)}<small>${m.bestDayDate}</small></td><td>${pct(m.worstDayPct)}<small>${m.worstDayDate}</small></td><td>${pct(m.positiveDaysPct)}</td><td>${m.observedReturnCount}<small>${frequency}changes</small></td>`;
      document.querySelector('#risk-metrics-table tbody').append(risk);
    });
    this.sortLinkedTables();
    this.chart?.destroy(); this.drawdownChart?.destroy(); this.chart = null; this.drawdownChart = null;
    this.riskOutput.innerHTML = `<div class="portfolio-lab__result-heading"><div><span class="portfolio-lab__step">PORTFOLIO TESTS</span><h3>Risk and distribution</h3></div><span>Nominal / EUR</span></div><div class="portfolio-lab__chart"><canvas id="pf-drawdown-chart" role="img" aria-label="Drawdown paths of finished portfolios"></canvas></div><div class="portfolio-lab__result-grid">${entries.map(([key, e], i) => this.resultCard(key, e, i)).join('')}</div>`;
    if (window.Chart) {
      const dates = [...new Set(entries.flatMap(([, e]) => e.result.points.map((p) => p.date)))].sort();
      this.drawdownChart = new window.Chart(this.riskOutput.querySelector('canvas'), { type: 'line', data: { labels: dates,
        datasets: entries.map(([, e], i) => { const byDate = new Map(e.result.points.map((p) => [p.date, p.drawdownPct]));
          return { label: e.config.name, data: dates.map((d) => byDate.get(d) ?? null), borderColor: colors[i % colors.length], pointRadius: 0, borderWidth: 2, spanGaps: true }; }) },
        options: { responsive: true, maintainAspectRatio: false, animation: false, interaction: { mode: 'index', intersect: false },
          scales: { x: { ticks: { maxTicksLimit: 7 }, grid: { display: false } }, y: { max: 0, ticks: { callback: (v) => pct(v) } } },
          plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${pct(c.raw)}` } } } } });

    }
  }
  resultCard(key, e, i) {
    const d = e.diagnostics; const lastRolling = d.rolling.at(-1); const optimization = e.config.dataSnapshot?.optimization;
    return `<article class="portfolio-lab__result-card"><div class="portfolio-lab__result-title"><h4 style="color:${colors[i % colors.length]}">${esc(e.config.name)}</h4><span>${e.result.taxAdjustment?.applied?'Rules before tax checked':'Validated'}</span></div>
      ${optimization ? `<p class="portfolio-lab__objective-result">Optimisation objective: ${esc(OBJECTIVES[e.config.objective ?? 'cagr'][0])}. Current objective value: ${number(objectiveValue(e.result,e.config.objective ?? 'cagr'),4)}${['sharpe','calmar'].includes(e.config.objective) ? '' : ' %'}</p>` : ''}
      <p>${Object.keys(e.config.weights).map((slug) => `${esc(ASSETS[slug][0])}: ${number(assetLeverage(e.config,slug))}× / TER ${pct(assetTer(e.config,slug))}`).join('; ')}. Additional portfolio TER ${pct(e.config.terPct ?? 0)} p.a.; Financing rate ${pct(e.config.financingPct ?? 0)} p.a.</p>
      <p>${e.result.points[0].date} to ${e.result.points.at(-1).date} / daily valuation</p><p class="portfolio-lab__hint">Risk grid: ${labels[e.market.frequency]} returns / ${e.result.metrics.observedReturnCount} observations / Latest source price: ${e.result.metrics.lastRawObservationDate}</p>
      <dl><div><dt>Expected Shortfall 95 %</dt><dd>${pct(e.result.metrics.expectedShortfallPct)}</dd></div><div><dt>Tracking Error p.a.</dt><dd>${pct(e.result.trackingErrorPct)}</dd></div></dl>
      <details><summary>Rules and risk contributions</summary><ul>${e.result.checks.map((c) => `<li>${esc(c.label)}: ${c.enabled === false ? 'Disabled' : `${pct(c.value)} ≤ ${pct(c.limit)}`}</li>`).join('')}${Object.entries(e.result.riskContributions).map(([slug, v]) => `<li>${esc(ASSETS[slug][0])}: ${pct(v)} risk contribution</li>`).join('')}</ul><p>Realised variance contributions including weight drift. Contributions can be negative.</p></details>
      <details><summary>Stress tests and rolling risk</summary><ul>${d.stresses.map((s) => `<li>${esc(s.name)}: ${s.available ? `${pct(s.returnPct)} / 60/40: ${pct(s.referencePct)}` : 'No complete common history'}</li>`).join('')}</ul><p>${lastRolling ? `Rolling window of about ${e.config.rollingMonths} months. Latest volatility: ${pct(lastRolling.volatility)} / 60/40 ${pct(lastRolling.reference)}. ${d.rolling.filter((r) => r.volatility > r.reference + 1e-6).length} of ${d.rolling.length} windows above the reference.` : 'Insufficient data for rolling analysis.'}</p>
      <p>${d.rebalances.length} rebalances / maximum ${pct(Math.max(0, ...d.rebalances.map((r) => r.turnoverPct)))} Turnover / ${d.breaches.length} periods with market-driven allocation breaches before rebalancing.</p></details>
      <details><summary>Historical weights and rebalances</summary><div class="portfolio-lab__detail-scroll"><table><thead><tr><th>Date</th>${Object.keys(e.config.weights).map((s) => `<th>${esc(ASSETS[s][0])}</th>`).join('')}<th>Turnover</th></tr></thead><tbody>${e.result.points.filter((p, j) => j === e.result.points.length - 1 || d.rebalances.some((r) => r.date === p.date)).map((p) => `<tr><td>${p.date}</td>${Object.keys(e.config.weights).map((s) => `<td>${pct(p.weights?.[s])}</td>`).join('')}<td>${pct(d.rebalances.find((r) => r.date === p.date)?.turnoverPct ?? 0)}</td></tr>`).join('')}</tbody></table></div><p>Weights after market movements and before each rebalance. Recovery periods appear in the drawdown chart until it returns to 0%.</p></details>
      <details><summary>${labels[e.market.frequency]}distribution</summary><div class="portfolio-lab__histogram" role="img" aria-label="Frequency of period returns">${d.histogram.map((n, j) => `<div><span style="height:${Math.max(2, n / Math.max(...d.histogram) * 75)}px;background:${j < 3 ? '#f87171' : '#6ee7b7'}"></span><b>${n}</b><small>${['< −3', '−3…−1', '−1…0', '0…1', '1…3', '3…5', '≥ 5'][j]} %</small></div>`).join('')}</div></details>
      <p class="portfolio-lab__hint">${e.market.adjustments?.length ? `Distribution models supplied by the API: ${esc(e.market.adjustments.join(', '))}. ` : ''}${e.model ? 'Modelled over the full selected period. ' : ''}Risk limits are historical checks.</p>
</article>`;
  }
}
