import test from 'node:test';
import assert from 'node:assert/strict';
import {PortfolioLab} from '../../assets/js/asset-backtester/portfolio-ui.js';
import {ASSETS,defaults,standardPortfolios,cleanConfig} from '../../assets/js/asset-backtester/portfolio-engine.js';
test('new portfolio drafts inherit the selected comparison start and allow the whole catalog',()=>{
  const original=defaults();const draft=PortfolioLab.prototype.draftConfig.call({comparisonStart:'2021-10-27',comparisonEnd:'2025-12-31'},original);
  assert.equal(draft.start,'2021-10-27');
  assert.equal(draft.end,'2025-12-31');
  assert.equal(original.start,'2018-01-01');
  assert.deepEqual(draft.optimizationAssets,Object.keys(ASSETS));
  assert.notEqual(draft.weights,original.weights);
  assert.deepEqual(draft.weights,original.weights);
});
test('loading a portfolio preserves an explicitly selected universe while following the current start',()=>{
  const original=defaults({optimizationAssets:['gold','msci_world'],assetSettings:{gold:{leverage:1.4,terPct:0.2}}});
  const draft=PortfolioLab.prototype.draftConfig.call({comparisonStart:'2016-10-27',comparisonEnd:'2024-12-31'},original);
  assert.equal(draft.start,'2016-10-27');
  assert.equal(draft.end,'2024-12-31');
  assert.deepEqual(draft.optimizationAssets,['gold','msci_world']);
  assert.deepEqual(draft.assetSettings,original.assetSettings);
});

test('changing only the comparison end refreshes portfolio evaluations without mutating saved dates',()=>{
  let invalidations=0, restores=0;
  const state={comparisonStart:'2020-01-01',comparisonEnd:'2024-12-31',config:{start:'2020-01-01',end:'2024-12-31'},templates:[{start:'2020-01-01',end:'2024-12-31'}],saved:[{start:'2018-01-01',end:'2023-12-31'}],results:new Map([['old',{}]]),invalidate(){invalidations++;},renderLibrary(){},restore(){restores++;}};
  PortfolioLab.prototype.setComparisonRange.call(state,'2020-01-01','2025-12-31');
  assert.equal(state.config.end,'2025-12-31');
  assert.equal(state.templates[0].end,'2025-12-31');
  assert.equal(state.saved[0].end,'2023-12-31');
  assert.equal(state.results.size,0);
  assert.equal(restores,1);
  PortfolioLab.prototype.setComparisonRange.call(state,'2020-01-01','2025-12-31');
  assert.equal(invalidations,1);
});

test('new portfolios and templates start without rebalancing while explicit saved choices remain',async()=>{
  assert.equal(defaults().rebalancing,'none');
  assert.ok(standardPortfolios().every(p=>p.rebalancing==='none'));
  const explicit=defaults({rebalancing:'monthly'});
  assert.equal(PortfolioLab.prototype.draftConfig.call({},explicit).rebalancing,'monthly');
  const english=await import('../../../../en/dashboards/backtester/assets/js/asset-backtester/portfolio-engine.js');
  assert.equal(english.defaults().rebalancing,'none');
  assert.ok(english.standardPortfolios().every(p=>p.rebalancing==='none'));
});
test('older portfolios load while obsolete training settings are discarded',()=>{
 const legacy={...defaults({volatilityLimitPct:12.34,rebalancing:'monthly'}),lookback:36};
 const current=cleanConfig(legacy);
 assert.equal(Object.hasOwn(current,'lookback'),false);
 assert.equal(current.volatilityLimitPct,12.34);
 assert.equal(current.rebalancing,'monthly');
 assert.deepEqual(current.weights,legacy.weights);
});
test('leverage buttons enable positions independently and disabling removes hidden exposure',()=>{
 let invalidated=0,rendered=0;
 const state={config:defaults({assetSettings:{msci_world:{leverage:1,terPct:0},global_ig_bonds:{leverage:1,terPct:0}}}),enabledLeverage:new Set(),readForm(){},invalidate(){invalidated++;},renderForm(){rendered++;},message(text){throw Error(text);}};
 state.leverageEnabled=slug=>PortfolioLab.prototype.leverageEnabled.call(state,slug);
 assert.equal(state.leverageEnabled('msci_world'),false);
 PortfolioLab.prototype.action.call(state,'toggle-leverage','msci_world');
 assert.equal(state.leverageEnabled('msci_world'),true);
 assert.equal(state.leverageEnabled('global_ig_bonds'),false);
 state.config.assetSettings.msci_world.leverage=2.4;
 state.config.assetSettings.msci_world.terPct=0.25;
 PortfolioLab.prototype.action.call(state,'toggle-leverage','msci_world');
 assert.equal(state.leverageEnabled('msci_world'),false);
 assert.deepEqual(state.config.assetSettings.msci_world,{leverage:1,terPct:0});
 assert.equal(invalidated,2);assert.equal(rendered,2);
});

test('a saved leveraged position remains visibly active when loaded',()=>{
 const state={config:defaults({assetSettings:{msci_world:{leverage:2,terPct:0.1}}}),enabledLeverage:new Set()};
 assert.equal(PortfolioLab.prototype.leverageEnabled.call(state,'msci_world'),true);
 assert.equal(PortfolioLab.prototype.leverageEnabled.call(state,'global_ig_bonds'),false);
 state.config.assetSettings.msci_world.leverage=1;
 assert.equal(PortfolioLab.prototype.leverageEnabled.call(state,'msci_world'),true);
});
