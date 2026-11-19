'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const {createServer}=require('./erp2-original-identity-auth-only.cjs');
async function ready(fetchImpl) {
  const server=createServer({fetchImpl,distDir:__dirname});
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const base='http://127.0.0.1:'+server.address().port;
  return {server,base};
}
test('R2 gate never proxies Production business routes or dashboard',async(t)=>{
  const called=[];
  const {server,base}=await ready(async url=>{called.push(url);throw Error('SHOULD_NOT_CALL_PROD')});
  t.after(()=>new Promise(resolve=>server.close(resolve)));
  for(const p of ['/api/erp2/v1/logistics/domestic/pending','/dashboard?order=1','/api/erp2/v1/orders']) {
    const r=await fetch(base+p);
    assert.equal(r.status,403,p);
  }
  const safeDashboard=await fetch(base+'/dashboard');
  assert.equal(safeDashboard.status,200);
  assert.match(await safeDashboard.text(),/R2_LOCAL_LOGOUT_ONLY/);
  assert.equal((await fetch(base+'/dashboard',{method:'POST'})).status,403);
  const health=await(await fetch(base+'/api/health')).json();
  assert.equal(health.production_business_connected,false);
  assert.equal(called.length,0);
});
test('R2 real Laravel cookie stays server-side; redirects cannot load dashboard',async(t)=>{
  const called=[];
  let authenticated=false;
  let logoutVerified=false;
  const identity={source:'ERP_ORIGINAL_LARAVEL_PRODUCTION_SESSION_V1',
    user:{id:17,name:'Liang',email:'liang@fixture.invalid'},
    permissionMode:'role',roleKeys:['logistics'],
    effectivePermissionKeys:['logistics.view'],
    navigation:[{key:'logistics',label:'Logistics',path:'/logistics',children:[]}]};
  function response(body,status,type,cookies=[]) {
    const headers=new Headers({'content-type':type});
    for(const item of cookies)headers.append('set-cookie',item);
    return new Response(body,{status,headers});
  }
  const fake=async(url,options)=>{
    called.push({url,method:options.method,cookie:options.headers.Cookie||'',redirect:options.redirect});
    assert.ok(url.startsWith('https://erp.beyourlover.com/'));
    assert.equal(options.redirect,'manual');
    if(url.endsWith('/login')&&options.method==='GET')
      return response('<meta name="csrf-token" content="fixture">',200,'text/html',[
        'laravel_session=guest-session; Path=/; HttpOnly',
        'XSRF-TOKEN=guest-xsrf; Path=/']);
    if(url.endsWith('/login')&&options.method==='POST') {
      assert.match(options.headers.Cookie,/laravel_session=guest-session/);
      authenticated=true;
      return response('',302,'text/html',[
        'laravel_session=staff-session; Path=/; HttpOnly',
        'XSRF-TOKEN=staff-xsrf; Path=/']);
    }
    if(url.endsWith('/logout')&&options.method==='POST') {
      assert.equal(options.headers['X-XSRF-TOKEN'],'staff-xsrf');
      assert.equal(options.headers['X-CSRF-TOKEN'],undefined);
      authenticated=false;
      return response('',302,'text/html');
    }
    if(url.endsWith('/_erp2/identity')&&authenticated)
      return response(JSON.stringify(identity),200,'application/json');
    if(url.endsWith('/_erp2/identity')&&!authenticated) {
      logoutVerified=true;
      return response('{}',401,'application/json');
    }
    throw Error('PROD_ROUTE_OUTSIDE_ALLOWLIST');
  };
  const {server,base}=await ready(fake);
  t.after(()=>new Promise(resolve=>server.close(resolve)));
  assert.equal((await fetch(base+'/_erp2/identity')).status,401);
  const login=await fetch(base+'/login');
  assert.equal(login.status,200);
  const cookie=login.headers.get('set-cookie').split(';')[0];
  assert.match(cookie,/^ERP2_R2_HANDLE=/);
  assert.doesNotMatch(login.headers.get('set-cookie'),/laravel_session|XSRF-TOKEN/);
  const post=await fetch(base+'/login',{method:'POST',headers:{
    Cookie:cookie,'X-CSRF-TOKEN':'fixture','Content-Type':'application/x-www-form-urlencoded'},
    body:'email=liang%40fixture.invalid&password=fixture'});
  assert.equal(post.status,200);
  const proof=await fetch(base+'/_erp2/identity',{headers:{Cookie:cookie}});
  assert.equal(proof.status,200);
  assert.equal((await proof.json()).user.id,17);
  assert.equal((await fetch(base+'/_erp2/identity?user_id=999',{headers:{Cookie:cookie}})).status,405);
  const logout=await fetch(base+'/logout',{method:'POST',headers:{Cookie:cookie,'X-CSRF-TOKEN':'R2_LOCAL_LOGOUT_ONLY'}});
  assert.equal(logout.status,200);
  assert.equal(logoutVerified,true);
  assert.equal((await fetch(base+'/_erp2/identity',{headers:{Cookie:cookie}})).status,401);
  assert.equal(called.some(x=>x.url.endsWith('/dashboard')),false);
});
