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
