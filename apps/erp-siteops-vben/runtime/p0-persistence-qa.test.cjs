'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const http=require('node:http');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const crypto=require('node:crypto');
const {once}=require('node:events');
const {createBridge}=require('./p0-production-identity-bridge.cjs');
async function listen(s){s.listen(0,'127.0.0.1');await once(s,'listening');return 'http://127.0.0.1:'+s.address().port;}
async function close(s){await new Promise((resolve,reject)=>s.close(e=>e?reject(e):resolve()));}
test('P0-A/B: encrypted sessions survive bridge restart, expire at 60 days and revoke on confirmed logout',async()=>{
  const root=fs.mkdtempSync(path.join(os.tmpdir(),'erp2-p0-'));
  const sessionStorage={filePath:path.join(root,'sessions.enc'),key:crypto.randomBytes(32)};
  const user={id:17,name:'Liang',email:'liang@fixture.invalid'};
  const profile='<meta name="csrf-token" content="after"><script data-page="app">'+
    JSON.stringify({component:'Identity/Account/Profile',props:{auth:{user}}})+'</script>';
  let revoke=false;
  const upstream=http.createServer(async(req,res)=>{
    if(req.url==='/login'&&req.method==='GET'){
      res.writeHead(200,{'set-cookie':'laravel_session=pre'});
      return res.end('<meta name="csrf-token" content="before">');
    }
    if(req.url==='/login'&&req.method==='POST'){
      let body='';for await(const chunk of req)body+=chunk;
      assert.match(body,/remember=1/);
      return res.writeHead(302,{'location':'/dashboard','set-cookie':'laravel_session=active'}).end();
    }
    if(req.url==='/account/profile'){
      if(revoke)return res.writeHead(302,{'location':'/login'}).end();
      return res.writeHead(200).end(profile);
    }
    if(req.url==='/_erp2/identity'){
      return res.writeHead(200,{'content-type':'application/json'}).end(JSON.stringify({
        source:'ERP_ORIGINAL_LARAVEL_PRODUCTION_SESSION_V1',user,
        permissionMode:'role',roleKeys:['logistics'],effectivePermissionKeys:['orders.view'],
        navigation:[{key:'orders',label:'Orders',path:'/orders',children:[]}]
      }));
    }
    return res.writeHead(404).end();
  });
  const upstreamUrl=await listen(upstream);
  const options={upstream:upstreamUrl,allowedUpstreams:[upstreamUrl],enableCredentials:true,
    distDir:root,port:0,sessionStorage};
  const opened=[];
  const savedNow=Date.now;
  let fakeNow=savedNow();
  Date.now=()=>fakeNow;
  async function start(){const s=createBridge(options);opened.push(s);return {server:s,url:await listen(s)};}
  async function login(url){
    const first=await fetch(url+'/login');
    const pre=first.headers.getSetCookie()[0].split(';')[0];
    const response=await fetch(url+'/login',{method:'POST',headers:{
      Cookie:pre,'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':'before'
    },body:new URLSearchParams({email:'Liang',password:'mock-only'}).toString()});
    assert.equal(response.status,200);
    assert.match(response.headers.get('set-cookie'),/Max-Age=5184000/);
    return response.headers.getSetCookie()[0].split(';')[0];
  }
  try{
    let b=await start();
    const cookie=await login(b.url);
    const encrypted=fs.readFileSync(sessionStorage.filePath).toString('utf8');
    assert.ok(!encrypted.includes('laravel_session'));
    assert.ok(!encrypted.includes('liang@fixture.invalid'));
    await close(b.server);opened.pop();
    fakeNow+=59*24*60*60_000;
    b=await start();
    assert.equal((await fetch(b.url+'/_erp2/identity',{headers:{Cookie:cookie}})).status,200);
    await close(b.server);opened.pop();
    fakeNow+=2*24*60*60_000;
    b=await start();
    assert.equal((await fetch(b.url+'/_erp2/identity',{headers:{Cookie:cookie}})).status,401);
    const cookie2=await login(b.url);
    assert.equal((await fetch(b.url+'/_erp2/identity',{headers:{Cookie:cookie2}})).status,200);
    revoke=true;
    const denied=await fetch(b.url+'/_erp2/identity',{headers:{Cookie:cookie2}});
    assert.equal(denied.status,401);
    assert.match(denied.headers.get('set-cookie'),/Max-Age=0/);
    await close(b.server);opened.pop();
    b=await start();
    assert.equal((await fetch(b.url+'/_erp2/identity',{headers:{Cookie:cookie2}})).status,401);
  }finally{
    Date.now=savedNow;
    for(const server of opened)await close(server);
    await close(upstream);
    fs.rmSync(root,{recursive:true,force:true});
  }
});
