import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import ts from 'typescript';
import {parse,compileScript,compileTemplate} from 'vue/compiler-sfc';
const root=path.dirname(fileURLToPath(import.meta.url)),src=path.join(root,'overrides/apps/web-antd/src');
const read=p=>fs.readFileSync(path.join(src,p),'utf8');
const manifest=JSON.parse(fs.readFileSync(path.join(root,'manifest.json'),'utf8'));
for(const [rel,sha] of Object.entries(manifest.files)){assert.equal(crypto.createHash('sha256').update(fs.readFileSync(path.join(root,rel))).digest('hex'),sha,rel)}
console.log('PASS exact archive file hashes');
for(const f of ['components/OfflineWarehouseMap.vue','components/AllocationGeoReference.vue','views/erp/logistics-projection.vue','views/erp/domestic-allocation-panel.vue']){
 const {descriptor,errors}=parse(read(f),{filename:f});assert.deepEqual(errors,[]);
 const script=compileScript(descriptor,{id:f});assert.deepEqual(compileTemplate({source:descriptor.template.content,filename:f,id:f,compilerOptions:{bindingMetadata:script.bindings}}).errors,[]);console.log('PASS Vue compile '+f);
}
function load(f,requires={}){
 const source=read(f).replaceAll('import.meta.env','__env');
 const t=ts.transpileModule(source,{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022},reportDiagnostics:true});assert.equal(t.diagnostics.length,0);
 const m={exports:{}};new Function('module','exports','require','__env',t.outputText)(m,m.exports,k=>{assert.ok(Object.hasOwn(requires,k),'Unexpected dependency '+k);return requires[k]},{VITE_ERP_IDENTITY_MODE:'laravel',VITE_ERP_P0_DOMESTIC_FLOW_ENABLED:'1'});return m.exports;
}
const geo=load('lib/offlineWarehouseMap.ts');
const data=JSON.parse(read('lib/offline-geography.json'));data.cities={...data.cities,...JSON.parse(read('lib/offline-cities/IT.json'))};
assert.equal(data.paths.length,177);assert.equal(geo.locate({country:'IT',city:'Rome'},data).precision,'city');assert.equal(geo.locate({country:'IT',city:'unknown'},data).precision,'country');assert.equal(geo.locate({country:'ZZ'},data),null);assert.ok(Math.abs(geo.distanceKm({lat:0,lon:0},{lat:0,lon:1})-111.195)<.01);
assert.equal(geo.stockState({code:'DE',name:'DE',id:2},[{sku:'a',quantity:2,availability:[{warehouseId:2,available:3}]},{sku:'A',quantity:2,availability:[{warehouseId:2,available:3}]}]),'short');
console.log('PASS offline geography, fallback, distance and stock contract');
const api=load('api/erp-logistics.ts',{'./erp-orders':{},'./logistics-query-contract':{}});
const json=(v,status=200)=>new Response(JSON.stringify(v),{status,headers:{'content-type':'application/json'}});
const plan=api.parseAllocationPlan({items:[{id:1,product_id:7,source_product_id:6,sku:'OLD',source_sku:'SOURCE',previous_replacement_product_id:7,quantity:2,availability:[{warehouse_id:1,quantity_available:3}]}]});
assert.equal(plan.items[0].sourceProductId,6);assert.equal(plan.items[0].previousReplacementId,7);
assert.deepEqual(await api.readAllocationProducts(async(u,o)=>{assert.equal(u,'/logistics/wait-stock/allocation-products');assert.equal(o.method,'GET');return json({products:[{id:8,sku:'NEW',name:'EN',material_full_name:'中文'},{id:9,sku:'gift'}]})}),[{id:8,sku:'NEW',name:'EN',materialName:'中文'}]);
assert.deepEqual(await api.readAllocationProductAvailability(8,async u=>{assert.ok(u.endsWith('product_id=8'));return json({availability:[{warehouse_id:1,quantity_available:0}]})}),[{warehouseId:1,available:0}]);
const a={order_item_id:1,source_code:'FACTORY',gift_mapping_id:null,replacement_product_id:8,carrier:null,service:null};const input={orderId:1,plan,warehouses:[{code:'FACTORY',name:'Factory'}],mode:'manual',assignments:[a]};
const run=async(id)=>{const calls=[];const f=async(u,o)=>{const action=u.split('/').pop();calls.push(action);if(action==='preflight'||action==='manual'){assert.equal(JSON.parse(o.body).assignments[0].replacement_product_id,8);return json(action==='preflight'?{can_submit:true}:{status:'submitted'})}return json({status:'submitted',decision_source:'manual',assignments:[{...a,replacement_product_id:id}]})};if(id===8)assert.equal((await api.submitOriginalAllocation(input,f,'XSRF-TOKEN=fixture')).status,'submitted');else await assert.rejects(api.submitOriginalAllocation(input,f,'XSRF-TOKEN=fixture'));assert.deepEqual(calls,['preflight','manual','status'])};
await run(8);await run(99);console.log('PASS catalog, inventory, preflight, single commit and exact readback');
const gateway=fs.readFileSync(path.join(root,'gateway/vben-local-gateway.cjs'),'utf8');assert.ok(gateway.includes("url.pathname==='/logistics/wait-stock/allocation-products'"));assert.ok(gateway.includes('ERP2_ALLOCATION_CATALOG_READ_ONLY'));assert.ok(gateway.includes("url.pathname==='/settings/backup/download'"));
assert.ok(!/AIza[A-Za-z0-9_-]{35}/.test(gateway));console.log('PASS candidate gateway archive contract');
