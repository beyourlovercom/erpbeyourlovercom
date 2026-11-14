'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs');
const base='http://127.0.0.1:18786';
(async()=>{
  const secret=fs.readFileSync(__dirname+'/private/local-admin-key.txt','utf8').trim();
  const auth=await fetch(base+'/api/auth/login',{method:'POST',
    headers:{'content-type':'application/json'},
    body:JSON.stringify({username:'owner',password:secret})});
  assert.equal(auth.status,200);
  const login=await auth.json();
  const headers={Authorization:'Bearer '+login.data.accessToken};
  assert.equal((await fetch(base+'/api/erp/dev/orders')).status,401);
  assert.equal((await fetch(base+'/api/erp/dev/orders',{method:'POST',headers})).status,405);
  assert.equal((await fetch(base+'/api/erp/dev/orders?view=bad',{headers})).status,400);
  const orders=await fetch(base+'/api/erp/dev/orders?page=1&per_page=50',{headers});
  assert.equal(orders.status,200);
  const response=await orders.json();
  assert.equal(response.data.source,'MAC_LOCAL_DEV_SQLITE');
  assert.equal(response.data.scope,'DEV_UAT_OWNER_ONLY');
  assert.equal(response.data.inertia.component,'Orders/Index');
  const page=response.data.inertia.props.orders;
  assert.equal(page.total,10);
  assert.equal(page.data.length,10);
  assert.ok(page.data.every(o=>o.latest_order_note===''));
  assert.ok(page.data.every(o=>!('address' in o)&&!('customer_email' in o)));
  const missing=await fetch(base+'/api/erp/dev/orders?search=R2-NOT-FOUND-STRING-999999',{headers});
  assert.equal(missing.status,200);
  const q=await missing.json();
  assert.equal(q.data.inertia.props.orders.total,0);
  const bridgeUrl=fs.readFileSync(__dirname+'/private/dev-bridge-address.txt','utf8').trim();
  const bridgeToken=fs.readFileSync(__dirname+'/private/dev-bridge-token.txt','utf8').trim();
  assert.equal((await fetch(bridgeUrl+'r2/orders')).status,403);
  assert.equal((await fetch(bridgeUrl+'r2/orders',{method:'POST',headers:{'x-byl-r2-token':bridgeToken}})).status,405);
  console.log('R2 PASS: 007 auth401, POST405, invalid400, Dev GET200, 10 rows, PII omitted, search0; Mac token403/write405');
})().catch(e=>{console.error('R2 FAIL:',e.message);process.exitCode=1});