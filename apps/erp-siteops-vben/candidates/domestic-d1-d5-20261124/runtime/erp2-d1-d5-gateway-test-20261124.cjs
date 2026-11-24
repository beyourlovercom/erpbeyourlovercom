'use strict';
const http=require('node:http'),{spawn}=require('node:child_process'),assert=require('node:assert/strict'),path=require('node:path');
const root=__dirname;
const routes=['/logistics/wait-stock/batch-allocation','/logistics/china/batch-return-allocation','/logistics/china/batch-review','/logistics/china/orders/101/carrier-service','/logistics/china/orders/101/tracking-draft','/logistics/china/orders/101/quick-ship','/logistics/china/pending-batch/tracking-import/apply','/logistics/china/4px/tracking-import/apply','/logistics/china/weship/tracking-import/apply'];
const blocked=['/logistics/oversea/batch-review','/logistics/oversea/orders/101/manual-ship','/logistics/oversea/us-moreplus/orders/101/shipout/production/send','/customers/orders/101/notes','/sync/woocommerce/tracking/push'];
(async()=>{
 const hits=[];const mock=http.createServer((req,res)=>{hits.push({path:req.url,method:req.method,xsrf:req.headers['x-xsrf-token']});req.resume();req.on('end',()=>{res.writeHead(200,{'content-type':'application/json'});res.end('{}')})});
 await new Promise(r=>mock.listen(0,'127.0.0.1',r));
 const mockPort=mock.address().port;
 const socket=http.createServer();await new Promise(r=>socket.listen(0,'127.0.0.1',r));const port=socket.address().port;await new Promise(r=>socket.close(r));
 let child,checks=0;
 const base='http://127.0.0.1:'+port;
 const req=async(p,status,options={})=>{const res=await fetch(base+p,options);assert.equal(res.status,status,p);await res.arrayBuffer();checks++};
 const post={method:'POST',headers:{'Content-Type':'application/json','X-XSRF-TOKEN':'mock-csrf'},body:'{}'};
 async function start(on){child=spawn(process.execPath,[path.join(root,'erp2-d1-d5-domestic-gateway-candidate-20261124.cjs')],{env:{...process.env,ERP2_LOGISTICS_DEV_PORT:String(port),ERP2_LOGISTICS_DEV_UPSTREAM:'http://127.0.0.1:'+mockPort,ERP2_LOGISTICS_VBEN_DIST:path.join(root,'erp2-d1-d5-candidate-dist-20261124'),ERP2_D1_D5_DOMESTIC_WRITE:on?'1':'0'},stdio:['ignore','pipe','pipe']});await new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(Error('gateway timeout')),8000);child.stdout.on('data',d=>{if(String(d).includes('READY')){clearTimeout(timer);resolve()}});child.once('exit',c=>{clearTimeout(timer);reject(Error('exit '+c))});child.stderr.on('data',d=>process.stderr.write(d))})}
 async function stop(){if(child&&!child.killed){const exit=new Promise(r=>child.once('exit',r));child.kill();await exit}child=null}
 try{
  await start(false);for(const r of routes)await req(r,405,post);await req('/logistics/china/tracking-import/preview',405,{method:'POST',headers:{'Content-Type':'multipart/form-data; boundary=X','X-XSRF-TOKEN':'mock-csrf'},body:'--X--'});assert.equal(hits.length,0);await stop();
  await start(true);for(const r of routes){await req(r,200,post);await req(r,405,{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});await req(r,405)}
  await req('/logistics/china/tracking-import/preview',200,{method:'POST',headers:{'Content-Type':'multipart/form-data; boundary=X','X-XSRF-TOKEN':'mock-csrf'},body:'--X--'});
  for(const r of blocked)await req(r,405,post);
  assert.equal(hits.length,routes.length+1);assert.ok(hits.every(x=>x.xsrf==='mock-csrf'));
  console.log('PASS '+checks+' route checks, '+hits.length+' mock hits; default off, only domestic allowlist, no external requests');
 }finally{await stop();await new Promise(r=>mock.close(r))}
})().catch(e=>{console.error(e);process.exitCode=1});
