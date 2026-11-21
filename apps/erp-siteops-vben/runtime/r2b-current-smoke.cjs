'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const base='http://127.0.0.1:18786';
(async()=>{
 const key=fs.readFileSync(path.join(__dirname,'private/local-admin-key.txt'),'utf8').trim();
 const login=await fetch(base+'/api/auth/login',{method:'POST',
  headers:{'content-type':'application/json'},body:JSON.stringify({username:'owner',password:key})});
 assert.equal(login.status,200);
 const token=(await login.json()).data.accessToken;
 const auth={Authorization:'Bearer '+token};
 assert.equal((await fetch(base+'/api/erp/current/orders')).status,401);
 assert.equal((await fetch(base+'/api/erp/current/orders',{method:'POST',headers:auth})).status,405);
 assert.equal((await fetch(base+'/api/erp/current/orders?role=invalid',{headers:auth})).status,400);
 const roles=[['owner',123],['staff',123],['kol',20],['manager',41]];
 for(const [role,count] of roles){
  const response=await fetch(base+'/api/erp/current/orders?role='+role,{headers:auth,signal:AbortSignal.timeout(25000)});
  assert.equal(response.status,200,role+' status '+response.status);
  const {data}=await response.json();
  assert.equal(data.source,'CURRENT_LARAVEL_MAIN_DEV_B07885E3');
  assert.equal(data.authenticatedByLaravel,true);
  assert.equal(data.inertia.props.orders.total,count,role+' scope');
  const rows=data.inertia.props.orders.data;
  assert.ok(rows.length>0);
  assert.ok(rows.every(o=>!('address' in o)&&!('customer_email' in o)&&o.latest_order_note===''));
  const nav=data.inertia.props.shellNavigation;
  assert.ok(Array.isArray(nav));
  if(role==='owner')assert.ok(nav.some(x=>x.key==='inventory'&&x.children.some(y=>y.key.startsWith('inventory-warehouse-'))));
  if(role==='kol'||role==='manager')assert.ok(!nav.some(x=>x.key==='inventory'));
  console.log('GATEWAY_ROLE_PASS',role,'total',count,'rows',rows.length,'nav',nav.length);
 }
 const customer=await fetch(base+'/api/erp/current/orders?role=owner&scope=customers&per_page=20',{headers:auth});
 const obj=(await customer.json()).data;
 assert.equal(obj.inertia.props.orders.per_page,20);
 console.log('R2B_API_PASS authenticated 4 roles, KOL scope, PII strip, archive filter, Dev warehouses, customer20, deny401/405/400');
})().catch(e=>{console.error('R2B_API_FAIL',e.message);process.exitCode=1});