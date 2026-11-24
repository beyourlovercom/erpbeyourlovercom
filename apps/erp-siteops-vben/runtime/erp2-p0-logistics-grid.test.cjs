'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.join(__dirname,'../overrides/src');
function source(p){return fs.readFileSync(path.join(root,p),'utf8');}
test('P0 logistics uses canonical ERP1 GlobalGrid, not a stripped direct AG Grid',()=>{
 const s=source('views/erp/logistics-projection.vue');
 assert.match(s,/import GlobalGrid from '#\/components\/erp-global-grid\/GlobalGrid.vue'/);
 assert.match(s,/<GlobalGrid/);
 assert.doesNotMatch(s,/<AgGridVue/);
 for(const callback of ['selection-change','page-change','page-size-change','sort-change','column-search-change'])
   assert.ok(s.includes('@'+callback),'Missing '+callback);
});
test('P0 ERP1 grid retains maximize, escape, user scoped preferences',()=>{
 const oms=source('components/erp-global-grid/OmsGrid.vue');
 const wrapper=source('components/erp-global-grid/GlobalGrid.vue');
 for(const str of ['is-maximized','最大化列表','Escape','列设置','密度：','account'])
   assert.ok(oms.includes(str),'Legacy Grid action lost: '+str);
 assert.match(wrapper,/preference-scope="account"/);
 assert.match(oms,/\/ui\/preferences\//);
});
test('R2 login completes canonical router bootstrap before home redirect',()=>{
 const login=source('views/_core/authentication/login.vue');
 const bootstrap=login.indexOf('await bootstrapCanonicalDevNavigation(router)');
 const redirect=login.indexOf('await router.replace(canonicalHomePath(verifiedIdentity))');
 assert.ok(bootstrap>=0&&redirect>bootstrap);
 assert.match(login,/authenticatedButNavigationFailed/);
});

test('P0 domestic Excel buttons reuse exact Laravel routes and selected order_ids',()=>{
 const grid=source('views/erp/logistics-projection.vue');
 const gateway=fs.readFileSync(path.join(__dirname,'erp2-logistics-dev-gateway.cjs'),'utf8');
 for(const route of [
  '/logistics/china/today/export','/logistics/china/export/4px',
  '/logistics/china/export/weship','/logistics/china/pending-batch/export',
  '/logistics/china/pending-batch/export-sku-merged'
 ]) {
  assert.ok(grid.includes(route),'Missing Dev download action: '+route);
  assert.ok(gateway.includes(route),'Gateway must use exact Laravel export URL: '+route);
 }
 assert.match(grid,/qs\.append\('order_ids\[\]'/);
 assert.match(gateway,/domesticExportRead/);
 assert.match(gateway,/content-disposition/);
});
