import { describe,it,expect } from 'vitest';
import { logisticsFields,logisticsOrderColumns,logisticsPageNamespace,logisticsQueryCapabilities } from './logisticsOrderGrid';
import { gridFeatureEnabled,sanitizeGridFeatures } from './unifiedGridFeatures';
import { sanitizeOmsGridPreference } from './omsGridState';
describe('Unified Grid V1',()=>{
  it('uses one field catalogue for every logistics queue',()=>{
    for(const scope of ['allocation','domestic','overseas','awaiting','transit','exceptions'] as const)
      for(const view of ['review','pending','today-4px','stockout','all']){
        const cols=logisticsOrderColumns(scope,view);
        expect(new Set(cols.map(c=>c.colId))).toEqual(new Set([...logisticsFields.map(c=>c.key),'action']));
        expect(cols[0]?.colId).toBe('order_number');
      }
  });
  it('defaults review and pending differently without changing field identity',()=>{
    expect(logisticsOrderColumns('domestic','review')[1]?.colId).toBe('review_status');
    expect(logisticsOrderColumns('domestic','pending')[1]?.colId).toBe('tracking');
  });
  it('isolates page and warehouse preferences',()=>{
    const keys=['review','pending','today','today-4px','today-weship','stockout','outsource','exceptions','all'].map(v=>logisticsPageNamespace('domestic',v,''));
    keys.push(...['','us-ny','us-moreplus','us-sm','de-tianyun','ca'].flatMap(w=>['review','pending','stockout','all','issued','shipped','exceptions'].map(v=>logisticsPageNamespace('overseas',v,w))));
    expect(new Set(keys).size).toBe(keys.length);
    expect(logisticsPageNamespace('allocation','review','')).toBe(logisticsPageNamespace('allocation','all',''));
    expect(()=>logisticsPageNamespace('overseas','../bad','')).toThrow();
  });
  it('keeps only real server capabilities',()=>{
    expect(logisticsQueryCapabilities('domestic','review').filterKeys).toEqual([]);
    expect(logisticsQueryCapabilities('overseas','pending').sortKeys).toEqual(['tracking','shipped_at','line']);
    expect(logisticsQueryCapabilities('domestic','all').filterKeys).toHaveLength(9);
    expect(logisticsQueryCapabilities('domestic','all').filterMap.review_status).toBe('review');
    expect(logisticsQueryCapabilities('domestic','all').filterMap.country).toBe('destination');
    expect(logisticsQueryCapabilities('allocation','review').selection).toBe(false);
    expect(logisticsFields).toHaveLength(51);
    expect(logisticsQueryCapabilities('transit','review').sortKeys).toEqual(['tracking','shipped_at','carrier']);
    expect(logisticsQueryCapabilities('transit','review').selection).toBe(false);
    expect(logisticsPageNamespace('transit','review','')).not.toBe(logisticsPageNamespace('awaiting','review',''));
  });
  it('sanitizes preferences and never grants a capability',()=>{
    expect(sanitizeGridFeatures({search:false,selection:true,unknown:true,density:'yes'})).toEqual({search:false,selection:true});
    expect(gridFeatureEnabled('selection',{selection:false},{selection:true})).toBe(false);
    expect(gridFeatureEnabled('search',{search:true},{search:false})).toBe(false);
    expect(gridFeatureEnabled('maximize',{maximize:true},{})).toBe(true);
  });
  it('preserves features, page size and old column preferences',()=>{
    const old={version:1,columns:[{colId:'sku',hide:true}],pageSize:20};
    expect(sanitizeOmsGridPreference(old,['sku'])?.features).toEqual({});
    const restored=sanitizeOmsGridPreference({...old,pageSize:100,features:{maximize:false}},['sku']);
    expect(restored?.features).toEqual({maximize:false});
    expect(restored?.pageSize).toBe(100);
    expect(restored?.columns[0]?.hide).toBe(true);
  });
});
