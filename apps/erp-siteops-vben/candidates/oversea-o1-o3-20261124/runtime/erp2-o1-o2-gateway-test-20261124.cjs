'use strict';
const http=require('node:http'),{spawn}=require('node:child_process'),assert=require('node:assert/strict'),path=require('node:path');
(async()=>{
 const hits=[];let child;const mock=http.createServer((req,res)=>{hits.push({path:req.url,method:req.method,xsrf:req.headers['x-xsrf-token']});req.resume();req.on('end',()=>{res.writeHead(200,{'content-type':'application/json','content-disposition':'attachment; filename="mock.xlsx"'});res.end('{}')})});
 await new Promise(r=>mock.listen(0,'127.0.0.1',r));const mockPort=mock.address().port;
 const socket=http.createServer();await new Promise(r=>socket.listen(0,'127.0.0.1',r));const port=socket.address().port;await new Promise(r=>socket.close(r));let checks=0;
 const base='http://127.0.0.1:'+port;
 const post={method:'POST',headers:{'Content-Type':'application/json','X-XSRF-TOKEN':'mock-csrf'},body:'{}'},multi={method:'POST',headers:{'Content-Type':'multipart/form-data; boundary=X','X-XSRF-TOKEN':'mock-csrf'},body:'--X--'};
 const routes=['/logistics/oversea/batch-review','/logistics/oversea/batch-return-allocation','/logistics/oversea/orders/101/manual-ship','/logistics/oversea/us-3sm/orders/101/manual-ship','/logistics/oversea/us-moreplus/orders/101/manual-ship'];
 const warehouses=['us-ny','us-moreplus','us-3sm','de-tianyun','ca'];
 const req=async(p,status,options={})=>{const res=await fetch(base+p,options);assert.equal(res.status,status,p);await res.arrayBuffer();checks++;return res};
 async function start(on){child=spawn(process.execPath,[path.join(__dirname,'erp2-o1-o2-gateway-candidate-20261124.cjs')],{env:{...process.env,ERP2_LOGISTICS_DEV_PORT:String(port),ERP2_LOGISTICS_DEV_UPSTREAM:'http://127.0.0.1:'+mockPort,ERP2_LOGISTICS_VBEN_DIST:path.join(__dirname,'erp2-d1-d5-manual-final-dist-20261124'),ERP2_D1_D5_DOMESTIC_WRITE:'0',ERP2_O1_O2_OVERSEA_WRITE:on?'1':'0'},stdio:['ignore','pipe','pipe']});await new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(Error('gateway timeout')),8000);child.stdout.on('data',d=>{if(String(d).includes('READY')){clearTimeout(timer);resolve()}});child.once('exit',c=>{clearTimeout(timer);reject(Error('exit '+c))});child.stderr.on('data',d=>process.stderr.write(d))})}
 async function stop(){if(child&&!child.killed){const e=new Promise(r=>child.once('exit',r));child.kill();await e}child=null}
 try{
  await start(false);for(const r of routes)await req(r,405,post);for(const w of warehouses){await req('/logistics/oversea/'+w+'/tracking-import/preview',405,multi);await req('/logistics/oversea/'+w+'/tracking-import/apply',405,post)}assert.equal(hits.length,0);await stop();
  await start(true);
  for(const r of routes){await req(r,200,post);await req(r,405);await req(r,405,{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'})}
  for(const w of warehouses){const p='/logistics/oversea/'+w;const file=await req(p+'/export?order_ids%5B%5D=101',200);assert.equal(file.headers.get('content-disposition'),'attachment; filename="mock.xlsx"');await req(p+'/export',405,post);await req(p+'/tracking-import/preview',200,multi);await req(p+'/tracking-import/preview',405,post);await req(p+'/tracking-import/apply',200,post);await req(p+'/tracking-import/apply',405,multi)}
  for(const r of ['/logistics/oversea/us-moreplus/orders/101/shipout/production/send','/logistics/oversea/de-tianyun/xlwms/batch/start','/logistics/oversea/us-3sm/orders/101/xlwms/send','/logistics/china/batch-review','/logistics/oversea/all/export'])await req(r,405,post);
  assert.equal(hits.length,20);assert.ok(hits.filter(x=>x.method==='POST').every(x=>x.xsrf==='mock-csrf'));console.log('PASS '+checks+' checks, '+hits.length+' mock upstream requests; OMS routes denied');
 }finally{await stop();await new Promise(r=>mock.close(r))}
})().catch(e=>{console.error(e);process.exitCode=1});
