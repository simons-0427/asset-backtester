// All returns and weights are fractions internally. Public metrics use percent.
export const PORTFOLIO_VERSION = 1;
export const MAX_PORTFOLIOS = 5;
export const ASSETS = Object.freeze({
  msci_world: ['MSCI World', false], sp500: ['S&P 500', false],
  dax: ['DAX', false], eurostoxx50: ['EURO STOXX 50', false],
  global_ig_bonds: ['Globale IG-Anleihen (EUR-gesichert)', false],
  global_ig_bonds_unhedged: ['Globale IG-Anleihen (ungesichert)', false],
  euro_money_market: ['Euro-Geldmarkt', false], us_money_market: ['US-Geldmarkt (EUR)', false],
  gold: ['Gold', true], bitcoin: ['Bitcoin', true], commodities: ['Breite Rohstoffe', true],
  de_real_estate: ['Immobilien DE', true], us_real_estate: ['Immobilien USA', true],
});
export const REFERENCE = Object.freeze({ msci_world: 60, global_ig_bonds: 40 });
export const OPTIONAL_RULES = Object.freeze({
  drawdown: ['Maximaler Drawdown', 'Verlust seit dem Höchststand', 0, 100],
  shortfall: ['Expected Shortfall (95 %)', 'Durchschnittlicher Verlust der schlechtesten 5 % der Perioden', 0, 100],
  contribution: ['Risikobeitrag je Anlageklasse', 'Maximaler Anteil an der Portfoliovarianz', 1, 100],
  tracking: ['Tracking Error p.a.', 'Schwankung der Renditedifferenz zur 60/40-Referenz', 0, 100],
  turnover: ['Turnover je Umschichtung', 'Halbe Summe der absoluten Gewichtsänderungen', 0, 100],
});
export const OBJECTIVES = Object.freeze({
  cagr: ['Höchste Rendite (CAGR)', 'Maximiert die historische jährliche Wachstumsrate nach Hebel und Kosten.', 'cagrPct', 1],
  sharpe: ['Höchste Sharpe-Ratio', 'Maximiert Rendite im Verhältnis zur Volatilität. Risikofreier Zins: 0 %.', 'sharpeRatio', 1],
  calmar: ['Höchste Calmar-Ratio', 'Maximiert CAGR im Verhältnis zum Betrag des maximalen Drawdowns.', 'calmarRatio', 1],
  volatility: ['Geringste Volatilität', 'Minimiert die annualisierte Schwankung der Portfoliorenditen.', 'annualizedVolatilityPct', -1],
  drawdown: ['Geringster maximaler Drawdown', 'Minimiert den größten historischen Verlust vom Höchststand.', 'maxDrawdownPct', -1],
  shortfall: ['Geringster Expected Shortfall', 'Minimiert den mittleren Verlust der schlechtesten 5 % der Perioden. Mindestens 40 Beobachtungen.', 'expectedShortfallPct', -1],
});
export function objectiveValue(result, objective = 'cagr') {
  const spec = OBJECTIVES[objective]; if (!spec) return null;
  const value = result.metrics[spec[2]];
  return Number.isFinite(value) ? (objective === 'drawdown' ? Math.abs(value) : value) : null;
}
export function optimizationConfig(config) {
  const pool = config.optimizationAssets ?? Object.keys(config.weights || {});
  if (!Array.isArray(pool) || !pool.length || new Set(pool).size !== pool.length || pool.some((s) => !Object.hasOwn(ASSETS,s))) throw new Error('Mindestens eine gültige Anlageklasse für die Optimierung auswählen.');
  return {...config, optimizationAssets:[...pool], weights:Object.fromEntries(pool.map((s) => [s,Object.hasOwn(config.weights || {},s) ? config.weights[s] : 0]))};
}
export const limitEnabled = (config, key) => config.enabledLimits?.[key] !== false;
export const assetLeverage = (config, slug) => config.assetSettings?.[slug]?.leverage ?? config.leverage ?? 1;
export const assetTer = (config, slug) => config.assetSettings?.[slug]?.terPct ?? 0;
const EPS = 1e-8;
const clone = (value) => JSON.parse(JSON.stringify(value));
export function defaults(overrides = {}) {
  return { version: PORTFOLIO_VERSION, id: '', name: 'Mein Portfolio', description: '',
    weights: { ...REFERENCE }, amount: 10000, start: '2018-01-01',
    end: new Date().toISOString().slice(0, 10), frequency: 'auto', rebalancing: 'none',
    volatilityLimitPct: null, objective: 'cagr', optimizationAssets: null, enabledLimits: { alternatives: true, position: true, volatility: true }, assetSettings: {}, leverage: 1, terPct: 0, financingPct: 0, threshold: 5, rollingMonths: 12, limits: { alternatives: 30, position: 10, volatility: 100 }, rules: {}, dataSnapshot: null, ...clone(overrides) };
}
export function standardPortfolios(end = new Date().toISOString().slice(0, 10)) {
  return [
    defaults({ name: 'Weltportfolio 60/40', description: '60 % MSCI World und 40 % EUR-gesicherte globale IG-Anleihen. Buy & Hold.', end }),
    defaults({ name: 'Defensiv 40/40/20', description: '40 % MSCI World, 40 % EUR-gesicherte globale IG-Anleihen, 20 % Euro-Geldmarkt.', weights: { msci_world: 40, global_ig_bonds: 40, euro_money_market: 20 }, end }),
  ];
}
function validDate(value) {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
    && Number.isFinite(Date.parse(value)) && new Date(value).toISOString().slice(0, 10) === value;
}
export function validateConfig(input, { allocation = true } = {}) {
  const errors = [];
  if (!input || typeof input !== 'object' || Array.isArray(input)) return ['Ungültige Portfoliodatei.'];
  if (!Object.hasOwn(OBJECTIVES,input.objective ?? 'cagr')) errors.push('Unbekanntes Optimierungsziel.');
  if (input.optimizationAssets != null && (!Array.isArray(input.optimizationAssets) || input.optimizationAssets.length > Object.keys(ASSETS).length
    || new Set(input.optimizationAssets).size !== input.optimizationAssets.length || input.optimizationAssets.some((s) => !Object.hasOwn(ASSETS,s)))) errors.push('Ungültige Anlageklassen für die Optimierung.');
  if (input.version !== PORTFOLIO_VERSION) errors.push('Nicht unterstützte Formatversion.');
  if (typeof input.name !== 'string' || !input.name.trim() || input.name.length > 80) errors.push('Name: 1 bis 80 Zeichen.');
  if (typeof input.description !== 'string' || input.description.length > 500) errors.push('Beschreibung: maximal 500 Zeichen.');
  if (!validDate(input.start) || !validDate(input.end) || input.start >= input.end
    || input.end > new Date().toISOString().slice(0, 10)) errors.push('Gültigen vergangenen Zeitraum angeben.');
  if (!Number.isFinite(input.amount) || input.amount < 100 || input.amount > 10000000) errors.push('Startkapital: 100 bis 10.000.000 EUR.');
  if (!['auto', 'daily', 'monthly', 'quarterly'].includes(input.frequency)) errors.push('Unbekannte Datenfrequenz.');
  if (!['none', 'monthly', 'quarterly', 'yearly', 'threshold'].includes(input.rebalancing)) errors.push('Unbekanntes Rebalancing.');
  if (!Number.isFinite(input.threshold) || input.threshold <= 0 || input.threshold > 100) errors.push('Gewichtsband: größer 0 bis 100 Prozentpunkte.');
  if (input.volatilityLimitPct != null && (!Number.isFinite(input.volatilityLimitPct) || input.volatilityLimitPct < 0 || input.volatilityLimitPct > 1000)) errors.push("Volatilitätsgrenze: 0 bis 1.000 % pro Jahr.");
  const rollingMonths = input.rollingMonths ?? 12;
  if (!Number.isInteger(rollingMonths) || rollingMonths < 12 || rollingMonths > 120) errors.push('Rollierendes Risikofenster: 12 bis 120 Monate.');
  const limits = input.limits || { alternatives: 30, position: 10, volatility: 100 };
  for (const [key, hardLimit] of Object.entries({ alternatives: 100, position: 100, volatility: 100 })) {
    if (!Number.isFinite(limits[key]) || limits[key] < 0 || limits[key] > hardLimit) errors.push(`Ungültige Pflichtgrenze: ${key}.`);
  }
  if (input.enabledLimits !== undefined && (!input.enabledLimits || typeof input.enabledLimits !== 'object' || Array.isArray(input.enabledLimits)
    || Object.entries(input.enabledLimits).some(([key,value]) => !['alternatives','position','volatility'].includes(key) || typeof value !== 'boolean'))) errors.push('Ungültige Regelschalter.');
  if (input.assetSettings !== undefined && (!input.assetSettings || typeof input.assetSettings !== 'object' || Array.isArray(input.assetSettings))) errors.push('Ungültige Positionseinstellungen.');
  else for (const [slug, setting] of Object.entries(input.assetSettings || {})) {
    if (!Object.hasOwn(ASSETS,slug) || !setting || typeof setting !== 'object' || Array.isArray(setting)
      || !Number.isFinite(setting.leverage) || setting.leverage < 1 || setting.leverage > 3
      || !Number.isFinite(setting.terPct) || setting.terPct < 0 || setting.terPct > 5) errors.push('Ungültiger Hebel oder TER je Anlageklasse: ' + slug + '.');
  }
  const entries = input.weights && typeof input.weights === 'object' && !Array.isArray(input.weights) ? Object.entries(input.weights) : [];
  if (!entries.length || entries.length > Object.keys(ASSETS).length) errors.push('Mindestens eine Anlageklasse auswählen.');
  const leverage = input.leverage === undefined ? 1 : input.leverage;
  if (!Number.isFinite(leverage) || leverage < 1 || leverage > 3) errors.push('Hebel: 1 bis 3.');
  if (input.terPct !== undefined && (!Number.isFinite(input.terPct) || input.terPct < 0 || input.terPct > 5)) errors.push('Portfolio-TER: 0 bis 5 % pro Jahr.');
  if (input.financingPct !== undefined && (!Number.isFinite(input.financingPct) || input.financingPct < 0 || input.financingPct > 20)) errors.push('Finanzierungszins: 0 bis 20 % pro Jahr.');
  let total = 0; let alternatives = 0;
  for (const [slug, weight] of entries) {
    if (!Object.hasOwn(ASSETS, slug)) { errors.push(`Unbekannte Anlageklasse: ${String(slug).slice(0, 80)}`); continue; }
    if (!Number.isFinite(weight) || weight < 0 || (allocation && weight === 0) || weight > 100) { errors.push(`${ASSETS[slug][0]}: Gewicht muss ${allocation ? 'größer ' : ''}0 bis 100 % sein.`); continue; }
    total += weight;
    if (ASSETS[slug][1]) {
      alternatives += weight * assetLeverage(input,slug);
      if (allocation && limitEnabled(input,'position') && weight * assetLeverage(input,slug) > limits.position + EPS) errors.push(`${ASSETS[slug][0]} überschreitet die Pflichtgrenze von ${limits.position} %.`);
    }
  }
  if (allocation && Math.abs(total - 100) > 0.00001) errors.push(`Gewichtssumme muss 100 % sein (aktuell ${total.toFixed(2)} %).`);
  if (allocation && limitEnabled(input,'alternatives') && alternatives > limits.alternatives + EPS) errors.push(`Alternative Anlagen überschreiten zusammen ${limits.alternatives} %.`);
  if (!input.rules || typeof input.rules !== 'object' || Array.isArray(input.rules)) errors.push('Ungültige Regeln.');
  else for (const [key, rule] of Object.entries(input.rules)) {
    if (!Object.hasOwn(OPTIONAL_RULES, key)) { errors.push(`Unbekannte Regel: ${String(key).slice(0, 80)}`); continue; }
    const [, , min, max] = OPTIONAL_RULES[key];
    if (!rule || !['absolute', 'reference'].includes(rule.mode) || !Number.isFinite(rule.value)
      || rule.value < min || rule.value > max
      || (['contribution', 'turnover', 'tracking'].includes(key) && rule.mode !== 'absolute')) errors.push(`Ungültiger Grenzwert: ${OPTIONAL_RULES[key][0]}.`);
  }
  return errors;
}
export function cleanConfig(input) {
  const errors = validateConfig(input);
  if (errors.length) throw new Error(errors.join(' '));
  const c = defaults();
  for (const key of Object.keys(c)) if (Object.hasOwn(input, key)) c[key] = clone(input[key]);
  c.assetSettings = Object.fromEntries(([...new Set([...Object.keys(c.weights),...(c.optimizationAssets || [])])]).map((slug) => [slug, {leverage:assetLeverage(c,slug),terPct:assetTer(c,slug)}]));
  c.leverage = 1;
  c.amount = 10000;
  c.enabledLimits = {alternatives:true,position:true,volatility:true,...c.enabledLimits};
  c.name = c.name.trim(); c.id = /^[a-zA-Z0-9_-]{1,80}$/.test(c.id) ? c.id : '';
  return c;
}
const mean = (values) => values.reduce((a, b) => a + b, 0) / values.length;
export function covariance(a, b) {
  if (a.length !== b.length || a.length < 2) return null;
  const am = mean(a); const bm = mean(b);
  return a.reduce((sum, value, i) => sum + (value - am) * (b[i] - bm), 0) / (a.length - 1);
}
export function portfolioPeriod(result, frequency, label) {
  const match = /^(\d+)(W|M|Y)$/.exec(label);
  if (!match || !result.points?.length) return null;
  const length = Number(match[1]); const unit = match[2]; const end = result.points.at(-1);
  let first;
  if (frequency !== 'daily' && !result.calendarValuation) {
    if (unit === 'W') return null;
    const months = length * (unit === 'Y' ? 12 : 1); const step = frequency === 'quarterly' ? 3 : 1;
    if (months % step !== 0) return null;
    first = result.points[result.points.length - 1 - months / step];
  } else {
    const target = new Date(`${end.date}T00:00:00Z`);
    if (unit === 'W') target.setUTCDate(target.getUTCDate() - length * 7);
    else {
      const day = target.getUTCDate(); target.setUTCDate(1);
      target.setUTCMonth(target.getUTCMonth() - length * (unit === 'Y' ? 12 : 1));
      const lastDay = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
      target.setUTCDate(Math.min(day, lastDay));
    }
    const cutoff = target.toISOString().slice(0, 10);
    first = result.points.findLast((p) => p.date <= cutoff);
  }
  return first ? { factor: end.portfolioValue / first.portfolioValue, start: first.date, end: end.date } : null;
}
function periodKey(date, frequency) {
  if (frequency === 'daily') return date;
  const month = Number(date.slice(5, 7));
  return frequency === 'quarterly' ? `${date.slice(0, 4)}-Q${Math.ceil(month / 3)}` : date.slice(0, 7);
}
function periodNumber(key, frequency) {
  return Number(key.slice(0, 4)) * (frequency === 'quarterly' ? 4 : 12)
    + Number(frequency === 'quarterly' ? key.slice(-1) : key.slice(5, 7));
}
function periodEnd(key, frequency) {
  if (frequency === 'daily') return key;
  const month = frequency === 'quarterly' ? Number(key.slice(-1)) * 3 : Number(key.slice(5, 7));
  return new Date(Date.UTC(Number(key.slice(0, 4)), month, 0)).toISOString().slice(0, 10);
}
export function calendarMarket(seriesList, config) {
  const slugs=[...new Set([...Object.keys(config.weights),...Object.keys(REFERENCE)])];
  const rows=Object.fromEntries(slugs.map(slug=>{
    const series=seriesList.find(s=>s.slug===slug);
    const valid=(series?.points || []).filter(p=>(p.lastRawObservationDate==null || p.lastRawObservationDate<=p.date) && p.date<=config.end && Number.isFinite(Number(p.portfolioValue)) && Number(p.portfolioValue)>0).sort((a,b)=>a.date.localeCompare(b.date));
    const anchor=valid.findLast(p=>p.date<=config.start) || valid[0];
    if(!anchor)throw Error("Kein Anfangskurs für "+slug);
    const observed=valid.filter(p=>p.date>anchor.date && !p.isCarriedForward && p.lastRawObservationDate===p.date);
    return [slug,[anchor,...observed]];
  }));
  const start=[config.start,...slugs.map(s=>rows[s][0].date)].sort().at(-1);
  const dates=[];for(let time=Date.parse(start);time<=Date.parse(config.end);time+=86400000)dates.push(new Date(time).toISOString().slice(0,10));
  if(dates.length<2)throw Error("Kein gemeinsamer Bewertungszeitraum.");
  const prices={},sourceDates={},observationDates={};
  for(const slug of slugs){let index=0;observationDates[slug]=[];prices[slug]=dates.map(date=>{while(index+1<rows[slug].length && rows[slug][index+1].date<=date)index++;observationDates[slug].push(rows[slug][index].lastRawObservationDate || rows[slug][index].date);return Number(rows[slug][index].portfolioValue);});sourceDates[slug]=observationDates[slug].at(-1); }
  const returns=Object.fromEntries(slugs.map(s=>[s,prices[s].slice(1).map((v,i)=>v/prices[s][i]-1)]));
  return {dates,prices,returns,sourceDates,observationDates,annualization:365.2425};
}
export function prepareMarket(seriesList, config) {
  const required = [...new Set([...Object.keys(config.weights), ...Object.keys(REFERENCE)])];
  const bySlug = new Map(seriesList.map((series) => [series.slug, series]));
  const observations = {};
  for (const slug of required) {
    const series = bySlug.get(slug);
    if (!series) throw new Error(`Keine Daten für ${ASSETS[slug]?.[0] || slug}.`);
    observations[slug] = (series.points || []).filter((p) => p.date >= config.start && p.date <= config.end
      && !p.isCarriedForward && p.lastRawObservationDate === p.date
      && Number.isFinite(Number(p.portfolioValue)) && Number(p.portfolioValue) > 0)
      .map((p) => ({ date: p.date, value: Number(p.portfolioValue) })).sort((a, b) => a.date.localeCompare(b.date));
    if (observations[slug].length < 2) throw new Error(`Zu wenig echte Beobachtungen für ${ASSETS[slug][0]}.`);
  }
  let frequency = config.frequency;
  if (frequency === 'auto') {
    if (required.some((slug) => /quarter/i.test(bySlug.get(slug).nativeFrequency))) frequency = 'quarterly';
    else {
      const dense = required.every((slug) => {
        const counts = new Map();
        observations[slug].forEach((p) => counts.set(p.date.slice(0, 7), (counts.get(p.date.slice(0, 7)) || 0) + 1));
        const middle = [...counts.values()].slice(1, -1);
        return middle.length >= 2 && middle.every((count) => count >= 12);
      });
      frequency = dense ? 'daily' : 'monthly';
    }
  }
  const calendar=calendarMarket(seriesList,config);
  const calendarIndex=new Map(calendar.dates.map((d,i)=>[d,i]));
  let dates;
  if(frequency==='daily') dates=[...new Set(required.flatMap(s=>observations[s].map(p=>p.date)))].filter(d=>calendarIndex.has(d)).sort();
  else {
    const ends=required.map(slug=>{
      const latest=observations[slug].at(-1),key=periodKey(latest.date,frequency),end=periodEnd(key,frequency);
      if(/month|quarter/i.test(bySlug.get(slug).nativeFrequency) || Date.parse(end)-Date.parse(latest.date)<=7*86400000)return end;
      const month=Number(latest.date.slice(5,7)),firstMonth=frequency==='quarterly'?Math.floor((month-1)/3)*3:month-1;
      return new Date(Date.UTC(Number(latest.date.slice(0,4)),firstMonth,0)).toISOString().slice(0,10);
    });
    const coverageEnd=[config.end,...ends].sort()[0];
    dates=calendar.dates.filter(d=>d<=coverageEnd && d===periodEnd(periodKey(d,frequency),frequency));
  }
  if(dates.length<13)throw Error("Mindestens 12 Renditebeobachtungen erforderlich. Zeitraum erweitern.");
  const prices=Object.fromEntries(required.map(slug=>[slug,dates.map(d=>calendar.prices[slug][calendarIndex.get(d)])]));
  const returns=Object.fromEntries(required.map(slug=>[slug,prices[slug].slice(1).map((p,i)=>p/prices[slug][i]-1)]));
  return {slugs:required,dates,prices,returns,calendar,frequency,annualization:{daily:required.includes('bitcoin')?365.2425:252,monthly:12,quarterly:4}[frequency],
    hasSlowSeries:required.some(slug=>/month|quarter/i.test(bySlug.get(slug).nativeFrequency)),
    sourceDates:Object.fromEntries(required.map(slug=>[slug,calendar.observationDates[slug][calendarIndex.get(dates.at(-1))]])),dataEnd:dates.at(-1)};
}
export function rebalanceDue(date, previous, c, actual, target) {
  if (c.rebalancing === 'none') return false;
  if (c.rebalancing === 'threshold') return actual.some((w, i) => Math.abs(w - target[i]) * 100 >= c.threshold);
  const f = { monthly: 'monthly', quarterly: 'quarterly', yearly: null }[c.rebalancing];
  return f ? periodKey(date, f) !== periodKey(previous, f) : date.slice(0, 4) !== previous.slice(0, 4);
}
export function sleeveReturnsFor(valuation,t,slugs,config) {
  const years=(Date.parse(valuation.dates[t+1])-Date.parse(valuation.dates[t]))/86400000/365.2425;
  const portfolioCost=(1-(config.terPct??0)/100)**years;
  return slugs.map((slug) => {
      const leverage = assetLeverage(config,slug);
      const gross = 1 + leverage * valuation.returns[slug][t];
      if (gross <= 0) throw new Error('Totalverlust im Hebelmodell am ' + valuation.dates[t + 1] + ' (' + ASSETS[slug][0] + '). Hebel reduzieren.');
      const costs = (1 - assetTer(config,slug) / 100) ** years * Math.exp(-(config.financingPct ?? 0) / 100 * (leverage - 1) * years) * portfolioCost;
      return leverage === 1 && costs === 1 ? valuation.returns[slug][t] : gross * costs - 1;
    });

}
export function simulate(market, weights, config) {
  const valuation=market.calendar || market;
  const limits = config.limits || { alternatives: 30, position: 10, volatility: 100 };
  const slugs = Object.keys(weights); const targets = slugs.map((slug) => weights[slug] / 100);
  let holdings = targets.map((w) => config.amount * w); let peak = config.amount;
  const observationAt=index=>valuation.observationDates?slugs.map(s=>valuation.observationDates[s][index]).sort().at(-1):valuation.dates[index];
  const points = [{ date: valuation.dates[0], portfolioValue: config.amount, returnPct: 0, drawdownPct: 0, lastRawObservationDate:observationAt(0), isCarriedForward:observationAt(0)<valuation.dates[0] }];
  const returns = []; const rebalances = []; const breaches = []; const realizedContributions = [];
  for (let t = 0; t < valuation.dates.length - 1; t++) {
    const before = holdings.reduce((a, b) => a + b, 0);
    const sleeveReturns = sleeveReturnsFor(valuation,t,slugs,config);
    realizedContributions.push(slugs.map((slug,i) => holdings[i] / before * sleeveReturns[i]));
    holdings = holdings.map((v,i) => v * (1 + sleeveReturns[i]));
    const value = holdings.reduce((a,b) => a+b,0);
    if (!(value > 0) || !Number.isFinite(value)) throw new Error('Portfoliowert kann nicht berechnet werden.');
    const r = value / before - 1; returns.push(r); peak = Math.max(peak, value);
    const actual = holdings.map((v) => v / value); const date = valuation.dates[t + 1];
    const alt = actual.reduce((sum, w, i) => sum + (ASSETS[slugs[i]][1] ? w * assetLeverage(config,slugs[i]) : 0), 0);
    if ((limitEnabled(config,'alternatives') && alt * 100 > limits.alternatives + EPS)
      || (limitEnabled(config,'position') && actual.some((w,i) => ASSETS[slugs[i]][1] && w * 100 * assetLeverage(config,slugs[i]) > limits.position + EPS))) breaches.push({date,alternativesPct:alt * 100});
    points.push({ date, portfolioValue: value, returnPct: (value / config.amount - 1) * 100,
      drawdownPct: (value / peak - 1) * 100, lastRawObservationDate:observationAt(t+1), isCarriedForward:observationAt(t+1)<date, weights: Object.fromEntries(slugs.map((slug, i) => [slug, actual[i] * 100])) });
    if (t < valuation.dates.length - 2 && rebalanceDue(date, valuation.dates[t], config, actual, targets)) {
      const requested = actual.reduce((sum, w, i) => sum + Math.abs(w - targets[i]) * assetLeverage(config,slugs[i]), 0) / 2;
      const cap = config.rules.turnover?.value / 100;
      const fraction = Number.isFinite(cap) && requested > cap ? cap / requested : 1;
      const executed = actual.map((w, i) => w + (targets[i] - w) * fraction);
      // Never silently retain an illegal allocation because a turnover cap prevented correction.
      if ((limitEnabled(config,'position') && executed.some((w,i) => ASSETS[slugs[i]][1] && w * 100 * assetLeverage(config,slugs[i]) > limits.position + EPS))
        || (limitEnabled(config,'alternatives') && executed.reduce((s,w,i) => s + (ASSETS[slugs[i]][1] ? w * assetLeverage(config,slugs[i]) : 0),0) * 100 > limits.alternatives + EPS)) {
        throw new Error('Turnover-Grenze verhindert die Einhaltung der Pflichtquoten beim Rebalancing.');
      }
      holdings = executed.map((w) => value * w);
      rebalances.push({ date, turnoverPct: requested * fraction * 100, partial: fraction < 1 });
    }
  }
  let riskReturns=returns,riskContributions=realizedContributions,riskDates=points.map(p=>p.date);
  if(market.calendar){
    const index=new Map(points.map((p,i)=>[p.date,i]));
    const selected=market.dates.map(d=>index.get(d)).filter(i=>i!==undefined);
    riskReturns=[];riskContributions=[];riskDates=selected.map(i=>points[i].date);
    for(let p=1;p<selected.length;p++){
      const from=selected[p-1],to=selected[p];let growth=1;const contributions=slugs.map(()=>0);
      for(let day=from;day<to;day++){for(let s=0;s<slugs.length;s++)contributions[s]+=realizedContributions[day][s]*growth;growth*=1+returns[day];}
      riskReturns.push(points[to].portfolioValue/points[from].portfolioValue-1);riskContributions.push(contributions);
    }
  }
  const summary=metrics(points,riskReturns,market.annualization,riskDates);
  if(market.calendar)summary.lastRawObservationDate=slugs.map(s=>valuation.sourceDates[s]).sort().at(-1);
  return {points,returns:riskReturns,calendarReturns:returns,calendarValuation:Boolean(market.calendar),riskDates,rebalances,breaches,slugs,realizedContributions:riskContributions,metrics:summary};
}
export function metrics(points, returns, annualization, riskDates = points.map(p=>p.date)) {
  const start = points[0].portfolioValue; const end = points.at(-1).portfolioValue;
  const years = (Date.parse(points.at(-1).date) - Date.parse(points[0].date)) / 86400000 / 365.2425;
  const variance = covariance(returns, returns); const volatility = variance === null ? null : Math.sqrt(Math.max(0, variance) * annualization);
  const sorted = [...returns].sort((a, b) => a - b);
  const tail = Math.max(1, Math.ceil(returns.length * 0.05));
  const es = returns.length >= 40 ? Math.max(0, -mean(sorted.slice(0, tail))) * 100 : null;
  const best = Math.max(...returns); const worst = Math.min(...returns);
  const maxDD = Math.min(0, ...points.map((p) => p.drawdownPct || 0));
  const cagr = years > 0 ? ((end / start) ** (1 / years) - 1) * 100 : null;
  return { finalValue: end, absoluteReturn: end - start, totalReturnPct: (end / start - 1) * 100,
    cagrPct: cagr, maxDrawdownPct: maxDD, annualizedVolatilityPct: volatility === null ? null : volatility * 100,
    sharpeRatio: volatility > EPS ? mean(returns) * annualization / volatility : null,
    calmarRatio: maxDD < -EPS ? cagr / Math.abs(maxDD) : null,
    expectedShortfallPct: es, bestDayPct: best * 100, worstDayPct: worst * 100,
    bestDayDate: riskDates[returns.indexOf(best) + 1], worstDayDate: riskDates[returns.indexOf(worst) + 1],
    positiveDaysPct: returns.filter((r) => r > 0).length / returns.length * 100,
    observedReturnCount: returns.length, annualizationPeriods: annualization, lastRawObservationDate: points.at(-1).date };
}
export function referenceVolatility(market, config) {
  return simulate(market, REFERENCE, { ...config, assetSettings: {}, leverage: 1, terPct: 0, financingPct: 0, rebalancing: 'quarterly', rules: {} }).metrics.annualizedVolatilityPct;
}
export function volatilityLimit(config, referencePct) {
  return config.volatilityLimitPct != null ? config.volatilityLimitPct : referencePct * (config.limits?.volatility ?? 100) / 100;
}
export function evaluate(market, config) {
  const limits = config.limits || { alternatives: 30, position: 10, volatility: 100 };
  const errors = validateConfig(config); if (errors.length) throw new Error(errors.join(' '));
  const result = simulate(market, config.weights, config);
  const reference = simulate(market, REFERENCE, { ...config, assetSettings: {}, leverage: 1, terPct: 0, financingPct: 0, rebalancing: 'quarterly', rules: {} });
  const variance = covariance(result.returns, result.returns);
  result.riskContributions = Object.fromEntries(result.slugs.map((slug, i) => [slug, variance > EPS * EPS
    ? covariance(result.realizedContributions.map((row) => row[i]), result.returns) / variance * 100 : null]));
  const delta = result.returns.map((r, i) => r - reference.returns[i]);
  result.trackingErrorPct = Math.sqrt(Math.max(0, covariance(delta, delta))) * Math.sqrt(market.annualization) * 100;
  const checks = [
    { key: 'alternatives', label: 'Alternative Anlagen zusammen', value: Object.entries(config.weights).reduce((s, [slug, w]) => s + (ASSETS[slug][1] ? w * assetLeverage(config,slug) : 0), 0), limit: limits.alternatives },
    { key: 'position', label: 'Einzelne alternative Anlageklasse', value: Math.max(0, ...Object.entries(config.weights).filter(([slug]) => ASSETS[slug][1]).map(([slug,w]) => w * assetLeverage(config,slug))), limit: limits.position },
    { key: 'volatility', label: 'Volatilität p.a.', value: result.metrics.annualizedVolatilityPct, limit: volatilityLimit(config, reference.metrics.annualizedVolatilityPct) },
  ];
  const values = { drawdown: Math.abs(result.metrics.maxDrawdownPct), shortfall: result.metrics.expectedShortfallPct,
    contribution: Object.values(result.riskContributions).some((v) => v === null) ? null : Math.max(...Object.values(result.riskContributions)),
    tracking: result.trackingErrorPct, turnover: Math.max(0, ...result.rebalances.map((r) => r.turnoverPct)) };
  for (const [key, rule] of Object.entries(config.rules)) checks.push({ key, label: OPTIONAL_RULES[key][0], value: values[key],
    limit: rule.mode === 'reference' ? ((key === 'drawdown' ? Math.abs(reference.metrics.maxDrawdownPct) : reference.metrics.expectedShortfallPct) === null ? null : (key === 'drawdown' ? Math.abs(reference.metrics.maxDrawdownPct) : reference.metrics.expectedShortfallPct) * rule.value / 100) : rule.value });
  checks.forEach((c) => { c.enabled = !['alternatives','position','volatility'].includes(c.key) || limitEnabled(config,c.key); c.passed = !c.enabled || (Number.isFinite(c.value) && Number.isFinite(c.limit) && c.value <= c.limit + 1e-6); c.available = Number.isFinite(c.value) && Number.isFinite(c.limit); });
  return { ...result, reference, checks, valid: checks.every((c) => c.passed), frequency: market.frequency,
    annualization: market.annualization, dataEnd: market.dataEnd, sourceDates: market.sourceDates };
}
function randomGenerator(seed = 734921) {
  return () => { seed = (1664525 * seed + 1013904223) >>> 0; return (seed + 1) / 4294967297; };
}
export function optimize(market, config, count = 500) {
  config = optimizationConfig(config);
  const errors = validateConfig(config,{allocation:false}); if (errors.length) throw new Error(errors.join(' '));
  const objective = config.objective ?? 'cagr'; const direction = OBJECTIVES[objective][3];
  let bestScore = -Infinity; let tried = 0; let feasible = 0; let undefinedObjective = false;
  const limits = config.limits || { alternatives: 30, position: 10, volatility: 100 };
  const random = randomGenerator(); const slugs = Object.keys(config.weights);
  let best = null; let bestConfig = null; let nearest = null; let nearestScore = Infinity; let lastError = '';
  const attempt = (weights) => {
    tried++;
    weights = Object.fromEntries(Object.entries(weights).filter(([,w]) => w > EPS));
    const candidate = { ...config, weights };
    try {
      const r = evaluate(market, candidate);
      if (r.valid) {
        feasible++;
        const value = objectiveValue(r,objective);
        if (value === null) undefinedObjective = true;
        else {
          const score = direction * value;
          if (!best || score > bestScore + 1e-10 || (Math.abs(score-bestScore) <= 1e-10 && r.metrics.cagrPct > best.metrics.cagrPct)) {best=r;bestConfig=candidate;bestScore=score;}
        }
      }
      if (!r.valid) {
        const score = r.checks.reduce((sum, c) => sum + (c.passed ? 0 : !c.available ? 1000 : Math.max(0, c.value - c.limit) / Math.max(0.01, c.limit)), 0);
        if (score < nearestScore) { nearest = r; nearestScore = score; }
      }
    } catch (error) { lastError = error.message; }
  };
  attempt(config.weights);
  if (Object.keys(REFERENCE).every((slug) => slugs.includes(slug))) attempt({ ...REFERENCE });
  // Include boundaries and neutral seeds; a random interior search alone misses exact optima at zero weights.
  attempt(Object.fromEntries(slugs.map((s) => [s,100/slugs.length])));
  for (const slug of slugs) attempt({[slug]:100});
  for (let a=0;a<slugs.length;a++) for(let b=a+1;b<slugs.length;b++) attempt({[slugs[a]]:50,[slugs[b]]:50});
  for (let n = 0; n < count; n++) {
    let weights;
    if (bestConfig && n % 2 === 0) {
      weights = Object.fromEntries(slugs.map((slug) => [slug, bestConfig.weights[slug] || 0]));
      const a = slugs[Math.floor(random() * slugs.length)]; const b = slugs[Math.floor(random() * slugs.length)];
      const transfer = Math.min(weights[a], random() * 8); weights[a] -= transfer; weights[b] += transfer;
    } else {
      weights = {}; let remaining = 100; let exposureLeft = limits.alternatives;
      const classic = slugs.filter((slug) => !ASSETS[slug][1]);
      if (!classic.length) {
        const raw = slugs.map(() => -Math.log(random())); const total = raw.reduce((a,b) => a+b,0);
        slugs.forEach((slug,i) => {weights[slug] = 100 * raw[i] / total;});
      } else {
        for (const slug of slugs.filter((slug) => ASSETS[slug][1])) {
          const leverage = assetLeverage(config,slug);
          const cap = Math.min(remaining,limitEnabled(config,'position') ? limits.position / leverage : 100,
            limitEnabled(config,'alternatives') ? Math.max(0,exposureLeft) / leverage : 100);
          const w = cap * random(); weights[slug] = w; remaining -= w; exposureLeft -= w * leverage;
        }
        const raw = classic.map(() => -Math.log(random())); const total = raw.reduce((a,b) => a+b,0);
        classic.forEach((slug,i) => {weights[slug] = remaining * raw[i] / total;});
      }
    }
    weights = Object.fromEntries(Object.entries(weights).filter(([, w]) => w > EPS));
    attempt(weights);
  }
  if (!best) {
    if (feasible && undefinedObjective) throw new Error('Das gewählte Optimierungsziel ist auf dieser Datenbasis nicht berechenbar. Ziel oder Zeitraum ändern.');
    const conflicts = nearest ? nearest.checks.filter((c) => !c.passed).map((c) => c.available
      ? `${c.label}: ${c.value.toFixed(2)} % bei Grenze ${c.limit.toFixed(2)} %`
      : `${c.label}: auf der verfügbaren Datenbasis nicht berechenbar`).join('; ') : lastError;
    throw new Error(`Keine zulässige Allokation gefunden. ${conflicts ? `Nächster geprüfter Kandidat: ${conflicts}. ` : ''}Gewählte Anlageklassen, Zeitraum und zusätzliche Regeln prüfen. Die Suche ist begrenzt und beweist keine Unlösbarkeit.`);
  }
  return {config:bestConfig,result:best,candidates:tried,optimization:{objective,value:objectiveValue(best,objective),candidates:tried,feasible}};
}
export function diagnostics(market, config, result) {
  const window = Math.max(2, Math.round((config.rollingMonths || 12) / 12 * market.annualization));
  const rolling = [];
  for (let i = window; i <= result.returns.length; i += Math.max(1, Math.round(market.annualization / 12))) {
    const r = result.returns.slice(i - window, i); const b = result.reference.returns.slice(i - window, i);
    rolling.push({ date: market.dates[i], volatility: Math.sqrt(covariance(r, r) * market.annualization) * 100,
      reference: Math.sqrt(covariance(b, b) * market.annualization) * 100 });
  }
  const stresses = [ ['Corona', '2020-02-01', '2020-04-30'], ['Aktien und Anleihen 2022', '2022-01-01', '2022-12-31'] ].map(([name, start, end]) => {
    // Require the whole window; never imply that a partial history covers a crisis.
    const points=result.points,reference=result.reference.points;
    const coverageEnd=market.calendar?Object.values(market.calendar.sourceDates).sort()[0]:market.dates.at(-1);
    if(points[0].date>start || coverageEnd<end)return {name,available:false};
    const from=points.findIndex(p=>p.date>=start),to=points.findLastIndex(p=>p.date<=end);
    const anchor=Math.max(0,from-1);
    return {name,available:true,returnPct:(points[to].portfolioValue/points[anchor].portfolioValue-1)*100,referencePct:(reference[to].portfolioValue/reference[anchor].portfolioValue-1)*100};
  });
  const counts = [0, 0, 0, 0, 0, 0, 0];
  result.returns.forEach((r) => { const p = r * 100; const i = [-3, -1, 0, 1, 3, 5].findIndex((edge) => p < edge); counts[i < 0 ? 6 : i]++; });
  return { rolling, stresses, histogram: counts, breaches: result.breaches, rebalances: result.rebalances };
}
