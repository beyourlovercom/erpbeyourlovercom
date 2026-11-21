'use strict';
// Internal BYL ERP R2 Dev orders projection bridge. Tailnet-only and secret-gated.
// Never forwards writes, cookies, arbitrary URLs or live Production traffic.
const fs = require('node:fs');
const http = require('node:http');
const crypto = require('node:crypto');
const SECRET = fs.readFileSync(__dirname + '/bridge.token', 'utf8').trim();
const cfg=JSON.parse(fs.readFileSync(__dirname+'/bridge.config.json','utf8'));
const HOST=String(cfg.bindHost||'');
const PORT=18796;
const DB=String(cfg.sqliteFile||'');
const ip=HOST.split('.').map(Number);
if(ip.length!==4||ip[0]!==100||ip[1]<64||ip[1]>127||ip.some(x=>!Number.isInteger(x)||x<0||x>255))
  throw new Error('TAILNET_ONLY_BIND_HOST_REQUIRED');
if(!fs.realpathSync(DB).startsWith('/Users/a/AI/007/uat/')||!DB.endsWith('.sqlite')||!fs.statSync(DB).isFile())
  throw new Error('LOCAL_DEV_SQLITE_REQUIRED');
function send(res,status,payload) {
  res.writeHead(status, {'content-type':'application/json; charset=utf-8',
    'cache-control':'no-store','x-content-type-options':'nosniff'});
  res.end(JSON.stringify(payload));
}
function eq(actual) {
  const a=Buffer.from(String(actual||'')), b=Buffer.from(SECRET);
  return a.length===b.length && crypto.timingSafeEqual(a,b);
}
function object(input){return input && typeof input==='object' && !Array.isArray(input) ? input : {};}
function text(value) { return typeof value==='string' ? value : value==null ? '' : String(value); }
function int(value,dflt){const n=Number(value);return Number.isSafeInteger(n)&&n>0?n:dflt;}
function preview(row) {
  const src=object(row), shipment=object(src.shipment), workflow=object(src.workflow_status_projection);
  const items=Array.isArray(src.items)?src.items.map(object):[];
  return {
    id:int(src.id,1),erp_order_id:text(src.erp_order_id),
    external_order_number:text(src.external_order_number),
    internal_order_number:text(src.internal_order_number),
    order_kind_label:text(src.order_kind_label || src.order_kind),
    order_date:text(src.order_date),
    order_status_label:text(src.order_status_label),
    workflow_status_projection:{zh:text(workflow.zh),code:text(workflow.code)},
    latest_order_note:'',
    items:items.map(x=>({sku:text(x.sku),quantity:int(x.quantity,1)})),
    shipment:{warehouse_name:text(shipment.warehouse_name),
      tracking_number:text(shipment.tracking_number)},
  };
}
http.createServer(async (req,res)=>{
  try {
    if(!eq(req.headers['x-byl-r2-token']))return send(res,403,{error:'FORBIDDEN'});
    if(req.method!=='GET')return send(res,405,{error:'READ_ONLY'});
    const url=new URL(req.url||'/', 'http://' + HOST + ':' + PORT);
    if(url.pathname==='/r2/health')return send(res,200,{ok:true,source:'MAC_LOCAL_DEV_SQLITE',writes:false});
    if(url.pathname!=='/r2/orders')return send(res,404,{error:'UNKNOWN_ROUTE'});
    if(!fs.existsSync(DB))return send(res,503,{error:'DEV_DB_NOT_FOUND'});
    const search=(url.searchParams.get('search')||'').trim().slice(0,90);
    const view=(url.searchParams.get('view')||'all');
    const allowedViews=['all','pending','exception','in_transit','shipped','return','loss','overweight','stockout'];
    if(!allowedViews.includes(view))return send(res,400,{error:'INVALID_VIEW'});
    const page=Math.min(999,Math.max(1,int(url.searchParams.get('page'),1)));
    const size=[20,50,100].includes(int(url.searchParams.get('per_page'),50))?int(url.searchParams.get('per_page'),50):50;
    const query=new URLSearchParams({page:String(page),per_page:String(size),view});
    if(search)query.set('search',search);
    const response=await fetch('http://erp.b/orders?'+query.toString(),{
      headers:{Accept:'text/html, application/xhtml+xml'},
      redirect:'manual',signal:AbortSignal.timeout(9500),
    });
    if(response.status!==200)return send(res,502,{error:'DEV_ERP_STATUS_'+response.status});
    const body=await response.text();
    if(body.length>3000000)return send(res,502,{error:'RESPONSE_TOO_LARGE'});
    const found=body.match(/<script[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/);
    if(!found)return send(res,502,{error:'DEV_INERTIA_HTML_MISSING'});
    const document=JSON.parse(found[1]);
    if(document.component!=='Orders/Index')return send(res,502,{error:'UNEXPECTED_ERP_COMPONENT'});
    const props=object(document.props), orders=object(props.orders);
    if(!Array.isArray(orders.data))return send(res,502,{error:'DEV_DATA_MISSING'});
    const clean={component:'Orders/Index',props:{
      orders:{data:orders.data.map(preview),current_page:int(orders.current_page,1),
        per_page:int(orders.per_page,50),total:Number(orders.total)||0,
        last_page:int(orders.last_page,1)},
      filters:{search,view},
    }};
    send(res,200,{source:'MAC_LOCAL_DEV_SQLITE',real_dev_rows:true,
      scope:'DEV_UAT_OWNER_ONLY',schema_version:'r2-20261114',inertia:clean});
  }catch(e){send(res,502,{error:'DEV_ERP_READ_FAILED'});}
}).listen(PORT,HOST,()=>console.log('R2_MAC_DEV_READONLY_18796_READY'));