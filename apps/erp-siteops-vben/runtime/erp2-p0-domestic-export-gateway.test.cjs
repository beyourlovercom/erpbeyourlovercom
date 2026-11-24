'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict');
const http=require('node:http'),{spawn}=require('node:child_process'),path=require('node:path');
const {setTimeout:delay}=require('node:timers/promises');
const gateway=path.join(__dirname,'erp2-logistics-dev-gateway.cjs');
const listen=s=>new Promise(resolve=>s.listen(0,'127.0.0.1',()=>resolve(s.address().port)));
const close=s=>new Promise(resolve=>s.close(resolve));
test('P0 domestic export preserves Laravel XLSX attachments; rejects unknown/mutation paths',async t=>{
 const sample=Buffer.from('fake-test-xlsx-only');
 const seen=[];
 const up=http.createServer((req,res)=>{
  seen.push({method:req.method,url:req.url});
  res.writeHead(200,{'content-type':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
   'content-disposition':'attachment; filename="007-4px-orders-261124.xlsx"',
   'x-byl-exported-orders':'3','x-byl-blocked-orders':'1'});
  res.end(sample);
 });
 const upPort=await listen(up);t.after(()=>close(up));
 const placeholder=http.createServer(),port=await listen(placeholder);await close(placeholder);
 const child=spawn(process.execPath,[gateway],{env:{...process.env,
  ERP2_LOGISTICS_DEV_UPSTREAM:'http://127.0.0.1:'+upPort,
  ERP2_LOGISTICS_DEV_PORT:String(port),ERP2_LOGISTICS_VBEN_DIST:'/tmp',
 },stdio:'ignore'});
 t.after(()=>child.kill());
 const base='http://127.0.0.1:'+port;
 let ready=false;
 for(let k=0;k<80;k++){try{if((await fetch(base+'/api/health')).ok){ready=true;break;}}catch{}await delay(30);}
 assert.equal(ready,true);
 for(const url of ['/logistics/china/today/export','/logistics/china/export/4px',
  '/logistics/china/export/weship','/logistics/china/pending-batch/export-sku-merged',
  '/logistics/china/pending-batch/export?order_ids%5B%5D=42']){
  const r=await fetch(base+url);
  assert.equal(r.status,200,url);
  assert.equal(r.headers.get('content-disposition'),'attachment; filename="007-4px-orders-261124.xlsx"');
  assert.equal(r.headers.get('x-byl-exported-orders'),'3');
  assert.equal(r.headers.get('x-byl-blocked-orders'),'1');
  assert.deepEqual(Buffer.from(await r.arrayBuffer()),sample);
 }
 assert.equal(seen.length,5);
 for(const url of ['/logistics/china/export/unknown','/logistics/china/tracking-import/preview']){
  assert.equal((await fetch(base+url)).status,405);
 }
 assert.equal((await fetch(base+'/logistics/china/export/4px',{method:'POST'})).status,405);
 assert.equal(seen.length,5,'never proxy unapproved operation');
});
