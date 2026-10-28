// Static release checks; no publication and no API writes.
import assert from 'node:assert/strict';
import {readFile,access,readdir} from 'node:fs/promises';
import {resolve,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
const site=fileURLToPath(new URL('../../..',import.meta.url));
const locales=[['dashboards/backtester','de','/en/dashboards/backtester/','../../en/dashboards/backtester/index.html'],['en/dashboards/backtester','en','/dashboards/backtester/','../../../dashboards/backtester/index.html']];
let checked=0;
for(const [folder,language,target,fileTarget] of locales){
  const base=resolve(site,folder);const html=await readFile(resolve(base,'index.html'),'utf8');
  assert.ok(html.includes(`<html lang="${language}">`));
  assert.equal(html.split(`data-language-target="${target}"`).length-1,2);
  assert.equal(html.split(`data-language-file-target="${fileTarget}"`).length-1,2);
  assert.ok(html.includes('portfolio.css?v=20261028-v13y'));
  for(const match of html.matchAll(/<(?:link|script)\b[^>]*(?:src|href)="([^"]+)"/g)){
    const path=match[1].split(/[?#]/)[0];if(/^(https?:|data:)/.test(path))continue;
    await access(path.startsWith('/')?resolve(site,'.'+path):resolve(base,path));checked++;
  }
  const moduleDir=resolve(base,'assets/js/asset-backtester');
  for(const name of await readdir(moduleDir)){
    if(!name.endsWith('.js'))continue;
    const source=await readFile(resolve(moduleDir,name),'utf8');
    for(const match of source.matchAll(/(?:from\s*|new URL\(\s*)['"](\.[^'"]+)['"]/g)){
      await access(resolve(moduleDir,match[1].split('?')[0]));checked++;
    }
    assert.ok(!source.includes('20261027-v13d'),`Old cache version: ${folder}/${name}`);
  }
  const engine=await readFile(resolve(moduleDir,'portfolio-engine.js'),'utf8');
  const ui=await readFile(resolve(moduleDir,'portfolio-ui.js'),'utf8');
  assert.ok(engine.includes('OBJECTIVES'));
  assert.ok(!/walk.?forward|pf-lookback/i.test(ui));
  assert.ok(!engine.includes('export function walkForward'));
  assert.ok(engine.includes('volatilityLimitPct: null'));
  assert.ok(engine.includes('export function referenceVolatility'));
  assert.ok(ui.includes('refreshVolatilityReference'));
  assert.ok(!ui.includes('reset-volatility'));
  assert.ok(!ui.includes('Absoluter Prozentwert.'));
  assert.ok(ui.includes('portfolio-lab__rule-info'));
  assert.ok(ui.includes('portfolio-lab__strategy-row'));
  assert.ok(engine.includes('export function calendarMarket'));
  assert.ok(engine.includes('calendarValuation:Boolean(market.calendar)'));
  assert.ok(ui.includes('pf-optimization-result'));
  assert.ok(ui.includes('optimizationConfig(config)'));
  assert.ok(ui.includes('forOptimization = Boolean(config.dataSnapshot?.optimization)'));
  assert.ok(ui.includes('data-pf-action="remove-base"'));
  assert.ok(ui.includes('<label>Rebalancing<select id="pf-rebalancing">'));
  assert.ok(!ui.includes('id="pf-start"'));
  assert.ok(!ui.includes('id="pf-end"'));
  assert.ok(!ui.includes('id="pf-amount"'));
  assert.ok(ui.includes('this.comparisonSelection = new Set()'));
  assert.ok(ui.includes('data-portfolio-option'));
  assert.ok(!ui.includes('data-pf-mode='));
  assert.ok(!ui.includes('<span>01</span>'));
  assert.ok(!ui.includes('03 / AUS REGELN'));
  assert.ok(ui.includes('setComparisonRange(start, end)'));
  assert.ok(!ui.includes('Lokal auf diesem Gerät'));
}
for(const shared of ['assets/site-header.css','assets/site-header.js'])await access(resolve(site,shared));
console.log(`Release checks passed: both locales, paired desktop/mobile language links, ${checked} static/module dependencies, cache versions, rule toggles and objective integration.`);
