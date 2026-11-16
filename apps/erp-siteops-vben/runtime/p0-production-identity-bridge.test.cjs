'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const {once} = require('node:events');
const {createBridge} = require('./p0-production-identity-bridge.cjs');

async function listen(server) {
  server.listen(0,'127.0.0.1');
  await once(server,'listening');
  return `http://127.0.0.1:${server.address().port}`;
}
async function close(server) { await new Promise((resolve,reject)=>server.close(e=>e?reject(e):resolve())); }
const user={id:18,name:'Vita',email:'vita@beyourlover.com'};
const profile=`<html><head><meta name="csrf-token" content="csrf-after-login"></head><body><script data-page="app">${JSON.stringify({component:'Identity/Account/Profile',props:{auth:{user},profile:{phone:'NEVER_RETURN_TO_BROWSER'},shellNavigation:[]}})}</script></body></html>`;

test('original auth session stays server-side; only verified effective rights reach ERP2',async()=>{
  const seen=[];
  let permissions=['orders.view','orders.edit'];
  const upstream=http.createServer(async(req,res)=>{
    seen.push(`${req.method} ${req.url}`);
    if(req.url==='/login'&&req.method==='GET'){
      res.writeHead(200,{'content-type':'text/html','set-cookie':'laravel_session=pre; HttpOnly; Secure; Path=/'});
      return res.end('<meta name="csrf-token" content="csrf-before-login">');
    }
    if(req.url==='/login'&&req.method==='POST'){
      let body='';for await(const chunk of req)body+=chunk;
      assert.equal(req.headers['x-csrf-token'],'csrf-before-login');
      assert.match(req.headers.cookie,/laravel_session=pre/);
      assert.match(body,/email=Vita/);
      res.writeHead(302,{'location':'/dashboard','set-cookie':'laravel_session=active; HttpOnly; Secure; Path=/'});
      return res.end();
    }
    if(req.url==='/account/profile'){
      if(!String(req.headers.cookie).includes('laravel_session=active'))return res.writeHead(302).end();
      res.writeHead(200,{'content-type':'text/html'});return res.end(profile);
    }
    if(req.url==='/_erp2/identity'){
      res.writeHead(200,{'content-type':'application/json'});
      return res.end(JSON.stringify({source:'ERP_CANONICAL_LARAVEL_DEV_SESSION_V1',user,
        roleKeys:['role_preset_operations'],permissionMode:'role',
        effectivePermissionKeys:permissions,navigation:[{key:'orders',label:'订单',path:'/orders',children:[]}]}));
    }
    if(req.url==='/logout')return res.writeHead(302,{'location':'/login'}).end();
    return res.writeHead(404).end();
  });
  const base=await listen(upstream);
  const bridge=createBridge({upstream:base,allowedUpstreams:[base],enableCredentials:true,distDir:__dirname,port:0});
  const origin=await listen(bridge);
  try{
    let res=await fetch(origin+'/login');
    assert.equal(res.status,200);
    assert.match(await res.text(),/csrf-before-login/);
    const localCookie=res.headers.getSetCookie()[0].split(';')[0];
    assert.match(localCookie,/^erp2_identity_dev=/);
    assert.doesNotMatch(localCookie,/laravel_session/);
    res=await fetch(origin+'/login',{method:'POST',headers:{Cookie:localCookie,
      'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':'csrf-before-login'},
      body:new URLSearchParams({email:'Vita',password:'fixture-only'}).toString()});
    assert.equal(res.status,200);
    const nextCookie=res.headers.getSetCookie()[0].split(';')[0];
    assert.doesNotMatch(nextCookie,/laravel_session/);
    const initial=await fetch(origin+'/_erp2/identity',{headers:{Cookie:nextCookie}});
    assert.equal(initial.status,200);
    const result=await initial.json();
    assert.deepEqual(result.effectivePermissionKeys,permissions);
    assert.ok(!JSON.stringify(result).includes('NEVER_RETURN_TO_BROWSER'));
    permissions=['orders.view'];
    res=await fetch(origin+'/_erp2/identity',{headers:{Cookie:nextCookie}});
    assert.deepEqual((await res.json()).effectivePermissionKeys,['orders.view']);
    res=await fetch(origin+'/orders',{headers:{Cookie:nextCookie}});
    assert.equal(res.status,405);
    res=await fetch(origin+'/api/erp/current/orders',{headers:{Cookie:nextCookie}});
    assert.equal(res.status,405);
    assert.ok(seen.every(s=>!s.includes('/orders')));
    res=await fetch(origin+'/dashboard',{headers:{Cookie:nextCookie}});
    assert.match(await res.text(),/csrf-after-login/);
    res=await fetch(origin+'/logout',{method:'POST',headers:{Cookie:nextCookie,
      'X-CSRF-TOKEN':'csrf-after-login'}});
    assert.equal(res.status,200);
    res=await fetch(origin+'/_erp2/identity',{headers:{Cookie:nextCookie}});
    assert.equal(res.status,401);
  }finally{await close(bridge);await close(upstream);}
});

test('live credential submission defaults HOLD; production order routes remain blocked',async()=>{
  const bridge=createBridge({distDir:__dirname,enableCredentials:false,port:0});
  const base=await listen(bridge);
  try{
    assert.equal((await fetch(base+'/login')).status,503);
    assert.equal((await fetch(base+'/login',{method:'POST',body:'email=x&password=y',
      headers:{'Content-Type':'application/x-www-form-urlencoded'}})).status,503);
    assert.equal((await fetch(base+'/orders')).status,405);
    assert.equal((await fetch(base+'/customers/orders')).status,405);
    assert.equal((await fetch(base+'/orders',{method:'POST',body:'x'})).status,405);
  }finally{await close(bridge);}
});

test('wrong live permissions identity cannot authorize even after valid login',async()=>{
  const upstream=http.createServer(async(req,res)=>{
    if(req.url==='/login'&&req.method==='GET'){
      res.writeHead(200,{'set-cookie':'laravel_session=pre'});
      return res.end('<meta name="csrf-token" content="csrf-before-login">');
    }
    if(req.url==='/login'&&req.method==='POST'){
      res.writeHead(302,{'set-cookie':'laravel_session=active','location':'/dashboard'});return res.end();
    }
    if(req.url==='/account/profile')return res.writeHead(200).end(profile);
    if(req.url==='/_erp2/identity')return res.writeHead(404).end();
    return res.writeHead(404).end();
  });
  const upstreamUrl=await listen(upstream);
  const bridge=createBridge({upstream:upstreamUrl,allowedUpstreams:[upstreamUrl],
    enableCredentials:true,distDir:__dirname,port:0});
  const base=await listen(bridge);
  try{
    let res=await fetch(base+'/login');
    const cookie=res.headers.getSetCookie()[0].split(';')[0];
    res=await fetch(base+'/login',{method:'POST',headers:{Cookie:cookie,
      'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':'csrf-before-login'},
      body:new URLSearchParams({email:'Vita',password:'fixture-only'}).toString()});
    assert.equal(res.status,503);
    assert.equal((await fetch(base+'/_erp2/identity',{headers:{Cookie:cookie}})).status,401);
  }finally{await close(bridge);await close(upstream);}
});
