'use strict';
// ERP2 Vben Dev-only canonical Laravel Session gateway. Loopback only.
// Isolated Windows Laravel + MySQL, strict route allowlist, business writes OFF until UAT.
const http=require('node:http'),fs=require('node:fs'),path=require('node:path');
const HOST='127.0.0.1';
const PORT=18824;
const MAC='http://127.0.0.1:18823';
const DIST=path.resolve('E:/ai/erp2beyourlover/dist/vben');
const REVIEW_DEV_WRITE=false;
const domesticFlowWrite=new Set(['/logistics/wait-stock/batch-allocation','/logistics/china/batch-return-allocation']);
const DOMESTIC_APPLY_DEV_WRITE=REVIEW_DEV_WRITE;
const domesticTrackingApply=new Set(['/logistics/china/pending-batch/tracking-import/apply','/logistics/china/4px/tracking-import/apply','/logistics/china/weship/tracking-import/apply']);
const SHIPPING_DEV_WRITE=REVIEW_DEV_WRITE;
const OVERSEA_DEV_WRITE=false;
const overseaExportRead=p=>/^\/logistics\/oversea\/(us-ny|us-moreplus|us-3sm|de-tianyun|ca)\/export$/.test(p);
const overseaPreview=p=>/^\/logistics\/oversea\/(us-ny|us-moreplus|us-3sm|de-tianyun|ca)\/tracking-import\/preview$/.test(p);
const overseaJsonWrite=p=>/^\/logistics\/oversea\/(batch-review|batch-return-allocation|(?:us-3sm\/|us-moreplus\/)?orders\/[1-9][0-9]*\/manual-ship|(?:us-ny|us-moreplus|us-3sm|de-tianyun|ca)\/tracking-import\/apply)$/.test(p);
const omsRead=p=>/^\/logistics\/oversea\/(?:us-moreplus\/shipout|de-tianyun\/xlwms)\/batch\/(?:preview|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/status)$/.test(p)||/^\/logistics\/oversea\/us-3sm\/orders\/[1-9][0-9]*\/(?:manual-export|xlwms\/preflight)$/.test(p);
const omsStart=p=>/^\/logistics\/oversea\/(?:us-moreplus\/shipout|de-tianyun\/xlwms)\/batch\/start$/.test(p);
const OMS_SIM_WRITE=false;
const overseaRoute=p=>overseaExportRead(p)||overseaPreview(p)||overseaJsonWrite(p)||omsRead(p)||omsStart(p);
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
const inertiaOrders=new Set(['/orders','/customers/orders','/customers/orders/create','/kol/orders',
 '/orders/collaboration','/orders/procurement','/orders/archive']);
const inertiaLogistics=new Set(['/logistics/china','/logistics/oversea','/logistics/wait-stock','/logistics/awaiting-tracking','/logistics/in-transit','/logistics/exceptions']);
const logisticsDetail=(pathname)=>/^\/logistics\/oversea\/(us-ny|us-moreplus|us-3sm|de-tianyun|ca)$/.test(pathname);
const inertiaDetail=(pathname)=>/^\/(?:orders|customers\/orders|kol\/orders)\/[1-9][0-9]*$/.test(pathname);
const noteWrite=(pathname)=>/^\/(customers|kol)\/orders\/[1-9][0-9]*\/notes$/.test(pathname);
const reviewWrite=new Set(['/logistics/china/batch-review',...domesticFlowWrite]);
// Exact original ERP1 domestic XLSX downloads, never a duplicate exporter.
const domesticExportRead=new Set([
 '/logistics/china/today/export',
 '/logistics/china/pending-batch/export',
 '/logistics/china/pending-batch/export-sku-merged',
 '/logistics/china/export/4px',
 '/logistics/china/export/weship',
]);
// Preview only: original Laravel parser stores a session token, no business data changes.
const domesticTrackingPreview=new Set([
 '/logistics/china/tracking-import/preview',
 '/logistics/china/pending-batch/tracking-import/preview',
 '/logistics/china/4px/tracking-import/preview',
 '/logistics/china/weship/tracking-import/preview',
]);
const accountPreferences=pathname=>/^\/ui\/preferences\/grid\.[A-Za-z0-9._:-]{1,110}$/.test(pathname);
const allocationWrite=p=>/^\/logistics\/orders\/[1-9][0-9]*\/fulfillment-allocation\/(preflight|manual|recommended|reset)$/.test(p);
const allocationRead=p=>/^\/logistics\/orders\/[1-9][0-9]*\/fulfillment-allocation\/status$/.test(p);
const barcodeRead=p=>/^\/logistics\/china\/orders\/[1-9][0-9]*\/barcode-69$/.test(p);
const shippingWrite=pathname=>/^\/logistics\/china\/orders\/[1-9][0-9]*\/(carrier-service|tracking-draft|quick-ship)$/.test(pathname);
const mime={'.html':'text/html; charset=utf-8','.js':'text/javascript; charset=utf-8',
 '.css':'text/css; charset=utf-8','.json':'application/json','.svg':'image/svg+xml',
 '.png':'image/png','.ico':'image/x-icon','.woff':'font/woff','.woff2':'font/woff2'};
function json(res,status,data){
 res.writeHead(status,{'content-type':'application/json; charset=utf-8','cache-control':'no-store','x-content-type-options':'nosniff'});
 res.end(JSON.stringify(data));
}
function isInertia(pathname){
 return pathname==='/inventory/products'||inertiaOrders.has(pathname)||inertiaDetail(pathname)||inertiaLogistics.has(pathname)||
  logisticsDetail(pathname);
}
const VITA_UAT_WRITE=true;
// Narrow pilot: original Laravel session, original operations ACL, isolated Dev only.
function vitaWriteMethod(p){
 if(p==='/customers/orders'||p==='/logistics/wait-stock/batch-allocation')return 'POST';
 if(/^\/customers\/orders\/[1-9][0-9]*\/items\/preview$/.test(p)||/^\/orders\/[1-9][0-9]*\/address\/sync-children$/.test(p)||allocationWrite(p))return 'POST';
 if(/^\/customers\/orders\/[1-9][0-9]*$/.test(p)||/^\/orders\/[1-9][0-9]*\/(?:address|warehouse-route|workflow-status)$/.test(p))return 'PATCH';
 return '';
}
function isVitaWrite(req,url){return VITA_UAT_WRITE&&!!vitaWriteMethod(url.pathname)&&req.method===vitaWriteMethod(url.pathname)}
async function authorizedVita(req){
 if(!req.headers.cookie)return false;
 const r=await fetch(MAC+'/_erp2/identity',{headers:{Cookie:req.headers.cookie,Accept:'application/json','X-Requested-With':'XMLHttpRequest'},redirect:'manual',signal:AbortSignal.timeout(5000)});
 if(!r.ok||!r.headers.get('content-type')?.includes('application/json'))return false;
 const v=await r.json();
 return v.user?.name==='vita'&&Array.isArray(v.roleKeys)&&v.roleKeys.includes('role_preset_operations')&&Array.isArray(v.effectivePermissionKeys)&&v.effectivePermissionKeys.includes('orders.edit');
}
function allowed(req,url){
 if(isVitaWrite(req,url))return req.headers['content-type']?.startsWith('application/json')&&Boolean(req.headers['x-xsrf-token']);

 if(url.pathname==='/products/sku-search')return req.method==='GET';
 if(noteWrite(url.pathname))return false;
 if(omsRead(url.pathname))return false;
 if(omsStart(url.pathname))return OMS_SIM_WRITE&&req.method==='POST'&&req.headers['content-type']?.startsWith('application/json')&&Boolean(req.headers['x-xsrf-token']);
 if(overseaExportRead(url.pathname))return req.method==='GET';
 if(overseaJsonWrite(url.pathname))return OVERSEA_DEV_WRITE&&req.method==='POST'&&req.headers['content-type']?.startsWith('application/json')&&Boolean(req.headers['x-xsrf-token']);
 if(overseaPreview(url.pathname))return OVERSEA_DEV_WRITE&&req.method==='POST'&&req.headers['content-type']?.startsWith('multipart/form-data;')&&Boolean(req.headers['x-xsrf-token']);
 if(allocationRead(url.pathname))return VITA_UAT_WRITE&&req.method==='GET';
 if(barcodeRead(url.pathname))return req.method==='GET';
 if(allocationWrite(url.pathname))return REVIEW_DEV_WRITE&&req.method==='POST'&&req.headers['content-type']?.startsWith('application/json')&&Boolean(req.headers['x-xsrf-token']);
 if(domesticTrackingPreview.has(url.pathname)&&!REVIEW_DEV_WRITE)return false;
 if(domesticTrackingApply.has(url.pathname))return DOMESTIC_APPLY_DEV_WRITE && req.method==='POST' && req.headers['content-type']?.startsWith('application/json') && Boolean(req.headers['x-xsrf-token']);
 if(domesticTrackingPreview.has(url.pathname))return req.method==='POST'
  && req.headers['content-type']?.startsWith('multipart/form-data;')
  && Boolean(req.headers['x-xsrf-token']);
 if(domesticExportRead.has(url.pathname))return req.method==='GET';
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
 if(logistics.has(url.pathname))return false;
 if(url.pathname==='/_erp2/identity')return req.method==='GET';
 if(url.pathname==='/login')return ['GET','POST'].includes(req.method);
 if(url.pathname==='/dashboard')return req.method==='GET';
 if(url.pathname==='/logout')return req.method==='POST';
 return false;
}
const collaborationPartners=p=>p==='/orders/collaboration/partners'||/^\/orders\/collaboration\/partners\/[1-9][0-9]*$/.test(p);
const collaborationImport=p=>/^\/orders\/oms\/import\/(template|preview|confirm)$/.test(p);
async function collaborationAuthorized(req,manage){
 if(!req.headers.cookie)return false;
 const r=await fetch(MAC+'/_erp2/identity',{headers:{Cookie:req.headers.cookie,Accept:'application/json','X-Requested-With':'XMLHttpRequest'},redirect:'manual',signal:AbortSignal.timeout(5000)});
 if(!r.ok||!r.headers.get('content-type')?.includes('application/json'))return false;
 const v=await r.json(),perms=v.effectivePermissionKeys;
 if(!Array.isArray(perms)||!perms.includes('orders.view'))return false;
 if(manage)return perms.includes('system.permissions.manage');
 return perms.includes('orders.create')&&(perms.includes('system.permissions.manage')||(v.user?.name==='vita'&&Array.isArray(v.roleKeys)&&v.roleKeys.includes('role_preset_operations')));
}
function collaborationAllowed(req,url){
 const p=url.pathname, method=req.method, type=String(req.headers['content-type']||'');
 if(p==='/orders/collaboration/partners'&&method==='GET')return true;
 if(p==='/orders/oms/import/template'&&method==='GET')return true;
 if(collaborationPartners(p))return ((p==='/orders/collaboration/partners'&&method==='POST')||(/^\/orders\/collaboration\/partners\/[1-9][0-9]*$/.test(p)&&method==='PATCH'))&&type.startsWith('application/json')&&Boolean(req.headers['x-xsrf-token']);
 return collaborationImport(p)&&p!=='/orders/oms/import/template'&&method==='POST'&&type.startsWith('multipart/form-data;')&&Boolean(req.headers['x-xsrf-token']);
}
async function proxy(req,res,url){
 const headers={Accept:req.headers.accept||'text/html','X-Requested-With':req.headers['x-requested-with']||'XMLHttpRequest'};
 if(isInertia(url.pathname))headers['X-Inertia']='true';
 if(isInertia(url.pathname)&&req.headers['x-inertia-version'])
  headers['X-Inertia-Version']=String(req.headers['x-inertia-version']).slice(0,128);
 if(req.headers.cookie)headers.Cookie=req.headers.cookie;
 if(req.headers['content-type'])headers['Content-Type']=req.headers['content-type'];
 if(req.headers['x-csrf-token'])headers['X-CSRF-TOKEN']=req.headers['x-csrf-token'];
 if(url.pathname==='/settings/backup/download'&&req.headers['x-xsrf-token'])headers['X-XSRF-TOKEN']=req.headers['x-xsrf-token'];
 if((isVitaWrite(req,url)||overseaRoute(url.pathname)||noteWrite(url.pathname)||reviewWrite.has(url.pathname)||allocationWrite(url.pathname)||shippingWrite(url.pathname)||accountPreferences(url.pathname)||domesticTrackingPreview.has(url.pathname)||domesticTrackingApply.has(url.pathname)||collaborationPartners(url.pathname)||collaborationImport(url.pathname))&&req.headers['x-xsrf-token'])
  headers['X-XSRF-TOKEN']=req.headers['x-xsrf-token'];
 const payload=[];
 let payloadBytes=0;
 const payloadLimit=collaborationImport(url.pathname)?5*1024*1024+65536:(overseaPreview(url.pathname)||domesticTrackingPreview.has(url.pathname))?10*1024*1024+65536:16384;
 if(['POST','PUT','PATCH'].includes(req.method))for await(const c of req){
  payloadBytes+=c.length;
  if(payloadBytes>payloadLimit)throw Error('ERP2_DEV_REQUEST_BODY_TOO_LARGE');
  payload.push(c);
 }

 if(collaborationImport(url.pathname)&&req.method==='POST'){
  try{
   const body=Buffer.concat(payload);
   const form=await new Request('http://127.0.0.1/',{method:'POST',headers:{'Content-Type':String(req.headers['content-type'])},body}).formData();
   const fields=url.pathname.endsWith('/confirm')?['file','import_type','partner_id','token','confirm']:['file','import_type','partner_id'];
   if([...form.keys()].some(k=>!fields.includes(k))||fields.some(k=>form.getAll(k).length!==1)||form.get('import_type')!=='collaboration'||! /^[1-9][0-9]*$/.test(String(form.get('partner_id')))||typeof form.get('file')==='string')return json(res,422,{error:'ERP2_COLLABORATION_IMPORT_ONLY'});
  }catch{return json(res,422,{error:'ERP2_COLLABORATION_MULTIPART_INVALID'});}
 }
 const upstream=await fetch(MAC+url.pathname+url.search,{
  method:req.method,headers,body:payload.length?Buffer.concat(payload):undefined,
  redirect:'manual',signal:AbortSignal.timeout(url.pathname==='/settings/backup/download'?120000:15000),
 });
 const responseHeaders={
  'content-type':upstream.headers.get('content-type')||'text/plain',
  'cache-control':'no-store,private','x-content-type-options':'nosniff',
 };
 // Preserve original Laravel XLSX attachment name and export audit totals.
 if((overseaExportRead(url.pathname)||domesticExportRead.has(url.pathname)||url.pathname.endsWith('/manual-export')||url.pathname==='/orders/oms/import/template'||url.pathname==='/orders/collaboration/export')&&upstream.ok){
  for(const header of ['content-disposition','x-byl-exported-orders','x-byl-blocked-orders']){
   const value=upstream.headers.get(header);
   if(value)responseHeaders[header]=value;
  }
 }
 if(url.pathname==='/settings/backup/download'&&upstream.ok){
  for(const name of ['content-disposition','content-length','x-backup-sha256']){
   const value=upstream.headers.get(name);if(value)responseHeaders[name]=value;
  }
 }
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
const canonicalPages=new Set(["/accounting","/accounting/order-costs","/customers","/customers/companies","/customers/groups","/customers/import","/customers/library","/customers/orders","/dashboard","/guides","/guides/manuals","/guides/videos","/inventory","/inventory/list","/inventory/packaging","/inventory/products","/inventory/sync","/kol","/kol/affiliatewp","/kol/approvals/collaborations","/kol/approvals/kols","/kol/approvals/payouts","/kol/collaborations","/kol/commissions","/kol/communications","/kol/content","/kol/library","/kol/library/create","/kol/logistics","/kol/opportunities","/kol/orders","/kol/samples","/logistics","/logistics/awaiting-tracking","/logistics/carriers","/logistics/china","/logistics/exceptions","/logistics/in-transit","/logistics/oversea","/logistics/tingon","/logistics/wait-stock","/marketing","/marketing/automations","/marketing/campaigns","/marketing/newsletters","/marketing/promotions","/marketing/subscribers","/marketing/traffic","/orders","/orders/archive","/orders/collaboration","/orders/procurement","/products","/products/factory-parameters","/products/list","/products/sku-aliases","/products/skus","/products/weight-evidence","/purchase","/purchase/external","/purchase/files","/purchase/records","/settings","/settings/audit-logs","/settings/backup","/settings/changelog","/settings/fx","/settings/roles-permissions","/settings/statuses","/settings/users","/settings/warehouses","/sync","/sync/customers/import","/sync/exports","/sync/kol","/sync/orders","/sync/warehouses","/","/auth/login","/403","/login"]);
function isDocumentNavigation(req,url){
 if(!["GET","HEAD"].includes(req.method)||req.headers["x-inertia"]||req.headers["x-requested-with"]||!String(req.headers.accept||"").includes("text/html"))return false;
 return req.headers["sec-fetch-mode"]==="navigate" && req.headers["sec-fetch-dest"]==="document" && (canonicalPages.has(url.pathname)||inertiaDetail(url.pathname)||logisticsDetail(url.pathname)||/^\/inventory\/[a-z0-9-]+$/.test(url.pathname)||/^\/erp\/inventory\/inventory-warehouse-[1-9][0-9]*$/.test(url.pathname));
}
function spaIndex(req,res){
 const file=path.join(DIST,"index.html");
 if(!fs.existsSync(file))return json(res,503,{error:"DEV_VBEN_BUILD_MISSING"});
 res.writeHead(200,{"content-type":"text/html; charset=utf-8","x-content-type-options":"nosniff","cache-control":"no-store"});
 if(req.method==="HEAD")return res.end();
 fs.createReadStream(file).pipe(res);
}
function staticFile(req,res,url){
 if(!['GET','HEAD'].includes(req.method))return json(res,405,{error:'STATIC_READ_ONLY'});
 let decoded;try{decoded=decodeURIComponent(url.pathname);}catch{return json(res,400,{error:'BAD_URL'});}
 const target=path.resolve(DIST,'.'+decoded);
 if(target!==DIST&&!target.startsWith(DIST+path.sep))return json(res,403,{error:'BAD_FILE'});
 let file=target;try{if(!fs.statSync(file).isFile())return json(res,404,{error:'ERP2_DEV_PATH_NOT_FOUND'});}
 catch{return json(res,404,{error:'ERP2_DEV_PATH_NOT_FOUND'});}
 if(!fs.existsSync(file))return json(res,503,{error:'DEV_VBEN_BUILD_MISSING'});
 res.writeHead(200,{'content-type':mime[path.extname(file)]||'application/octet-stream',
  'x-content-type-options':'nosniff','cache-control':file.endsWith('index.html')?'no-store':'public,max-age=300'});
 if(req.method==='HEAD')return res.end();
 fs.createReadStream(file).pipe(res);
}
const server=http.createServer(async(req,res)=>{
 try{
  const url=new URL(req.url||'/','http://'+HOST+':'+PORT);
  if(url.pathname==='/api/health'&&req.method==='GET'){const check=await fetch(MAC+'/up',{signal:AbortSignal.timeout(4000),redirect:'manual'});if(!check.ok)return json(res,503,{error:'LOCAL_LARAVEL_UNAVAILABLE'});return json(res,200,{
    source:'ISOLATED_007WINDOWS_DEV_MYSQL',production_connected:false,
    write_enabled:VITA_UAT_WRITE?'vita-order-allocation-only':'account-preferences-only',release:'ERP2 local integration 20261125',order_create_enabled:VITA_UAT_WRITE,oversea_flow_enabled:OVERSEA_DEV_WRITE,oms_simulation_write_enabled:OMS_SIM_WRITE,
    domestic_apply_enabled:DOMESTIC_APPLY_DEV_WRITE,mode:'laravel-session',app:'ERP2 Vben Logistics Dev',backend:'127.0.0.1:18823'});}
  if(url.pathname==='/settings/backup/download'){
   if(req.method!=='POST'||!req.headers['content-type']?.startsWith('application/json')||!req.headers['x-xsrf-token'])return json(res,405,{error:'ERP2_BACKUP_METHOD_DENIED'});
   return await proxy(req,res,url);
  }
  if(url.pathname==='/orders/collaboration/export'){if(req.method!=='GET')return json(res,405,{error:'ERP2_COLLABORATION_METHOD_DENIED'});return await proxy(req,res,url);}
  if(collaborationPartners(url.pathname)||collaborationImport(url.pathname)){
   if(!collaborationAllowed(req,url))return json(res,405,{error:'ERP2_COLLABORATION_METHOD_DENIED'});
   if(!(collaborationPartners(url.pathname)&&req.method==='GET')&&!await collaborationAuthorized(req,collaborationPartners(url.pathname)))return json(res,403,{error:'ERP2_COLLABORATION_PERMISSION_REQUIRED'});
   return await proxy(req,res,url);
  }
  // ERP2 allocation product catalog: existing isolated Vita session + Laravel allocation ACL.
  if(url.pathname==='/logistics/wait-stock/allocation-products'){
   if(req.method!=='GET')return json(res,405,{error:'ERP2_ALLOCATION_CATALOG_READ_ONLY'});
   const ids=url.searchParams.getAll('product_id');
   if([...url.searchParams.keys()].some(k=>k!=='product_id')||ids.length>1||ids.some(v=>! /^[1-9][0-9]*$/.test(v)||!Number.isSafeInteger(Number(v))))return json(res,422,{error:'ERP2_ALLOCATION_PRODUCT_ID_INVALID'});
   if(!await authorizedVita(req))return json(res,403,{error:'ERP2_VITA_UAT_SESSION_REQUIRED'});
   return await proxy(req,res,url);
  }
  if(isDocumentNavigation(req,url))return spaIndex(req,res);
  if(isVitaWrite(req,url)||allocationRead(url.pathname)){
   if(!allowed(req,url))return json(res,405,{error:'ERP2_DEV_METHOD_OR_PATH_DENIED'});
   if(!await authorizedVita(req))return json(res,403,{error:'ERP2_VITA_UAT_SESSION_REQUIRED'});
   return await proxy(req,res,url);
  }
  // Never let nested Laravel business URLs fall through to a misleading SPA 200.
  if(url.pathname.startsWith('/logistics/')&&!isInertia(url.pathname)&&
     !overseaRoute(url.pathname)&&!reviewWrite.has(url.pathname)&&!allocationWrite(url.pathname)&&!allocationRead(url.pathname)&&!barcodeRead(url.pathname)&&!shippingWrite(url.pathname)&&!domesticExportRead.has(url.pathname)&&!domesticTrackingPreview.has(url.pathname)&&!domesticTrackingApply.has(url.pathname))
    return json(res,405,{error:'ERP2_DEV_METHOD_OR_PATH_DENIED'});
  if(/^\/(orders|customers\/orders|kol\/orders)\/.+/.test(url.pathname)&&
     !isInertia(url.pathname)&&!noteWrite(url.pathname))
    return json(res,405,{error:'ERP2_DEV_METHOD_OR_PATH_DENIED'});
  if(url.pathname==='/products/sku-search'||url.pathname.startsWith('/api/')||isInertia(url.pathname)||
     overseaRoute(url.pathname)||noteWrite(url.pathname)||reviewWrite.has(url.pathname)||allocationWrite(url.pathname)||allocationRead(url.pathname)||barcodeRead(url.pathname)||shippingWrite(url.pathname)||accountPreferences(url.pathname)||domesticExportRead.has(url.pathname)||domesticTrackingPreview.has(url.pathname)||domesticTrackingApply.has(url.pathname)||
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