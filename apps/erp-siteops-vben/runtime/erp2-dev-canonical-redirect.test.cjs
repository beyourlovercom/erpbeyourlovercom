'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const http=require('node:http');
const {spawn}=require('node:child_process');
const {setTimeout:delay}=require('node:timers/promises');
const path=require('node:path');
const gateway=path.join(__dirname,'erp2-logistics-dev-gateway.cjs');
const listen=s=>new Promise(r=>s.listen(0,'127.0.0.1',()=>r(s.address().port)));
const close=s=>new Promise(r=>s.close(r));
test('original Laravel Dev logout maps only ERP2 canonical-origin redirects; rejects outside origins',async t=>{
 const options={
  '/logout':'https://erp2.beyourlover.com/?logout=1',
  '/login':'https://erp.beyourlover.com/',
  '/dashboard':'https://erp2.beyourlover.com.evil.tld/',
 };
 const upstream=http.createServer((req,res)=>{
  // Laravel's logout can redirect to the upstream origin WITHOUT a trailing slash.
  const location=req.url==='/logout?root=1' ? 'http://127.0.0.1:'+upstream.address().port : options[req.url]||'/login';
  res.writeHead(302,{location});
  res.end();
 });
 const originPort=await listen(upstream);
 t.after(()=>close(upstream));
 const placeholder=http.createServer();
 const frontendPort=await listen(placeholder);
 await close(placeholder);
 const child=spawn(process.execPath,[gateway],{env:{
  ...process.env,ERP2_LOGISTICS_DEV_PORT:String(frontendPort),
  ERP2_LOGISTICS_DEV_UPSTREAM:'http://127.0.0.1:'+originPort,
  ERP2_LOGISTICS_VBEN_DIST:'/tmp',
 },stdio:'ignore'});
 t.after(()=>child.kill());
 const base='http://127.0.0.1:'+frontendPort;
 let healthy=false;
 for(let i=0;i<60;i++){
  try{if((await fetch(base+'/api/health')).ok){healthy=true;break;}}catch{}
  await delay(50);
 }
 assert.equal(healthy,true);
 const logout=await fetch(base+'/logout',{method:'POST',redirect:'manual'});
 assert.equal(logout.status,302,'canonical ERP2 logout redirect must be accepted');
 assert.equal(logout.headers.get('location'),'/?logout=1','redirect must stay same-origin on Dev gateway');
 const originOnly=await fetch(base+'/logout?root=1',{method:'POST',redirect:'manual'});
 assert.equal(originOnly.status,302,'root-origin-only redirect must not be rejected');
 assert.equal(originOnly.headers.get('location'),'/', 'exact upstream origin maps safely to Dev root');
 const login=await fetch(base+'/login',{redirect:'manual'});
 assert.equal(login.status,502,'original ERP Production redirect must never be forwarded');
 assert.equal((await login.json()).error,'ERP2_EXTERNAL_REDIRECT_DENIED');
 const lookalike=await fetch(base+'/dashboard',{redirect:'manual'});
 assert.equal(lookalike.status,502,'hostname prefix tricks must never be accepted');
 assert.equal((await lookalike.json()).error,'ERP2_EXTERNAL_REDIRECT_DENIED');
});
