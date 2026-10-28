// Rebase only within observed history. Never extend a portfolio outside its tested interval.
export function buildPortfolioComparison(entries, range = {}, _comparisonAmount = 10000, language = 'de') {
  const amount = 10000;
  const english = language === 'en';
  const series = [];
  const add = (key, name, source, color, reference = false, sourceMetrics = {},taxMetadata = {}) => {
    const selected = source.filter((p) => (!range.start || p.date >= range.start) && (!range.end || p.date <= range.end));
    if (selected.length < 2 || !(selected[0].portfolioValue > 0)) return;
    const initial = selected[0].portfolioValue;
    series.push({ slug: 'portfolio-' + key, displayName: name,
      currency: 'EUR', isPortfolio: true, ...taxMetadata, portfolioStyle: { color, reference }, effectiveStartDate: selected[0].date,
      effectiveEndDate: selected.at(-1).date, metrics: {...sourceMetrics,finalValue:amount*selected.at(-1).portfolioValue/initial,totalReturnPct:(selected.at(-1).portfolioValue/initial-1)*100}, points: selected.map((p) => ({ ...p,
        portfolioValue: amount * p.portfolioValue / initial, sourceValue: amount * p.portfolioValue / initial,
        returnPct: (p.portfolioValue / initial - 1) * 100, lastRawObservationDate: p.lastRawObservationDate || p.date, isCarriedForward: p.isCarriedForward ?? false })) });
  };
  const colors = ['#34d399', '#a78bfa', '#fbbf24', '#f472b6', '#38bdf8'];
  entries.forEach(([key,e],i) => add(key, e.config.name + ' / Portfolio', e.result.points, colors[i % colors.length],false,e.result.metrics,{taxAdjustment:e.result.taxAdjustment,taxRebalances:e.result.taxRebalances}));
  if (series.length && entries[0]?.[1]?.result.reference) add('reference', english ? '60/40 reference' : '60/40-Referenz', entries[0][1].result.reference.points, '#94a3b8', true,entries[0][1].result.reference.metrics,{taxAdjustment:entries[0][1].result.reference.taxAdjustment,taxRebalances:entries[0][1].result.reference.taxRebalances});
  return series;
}
