'use strict';
// Owner-only 007 Dev UAT adapter: role-authenticated, GET-only, never a production connector.
const fs=require('node:fs'),path=require('node:path');
const crypto=require('node:crypto');
const DEV_URL='http://100.94.52.109:18811';
const SOURCE='CURRENT_LARAVEL_MAIN_DEV_B07885E3';
const FILE=path.join(__dirname,'private/r2b-current-dev-roles.json');
const roles=new Set(['owner','staff','kol','manager']);
const sessions=new Map();
function config(){
 const c=JSON.parse(fs.readFileSync(FILE,'utf8'));
 if(c.url!==DEV_URL||!c.roles||!Object.keys(c.roles).every(k=>roles.has(k)))
   throw Error('UNAPPROVED_DEV_HOST');
 return c;
}
function cookiesFrom(response,jar){
 for(const raw of response.headers.getSetCookie()){
   const part=raw.split(';')[0];
   const eq=part.indexOf('=');
   if(eq>0)jar.set(part.slice(0,eq),part.slice(eq+1));
 }
}
function cookieHeader(jar){return [...jar].map(([k,v])=>k+'='+v).join('; ');}
async function devFetch(uri,jar,options={}){
 if(!uri.startsWith('/')||uri.startsWith('//')||uri.includes('://'))throw Error('BAD_DEV_URI');
 const response=await fetch(DEV_URL+uri,{
  method:options.method||'GET',
  headers:{Cookie:cookieHeader(jar),Accept:'text/html, application/xhtml+xml',
   ...(options.headers||{})},
  body:options.body,redirect:'manual',signal:AbortSignal.timeout(45000),
 });
 cookiesFrom(response,jar);
 return response;
}
async function logIn(role){
 if(!roles.has(role))throw Error('UNKNOWN_UAT_ROLE');
 const profile=config().roles[role],jar=new Map();
 if(!profile?.email?.endsWith('@local.invalid')||typeof profile.password!=='string')
  throw Error('UNAPPROVED_DEV_ACCOUNT');
 const page=await devFetch('/login',jar);
 if(page.status!==200)throw Error('DEV_LOGIN_UNAVAILABLE');
 const html=await page.text();
 const m=html.match(/<meta\s+name="csrf-token"\s+content="([^"]+)"/i);
 if(!m)throw Error('DEV_CSRF_MISSING');
 const body=new URLSearchParams({_token:m[1],email:profile.email,password:profile.password});
 const post=await devFetch('/login',jar,{method:'POST',
  headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
  body:body.toString()});
 const location=post.headers.get('location')||'';
 if(post.status!==302||location.includes('/login'))throw Error('DEV_ROLE_LOGIN_DENIED');
 sessions.set(role,{jar,expires:Date.now()+15*60_000});
 return jar;
}
function safeText(v){return typeof v==='string'?v:v==null?'':String(v);}
function obj(v){return v&&typeof v==='object'&&!Array.isArray(v)?v:{};}
function int(v,fallback){const n=Number(v);return Number.isSafeInteger(n)&&n>=0?n:fallback;}
function project(inertia,role){
 if(inertia.component!=='Orders/Index')throw Error('UNEXPECTED_INERTIA_COMPONENT');
 const p=obj(inertia.props),orders=obj(p.orders);
 if(!Array.isArray(orders.data)||!Array.isArray(p.shellNavigation))
  throw Error('DEV_ORDERS_OR_AUTH_NAV_INVALID');
 const rows=orders.data.map(item=>{
  const o=obj(item),workflow=obj(o.workflow_status_projection),shipment=obj(o.shipment);
  const items=Array.isArray(o.items)?o.items.map(x=>obj(x)):[];
  return {
   id:int(o.id,0),erp_order_id:safeText(o.erp_order_id),
   external_order_number:safeText(o.external_order_number),
   internal_order_number:safeText(o.internal_order_number),
   order_kind_label:safeText(o.order_kind_label),
   order_date:safeText(o.order_date),order_status_label:safeText(o.order_status_label),
   workflow_status_projection:{zh:safeText(workflow.zh),code:safeText(workflow.code)},
   latest_order_note:'',items:items.map(i=>({sku:safeText(i.sku),quantity:int(i.quantity,1)})),
   shipment:{warehouse_name:safeText(shipment.warehouse_name),tracking_number:safeText(shipment.tracking_number)},
  };
 });
 const menus=p.shellNavigation.map(group=>{
  const g=obj(group);
  return {key:safeText(g.key),label:safeText(g.label),
   path:safeText(g.path),children:Array.isArray(g.children)?g.children.map(child=>{
    const x=obj(child);return {key:safeText(x.key),label:safeText(x.label),path:safeText(x.path)};
   }):[]};
 });
 return {source:SOURCE,devOnly:true,authenticatedByLaravel:true,role,
  rowsAreIsolatedUat:true,inertia:{component:'Orders/Index',props:{
   orders:{data:rows,current_page:int(orders.current_page,1),
    last_page:int(orders.last_page,1),per_page:int(orders.per_page,50),total:int(orders.total,0)},
   shellNavigation:menus,
   filters:{search:safeText(obj(p.filters).search)},
  }}};
}
async function currentDevOrders(url) {
 const role=url.searchParams.get('role')||'owner';
 if(!roles.has(role))throw Error('UNKNOWN_UAT_ROLE');
 const scope=url.searchParams.get('scope')||'all';
 if(!['all','customers'].includes(scope))throw Error('UNKNOWN_ORDERS_SCOPE');
 const requestedSize=url.searchParams.get('per_page')||'50';
 if(!['20','50','100'].includes(requestedSize))throw Error('INVALID_PAGE_SIZE');
 const rawPage=Number(url.searchParams.get('page')||1);
 if(!Number.isSafeInteger(rawPage)||rawPage<1||rawPage>999)throw Error('INVALID_PAGE');
 const search=(url.searchParams.get('search')||'').trim();
 if(search.length>90)throw Error('SEARCH_TOO_LONG');
 const base=scope==='all'?'/orders':'/customers/orders';
 const query=new URLSearchParams({page:String(rawPage),per_page:requestedSize});
 if(search)query.set('search',search);
 const uri=base+'?'+query.toString();
 let session=sessions.get(role);
 if(!session||session.expires<Date.now())session={jar:await logIn(role)};
 let response=await devFetch(uri,session.jar);
 if(response.status===302){
  const jar=await logIn(role);response=await devFetch(uri,jar);
 }
 if(response.status===403)throw Error('LARAVEL_ROLE_FORBIDDEN');
 if(response.status!==200)throw Error('LARAVEL_DEV_READ_STATUS_'+response.status);
 const html=await response.text();
 if(html.length>6000000)throw Error('DEV_RESPONSE_TOO_LARGE');
 const match=html.match(/<script[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/);
 if(!match)throw Error('LARAVEL_INERTIA_HTML_MISSING');
 return project(JSON.parse(match[1]),role);
}
module.exports={currentDevOrders,SOURCE};