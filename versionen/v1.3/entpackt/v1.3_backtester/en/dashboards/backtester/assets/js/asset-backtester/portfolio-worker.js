import { evaluate,optimize,diagnostics,REFERENCE } from './portfolio-engine.js?v=20261028-v13y';
import { simulatePortfolioTaxes,portfolioTaxKey } from './portfolio-tax.js?v=20261028-v13y';
self.onmessage=({data})=>{
  try {
    const {type,market,config,taxSettings}=data;
    const found=type==='optimize'?optimize(market,config):{config,result:evaluate(market,config)};
    if(type==='restore'){
      found.diagnostics=diagnostics(market,config,found.result);
      if(found.result.valid && taxSettings?.taxesEnabled){
        found.taxKey=portfolioTaxKey(taxSettings);
        try {
          const result=simulatePortfolioTaxes(market,config,taxSettings,found.result);
          const referenceConfig={...config,weights:REFERENCE,assetSettings:{},leverage:1,terPct:0,financingPct:0,rebalancing:'quarterly',rules:{}};
          if(found.result.reference)result.reference=simulatePortfolioTaxes(market,referenceConfig,taxSettings,found.result.reference);
          found.taxView={result,diagnostics:diagnostics(market,config,result)};
        }catch(error){found.taxError=error.message;}
      }
    }
    self.postMessage({success:true,result:found});
  }catch(error){self.postMessage({success:false,message:error.message});}
};
