'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const gateway = path.join(__dirname, 'erp2-logistics-dev-gateway.cjs');
function refused(upstream, dist = '/tmp/erp2-logistics-dev-test') {
  const env = { ...process.env, ERP2_LOGISTICS_VBEN_DIST: dist };
  delete env.ERP2_LOGISTICS_DEV_UPSTREAM;
  if (upstream !== undefined) env.ERP2_LOGISTICS_DEV_UPSTREAM = upstream;
  return spawnSync(process.execPath, [gateway], {env, encoding: 'utf8', timeout: 3000});
}
test('gateway refuses missing explicit Dev upstream', () => {
  const r = refused(undefined);
  assert.equal(r.status, 1);
  assert.match(r.stderr, /ERP2_DEV_UPSTREAM_REQUIRED/);
});
test('gateway refuses Production and arbitrary Internet upstreams', () => {
  for (const host of ['https://erp.beyourlover.com', 'http://example.com', 'http://192.168.1.7']) {
    const r = refused(host);
    assert.equal(r.status, 1);
    assert.match(r.stderr, /ERP2_DEV_UPSTREAM_MUST_BE_LOCAL_OR_TAILNET/);
  }
});
test('gateway rejects non-absolute build paths', () => {
  const r = refused('http://127.0.0.1:18811', './dist');
  assert.equal(r.status, 1);
  assert.match(r.stderr, /ERP2_DEV_PORT_OR_ABSOLUTE_DIST_REQUIRED/);
});

// Live loopback proxy contract: never test against any real ERP business server.
const http = require('node:http');
const { spawn } = require('node:child_process');
const { setTimeout: delay } = require('node:timers/promises');
const listen = (server) => new Promise((resolve) =>
  server.listen(0, '127.0.0.1', () => resolve(server.address().port)));
const close = (server) => new Promise((resolve) => server.close(resolve));
test('isolated gateway forwards only authorized Inertia Orders GET requests', async (t) => {
  const observed = [];
  const upstream = http.createServer((req, res) => {
    observed.push({path:req.url,method:req.method,inertia:req.headers['x-inertia']});
    if (!req.headers.cookie) {
      res.writeHead(401, {'content-type':'application/json'});
      return res.end(JSON.stringify({error:'UNAUTHENTICATED'}));
    }
    res.writeHead(200, {'content-type':'application/json'});
    res.end(JSON.stringify({component:req.url.startsWith('/orders?') ? 'Orders/Master' : 'Orders/Index', props:{orders:{data:[]}}}));
  });
  const upstreamPort = await listen(upstream);
  t.after(() => close(upstream));
  const reserved = http.createServer();
  const gatewayPort = await listen(reserved);
  await close(reserved);
  const child = spawn(process.execPath, [gateway], {env:{...process.env,
    ERP2_LOGISTICS_DEV_UPSTREAM:'http://127.0.0.1:'+upstreamPort,
    ERP2_LOGISTICS_DEV_PORT:String(gatewayPort),ERP2_LOGISTICS_VBEN_DIST:'/tmp'},
    stdio:'ignore'});
  t.after(() => {if (!child.killed) child.kill();});
  const base='http://127.0.0.1:'+gatewayPort;
  let ready=false;
  for (let i=0;i<60;i++) {
    try {const r=await fetch(base+'/api/health');if (r.status===200){ready=true;break;}}
    catch {}
    await delay(50);
  }
  assert.ok(ready, 'isolated gateway must become healthy');
  for (const path of ['/orders?search=test','/customers/orders?page=2','/kol/orders?page=3']) {
    const denied=await fetch(base+path,{headers:{'X-Inertia':'true'}});
    assert.equal(denied.status,401,'no session cannot see orders');
    const allowed=await fetch(base+path,{headers:{'X-Inertia':'true',Cookie:'dev-session=fake'}});
    assert.equal(allowed.status,200);
    assert.ok((await allowed.json()).component.startsWith('Orders/'));
    assert.equal((await fetch(base+path,{method:'POST',headers:{'X-Inertia':'true'}})).status,405);
    assert.equal((await fetch(base+path)).status,405,'plain requests cannot bypass SPA route');
  }
  assert.equal((await fetch(base+'/api/erp2/v1/private?x=1')).status,405);
  assert.equal((await fetch(base+'/orders/create',{headers:{'X-Inertia':'true'}})).status,405);
  assert.equal(observed.length,6,'only exact allowlisted GETs reached the fake upstream');
  assert.ok(observed.every((req) => req.method==='GET' && req.inertia==='true'));
});
test('M2 only permits exact original review POST under explicit isolated Dev flag',async(t)=>{
  const observed=[];
  const upstream=http.createServer((req,res)=>{
    observed.push({path:req.url,method:req.method,
      inertia:req.headers['x-inertia'],xsrf:req.headers['x-xsrf-token']});
    res.writeHead(200,{'content-type':'application/json'});
    res.end(JSON.stringify(req.method==='POST'?
      {results:[{order_id:10,ok:true,message:'已处理'}],success_count:1,failed_count:0}:
      {component:req.url.startsWith('/logistics/china')?'Logistics/Domestic/Index':'Logistics/Oversea/Show',
        props:{rows:{data:[],current_page:1,per_page:20,total:0,last_page:1}}}));
  });
  const upstreamPort=await listen(upstream);
  t.after(()=>close(upstream));
  const reserved=http.createServer(),gatewayPort=await listen(reserved);
  await close(reserved);
  const child=spawn(process.execPath,[gateway],{env:{...process.env,
    ERP2_LOGISTICS_DEV_UPSTREAM:'http://127.0.0.1:'+upstreamPort,
    ERP2_LOGISTICS_DEV_PORT:String(gatewayPort),ERP2_LOGISTICS_VBEN_DIST:'/tmp',
    ERP2_M2_DEV_REVIEW_ENABLED:'1'},stdio:'ignore'});
  t.after(()=>{if(!child.killed)child.kill();});
  const base='http://127.0.0.1:'+gatewayPort;
  let ready=false;
  for(let i=0;i<60;i++){
    try{const r=await fetch(base+'/api/health');if(r.ok){ready=true;break;}}catch{}
    await delay(50);
  }
  assert.ok(ready);
  for(const target of ['/logistics/china?view=review','/logistics/oversea?view=review',
    '/logistics/oversea/us-ny?view=pending']){
    assert.equal((await fetch(base+target)).status,405);
    assert.equal((await fetch(base+target,{headers:{'X-Inertia':'true'}})).status,200);
  }
  for(const target of ['/logistics/china/batch-review','/logistics/oversea/batch-review']){
    assert.equal((await fetch(base+target,{method:'POST'})).status,405);
    assert.equal((await fetch(base+target,{method:'POST',headers:{'Content-Type':'application/json'}})).status,405);
    const result=await fetch(base+target,{method:'POST',headers:{
      'Content-Type':'application/json','X-XSRF-TOKEN':'test-dev-token'},body:JSON.stringify({
      order_ids:[10],action:'approve'})});
    assert.equal(result.status,200);
    assert.equal((await result.json()).success_count,1);
  }
  for(const target of ['/logistics/china/orders/10/quick-ship',
    '/logistics/oversea/orders/10/manual-ship','/logistics/oversea/bulk-ship/apply']){
    assert.equal((await fetch(base+target,{method:'POST',headers:{
      'Content-Type':'application/json','X-XSRF-TOKEN':'test'}})).status,405);
  }
  assert.equal(observed.filter(row=>row.method==='POST').length,2);
  assert.ok(observed.filter(row=>row.method==='POST').every(row=>row.xsrf==='test-dev-token'));
});

test('M2 Dev shipping permits only original manual/draft paths, never OMS or Woo',async t=>{
  const observed=[];
  const upstream=http.createServer((req,res)=>{
    observed.push({path:req.url,method:req.method,xsrf:req.headers['x-xsrf-token']});
    res.writeHead(302,{location:'/logistics/china?view=pending'});res.end();
  });
  const upstreamPort=await listen(upstream);
  t.after(()=>close(upstream));
  const reserved=http.createServer(),gatewayPort=await listen(reserved);
  await close(reserved);
  const child=spawn(process.execPath,[gateway],{env:{...process.env,
    ERP2_LOGISTICS_DEV_UPSTREAM:'http://127.0.0.1:'+upstreamPort,
    ERP2_LOGISTICS_DEV_PORT:String(gatewayPort),ERP2_LOGISTICS_VBEN_DIST:'/tmp',
    ERP2_M2_DEV_REVIEW_ENABLED:'1',ERP2_M2_DEV_SHIPPING_ENABLED:'1'},stdio:'ignore'});
  t.after(()=>{if(!child.killed)child.kill();});
  const base='http://127.0.0.1:'+gatewayPort;
  let ready=false;
  for(let i=0;i<60;i++){
    try{const r=await fetch(base+'/api/health');if(r.ok){ready=true;break;}}catch{}
    await delay(50);
  }
  assert.ok(ready);
  const accepted=[
    '/logistics/china/orders/10/carrier-service',
    '/logistics/china/orders/10/tracking-draft',
    '/logistics/china/orders/10/quick-ship',
    '/logistics/oversea/orders/10/manual-ship',
    '/logistics/oversea/us-3sm/orders/10/manual-ship',
    '/logistics/oversea/us-moreplus/orders/10/manual-ship',
  ];
  for(const target of accepted){
    assert.equal((await fetch(base+target,{method:'POST',redirect:'manual',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':'token'},
      body:'{}'})).status,302);
    assert.equal((await fetch(base+target,{method:'POST',
      headers:{'Content-Type':'application/json'},body:'{}'})).status,405);
    assert.equal((await fetch(base+target,{method:'GET'})).status,405);
  }
  for(const target of ['/logistics/oversea/us-3sm/orders/10/xlwms/send',
    '/logistics/oversea/bulk-ship/apply','/logistics/oversea/us-moreplus/orders/10/oms/create',
    '/api/woocommerce/ship','/logistics/china/orders/0/quick-ship',
    '/logistics/china/orders/10/quick-ship/extra']){
    assert.equal((await fetch(base+target,{method:'POST',headers:{
      'Content-Type':'application/json','X-XSRF-TOKEN':'token'},body:'{}'})).status,405);
  }
  assert.equal(observed.length,accepted.length);
  assert.ok(observed.every(r=>r.method==='POST'&&r.xsrf==='token'));
});
