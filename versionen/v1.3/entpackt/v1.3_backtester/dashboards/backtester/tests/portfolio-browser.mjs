// Read-only API fixture from the project's real CSV imports, not a PHP/DB integration test.
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { resolve, extname, relative } from 'node:path';
import { createRequire } from 'node:module';
import { ASSETS, metrics, defaults } from '../assets/js/asset-backtester/portfolio-engine.js';
import { exportPortfolios } from '../assets/js/asset-backtester/portfolio-store.js';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = fileURLToPath(new URL('..', import.meta.url));
const output = process.env.PORTFOLIO_SCREENSHOTS || resolve(root, 'tests/reports/portfolio');
await mkdir(output, { recursive: true });
const raw = new Map();
for (const slug of Object.keys(ASSETS)) {
  const text = await readFile(resolve(root, 'var/import', `${slug}.csv`), 'utf8');
  raw.set(slug, text.trim().split(/\r?\n/).slice(1).map((line) => {
    const [date, value] = line.replaceAll('"', '').split(','); return { date, value: Number(value) };
  }).filter((p) => p.value > 0).sort((a, b) => a.date.localeCompare(b.date)));
}
const frequency = (slug) => slug === 'de_real_estate' ? 'quarterly' : slug === 'us_real_estate' ? 'monthly' : 'daily';
const assets = [...raw].map(([slug, rows]) => ({ slug, displayName: ASSETS[slug][0], currency: 'EUR', nativeFrequency: frequency(slug),
  firstDate: rows[0].date, lastDate: rows.at(-1).date, source: { name: 'Lokale geprüfte CSV-Importhistorie' }, proxyDescription: 'Read-only Browser-Test, Preisreihe in EUR' }));
function series(slug, start, end, amount) {
  const rows = raw.get(slug); let anchor = rows.findLast((p) => p.date <= start);
  if (!anchor) return null;
  const sourceStart = anchor.value; const points = []; const observed = [];
  let peak = amount; let previous = amount;
  const byDate = new Map(rows.map((p) => [p.date, p]));
  for (let t = Date.parse(start); t <= Date.parse(end); t += 86400000) {
    const date = new Date(t).toISOString().slice(0, 10); const isRaw = byDate.has(date);
    if (isRaw) anchor = byDate.get(date);
    const value = amount * anchor.value / sourceStart; peak = Math.max(peak, value);
    if (isRaw && date > start) { observed.push(value / previous - 1); previous = value; }
    points.push({ date, sourceValue: anchor.value, portfolioValue: value, priceOnlyPortfolioValue: value,
      returnPct: (value / amount - 1) * 100, drawdownPct: (value / peak - 1) * 100,
      lastRawObservationDate: anchor.date, isCarriedForward: !isRaw });
  }
  const observedPoints = points.filter((p) => !p.isCarriedForward);
  const a = frequency(slug) === 'quarterly' ? 4 : frequency(slug) === 'monthly' ? 12 : 252;
  const m = observedPoints.length > 1 ? metrics(observedPoints, observedPoints.slice(1).map((p, i) => p.portfolioValue / observedPoints[i].portfolioValue - 1), a) : {};
  return { slug, displayName: ASSETS[slug][0], currency: 'EUR', nativeFrequency: frequency(slug), points,
    effectiveStartDate: start, effectiveEndDate: end, lastRawObservationDate: anchor.date, metrics: m,
    dividendAdjustment: { applied: false }, rentalCashflowAdjustment: { applied: false } };
}
const server = createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost'); const pathname = decodeURIComponent(url.pathname);
  try {
    if (pathname.includes('/api/')) {
      let payload;
      if (pathname.endsWith('assets.php')) payload = { assets };
      else if (pathname.endsWith('series.php')) {
        const start = url.searchParams.get('start'); const end = url.searchParams.get('end');
        payload = { request: { start, end }, warnings: [], series: url.searchParams.get('assets').split(',').map((s) => series(s, start, end, Number(url.searchParams.get('amount') || 10000))).filter(Boolean) };
      } else if (pathname.endsWith('summary.php')) payload = { summary: { periodLabels: ['1W', '1M', '6M', '1Y', '3Y', '5Y', '10Y'], globalEndDate: '2026-08-14', rows: assets.map((a) => ({ ...a, lastRawObservationDate: a.lastDate, periods: {} })) } };
      else payload = { metrics: [] };
      res.writeHead(200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify({ success: true, ...payload })); return;
    }
    const path = resolve(root, pathname === '/' ? 'index.html' : `.${pathname}`);
    if (relative(root, path).startsWith('..') || !['.html', '.css', '.js'].includes(extname(path))) { res.writeHead(404).end(); return; }
    const body = await readFile(path); res.writeHead(200, { 'Content-Type': { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' }[extname(path)] }); res.end(body);
  } catch { res.writeHead(404).end(); }
});
await new Promise((done) => server.listen(0, '127.0.0.1', done));
const browser = await chromium.launch({ headless: true,
  executablePath: process.env.PLAYWRIGHT_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
try {
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } }); const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(`http://127.0.0.1:${server.address().port}/`);
  await page.locator('#pf-form').waitFor();
  assert.equal(await page.locator('#pf-chart').count(), 0);
  assert.equal(await page.locator('[data-portfolio-row]').count(), 0);
  await page.locator('[data-pf-action="template"][data-key="0"]').click();
  await page.getByRole('button', { name: 'Portfolio fertigstellen', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('#pf-message').dataset.kind === 'success');
  assert.equal(await page.locator('[data-portfolio-row]').count(), 3);
  assert.match(await page.locator('#summary-table [data-portfolio-row] td').first().textContent(), /Nicht verfügbar/);
  await page.locator('[data-pf-action="save"]').click();
  await page.locator('#portfolio-lab').screenshot({ path: resolve(output, 'editor-desktop.png'), style: '.main-nav { visibility: hidden; }' });
  await page.locator('#chart-comparison').screenshot({ path: resolve(output, 'results-desktop.png') });
  await page.locator('#pf-weight-msci_world').fill('61');
  assert.equal(await page.locator('[data-portfolio-row]').count(), 0);
  await page.getByRole('button', { name: 'Portfolio fertigstellen', exact: true }).click();
  assert.match(await page.locator('#pf-message').textContent(), /100 %/);
  assert.equal(await page.locator('#pf-chart').count(), 0);
  await page.reload(); await page.waitForFunction(() => document.querySelectorAll('[data-portfolio-row]').length === 3);
  assert.match(await page.locator('#pf-slot-count').textContent(), /1 \/ 5/);
  await page.locator('[data-pf-action="template"][data-key="1"]').click();
  await page.getByRole('button', { name: 'Portfolio fertigstellen', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('#pf-message').dataset.kind === 'success');
  await page.locator('[data-pf-action="save"]').click();
  for (let i = 0; i < 3; i++) await page.locator('[data-pf-action="duplicate"]').click();
  assert.match(await page.locator('#pf-slot-count').textContent(), /5 \/ 5/);
  await page.locator('[data-pf-action="duplicate"]').click();
  assert.match(await page.locator('#pf-message').textContent(), /fünf/);
  await page.reload(); await page.waitForFunction(() => document.querySelectorAll('#metrics-table [data-portfolio-row]').length === 5);
  assert.equal(await page.locator('#summary-table [data-portfolio-row]').count(), 5);
  assert.equal(await page.locator('#risk-metrics-table [data-portfolio-row]').count(), 5);
  await page.locator('[data-pf-action="template"][data-key="0"]').click();
  await page.getByRole('button', { name: 'Portfolio fertigstellen', exact: true }).click();
  assert.match(await page.locator('#pf-message').textContent(), /fünf/);
  assert.equal(await page.locator('#metrics-table [data-portfolio-row]').count(), 5);
  await page.locator('#pf-saved').selectOption({ index: 1 }); await page.locator('[data-pf-action="load"]').click();
  const editedId = await page.locator('#pf-saved').inputValue();
  await page.locator('#pf-add-rule').selectOption('contribution'); await page.locator('[data-pf-action="add-rule"]').click();
  await page.locator('#pf-rule-contribution').fill('1');
  await page.getByRole('button', { name: 'Portfolio fertigstellen', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('#pf-message').dataset.kind === 'error');
  assert.match(await page.locator('#pf-message').textContent(), /Risikobeitrag/);
  await page.locator('[data-pf-action="remove-rule"]').click();
  await page.locator('.portfolio-lab__universe > summary').click();
  await page.locator('[data-pf-action="pool-editor"]').click();
  await page.locator('[data-pf-action="optimize"]').click();
  await page.waitForFunction(() => document.querySelector('#pf-message').dataset.kind === 'success');
  // Model from an intentionally unbalanced draft using actual range-control interactions.
  await page.locator('#pf-add-asset').selectOption('gold'); await page.locator('[data-pf-action="add-asset"]').click();
  await page.locator('#pf-add-asset').selectOption('euro_money_market'); await page.locator('[data-pf-action="add-asset"]').click();
  const positionSlider = page.locator('[data-pf-slider-for="pf-limit-position"]');
  await positionSlider.focus(); await positionSlider.press('Home');
  for (let i = 0; i < 4; i++) await positionSlider.press('ArrowRight');
  assert.equal(await page.locator('#pf-limit-position').inputValue(), '2');
  const volSlider = page.locator('[data-pf-slider-for="pf-limit-volatility"]');
  await volSlider.focus(); await volSlider.press('End');
  for (let i = 0; i < 20; i++) await volSlider.press('ArrowLeft');
  assert.equal(await page.locator('#pf-limit-volatility').inputValue(), '80');
  await page.locator('#pf-limit-alternatives').fill('5');
  assert.equal(await page.locator('[data-pf-slider-for="pf-limit-alternatives"]').inputValue(), '5');
  await page.locator('[data-pf-action="optimize"]').click();
  await page.waitForFunction(() => document.querySelector('#pf-message').dataset.kind === 'success');
  assert.equal(await page.locator('#pf-limit-volatility').inputValue(), '80');
  const weights = await page.locator('[data-pf-weight]').evaluateAll((els) => Object.fromEntries(els.map((e) => [e.dataset.pfWeight, Number(e.value)])));
  assert.ok(Math.abs(Object.values(weights).reduce((a, b) => a + b, 0) - 100) < 1e-5);
  assert.ok((weights.gold || 0) <= 2);
  await page.locator('[data-pf-action="save"]').click();
  await page.reload(); await page.waitForFunction(() => document.querySelectorAll('#metrics-table [data-portfolio-row]').length === 5);
  const savedLimits = await page.evaluate((id) => JSON.parse(localStorage.getItem('sms.asset-backtester.portfolios.v1')).portfolios.find((p) => p.id === id).limits, editedId);
  assert.deepEqual(savedLimits, { alternatives: 5, position: 2, volatility: 80 });
  const beforeBadImport = await page.evaluate(() => localStorage.getItem('sms.asset-backtester.portfolios.v1'));
  await page.locator('#pf-import-file').setInputFiles({ name: 'invalid.json', mimeType: 'application/json', buffer: Buffer.from('{invalid') });
  await page.waitForFunction(() => document.querySelector('#pf-message').textContent.includes('JSON'));
  assert.equal(await page.evaluate(() => localStorage.getItem('sms.asset-backtester.portfolios.v1')), beforeBadImport);
  await page.locator('#pf-saved').selectOption({ index: 1 });
  page.once('dialog', (dialog) => dialog.accept()); await page.locator('[data-pf-action="delete"]').click();
  await page.locator('#pf-import-file').setInputFiles({ name: 'valid.json', mimeType: 'application/json', buffer: Buffer.from(exportPortfolios([defaults({ name: 'Importiertes Weltportfolio' })])) });
  await page.waitForFunction(() => document.querySelector('#pf-slot-count').textContent.includes('5 / 5'));
  await page.waitForFunction(() => document.querySelectorAll('#metrics-table [data-portfolio-row]').length === 5);
  await page.locator('#pf-risk-results').screenshot({ path: resolve(output, 'risk-desktop.png'), style: '.main-nav { visibility: hidden; }' });
  const download = page.waitForEvent('download'); await page.locator('[data-pf-action="export"]').click();
  assert.equal((await download).suggestedFilename(), 'meine-portfolios.json');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.locator('#portfolio-lab').screenshot({ path: resolve(output, 'editor-mobile.png'), style: '.main-nav { visibility: hidden; }' });
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
  await page.locator('#pf-risk-results').screenshot({ path: resolve(output, 'risk-mobile.png'), style: '.main-nav { visibility: hidden; }' });
  await page.setViewportSize({ width: 320, height: 720 });
  await page.waitForTimeout(250); await page.evaluate(() => window.scrollTo(0, window.scrollY));
  await page.locator('#portfolio-lab').screenshot({ path: resolve(output, 'editor-mobile-320.png'), style: '.main-nav { visibility: hidden; }' });
  if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)) console.log(await page.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth, scrollX, offenders: [...document.querySelectorAll('body *')].filter((e) => e.getBoundingClientRect().right + scrollX > innerWidth && !e.closest('.asset-backtester__table-scroll, .asset-backtester__intervals')).slice(0, 25).map((e) => ({ tag: e.tagName, class: e.className, right: e.getBoundingClientRect().right + scrollX, width: e.getBoundingClientRect().width })) })));
  if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)) console.log(await page.evaluate(() => [...document.querySelectorAll('body *')].filter((e) => e.scrollWidth > e.clientWidth + 2 && getComputedStyle(e).overflowX === 'visible' && !e.closest('.asset-backtester__table-scroll, .asset-backtester__intervals')).slice(0, 30).map((e) => ({ tag: e.tagName, class: e.className, width: e.clientWidth, scroll: e.scrollWidth, text: e.textContent.slice(0, 60) }))));
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
  assert.deepEqual(errors, []);
  console.log('Browser verified: empty-state gating, two templates, finalisation, edit invalidation, local save/reload, five-slot limit, cross-section results, optional limits, custom rule sliders, automatic allocation from an unbalanced draft, deletion, valid and invalid JSON imports, export, 390px/320px layout.');
  console.log(`Screenshots: ${output}`);
} finally { await browser.close(); await new Promise((done) => server.close(done)); }
