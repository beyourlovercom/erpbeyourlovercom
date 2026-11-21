'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const {once} = require('node:events');
const path = require('node:path');
const {createServer} = require('./p0-canonical-proxy.cjs');

async function listen(server) {
  server.listen(0, '127.0.0.1');
  await once(server,'listening');
  return `http://127.0.0.1:${server.address().port}`;
}
async function close(server) {
  await new Promise((resolve,reject) => server.close(e => e ? reject(e) : resolve()));
}

test('only the original Laravel web session is accepted; business writes are denied', async () => {
  const seen = [];
  const laravel = http.createServer(async (req,res) => {
    seen.push(`${req.method} ${req.url}`);
    if (req.url === '/login' && req.method === 'GET') {
      res.writeHead(200, {'content-type':'text/html',
        'set-cookie':['XSRF-TOKEN=dev-token; Path=/','laravel_session=pre; HttpOnly; Path=/']});
      return res.end('<meta name="csrf-token" content="dev-csrf">');
    }
    if (req.url === '/login' && req.method === 'POST') {
      let body = '';
      for await (const chunk of req) body += chunk;
      assert.match(body,/email=staff%40local.invalid/);
      assert.equal(req.headers['x-csrf-token'],'dev-csrf');
      assert.match(req.headers.cookie,/laravel_session=pre/);
      res.writeHead(302, {'location':'/dashboard','set-cookie':'laravel_session=active; HttpOnly; Path=/'});
      return res.end();
    }
    if (req.url === '/_erp2/identity') {
      const active = String(req.headers.cookie || '').includes('laravel_session=active');
      res.writeHead(active ? 200 : 401,{'content-type':'application/json'});
      return res.end(JSON.stringify(active ? {source:'ERP_CANONICAL_LARAVEL_DEV_SESSION_V1',
        user:{id:18,name:'Staff',email:'staff@local.invalid'},permissionMode:'role',
        roleKeys:['role_preset_operations'],effectivePermissionKeys:['orders.view'],
        navigation:[]} : {}));
    }
    if (req.url === '/orders?per_page=20') {
      assert.match(req.headers.cookie,/laravel_session=active/);
      res.writeHead(200,{'content-type':'application/json'});
      return res.end(JSON.stringify({component:'Orders/Index',props:{orders:{data:[],total:0}}}));
    }
    return res.writeHead(404).end();
  });
  const upstream = await listen(laravel);
  const gateway = createServer({upstream,distDir:path.join(__dirname,'nonexistent-dist'),
    host:'127.0.0.1',port:0,allowedUpstreams:[upstream],loginEnabled:true});
  const preview = await listen(gateway);
  try {
    let response = await fetch(preview+'/_erp2/identity');
    assert.equal(response.status,401);
    response = await fetch(preview+'/login');
    assert.equal(response.status,200);
    assert.equal(response.headers.getSetCookie().length,2);
    response = await fetch(preview+'/login',{method:'POST',redirect:'manual',
      headers:{Cookie:'laravel_session=pre','Content-Type':'application/x-www-form-urlencoded',
        'X-CSRF-TOKEN':'dev-csrf'},
      body:new URLSearchParams({email:'staff@local.invalid',password:'test-only'}).toString()});
    assert.equal(response.status,302);
    assert.equal(response.headers.get('location'),'/dashboard');
    assert.match(response.headers.getSetCookie()[0],/laravel_session=active/);
    response = await fetch(preview+'/_erp2/identity',{headers:{Cookie:'laravel_session=active'}});
    assert.equal(response.status,200);
    assert.equal((await response.json()).user.id,18);
    response = await fetch(preview+'/orders?per_page=20',{headers:{Cookie:'laravel_session=active'}});
    assert.equal(response.status,200);
    response = await fetch(preview+'/orders',{method:'POST',body:'malicious'});
    assert.equal(response.status,405);
    response = await fetch(preview+'/api/auth/login',{method:'POST',body:'malicious'});
    assert.equal(response.status,405);
    response = await fetch(preview+'/login',{method:'POST',
      headers:{Origin:'https://example.com','Content-Type':'application/x-www-form-urlencoded'},body:'x=y'});
    assert.equal(response.status,403);
    assert.equal(seen.filter(x => x.startsWith('POST /orders')).length,0);
  } finally { await close(gateway); await close(laravel); }
});

test('non-approved or Production Laravel authority is refused at startup', () => {
  assert.throws(() => createServer({upstream:'https://erp.beyourlover.com',distDir:__dirname}),
    /UNAPPROVED_LARAVEL_DEV_ORIGIN/);
});

test('login remains disabled until a genuine Dev staff authority is provisioned', async () => {
  const upstream = 'http://127.0.0.1:18931';
  const gateway = createServer({upstream,distDir:__dirname,
    host:'127.0.0.1',port:0,allowedUpstreams:[upstream]});
  const preview = await listen(gateway);
  try {
    const response = await fetch(preview+'/login',{method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'email=someone&password=secret'});
    assert.equal(response.status,503);
  } finally { await close(gateway); }
});
