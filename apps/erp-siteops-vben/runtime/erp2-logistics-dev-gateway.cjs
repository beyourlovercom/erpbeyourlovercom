'use strict';
// ERP2 Vben Dev-only canonical Laravel Session gateway. Loopback only.
// Strict path allowlist: isolated Mac SQLite; M2 review POST default-OFF, shipping POST denied.
const http=require('node:http'),fs=require('node:fs'),path=require('node:path');
const HOST='127.0.0.1';
const PORT=Number(process.env.ERP2_LOGISTICS_DEV_PORT || '18795');
const MAC=(process.env.ERP2_LOGISTICS_DEV_UPSTREAM || '').replace(/\/+$/,'');
const DIST=process.env.ERP2_LOGISTICS_VBEN_DIST || '';
const REVIEW_DEV_WRITE=process.env.ERP2_M2_DEV_REVIEW_ENABLED==='1';
const SHIPPING_DEV_WRITE=process.env.ERP2_M2_DEV_SHIPPING_ENABLED==='1';
// Require explicit isolated Dev endpoints: no accidental Production routing.
let upstream;
try { upstream=new URL(MAC); } catch { throw Error('ERP2_DEV_UPSTREAM_REQUIRED'); }
const parts=upstream.hostname.split('.').map(Number);
const isTailnet=parts.length===4 && parts[0]===100 && parts[1]>=64 && parts[1]<=127 &&
 parts.every(n=>Number.isInteger(n)&&n>=0&&n<=255);
if (upstream.protocol!=='http:' || !(isTailnet||['localhost','127.0.0.1'].includes(upstream.hostname)) ||
 upstream.username || upstream.password || upstream.pathname!=='/' || upstream.search || upstream.hash)
 throw Error('ERP2_DEV_UPSTREAM_MUST_BE_LOCAL_OR_TAILNET');
if (!Number.isInteger(PORT) || PORT<1024 || PORT>65535 || !path.isAbsolute(DIST))
 throw Error('ERP2_DEV_PORT_OR_ABSOLUTE_DIST_REQUIRED');
const logistics=new Set([
 '/api/erp2/v1/logistics/pending-allocation',
 '/api/erp2/v1/logistics/domestic/pending',
 '/api/erp2/v1/logistics/overseas',
]);
// Canonical Laravel Orders routes, never route substrings or business mutations.
const inertiaOrders=new Set(['/orders','/customers/orders','/kol/orders',
 '/orders/collaboration','/orders/procurement','/orders/archive']);
const inertiaLogistics=new Set(['/logistics/china','/logistics/oversea']);
const logisticsDetail=(pathname)=>/^\/logistics\/oversea\/(us-ny|us-moreplus|us-3sm|de-tianyun|ca)$/.test(pathname);
const inertiaDetail=(pathname)=>/^\/(customers|kol)\/orders\/[1-9][0-9]*$/.test(pathname);
const noteWrite=(pathname)=>/^\/(customers|kol)\/orders\/[1-9][0-9]*\/notes$/.test(pathname);
const reviewWrite=new Set(['/logistics/china/batch-review','/logistics/oversea/batch-review']);
const accountPreferences=pathname=>/^\/ui\/preferences\/grid\.[A-Za-z0-9._:-]{1,110}$/.test(pathname);
const shippingWrite=pathname=>/^\/logistics\/china\/orders\/[1-9][0-9]*\/(carrier-service|tracking-draft|quick-ship)$/.test(pathname)||
  /^\/logistics\/oversea\/(orders|us-3sm\/orders|us-moreplus\/orders)\/[1-9][0-9]*\/manual-ship$/.test(pathname);
const mime={'.html':'text/html; charset=utf-8','.js':'text/javascript; charset=utf-8',
 '.css':'text/css; charset=utf-8','.json':'application/json','.svg':'image/svg+xml',
 '.png':'image/png','.ico':'image/x-icon','.woff':'font/woff','.woff2':'font/woff2'};
function json(res,status,data){
 res.writeHead(status,{'content-type':'application/json; charset=utf-8','cache-control':'no-store','x-content-type-options':'nosniff'});
 res.end(JSON.stringify(data));
}
function isInertia(pathname){
 return inertiaOrders.has(pathname)||inertiaDetail(pathname)||inertiaLogistics.has(pathname)||
  logisticsDetail(pathname);
}
function allowed(req,url){
 if(accountPreferences(url.pathname))return req.method==='GET' ||
   (req.method==='PUT' && req.headers['content-type']?.startsWith('application/json') &&
    Boolean(req.headers['x-xsrf-token']));
 if(isInertia(url.pathname))return req.method==='GET' && req.headers['x-inertia']==='true';
 if(noteWrite(url.pathname))return req.method==='POST' &&
  req.headers['content-type']?.startsWith('application/json') && Boolean(req.headers['x-xsrf-token']);
 if(reviewWrite.has(url.pathname))return REVIEW_DEV_WRITE && req.method==='POST' &&
  req.headers['content-type']?.startsWith('application/json') && Boolean(req.headers['x-xsrf-token']);
 if(shippingWrite(url.pathname))return SHIPPING_DEV_WRITE && req.method==='POST' &&
  req.headers['content-type']?.startsWith('application/json') && Boolean(req.headers['x-xsrf-token']);
 if(logistics.has(url.pathname))return req.method==='GET';
 if(url.pathname==='/_erp2/identity')return req.method==='GET';
 if(url.pathname==='/login')return ['GET','POST'].includes(req.method);
 if(url.pathname==='/dashboard')return req.method==='GET';
 if(url.pathname==='/logout')return req.method==='POST';
 return false;
}
async function proxy(req,res,url){
 const headers={Accept:req.headers.accept||'text/html','X-Requested-With':req.headers['x-requested-with']||'XMLHttpRequest'};
 if(isInertia(url.pathname))headers['X-Inertia']='true';
 if(isInertia(url.pathname)&&req.headers['x-inertia-version'])
  headers['X-Inertia-Version']=String(req.headers['x-inertia-version']).slice(0,128);
 if(req.headers.cookie)headers.Cookie=req.headers.cookie;
 if(req.headers['content-type'])headers['Content-Type']=req.headers['content-type'];
 if(req.headers['x-csrf-token'])headers['X-CSRF-TOKEN']=req.headers['x-csrf-token'];
 if((noteWrite(url.pathname)||reviewWrite.has(url.pathname)||shippingWrite(url.pathname)||accountPreferences(url.pathname))&&req.headers['x-xsrf-token'])
  headers['X-XSRF-TOKEN']=req.headers['x-xsrf-token'];
 const payload=[];
 if(req.method==='POST'||req.method==='PUT')for await(const c of req){payload.push(c);if(payload.reduce((n,x)=>n+x.length,0)>16384)throw Error('LOGIN_BODY_TOO_LARGE');}

 const upstream=await fetch(MAC+url.pathname+url.search,{
  method:req.method,headers,body:payload.length?Buffer.concat(payload):undefined,
  redirect:'manual',signal:AbortSignal.timeout(15000),
 });
 const responseHeaders={
  'content-type':upstream.headers.get('content-type')||'text/plain',
  'cache-control':'no-store,private','x-content-type-options':'nosniff',
 };
 const cookies=upstream.headers.getSetCookie();
 if(cookies.length)responseHeaders['set-cookie']=cookies;
 const location=upstream.headers.get('location');
 if(location&&!noteWrite(url.pathname)){
  const localOrigin='http://'+HOST+':'+PORT;
  if (location===MAC) responseHeaders.location='/';
  else if (location.startsWith(MAC+'/')) responseHeaders.location=localOrigin+location.slice(MAC.length);
  else if (location.startsWith('/')&&!location.startsWith('//')) responseHeaders.location=location;
  else {
   // Laravel Dev APP_URL may produce an absolute same-site redirect on logout.
   // Never forward to any external origin; map only ERP2's canonical Dev origin.
   let parsed;try{parsed=new URL(location);}catch{return json(res,502,{error:'ERP2_EXTERNAL_REDIRECT_DENIED'});}
   if(parsed.origin!=='https://erp2.beyourlover.com' || parsed.username || parsed.password)
    return json(res,502,{error:'ERP2_EXTERNAL_REDIRECT_DENIED'});
   responseHeaders.location=parsed.pathname+parsed.search+parsed.hash;
  }
 }
 res.writeHead(upstream.status,responseHeaders);
 res.end(Buffer.from(await upstream.arrayBuffer()));
}
function staticFile(req,res,url){
 if(!['GET','HEAD'].includes(req.method))return json(res,405,{error:'STATIC_READ_ONLY'});
 let decoded;try{decoded=decodeURIComponent(url.pathname);}catch{return json(res,400,{error:'BAD_URL'});}
 const target=path.resolve(DIST,'.'+decoded);
 if(target!==DIST&&!target.startsWith(DIST+path.sep))return json(res,403,{error:'BAD_FILE'});
 let file=target;try{if(!fs.statSync(file).isFile())file=path.join(DIST,'index.html');}
 catch{file=path.join(DIST,'index.html');}
 if(!fs.existsSync(file))return json(res,503,{error:'DEV_VBEN_BUILD_MISSING'});
 res.writeHead(200,{'content-type':mime[path.extname(file)]||'application/octet-stream',
  'x-content-type-options':'nosniff','cache-control':file.endsWith('index.html')?'no-store':'public,max-age=300'});
 if(req.method==='HEAD')return res.end();
 fs.createReadStream(file).pipe(res);
}
const server=http.createServer(async(req,res)=>{
 try{
  const url=new URL(req.url||'/','http://'+HOST+':'+PORT);
  if(url.pathname==='/api/health'&&req.method==='GET')return json(res,200,{
    source:'ISOLATED_MAC_DEV_SQLITE',production_connected:false,
    write_enabled:SHIPPING_DEV_WRITE?'notes-review-and-manual-shipping-dev':REVIEW_DEV_WRITE?'notes-and-original-review-dev':'notes-only-dev',
    mode:'laravel-session',app:'ERP2 Vben Logistics Dev'});
  // Never let nested Laravel business URLs fall through to a misleading SPA 200.
  if(url.pathname.startsWith('/logistics/')&&!isInertia(url.pathname)&&
     !reviewWrite.has(url.pathname)&&!shippingWrite(url.pathname))
    return json(res,405,{error:'ERP2_DEV_METHOD_OR_PATH_DENIED'});
  if(/^\/(orders|customers\/orders|kol\/orders)\/.+/.test(url.pathname)&&
     !isInertia(url.pathname)&&!noteWrite(url.pathname))
    return json(res,405,{error:'ERP2_DEV_METHOD_OR_PATH_DENIED'});
  if(url.pathname.startsWith('/api/')||isInertia(url.pathname)||
     noteWrite(url.pathname)||reviewWrite.has(url.pathname)||shippingWrite(url.pathname)||accountPreferences(url.pathname)||
     ['/_erp2/identity','/login','/dashboard','/logout'].includes(url.pathname)){
    if(!allowed(req,url))return json(res,405,{error:'ERP2_DEV_METHOD_OR_PATH_DENIED'});
    return await proxy(req,res,url);
  }
  return staticFile(req,res,url);
 }catch(e){
  console.error('[ERP2 Dev Proxy]',String(e?.message||e));
  // A slow/cancelled browser request can fail AFTER upstream sent headers.
  // Never attempt a second writeHead: that would crash the whole Dev Gateway.
  if(res.destroyed||res.writableEnded)return;
  if(res.headersSent){res.destroy();return;}
  return json(res,502,{error:'ERP2_ISOLATED_DEV_UNAVAILABLE'});
 }
});
server.listen(PORT,HOST,()=>console.log('ERP2_VBEN_CANONICAL_DEV_READY http://'+HOST+':'+PORT));
