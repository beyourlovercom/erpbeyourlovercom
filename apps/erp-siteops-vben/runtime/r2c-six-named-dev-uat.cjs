'use strict';
// Six explicitly named synthetic DEV counterparts, never Production staff credentials.
// Run only on 007windows against the isolated US Mac Laravel SQLite port 18812.
const assert=require('node:assert/strict');
const BASE='http://100.94.52.109:18812';
const accounts=[
 {name:'Vita',email:'vita-r2c@local.test',required:['orders'],visible:'007-1115-00001'},
 {name:'Liang',email:'liang-r2c@local.test',required:['logistics'],visible:'007-1115-00001'},
 {name:'Dede',email:'dede-r2c@local.test',required:['logistics'],visible:'007-1115-00001'},
 {name:'Emma',email:'emma-r2c@local.test',required:['kol'],denied:['inventory','accounting'],visible:'007-R2C-EMMA-001',hidden:'007-R2C-HIKO-001'},
 {name:'Guts',email:'guts-r2c@local.test',required:['kol'],denied:['inventory','accounting'],visible:'007-R2C-GUTS-001',hidden:'007-1115-00001'},
 {name:'Hiko',email:'hiko-r2c@local.test',required:['kol'],denied:['inventory','accounting'],visible:'007-R2C-HIKO-001',hidden:'007-R2C-EMMA-001'},
];
function cookieJar(){return new Map()}
function addCookies(res,jar){for(const raw of res.headers.getSetCookie()){const rawPair=raw.split(';')[0];const i=rawPair.indexOf('=');if(i>0)jar.set(rawPair.slice(0,i),rawPair.slice(i+1));}}
function cookieHeader(jar){return [...jar].map(([k,v])=>k+'='+v).join('; ')}
async function call(uri,jar,opt={}){
 if(!uri.startsWith('/')||uri.startsWith('//')||uri.includes('://'))throw Error('NON_DEV_URI');
 const res=await fetch(BASE+uri,{method:opt.method||'GET',headers:{
  Cookie:cookieHeader(jar),Accept:'text/html, application/xhtml+xml',
  ...(opt.headers||{}),
 },body:opt.body,redirect:'manual',signal:AbortSignal.timeout(40000)});
 addCookies(res,jar);
 return res;
}
function parseInertia(html){
 const m=html.match(/<script[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/);
 if(!m)throw Error('INERTIA_PAGE_MISSING');
 return JSON.parse(m[1]);
}
async function logIn(user){
 const jar=cookieJar(),form=await call('/login',jar);
 assert.equal(form.status,200,'login form');
 const html=await form.text(),csrf=html.match(/<meta\s+name="csrf-token"\s+content="([^"]+)"/i);
 if(!csrf)throw Error('CSRF_MISSING');
 const body=new URLSearchParams({_token:csrf[1],email:user.email,password:'password'});
 const res=await call('/login',jar,{method:'POST',headers:{'content-type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:body.toString()});
 assert.equal(res.status,302,'login redirect status');
 assert.ok(!(res.headers.get('location')||'').includes('/login'),'LOGIN_DENIED');
 return jar;
}
async function ordersPage(jar,query=''){
 const res=await call('/orders?per_page=50'+query,jar);
 assert.equal(res.status,200,'authenticated order GET');
 const page=parseInertia(await res.text());
 assert.equal(page.component,'Orders/Index');
 return page.props;
}
function orderNumbers(row){return [row.erp_order_id,row.internal_order_number,row.external_order_number].filter(Boolean)}
async function searchExact(jar,orderNo,want){
 const props=await ordersPage(jar,'&search='+encodeURIComponent(orderNo));
 const found=(props.orders?.data||[]).some(row=>orderNumbers(row).includes(orderNo));
 assert.equal(found,want,(want?'EXPECTED_ORDER_HIDDEN:':'CROSS_USER_ORDER_LEAK:')+orderNo);
}
async function main(){
 let pass=0,fail=0;
 for(const account of accounts){
  try {
   const jar=await logIn(account);
   const props=await ordersPage(jar);
   assert.equal(props.auth?.user?.email?.toLowerCase(),account.email);
   assert.ok(Array.isArray(props.shellNavigation),'MISSING_BACKEND_MENU');
   const keys=new Set(props.shellNavigation.flatMap(g=>[g.key,...(g.children||[]).map(x=>x.key)]));
   for(const key of account.required)assert.ok(keys.has(key),'MISSING_REQUIRED_MENU:'+key);
   for(const key of account.denied||[])assert.ok(!keys.has(key),'LEAKED_MENU:'+key);
   await searchExact(jar,account.visible,true);
   if(account.hidden)await searchExact(jar,account.hidden,false);
   console.log('R2C_NAMED_DEV_PASS',account.name,'auth=true','menuGroups='+props.shellNavigation.length,'positive=true','negative='+(account.hidden?'true':'n/a'));
   pass++;
  }catch(error){
   console.error('R2C_NAMED_DEV_FAIL',account.name,error.message);fail++;
  }
 }
 console.log('R2C_NAMED_DEV_SUMMARY',JSON.stringify({passed:pass,failed:fail,configured:accounts.length,isolatedDev:true,productionTouched:false}));
 if(fail)process.exitCode=1;
}
main().catch(error=>{console.error('R2C_NAMED_DEV_ABORT',error.message);process.exitCode=1});
