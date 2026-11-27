'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const http=require('node:http');
const {spawn}=require('node:child_process');
const {setTimeout:delay}=require('node:timers/promises');
const path=require('node:path');
const gateway=path.join(__dirname,'erp2-logistics-dev-gateway.cjs');
const listen=s=>new Promise(r=>s.listen(0,'127.0.0.1',()=>r(s.address().port)));
const close=s=>new Promise(r=>s.close(r));
test('Original Laravel account-scoped Grid preferences are allowlisted, user auth stays authoritative',async t=>{
 const seen=[];
 const upstream=http.createServer(async(req,res)=>{
  const chunks=[];for await(const b of req)chunks.push(b);
  seen.push({method:req.method,path:req.url,xsrf:req.headers['x-xsrf-token']||null,body:Buffer.concat(chunks).toString()});
  res.writeHead(200,{'content-type':'application/json'});
  res.end(JSON.stringify({value:{density:'compact',columns:[]}}));
 });
 const upstreamPort=await listen(upstream);t.after(()=>close(upstream));
 const holder=http.createServer(),port=await listen(holder);await close(holder);
 const proc=spawn(process.execPath,[gateway],{env:{...process.env,
  ERP2_LOGISTICS_DEV_UPSTREAM:'http://127.0.0.1:'+upstreamPort,
  ERP2_LOGISTICS_DEV_PORT:String(port),ERP2_LOGISTICS_VBEN_DIST:'/tmp',
 },stdio:'ignore'});
 t.after(()=>proc.kill());
 const url='http://127.0.0.1:'+port;
 let ready=false;
 for(let i=0;i<60;i++){try{if((await fetch(url+'/api/health')).ok){ready=true;break;}}catch{}await delay(50);}
 assert.equal(ready,true);
 const valid='/ui/preferences/grid.erp2.logistics.overseas.v1';
 assert.equal((await fetch(url+valid)).status,200);
 assert.equal((await fetch(url+valid,{method:'PUT',headers:{'Content-Type':'application/json'},body:'{"value":{}}'})).status,405);
 const put=await fetch(url+valid,{method:'PUT',headers:{'Content-Type':'application/json','X-XSRF-TOKEN':'dev-test-only'},body:JSON.stringify({value:{density:'compact'}})});
 assert.equal(put.status,200);
 assert.equal(seen[1].xsrf,'dev-test-only');
 assert.deepEqual(JSON.parse(seen[1].body),{value:{density:'compact'}});
 for(const target of ['/ui/preferences/orders.secret','/ui/preferences/grid.foo%2Fadmin','/ui/preferences/not-grid']){
   await fetch(url+target);
   assert.equal(seen.length,2,'unknown preference URL must never reach Laravel');
 }
 assert.equal((await fetch(url+'/ui/preferences/grid.foo',{method:'DELETE'})).status,405);
 assert.equal((await fetch(url+'/ui/preferences/grid.foo',{method:'POST',headers:{'X-XSRF-TOKEN':'token'}})).status,405);
 assert.equal(seen.length,2,'only approved GET and PUT reach Laravel');
});
