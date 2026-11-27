'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict');
const http=require('node:http'),{spawn}=require('node:child_process'),path=require('node:path');
const {setTimeout:delay}=require('node:timers/promises');
const src=path.join(__dirname,'erp2-logistics-dev-gateway.cjs');
const listen=s=>new Promise(resolve=>s.listen(0,'127.0.0.1',()=>resolve(s.address().port)));
const close=s=>new Promise(resolve=>s.close(resolve));
test('P0 only allows authenticated Laravel domestic tracking preview multipart; Apply stays denied',async t=>{
 const requests=[];
 const upstream=http.createServer(async(req,res)=>{
  const chunks=[];for await(const c of req)chunks.push(c);
  requests.push({method:req.method,path:req.url,ctype:req.headers['content-type'],
    token:req.headers['x-xsrf-token'],bytes:Buffer.concat(chunks).length});
  res.writeHead(200,{'content-type':'application/json'});
  res.end(JSON.stringify({provider:'4px',apply_count:0,noop_count:0,blocked_count:1,message:'preview only'}));
 });
 const upPort=await listen(upstream);t.after(()=>close(upstream));
 const holder=http.createServer(),port=await listen(holder);await close(holder);
 const proc=spawn(process.execPath,[src],{env:{...process.env,
  ERP2_LOGISTICS_DEV_PORT:String(port),ERP2_LOGISTICS_DEV_UPSTREAM:'http://127.0.0.1:'+upPort,
  ERP2_LOGISTICS_VBEN_DIST:'/tmp'},stdio:'ignore'});
 t.after(()=>proc.kill());
 const base='http://127.0.0.1:'+port;
 let ready=false;for(let i=0;i<70;i++){try{if((await fetch(base+'/api/health')).ok){ready=true;break;}}catch{}await delay(40)}
 assert.equal(ready,true);
 const preview='/logistics/china/tracking-import/preview';
 const data=Buffer.from('--erp2-test\r\nContent-Disposition: form-data; name="file"; filename="demo.xlsx"\r\nContent-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\nPK-fake\r\n--erp2-test--\r\n');
 const resp=await fetch(base+preview,{method:'POST',headers:{'Content-Type':'multipart/form-data; boundary=erp2-test','X-XSRF-TOKEN':'test-only',
  'Accept':'application/json'},body:data});
 assert.equal(resp.status,200);
 assert.deepEqual((await resp.json()).provider,'4px');
 assert.equal(requests[0]?.method,'POST');
 assert.equal(requests[0]?.token,'test-only');
 assert.equal(requests[0]?.bytes,data.length);
 const denied=[
  fetch(base+preview,{method:'POST',headers:{'Content-Type':'multipart/form-data; boundary=erp2-test'},body:data}),
  fetch(base+preview,{method:'GET'}),
  fetch(base+'/logistics/china/pending-batch/tracking-import/apply',{method:'POST',
    headers:{'Content-Type':'application/json','X-XSRF-TOKEN':'test-only'},body:'{"token":"invalid"}'}),
  fetch(base+'/logistics/china/4px/tracking-import/apply',{method:'POST',
    headers:{'Content-Type':'application/json','X-XSRF-TOKEN':'test-only'},body:'{"token":"invalid"}'}),
 ];
 for(const promise of denied)assert.equal((await promise).status,405);
 assert.equal(requests.length,1,'No Apply reaches Laravel');
});
