import { laravelInertiaVersion } from './erp-orders';
import {logisticsQueryCapabilities,isLogisticsQueue,originalQueueRoutes,type LogisticsQueue,type LogisticsFilterKey,type LogisticsSortKey} from './logistics-query-contract';

/** ERP2 Dev-only read projections. Laravel Session and logistics.view are the authority. */
export type LogisticsScope = 'allocation' | 'domestic' | 'overseas' | LogisticsQueue;
export type DomesticView =
  | 'review' | 'stockout' | 'outsource' | 'pending' | 'today'
  | 'today-4px' | 'today-weship' | 'exceptions' | 'all';
export type OverseasView =
  | 'review' | 'stockout' | 'pending' | 'pending_dispatch' | 'issued'
  | 'dispatched' | 'shipped' | 'exceptions' | 'all';
export interface LogisticsItem {
  sku: string; source_sku: string; original_sku: string;
  resolved_sku: string; final_sku: string; quantity: number;
  has_liquid?:boolean;
}
export interface LogisticsRow {
  key: string; order_id: number; shipment_id: null | number;
  order_number: string; order_date: string; status: string;
  fulfillment_suffix: string; fulfillment_parent_id: null | number;
  warehouse_code: string; carrier: string; service: string; tracking: string; shipment_status: string;
  review_status: string; oms_status: string; oms_order_number: string;
  items: LogisticsItem[]; sku: string; actual_sku: string; qty: number;
  line?:number; paid_date?:string; source_type?:string; order_kind?:string;
  warehouse_route?:string; warehouse_name?:string; material_full_name?:string; has_liquid?:boolean;
  amount?:string; export_status?:string; provider_reference?:string; country?:string;
  tracking_sync_status?:string; tracking_sync_reason?:string; tracking_sync_verified_at?:string; shipped_at?:string; reviewer_name?:string; reviewed_at?:string;
  dispatch_status?:string; exception_type?:string; procurement_number?:string; procurement_status?:string;
  original_number?:string; original_status?:string; order_kind_label?:string; us_zone?:string;
  warehouse_priority?:string; proposed_warehouse?:string; proposed_cost?:string; allocation_status?:string;
  allocation_plan?:AllocationPlan;
  has_gift?:boolean; owner_label?:string; rejection_state?:string; rejection_type?:string; rejected_at?:string; rejected_by?:string;


}
export interface LogisticsCarrierOption {value:string;label:string;default_service_value:string;services:{value:string;label:string}[]}
export interface LogisticsPage {
  rows: LogisticsRow[]; page: number; perPage: number; total: number; lastPage: number;
  canReview?: boolean; canOperate?: boolean; carrierOptions?:LogisticsCarrierOption[]; allocationWarehouses?:AllocationWarehouse[]; omsCapabilities?:OmsCapabilities;
}
const ENDPOINT: Record<Exclude<LogisticsScope,LogisticsQueue>, string> = {
  allocation: '/api/erp2/v1/logistics/pending-allocation',
  domestic: '/api/erp2/v1/logistics/domestic/pending',
  overseas: '/api/erp2/v1/logistics/overseas',
};
type Obj = Record<string, unknown>;
const obj = (v: unknown): Obj => v && typeof v === 'object' && !Array.isArray(v) ? v as Obj : {};
const str = (v: unknown): string => typeof v === 'string' ? v : v == null ? '' : String(v);
const integer = (v: unknown, fallback = 0): number => {
  const n = Number(v); return Number.isSafeInteger(n) && n >= 0 ? n : fallback;
};
const optionalId = (v: unknown): null | number => v == null ? null : integer(v);
const normalizeItems = (input: unknown): LogisticsItem[] => {
  if (!Array.isArray(input)) throw new Error('ERP2_LOGISTICS_ITEMS_CONTRACT_INVALID');
  return input.map((entry): LogisticsItem => {
    const i = obj(entry);
    return {
      sku: str(i.sku), source_sku: str(i.source_sku), original_sku: str(i.original_sku),
      resolved_sku: str(i.resolved_sku || i.resolved_fulfillment_sku), final_sku: str(i.final_sku),
      quantity: integer(i.quantity),has_liquid:typeof i.has_liquid==='boolean'?i.has_liquid:undefined,
    };
  });
};

const businessFields = (r:Obj,items:LogisticsItem[]):Partial<LogisticsRow> => {
  const route=obj(r.routing_warehouse),dispatch=obj(r.warehouse_dispatch),procurement=obj(r.procurement);
  const blockers=Array.isArray(r.export_blockers)?r.export_blockers.filter((x):x is string=>typeof x==='string'):null;
  const materials=Array.isArray(r.items)?r.items.map(i=>str(obj(i).material_full_name)).filter(Boolean):[];
  return {
    line:typeof r.line==='number'&&Number.isSafeInteger(r.line)?r.line:undefined,
    paid_date:str(r.paid_date),source_type:str(r.source_type),order_kind:str(r.order_kind),
    warehouse_route:str(route.name || r.routing_warehouse_name || r.warehouse_route_name),
    warehouse_name:str(r.warehouse_name),
    material_full_name:[...new Set(materials)].join(' / '),
    has_liquid:typeof r.has_liquid==='boolean'?r.has_liquid:
      items.some(i=>i.has_liquid===true)?true:items.length>0&&items.every(i=>i.has_liquid===false)?false:undefined,
    amount:r.amount==null||r.amount===''?'': [str(r.amount),str(r.amount_currency)].filter(Boolean).join(' '),
    export_status:blockers===null?'':blockers.length?blockers.join(' / '):'可导出',
    provider_reference:str(r.provider_reference),country:str(r.country),
    tracking_sync_status:str(r.tracking_sync_status),tracking_sync_reason:str(r.tracking_sync_reason),tracking_sync_verified_at:str(r.tracking_sync_verified_at),shipped_at:str(r.shipped_at),
    reviewer_name:str(r.reviewer_name),reviewed_at:str(r.reviewed_at),
    dispatch_status:str(dispatch.label),exception_type:str(r.exception_type),
    procurement_number:str(procurement.number),procurement_status:str(procurement.status),
  };
};

/** Never project customer names, addresses, contact data or order notes to the Grid. */
export function parseLogisticsPage(scope: LogisticsScope, input: unknown): LogisticsPage {
  const payload = obj(input),meta = obj(payload.meta);
  if (!Array.isArray(payload.data) || !Number.isSafeInteger(meta.current_page) ||
      !Number.isSafeInteger(meta.per_page) || !Number.isSafeInteger(meta.total) ||
      !Number.isSafeInteger(meta.last_page)) throw new Error('ERP2_LOGISTICS_PAGE_CONTRACT_INVALID');
  const rows = payload.data.map((record): LogisticsRow => {
    const r = obj(record),isOverseas = scope === 'overseas';
    const orderId = integer(isOverseas ? r.order_id : r.id);
    const shipmentId = isOverseas ? optionalId(r.shipment_id) : null;
    if (!orderId || (isOverseas && !shipmentId) || !str(r.order_number))
      throw new Error('ERP2_LOGISTICS_ROW_CONTRACT_INVALID');
    const items = normalizeItems(r.items),oms = obj(r.oms);
    return {
      ...businessFields(r,items),
      key: isOverseas ? 'shipment-'+shipmentId : 'order-'+orderId,
      order_id: orderId, shipment_id: shipmentId,
      order_number: str(r.order_number), order_date: str(r.order_date),
      status: str(isOverseas ? r.workflow_status || r.shipment_status : r.status),
      fulfillment_suffix: str(r.fulfillment_suffix),
      fulfillment_parent_id: optionalId(r.fulfillment_parent_id),
      warehouse_code: str(r.warehouse_code), carrier: str(r.carrier),service: str(r.service),
      tracking: str(r.tracking), shipment_status:str(r.shipment_status), review_status: str(r.review_status),
      oms_status: str(oms.status_code || oms.state), oms_order_number: str(oms.order_number),
      items, sku: items.map(i => i.sku).filter(Boolean).join(' / '),
      actual_sku: items.map(i => i.final_sku || i.resolved_sku || i.sku).filter(Boolean).join(' / '),
      qty: items.reduce((sum,i) => sum+i.quantity,0),
    };
  });
  return {rows,page:integer(meta.current_page,1),perPage:integer(meta.per_page,20),
    total:integer(meta.total),lastPage:integer(meta.last_page,1)};
}
/** Reuse the ORIGINAL Laravel workbench's nine/seven canonical queues and actor gates.
 * Only allowlisted business fields leave this adapter; no contacts or raw page props. */
export function parseOriginalLogisticsInertiaPage(
  scope: 'domestic' | 'overseas', input: unknown,
): LogisticsPage {
  const page=obj(input),props=obj(page.props),pagination=obj(props.rows);
  if (page.component !== (scope==='domestic'?'Logistics/Domestic/Index':'Logistics/Oversea/Show') ||
      !Array.isArray(pagination.data) ||
      ![pagination.current_page,pagination.per_page,pagination.total,pagination.last_page]
        .every(n=>Number.isSafeInteger(n) && (n as number)>=0))
    throw new Error('ERP2_ORIGINAL_LOGISTICS_INERTIA_CONTRACT_INVALID');
  const rows=pagination.data.map((record):LogisticsRow=>{
    const r=obj(record),orderId=integer(r.order_id),items=normalizeItems(r.items);
    const orderNumber=str(r.erp_order_id);
    if (!orderId || !orderNumber) throw new Error('ERP2_ORIGINAL_LOGISTICS_ROW_INVALID');
    const oms=obj(r.xlwms),route=obj(r.routing_warehouse),status=obj(r.workflow_status_projection);
    return {
      ...businessFields(r,items),
      key:'order-'+orderId,order_id:orderId,shipment_id:optionalId(r.shipment_id),
      order_number:orderNumber,order_date:str(r.order_date || r.paid_date),
      status:str(status.zh || status.label || status.code || r.shipment_status),
      fulfillment_suffix:str(r.fulfillment_suffix),
      fulfillment_parent_id:optionalId(r.fulfillment_parent_id),
      warehouse_code:str(r.warehouse_code || route.code || (scope==='domestic'?'FACTORY':'')),
      carrier:str(r.carrier),service:str(r.service),tracking:str(r.tracking_number),
      shipment_status:str(r.shipment_status),review_status:str(r.review_status),oms_status:str(oms.oms_status_code || oms.state),
      oms_order_number:str(oms.oms_order_no),
      items,sku:items.map(i=>i.sku).filter(Boolean).join(' / '),
      actual_sku:items.map(i=>i.final_sku || i.resolved_sku || i.sku)
        .filter(Boolean).join(' / '),
      qty:items.reduce((sum,i)=>sum+i.quantity,0),
    };
  });
  return {rows,page:integer(pagination.current_page,1),perPage:integer(pagination.per_page,20),
    total:integer(pagination.total),lastPage:integer(pagination.last_page,1),
    canReview:props.canReview===true,canOperate:props.canOperate===true,
    omsCapabilities:scope==='overseas'?parseOmsCapabilities(props):undefined,
    carrierOptions:Array.isArray(props.carrierOptions)?props.carrierOptions.map(raw=>{
      const c=obj(raw);return {value:str(c.value),label:str(c.label),
        default_service_value:str(c.default_service_value),
        services:Array.isArray(c.services)?c.services.map(s=>({value:str(obj(s).value),label:str(obj(s).label)})):[]};
    }).filter(c=>c.value):[]};
}
export function parseOriginalAllocationOrQueue(scope:'allocation'|LogisticsQueue,input:unknown):LogisticsPage{
  const p=obj(input),props=obj(p.props),allocation=scope==='allocation';
  const component=allocation?'Logistics/FulfillmentAllocation':{
    awaiting:'Logistics/AwaitingTracking',transit:'Logistics/InTransit',exceptions:'Logistics/Exceptions'
  }[scope as LogisticsQueue];
  const pager=obj(allocation?props.orders:props.shipments);
  if(p.component!==component||!Array.isArray(pager.data)||
     ![pager.current_page,pager.per_page,pager.total,pager.last_page].every(n=>Number.isSafeInteger(n)&&(n as number)>=0))
    throw Error('ERP2_ORIGINAL_LOGISTICS_QUEUE_CONTRACT_INVALID');
  const rows=pager.data.map((record,index):LogisticsRow=>{
    const r=obj(record),shipment=obj(r.shipment),route=obj(r.routing_warehouse),status=obj(r.workflow_status_projection);
    const id=integer(allocation?r.id:r.order_id),number=str(r.erp_order_id),shipmentId=optionalId(r.shipment_id);
    if(!id||!number||(!allocation&&!shipmentId))throw Error('ERP2_ORIGINAL_LOGISTICS_QUEUE_ROW_INVALID');
    const items=normalizeItems(r.items);
    const zone=obj(r.us_fulfillment_zone),decision=obj(r.allocation_decision),plan=obj(decision.recommended_plan),rejection=obj(r.allocation_rejection);
    const segments=Array.isArray(plan.segments)?plan.segments.map(s=>str(obj(s).warehouse_name)).filter(Boolean):[];
    return {...businessFields(r,items),
      key:allocation?'order-'+id:'shipment-'+shipmentId,order_id:id,shipment_id:shipmentId,order_number:number,
      line:(integer(pager.current_page,1)-1)*integer(pager.per_page,20)+index+1,
      order_date:str(r.order_date),status:str(status.zh||status.code||r.shipment_status),fulfillment_suffix:str(r.fulfillment_suffix),
      fulfillment_parent_id:optionalId(r.fulfillment_parent_id),warehouse_code:str(route.code||r.warehouse_code),
      warehouse_name:str(r.warehouse_name||shipment.warehouse_name),carrier:str(r.carrier||shipment.carrier),service:str(r.service||shipment.service),
      tracking:str(r.tracking_number||shipment.tracking_number),shipment_status:str(r.shipment_status),review_status:'',
      oms_status:'',oms_order_number:'',items,sku:items.map(i=>i.sku).filter(Boolean).join(' / '),
      actual_sku:items.map(i=>i.final_sku||i.resolved_sku||i.sku).filter(Boolean).join(' / '),qty:items.reduce((s,i)=>s+i.quantity,0),
      allocation_plan:allocation?parseAllocationPlan(r):undefined,
      original_number:str(r.external_order_number),original_status:str(r.order_status),order_kind_label:str(r.order_kind_label),
      us_zone:r.country==='US'?(zone.status==='matched'?str(zone.zone_label)||'已识别':'需人工确认'):'',
      warehouse_priority:Array.isArray(zone.warehouse_priority)?zone.warehouse_priority.map(str).join(' → '):'',
      proposed_warehouse:segments.join(' / '),proposed_cost:decision.estimated_cost==null?'':[str(decision.estimated_cost),str(decision.currency)].filter(Boolean).join(' '),
      allocation_status:str(decision.status_label),has_gift:Array.isArray(r.items)&&r.items.length>0&&r.items.every(i=>typeof obj(i).is_gift==='boolean')?r.items.some(i=>obj(i).is_gift===true):undefined,
      owner_label:str(r.owner_label),rejection_state:r.allocation_rejection?'已驳回':'',rejection_type:str(rejection.source),
      rejected_at:str(rejection.at),rejected_by:str(rejection.by),
    };
  });
  return {rows,page:integer(pager.current_page,1),perPage:integer(pager.per_page,20),total:integer(pager.total),lastPage:integer(pager.last_page,1),canReview:false,canOperate:allocation&&props.canOperate===true,allocationWarehouses:allocation&&Array.isArray(props.warehouses)?props.warehouses.map(w=>({code:str(obj(w).code),name:str(obj(w).name)})).filter(w=>/^[A-Z0-9-]+$/.test(w.code)):[]};
}

export interface LogisticsQuery {
  scope: LogisticsScope; page?: number; perPage?: 20 | 50 | 100;
  search?: string; view?: DomesticView | OverseasView; warehouse?: string;
  sortCol?: LogisticsSortKey; sortDir?: 'asc' | 'desc';
  columnFilters?: Partial<Record<LogisticsFilterKey, string>>;
}
export async function getLogisticsPage(query: LogisticsQuery, fetcher: typeof fetch = fetch): Promise<LogisticsPage> {
  // Fail closed: the local-JWT SiteOps fixture must never impersonate an ERP employee.
  if (import.meta.env.VITE_ERP_IDENTITY_MODE !== 'laravel') throw new Error('ERP2_LARAVEL_SESSION_REQUIRED');
  const original = import.meta.env.VITE_ERP_M2_ORIGINAL_INERTIA_ENABLED === '1';
  const queue=isLogisticsQueue(query.scope);
  if(queue&&!original)throw Error('ERP2_ORIGINAL_LOGISTICS_QUEUE_REQUIRED');
  const params = new URLSearchParams({
    page:String(Math.max(1,query.page ?? 1)),per_page:String(query.perPage ?? 20),
  });
  if (query.search?.trim()) params.set('search',query.search.trim().slice(0,100));
  const queryCaps=logisticsQueryCapabilities(query.scope,query.view??'review');
  if(original && queryCaps.sortKeys.includes(query.sortCol||'')){
    if(queue){params.set('sort',query.sortCol==='tracking'?'tracking_number':query.sortCol!);params.set('direction',query.sortDir==='asc'?'asc':'desc');}
    else {params.set('sort_col',query.sortCol!);params.set('sort_dir',query.sortDir==='asc'?'asc':'desc');}
  }
  if (original) for(const [key,value] of Object.entries(query.columnFilters||{})){
    const serverKey=Object.prototype.hasOwnProperty.call(queryCaps.filterMap,key)?queryCaps.filterMap[key as LogisticsFilterKey]:undefined;
    if(serverKey && value?.trim())params.set('filter_'+serverKey,value.trim().slice(0,100));
  }
  if (query.scope==='overseas'||(original&&query.scope==='domestic')) params.set('view',query.view ?? 'review');
  if(queue)params.set('global_grid','1');
  const warehouse = query.warehouse?.trim() || '';
  if (warehouse && !['us-ny','us-sm','us-3sm','us-moreplus','de-tianyun','ca'].includes(warehouse))
    throw new Error('ERP2_LOGISTICS_WAREHOUSE_INVALID');
  const endpoint = original
    ? query.scope==='allocation'?'/logistics/wait-stock':isLogisticsQueue(query.scope)?originalQueueRoutes[query.scope]:query.scope === 'domestic' ? '/logistics/china' :
      '/logistics/oversea'+(warehouse ? '/'+(warehouse==='us-sm'?'us-3sm':warehouse) : '')
    : ENDPOINT[query.scope as Exclude<LogisticsScope,LogisticsQueue>];
  if (!original && query.scope === 'overseas' && warehouse) params.set('warehouse',warehouse);
  // The old ERP requires an Inertia asset version; reuse the orders handshake.
  // On 409 (asset rollover), refresh once, exactly as the original orders adapter does.
  for (const refresh of (original ? [false,true] : [false])) {
    const version=original ? await laravelInertiaVersion(fetcher,refresh) : '';
    const response = await fetcher(endpoint+'?'+params.toString(),{
      method:'GET',credentials:'same-origin',cache:'no-store',redirect:'manual',
      headers:{Accept:original?'text/html, application/xhtml+xml':'application/json',
        'X-Requested-With':'XMLHttpRequest',
        ...(original ? {'X-Inertia':'true','X-Inertia-Version':version} : {})},
    });
    if (original && response.status===409 && !refresh) continue;
    if ([0,302,401,403,419].includes(response.status))
      throw new Error('ERP2_LOGISTICS_LOGIN_OR_PERMISSION_REQUIRED');
    if (response.status === 404) throw new Error('ERP2_LOGISTICS_DEV_FEATURE_UNAVAILABLE');
    if (!response.ok || !(response.headers.get('content-type')||'').includes('application/json'))
      throw new Error('ERP2_LOGISTICS_GET_FAILED_'+response.status);
    const payload:unknown=await response.json();
    return original ? (query.scope==='domestic'||query.scope==='overseas'?parseOriginalLogisticsInertiaPage(query.scope,payload):parseOriginalAllocationOrQueue(query.scope,payload))
      : parseLogisticsPage(query.scope,payload);
  }
  throw new Error('ERP2_LOGISTICS_INERTIA_VERSION_RETRY_EXHAUSTED');
}

/** Same registered Laravel controller used by old ERP; one order per UI decision. */
export type DomesticReviewAction = 'approve' | 'stockout' | 'reject' | 'restock' | 'return_allocation';
export type OverseasReviewAction = 'approve' | 'stockout' | 'reject' | 'restock';
export interface OriginalReviewResult {
  results: { order_id: number; ok: boolean; message: string }[];
  success_count: number;
  failed_count: number;
}
export async function submitOriginalErpReview(
  input: { scope: 'domestic'; orderIds: number[]; action: DomesticReviewAction; reason?: string }
    | { scope: 'overseas'; orderIds: number[]; action: OverseasReviewAction; reason?: string },
  fetcher: typeof fetch = fetch,
  cookieSource: string = typeof document === 'undefined' ? '' : document.cookie,
): Promise<OriginalReviewResult> {
  if (import.meta.env.VITE_ERP_IDENTITY_MODE !== 'laravel' ||
      import.meta.env.VITE_ERP_M2_REVIEW_WRITE_ENABLED !== '1')
    throw new Error('ERP2_M2_REVIEW_WRITE_DISABLED');
  if (!input.orderIds.length || input.orderIds.length > 50 ||
      input.orderIds.some(id => !Number.isSafeInteger(id) || id < 1) ||
      new Set(input.orderIds).size !== input.orderIds.length)
    throw new Error('ERP2_REVIEW_ORDER_IDS_INVALID');
  if (input.action === 'reject' && (!input.reason?.trim() ||
      input.reason.trim().length < 3 || input.reason.trim().length > 500))
    throw new Error('ERP2_REVIEW_REJECTION_REASON_INVALID');
  const cookie = cookieSource.split(';').map(s => s.trim()).find(s => s.startsWith('XSRF-TOKEN='));
  if (!cookie) throw new Error('ERP2_REVIEW_CSRF_REQUIRED');
  let token: string;
  try { token = decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)); }
  catch { throw new Error('ERP2_REVIEW_CSRF_INVALID'); }
  const path = input.scope === 'domestic' ? '/logistics/china/batch-review' :
    '/logistics/oversea/batch-review';
  const response = await fetcher(path,{
    method:'POST',credentials:'same-origin',redirect:'manual',
    headers:{'Content-Type':'application/json',Accept:'application/json',
      'X-Requested-With':'XMLHttpRequest','X-XSRF-TOKEN':token},
    body:JSON.stringify({order_ids:input.orderIds,action:input.action,
      reason:input.reason?.trim() || null}),
  });
  if ([0,401,403,404,419].includes(response.status))
    throw new Error('ERP2_REVIEW_NOT_AUTHORIZED_'+response.status);
  if (!response.ok || !(response.headers.get('content-type')||'').includes('application/json'))
    throw new Error('ERP2_REVIEW_POST_FAILED_'+response.status);
  const raw=obj(await response.json());
  if (!Array.isArray(raw.results) || !Number.isSafeInteger(raw.success_count) ||
      !Number.isSafeInteger(raw.failed_count) || raw.results.length !== input.orderIds.length)
    throw new Error('ERP2_REVIEW_LARAVEL_RESULT_INVALID');
  const results=raw.results.map(record=>{
    const row=obj(record);
    if (!Number.isSafeInteger(row.order_id) || !input.orderIds.includes(row.order_id as number) ||
        typeof row.ok !== 'boolean')
      throw new Error('ERP2_REVIEW_LARAVEL_ROW_INVALID');
    return {order_id:row.order_id as number,ok:row.ok,message:str(row.message)};
  });
  if(new Set(results.map(row=>row.order_id)).size!==input.orderIds.length)throw Error('ERP2_REVIEW_LARAVEL_ROW_INVALID');
  const success_count=results.filter(row=>row.ok).length,failed_count=results.length-success_count;
  if (success_count!==raw.success_count || failed_count!==raw.failed_count)
    throw new Error('ERP2_REVIEW_LARAVEL_COUNTS_MISMATCH');
  return {results,success_count,failed_count};
}

/** Only the ORIGINAL Laravel shipping actions; never OMS/Woo push or provider API. */
export type OriginalShippingAction =
  | {scope:'domestic'; action:'carrier-service'; orderId:number; carrier:string; service:string}
  | {scope:'domestic'; action:'tracking-draft'|'quick-ship'; orderId:number; tracking:string}
  | {scope:'overseas'; action:'manual-ship'; orderId:number; warehouse:string;
      tracking:string; outboundReference?:string};

export function originalShippingPath(input:OriginalShippingAction):string {
  if(!Number.isSafeInteger(input.orderId)||input.orderId<1)throw Error('ERP2_SHIPPING_ORDER_INVALID');
  if(input.scope==='domestic')return '/logistics/china/orders/'+input.orderId+'/'+input.action;
  const w=input.warehouse.trim().toUpperCase();
  if(['US-NY','DE','CA'].includes(w))
    return '/logistics/oversea/orders/'+input.orderId+'/manual-ship';
  if(w==='US-SM'||w==='US-3SM')
    return '/logistics/oversea/us-3sm/orders/'+input.orderId+'/manual-ship';
  if(w==='US-LA')
    return '/logistics/oversea/us-moreplus/orders/'+input.orderId+'/manual-ship';
  throw Error('ERP2_SHIPPING_WAREHOUSE_UNSUPPORTED');
}

/** A 302 / opaque redirect is only an ACK. The UI must GET and verify the persisted
 * shipment state before claiming success; NEVER auto-replay a shipping action. */
export async function submitOriginalErpShipment(
  input:OriginalShippingAction,fetcher:typeof fetch=fetch,
  cookieSource:string=typeof document==='undefined'?'':document.cookie,
):Promise<'readback-required'>{
  if(import.meta.env.VITE_ERP_IDENTITY_MODE!=='laravel' ||
      import.meta.env.VITE_ERP_M2_SHIPPING_WRITE_ENABLED!=='1')
    throw Error('ERP2_M2_SHIPPING_WRITE_DISABLED');
  const endpoint=originalShippingPath(input);
  let payload:Record<string,string>;
  if(input.action==='carrier-service'){
    if(!input.carrier.trim()||!input.service.trim()||
      input.carrier.length>120||input.service.length>255)
      throw Error('ERP2_SHIPPING_CARRIER_SERVICE_REQUIRED');
    payload={carrier:input.carrier.trim(),service:input.service.trim()};
  }else{
    const tracking=input.tracking.trim();
    if((input.action!=='tracking-draft'||tracking!=='') &&
      (tracking.length>128||!/^[A-Za-z0-9][A-Za-z0-9._:/+\-]*$/.test(tracking)))
      throw Error('ERP2_SHIPPING_TRACKING_INVALID');
    payload={tracking_number:tracking};
    if(input.scope==='overseas'&&input.warehouse.trim().toUpperCase()==='US-LA'){
      const outbound=(input.outboundReference||'').trim();
      if(!outbound||outbound.length>90)throw Error('ERP2_MOREPLUS_OUTBOUND_PROOF_REQUIRED');
      payload.outbound_reference=outbound;
      payload.confirmation='CONFIRM_MOREPLUS_MANUAL_SHIPPED_'+input.orderId;
    }
  }
  const csrf=cookieSource.split(';').map(s=>s.trim()).find(s=>s.startsWith('XSRF-TOKEN='));
  if(!csrf)throw Error('ERP2_SHIPPING_CSRF_REQUIRED');
  let token:string;
  try{token=decodeURIComponent(csrf.slice(11));}
  catch{throw Error('ERP2_SHIPPING_CSRF_INVALID');}
  const response=await fetcher(endpoint,{method:'POST',credentials:'same-origin',redirect:'manual',
    headers:{'Content-Type':'application/json',Accept:'text/html, application/xhtml+xml',
      'X-Requested-With':'XMLHttpRequest','X-XSRF-TOKEN':token},
    body:JSON.stringify(payload)});
  if(response.status===0||[301,302,303,307,308].includes(response.status))
    return 'readback-required';
  if(!response.ok)throw Error('ERP2_SHIPPING_REJECTED_'+response.status);
  // The old controllers are redirect-only, not JSON-confirming.
  throw Error('ERP2_SHIPPING_UNEXPECTED_SUCCESS_RESPONSE_RECHECK_MANUALLY');
}

/** Original Laravel upload PREVIEW only. Never calls Apply or shipping endpoints. */
export interface DomesticTrackingPreview {
  provider:'4px'|'weship'|'generic';message:string;
  apply_count:number;noop_count:number;blocked_count:number;
}
export async function previewOriginalDomesticTrackingReturn(
  file:File,fetcher:typeof fetch=fetch,
  cookieSource:string=typeof document==='undefined'?'':document.cookie,
):Promise<DomesticTrackingPreview>{
  if(import.meta.env.VITE_ERP_IDENTITY_MODE!=='laravel')
    throw Error('ERP2_LARAVEL_SESSION_REQUIRED');
  if(!/\.(xls|xlsx)$/i.test(file.name) || file.size===0 || file.size>10*1024*1024)
    throw Error('ERP2_TRACKING_PREVIEW_FILE_INVALID');
  const csrf=cookieSource.split(';').map(s=>s.trim()).find(s=>s.startsWith('XSRF-TOKEN='));
  if(!csrf)throw Error('ERP2_TRACKING_PREVIEW_CSRF_REQUIRED');
  let token:string;
  try{token=decodeURIComponent(csrf.slice(11));}
  catch{throw Error('ERP2_TRACKING_PREVIEW_CSRF_INVALID');}
  const body=new FormData();body.append('file',file,file.name);
  const resp=await fetcher('/logistics/china/tracking-import/preview',{
    method:'POST',credentials:'same-origin',redirect:'manual',
    headers:{Accept:'application/json','X-XSRF-TOKEN':token},
    body,
  });
  if([0,401,403,419].includes(resp.status))
    throw Error('ERP2_TRACKING_PREVIEW_LOGIN_OR_PERMISSION_REQUIRED');
  if(!resp.ok){
    let message='';
    try{message=str(obj(await resp.json()).message);}catch{}
    throw Error(message||'ERP2_TRACKING_PREVIEW_REJECTED_'+resp.status);
  }
  if(!(resp.headers.get('content-type')||'').includes('application/json'))
    throw Error('ERP2_TRACKING_PREVIEW_CONTENT_TYPE_INVALID');
  const data=obj(await resp.json());
  const provider=data.provider;
  if(!['4px','weship','generic'].includes(String(provider))||
     ![data.apply_count,data.noop_count,data.blocked_count].every(
       n=>Number.isSafeInteger(n)&&Number(n)>=0))
    throw Error('ERP2_TRACKING_PREVIEW_LARAVEL_SCHEMA_INVALID');
  return{provider:provider as DomesticTrackingPreview['provider'],message:str(data.message),
    apply_count:data.apply_count as number,noop_count:data.noop_count as number,
    blocked_count:data.blocked_count as number};
}
export interface DomesticTrackingConfirmation extends DomesticTrackingPreview {
  token:string; rows:{order_number:string;tracking_number:string;status:'apply'|'noop'|'blocked';message:string}[];
}
export async function readOriginalDomesticTrackingConfirmation(summary:DomesticTrackingPreview,fetcher:typeof fetch=fetch):Promise<DomesticTrackingConfirmation>{
  if(import.meta.env.VITE_ERP_IDENTITY_MODE!=='laravel')throw Error('ERP2_LARAVEL_SESSION_REQUIRED');
  const version=await laravelInertiaVersion(fetcher,false);
  const response=await fetcher('/logistics/china?view=pending&per_page=20',{method:'GET',credentials:'same-origin',redirect:'manual',cache:'no-store',headers:{Accept:'text/html, application/xhtml+xml','X-Requested-With':'XMLHttpRequest','X-Inertia':'true','X-Inertia-Version':version}});
  if(!response.ok||!(response.headers.get('content-type')||'').includes('application/json'))throw Error('ERP2_TRACKING_CONFIRMATION_READ_FAILED');
  const page=obj(await response.json()),props=obj(page.props),preview=obj(props.trackingImportPreview);
  if(page.component!=='Logistics/Domestic/Index'||props.canOperate!==true||props.trackingImportProvider!==summary.provider||!Array.isArray(preview.rows)||typeof preview.token!=='string'||!/^[a-f0-9]{64}$/i.test(preview.token)||!(['apply_count','noop_count','blocked_count'] as const).every(k=>preview[k]===summary[k]))throw Error('ERP2_TRACKING_CONFIRMATION_CHANGED');
  const rows=preview.rows.map(raw=>{const r=obj(raw);if(!['apply','noop','blocked'].includes(String(r.status)))throw Error('ERP2_TRACKING_CONFIRMATION_ROW_INVALID');return {order_number:str(r.order_number),tracking_number:str(r.tracking_number),status:r.status as 'apply'|'noop'|'blocked',message:str(r.message)};});
  if(rows.filter(r=>r.status==='apply').length!==summary.apply_count||rows.filter(r=>r.status==='noop').length!==summary.noop_count||rows.filter(r=>r.status==='blocked').length!==summary.blocked_count)throw Error('ERP2_TRACKING_CONFIRMATION_COUNT_MISMATCH');
  return {...summary,token:preview.token,rows};
}
export interface DomesticTrackingApplyResult {applied_count:number;noop_count:number;blocked_count:number;failed_count:number;sync_pending_count:number;sync_handoff_failed_count:number;message:string}
export async function applyOriginalDomesticTrackingReturn(input:DomesticTrackingConfirmation,fetcher:typeof fetch=fetch,cookieSource:string=typeof document==='undefined'?'':document.cookie):Promise<DomesticTrackingApplyResult>{
  if(import.meta.env.VITE_ERP_IDENTITY_MODE!=='laravel'||import.meta.env.VITE_ERP_P0_DOMESTIC_APPLY_ENABLED!=='1')throw Error('ERP2_TRACKING_APPLY_DISABLED');
  if(!['4px','weship','generic'].includes(input.provider)||!Number.isSafeInteger(input.apply_count)||input.apply_count<1||!/^[a-f0-9]{64}$/i.test(input.token))throw Error('ERP2_TRACKING_APPLY_PREVIEW_INVALID');
  const csrf=cookieSource.split(';').map(s=>s.trim()).find(s=>s.startsWith('XSRF-TOKEN='));if(!csrf)throw Error('ERP2_TRACKING_APPLY_CSRF_REQUIRED');
  const provider=input.provider==='generic'?'pending-batch':input.provider;
  const response=await fetcher('/logistics/china/'+provider+'/tracking-import/apply',{method:'POST',credentials:'same-origin',redirect:'manual',headers:{Accept:'application/json','Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','X-XSRF-TOKEN':decodeURIComponent(csrf.slice(11))},body:JSON.stringify({token:input.token})});
  if(!response.ok||!(response.headers.get('content-type')||'').includes('application/json'))throw Error('ERP2_TRACKING_APPLY_REJECTED_'+response.status);
  const data=obj(await response.json()),keys=['applied_count','noop_count','blocked_count','failed_count','sync_pending_count','sync_handoff_failed_count'] as const;
  if(!keys.every(k=>Number.isSafeInteger(data[k])&&Number(data[k])>=0))throw Error('ERP2_TRACKING_APPLY_RESULT_INVALID');
  return {applied_count:Number(data.applied_count),noop_count:Number(data.noop_count),blocked_count:Number(data.blocked_count),failed_count:Number(data.failed_count),sync_pending_count:Number(data.sync_pending_count),sync_handoff_failed_count:Number(data.sync_handoff_failed_count),message:str(data.message)};
}

export type DomesticFlowAction='allocate_factory'|'return_allocation';
/** Original Laravel domestic allocation/return contracts; separate Dev flag. */
export async function submitOriginalDomesticFlow(input:{action:DomesticFlowAction;orderIds:number[]},fetcher:typeof fetch=fetch,cookieSource:string=typeof document==='undefined'?'':document.cookie):Promise<OriginalReviewResult>{
  if(import.meta.env.VITE_ERP_IDENTITY_MODE!=='laravel'||import.meta.env.VITE_ERP_P0_DOMESTIC_FLOW_ENABLED!=='1')throw Error('ERP2_DOMESTIC_FLOW_DISABLED');
  const allocate=input.action==='allocate_factory',ids=input.orderIds;
  if(!allocate&&input.action!=='return_allocation')throw Error('ERP2_DOMESTIC_FLOW_ACTION_INVALID');
  if(!ids.length||ids.length>(allocate?100:50)||ids.some(id=>!Number.isSafeInteger(id)||id<1)||new Set(ids).size!==ids.length)throw Error('ERP2_DOMESTIC_FLOW_IDS_INVALID');
  const csrf=cookieSource.split(';').map(s=>s.trim()).find(s=>s.startsWith('XSRF-TOKEN='));
  if(!csrf)throw Error('ERP2_DOMESTIC_FLOW_CSRF_REQUIRED');
  let token:string;try{token=decodeURIComponent(csrf.slice(11))}catch{throw Error('ERP2_DOMESTIC_FLOW_CSRF_INVALID')}
  if(!token)throw Error('ERP2_DOMESTIC_FLOW_CSRF_REQUIRED');
  const response=await fetcher(allocate?'/logistics/wait-stock/batch-allocation':'/logistics/china/batch-return-allocation',{
    method:'POST',credentials:'same-origin',redirect:'manual',headers:{'Content-Type':'application/json',Accept:'application/json','X-Requested-With':'XMLHttpRequest','X-XSRF-TOKEN':token},
    body:JSON.stringify(allocate?{order_ids:ids,action:'warehouse',warehouse_code:'FACTORY',confirmed:true}:{order_ids:ids}),
  });
  if(!response.ok||!(response.headers.get('content-type')||'').includes('application/json'))throw Error('ERP2_DOMESTIC_FLOW_POST_FAILED_'+response.status);
  const raw=obj(await response.json());
  if(!Array.isArray(raw.results)||raw.results.length!==ids.length)throw Error('ERP2_DOMESTIC_FLOW_RESULT_INVALID');
  const seen=new Set<number>();
  const results=raw.results.map(record=>{const r=obj(record),id=r.order_id as number;
    if(!Number.isSafeInteger(id)||!ids.includes(id)||seen.has(id)||typeof r.ok!=='boolean')throw Error('ERP2_DOMESTIC_FLOW_RESULT_INVALID');
    seen.add(id);return {order_id:id,ok:r.ok,message:str(r.message)};});
  const success_count=results.filter(r=>r.ok).length,failed_count=results.length-success_count;
  if(success_count!==(allocate?raw.succeeded:raw.success_count)||failed_count!==(allocate?raw.failed:raw.failed_count))throw Error('ERP2_DOMESTIC_FLOW_COUNTS_MISMATCH');
  return {results,success_count,failed_count};
}

/** Do not infer Woo success from an ERP shipment acknowledgement. */
export function domesticShipmentReceipt(action:'carrier-service'|'tracking-draft'|'quick-ship',row:LogisticsRow,expected:{carrier:string;service:string;tracking:string}):string{
  if(action==='carrier-service'){
    if(row.shipment_status!=='pending'||row.carrier!==expected.carrier||row.service!==expected.service)throw Error('ERP2_SHIPPING_READBACK_UNCONFIRMED_DO_NOT_RETRY');
    return '物流商/服务已保存（未发货）';
  }
  if(row.tracking!==expected.tracking||row.shipment_status!==(action==='tracking-draft'?'pending':'shipped'))throw Error('ERP2_SHIPPING_READBACK_UNCONFIRMED_DO_NOT_RETRY');
  if(action==='tracking-draft')return 'Tracking 草稿已保存（未发货）';
  const status=row.tracking_sync_status;
  const label=({completed:'原 ERP 官网 Tracking 同步已验证',partial:'Tracking 已同步，等待其他包裹',pending:'官网待同步 / 待核验',processing:'官网同步处理中',retry_wait:'官网同步待重试',failed:'官网同步失败，请核对',conflict:'官网 Tracking 冲突，请核对'} as Record<string,string>)[status||'']||'官网同步状态待核验';
  return 'ERP 发货确认已落库；'+label+(row.tracking_sync_reason?'（'+row.tracking_sync_reason+'）':'');
}

export interface AllocationAssignment {order_item_id:number;source_code:string;gift_mapping_id:number|null;carrier:string|null;service:string|null}
export interface AllocationWarehouse {code:string;name:string}
export interface AllocationGift {mapping_id:number;fulfillment_sku:string;fulfillment_name:string;quantity_available:number|null}
export interface AllocationItem {id:number;sku:string;quantity:number;is_gift:boolean;gifts:Record<string,AllocationGift[]>}
export interface AllocationPlan {key:string;usable:boolean;warehouses:string[];items:AllocationItem[];routes:Record<string,{carrier:string|null;service:string|null}>}
export function parseAllocationPlan(raw:unknown):AllocationPlan{
 const r=obj(raw),d=obj(r.allocation_decision),p=obj(d.recommended_plan);
 const routes:AllocationPlan['routes']={};
 for(const [k,v] of Object.entries(obj(r.carrier_routes)))if(/^[A-Z0-9-]+$/.test(k))routes[k]={carrier:str(obj(v).carrier)||null,service:str(obj(v).service)||null};
 return {key:str(d.recommended_plan_key),usable:d.can_use_recommendation===true,
 warehouses:Array.isArray(p.segments)?p.segments.map(s=>str(obj(s).warehouse_name)).filter(Boolean):[],
 items:Array.isArray(r.items)?r.items.map(rawItem=>{const i=obj(rawItem),gifts:AllocationItem['gifts']={};
 for(const [k,v] of Object.entries(obj(i.gift_options_by_warehouse)))if(/^[A-Z0-9-]+$/.test(k)&&Array.isArray(v))gifts[k]=v.map(x=>{const g=obj(x);return {mapping_id:integer(g.mapping_id),fulfillment_sku:str(g.fulfillment_sku),fulfillment_name:str(g.fulfillment_name),quantity_available:typeof g.quantity_available==='number'?g.quantity_available:null}}).filter(g=>g.mapping_id>0&&g.fulfillment_sku);
 return {id:integer(i.id),sku:str(i.source_sku||i.sku),quantity:integer(i.quantity),is_gift:i.is_gift===true,gifts};
 }):[],routes};
}
function allocationHeaders(cookieSource:string){
 const item=cookieSource.split(';').map(s=>s.trim()).find(s=>s.startsWith('XSRF-TOKEN='));
 if(!item)throw Error('ERP2_ALLOCATION_CSRF_REQUIRED');
 return {Accept:'application/json','Content-Type':'application/json','X-XSRF-TOKEN':decodeURIComponent(item.slice(11)),'X-Requested-With':'XMLHttpRequest'};
}
export async function submitOriginalAllocation(input:{orderId:number;plan:AllocationPlan;warehouses:AllocationWarehouse[];mode:'manual'|'recommended';assignments?:AllocationAssignment[];note?:string;acknowledged?:boolean},fetcher:typeof fetch=fetch,cookieSource:string=typeof document==='undefined'?'':document.cookie){
 if(import.meta.env.VITE_ERP_IDENTITY_MODE!=='laravel'||import.meta.env.VITE_ERP_P0_DOMESTIC_FLOW_ENABLED!=='1')throw Error('ERP2_ALLOCATION_DISABLED');
 if(!Number.isSafeInteger(input.orderId)||input.orderId<1)throw Error('ERP2_ALLOCATION_ID_INVALID');
 const base='/logistics/orders/'+input.orderId+'/fulfillment-allocation/';
 const headers=allocationHeaders(cookieSource),note=input.note?.trim()||null;
 const manual=input.mode==='manual',assignments=input.assignments||[];
 if(manual){
  const items=input.plan.items;
  if(!items.length||assignments.length!==items.length||new Set(assignments.map(a=>a.order_item_id)).size!==items.length||assignments.some(a=>{
   const item=items.find(i=>i.id===a.order_item_id);
   return !item||!input.warehouses.some(w=>w.code===a.source_code)||(item.is_gift?!item.gifts[a.source_code]?.some(g=>g.mapping_id===a.gift_mapping_id):a.gift_mapping_id!==null);
  }))throw Error('每件商品必须指定有效仓库，礼品必须选择该仓真实 SKU。');
 }else if(!input.plan.usable||!input.plan.key)throw Error('ERP2_ALLOCATION_RECOMMENDATION_UNAVAILABLE');
 async function post(action:string,body:unknown){
  const r=await fetcher(base+action,{method:'POST',credentials:'same-origin',redirect:'manual',headers,body:JSON.stringify(body)});
  const data=obj(await r.json().catch(()=>null));
  if(!r.ok){
   const errors=obj(data.errors),message=Object.values(errors).flat().find(x=>typeof x==='string');
   throw Error(str(message||data.message||'ERP2_ALLOCATION_HTTP_'+r.status));
  }
  return data;
 }
 if(manual){const pre=await post('preflight',{assignments});
  if(pre.can_submit!==true)throw Error(Array.isArray(pre.issues)?pre.issues.map(i=>str(obj(i).reason)).join('；')||'实际 SKU 预检未通过':'实际 SKU 预检未通过');
 }
 let issue='';
 try{await post(input.mode,manual?{assignments,decision_note:note,stock_exception_acknowledged:input.acknowledged===true}:{plan_key:input.plan.key,decision_note:note});}catch(e){issue=e instanceof Error?e.message:'提交失败';}
 const response=await fetcher(base+'status',{method:'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
 if(!response.ok)throw Error((issue?issue+'；':'')+'分仓状态无法确认，请刷新核对，不要重复提交。');
 const status=obj(await response.json());
 const same=manual?status.decision_source==='manual'&&Array.isArray(status.assignments)&&status.assignments.length===assignments.length&&assignments.every(a=>status.assignments instanceof Array&&status.assignments.some(raw=>{const x=obj(raw);return integer(x.order_item_id)===a.order_item_id&&str(x.source_code)===a.source_code&&integer(x.gift_mapping_id)===integer(a.gift_mapping_id)})):status.decision_source==='system_recommendation'&&status.selected_plan_key===input.plan.key;
 if(status.status!=='submitted'||!same)throw Error((issue?issue+'；':'')+'未确认本次分仓已保存，请刷新核对。');
 return {status:'submitted',submittedAt:str(status.submitted_at)};
}
export async function readDomesticBarcode69(orderId:number,fetcher:typeof fetch=fetch){
 if(!Number.isSafeInteger(orderId)||orderId<1)throw Error('ERP2_BARCODE_ORDER_INVALID');
 const r=await fetcher('/logistics/china/orders/'+orderId+'/barcode-69',{method:'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
 const d=obj(await r.json().catch(()=>null));
 if(!r.ok)throw Error(str(Object.values(obj(d.errors)).flat()[0]||d.message||'条码读取失败 '+r.status));
 if(d.order_id!==orderId||!Array.isArray(d.items))throw Error('ERP2_BARCODE_CONTRACT_INVALID');
 return d.items.map(raw=>{const i=obj(raw);if(typeof i.barcode_69!=='string'||!i.barcode_69||!str(i.sku)||!Number.isSafeInteger(i.quantity))throw Error('ERP2_BARCODE_ROW_INVALID');return {sku:str(i.sku),quantity:integer(i.quantity),barcode:i.barcode_69}});
}

/** Overseas fulfillment uses the original warehouse-scoped routes, never provider guesses. */
export function originalOverseaWarehouse(key:string){
 const canonical=key==='us-sm'?'us-3sm':key;
 if(!['us-ny','us-moreplus','us-3sm','de-tianyun','ca'].includes(canonical))throw Error('请选择具体海外仓');
 return canonical;
}
export function originalOverseaExportHref(key:string,ids:number[]=[]){
 const warehouse=originalOverseaWarehouse(key);
 if(ids.some(id=>!Number.isSafeInteger(id)||id<1)||new Set(ids).size!==ids.length)throw Error('ERP2_OVERSEA_EXPORT_IDS_INVALID');
 const params=new URLSearchParams();for(const id of ids)params.append('order_ids[]',String(id));
 return '/logistics/oversea/'+warehouse+'/export'+(ids.length?'?'+params.toString():'');
}
function overseaFlowEnabled(){
 if(import.meta.env.VITE_ERP_IDENTITY_MODE!=='laravel'||import.meta.env.VITE_ERP_P0_OVERSEA_FLOW_ENABLED!=='1')throw Error('ERP2_OVERSEA_FLOW_DISABLED');
}
export async function submitOriginalOverseaReturn(ids:number[],fetcher:typeof fetch=fetch,cookieSource:string=typeof document==='undefined'?'':document.cookie):Promise<OriginalReviewResult>{
 overseaFlowEnabled();
 if(!ids.length||ids.length>50||ids.some(id=>!Number.isSafeInteger(id)||id<1)||new Set(ids).size!==ids.length)throw Error('ERP2_OVERSEA_RETURN_IDS_INVALID');
 const response=await fetcher('/logistics/oversea/batch-return-allocation',{method:'POST',credentials:'same-origin',redirect:'manual',headers:allocationHeaders(cookieSource),body:JSON.stringify({order_ids:ids})});
 if(!response.ok||!(response.headers.get('content-type')||'').includes('application/json'))throw Error('ERP2_OVERSEA_RETURN_FAILED_'+response.status);
 const data=obj(await response.json());if(!Array.isArray(data.results)||data.results.length!==ids.length)throw Error('ERP2_OVERSEA_RETURN_INVALID');
 const seen=new Set<number>();
 const results=data.results.map(raw=>{const r=obj(raw),id=r.order_id as number;if(!ids.includes(id)||seen.has(id)||typeof r.ok!=='boolean')throw Error('ERP2_OVERSEA_RETURN_INVALID');seen.add(id);return {order_id:id,ok:r.ok,message:str(r.message)}});
 const success_count=results.filter(r=>r.ok).length,failed_count=results.length-success_count;
 if(data.success_count!==success_count||data.failed_count!==failed_count)throw Error('ERP2_OVERSEA_RETURN_COUNT_MISMATCH');
 return {results,success_count,failed_count};
}
export interface OverseaTrackingConfirmation {
 warehouse:string;token:string;apply_count:number;noop_count:number;blocked_count:number;
 rows:{order_id:number|null;order_number:string;tracking_number:string;status:'apply'|'noop'|'blocked';message:string}[];
}
export function parseOverseaTrackingConfirmation(input:unknown,key:string):OverseaTrackingConfirmation{
 const warehouse=originalOverseaWarehouse(key),p=obj(input),props=obj(p.props),v=obj(props.trackingImportPreview);
 if(p.component!=='Logistics/Oversea/Show'||props.warehouseKey!==warehouse||props.view!=='pending'||props.canOperate!==true||!Array.isArray(v.rows)||typeof v.token!=='string'||!/^[a-f0-9]{64}$/i.test(v.token))throw Error('ERP2_OVERSEA_PREVIEW_INVALID');
 if(![v.apply_count,v.noop_count,v.blocked_count].every(n=>Number.isSafeInteger(n)&&Number(n)>=0))throw Error('ERP2_OVERSEA_PREVIEW_COUNTS_INVALID');
 const seen=new Set<number>();
 const rows=v.rows.map(raw=>{const r=obj(raw),id=optionalId(r.order_id);
 if(!['apply','noop','blocked'].includes(String(r.status))||(r.status==='apply'&&(!id||!str(r.order_number)||!str(r.tracking_number)||seen.has(id))))throw Error('ERP2_OVERSEA_PREVIEW_ROW_INVALID');
 if(r.status==='apply'&&id)seen.add(id);
 return {order_id:id,order_number:str(r.order_number),tracking_number:str(r.tracking_number),status:r.status as 'apply'|'noop'|'blocked',message:str(r.message)};});
 const apply_count=rows.filter(r=>r.status==='apply').length,noop_count=rows.filter(r=>r.status==='noop').length,blocked_count=rows.filter(r=>r.status==='blocked').length;
 if(v.apply_count!==apply_count||v.noop_count!==noop_count||v.blocked_count!==blocked_count)throw Error('ERP2_OVERSEA_PREVIEW_COUNT_MISMATCH');
 return {warehouse,token:v.token,apply_count,noop_count,blocked_count,rows};
}
async function readOverseaReceiptPage(warehouse:string,view:'pending'|'shipped',fetcher:typeof fetch){
 const version=await laravelInertiaVersion(fetcher,false);
 const r=await fetcher('/logistics/oversea/'+warehouse+'?view='+view+'&per_page=20',{method:'GET',credentials:'same-origin',redirect:'manual',cache:'no-store',headers:{Accept:'text/html, application/xhtml+xml','X-Requested-With':'XMLHttpRequest','X-Inertia':'true','X-Inertia-Version':version}});
 if(!r.ok||!(r.headers.get('content-type')||'').includes('application/json'))throw Error('ERP2_OVERSEA_RECEIPT_READ_FAILED');
 const page=obj(await r.json()),props=obj(page.props);
 if(page.component!=='Logistics/Oversea/Show'||props.warehouseKey!==warehouse||props.view!==view||props.canOperate!==true)throw Error('ERP2_OVERSEA_RECEIPT_SCOPE_INVALID');
 return page;
}
function requireOverseaRedirect(r:Response){
 if(r.type!=='opaqueredirect'&&![302,303].includes(r.status))throw Error('ERP2_OVERSEA_POST_UNCONFIRMED_'+r.status);
}
export async function previewOriginalOverseaReturn(key:string,file:File,fetcher:typeof fetch=fetch,cookieSource:string=typeof document==='undefined'?'':document.cookie){
 overseaFlowEnabled();const warehouse=originalOverseaWarehouse(key);
 if(!/\.(xlsx|csv|txt)$/i.test(file.name)||!file.size||file.size>10*1024*1024)throw Error('海外仓回传文件格式或大小无效');
 const headers=allocationHeaders(cookieSource),body=new FormData();body.append('file',file,file.name);
 // Cache the asset handshake before POST so that the next GET consumes the correct flash.
 await laravelInertiaVersion(fetcher,false);
 const response=await fetcher('/logistics/oversea/'+warehouse+'/tracking-import/preview',{method:'POST',credentials:'same-origin',redirect:'manual',headers:{Accept:'text/html, application/xhtml+xml','X-XSRF-TOKEN':headers['X-XSRF-TOKEN']},body});
 requireOverseaRedirect(response);
 const page=await readOverseaReceiptPage(warehouse,'pending',fetcher),flash=obj(obj(page.props).flash);
 if(!str(flash.success).startsWith('单号表预览完成：'))throw Error('ERP2_OVERSEA_PREVIEW_NOT_CONFIRMED');
 return parseOverseaTrackingConfirmation(page,warehouse);
}
export async function applyOriginalOverseaReturn(input:OverseaTrackingConfirmation,fetcher:typeof fetch=fetch,cookieSource:string=typeof document==='undefined'?'':document.cookie){
 overseaFlowEnabled();const warehouse=originalOverseaWarehouse(input.warehouse);
 if(!Number.isSafeInteger(input.apply_count)||input.apply_count<1||!/^[a-f0-9]{64}$/i.test(input.token))throw Error('ERP2_OVERSEA_APPLY_INVALID');
 await laravelInertiaVersion(fetcher,false);
 const r=await fetcher('/logistics/oversea/'+warehouse+'/tracking-import/apply',{method:'POST',credentials:'same-origin',redirect:'manual',headers:{...allocationHeaders(cookieSource),Accept:'text/html, application/xhtml+xml'},body:JSON.stringify({token:input.token})});
 requireOverseaRedirect(r);
 const page=await readOverseaReceiptPage(warehouse,'shipped',fetcher),flash=obj(obj(page.props).flash);
 const message=str(flash.success||flash.warning);
 const match=message.match(/^ERP 发货已登记 (\d+) 单，重复跳过 (\d+) 单；官网待同步 (\d+) 单，任务登记异常 (\d+) 单。/);
 if(!match||Number(match[1])!==input.apply_count)throw Error('ERP2_OVERSEA_APPLY_READBACK_UNCONFIRMED_DO_NOT_RETRY');
 return {applied_count:Number(match[1]),noop_count:Number(match[2]),sync_pending_count:Number(match[3]),sync_handoff_failed_count:Number(match[4]),message};
}

export type OmsBatchWarehouse='us-moreplus'|'de-tianyun';
export interface OmsBatchPreview {warehouse:OmsBatchWarehouse;ids:number[];count:number;scan_count:number;excluded_count:number;overflow:boolean;fingerprint:string;dry_run:boolean;items:{order_id:number;order_no:string;ready:boolean;blockers:string[]}[]}
export interface OmsBatchStatus {batch_id:string;status:string;dry_run:boolean;total:number;processed:number;items:{order_id:number;order_no:string;status:string;result_code:string;provider_order_id:string}[]}
export interface OmsCapabilities {batch:boolean;simulation:boolean;paused:boolean;latestBatchId:string}
export function parseOmsCapabilities(input:unknown):OmsCapabilities{
 const p=obj(input),w=p.warehouseKey,de=w==='de-tianyun',mp=w==='us-moreplus';
 const latest=obj(de?p.deTianyunLatestBatch:p.shipoutProductionLatestBatch);
 return {batch:de?p.deTianyunBatchUi===true:mp&&p.shipoutProductionBatchUi===true,simulation:de?p.deTianyunBatchSimulation===true:mp&&p.shipoutProductionBatchDryRun===true,paused:mp&&p.shipoutProductionBatchPaused===true,latestBatchId:latest.dry_run===true&&validBatchId(str(latest.id))?str(latest.id):''};
}
function validBatchId(id:string){return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id);}
function omsBase(w:OmsBatchWarehouse){if(w==='us-moreplus')return '/logistics/oversea/us-moreplus/shipout/batch';if(w==='de-tianyun')return '/logistics/oversea/de-tianyun/xlwms/batch';throw Error('ERP2_OMS_WAREHOUSE_INVALID');}
function omsSelection(ids:number[]){if(!ids.length||ids.length>200||ids.some(id=>!Number.isSafeInteger(id)||id<1)||new Set(ids).size!==ids.length)throw Error('请选择 1–200 个有效订单');return [...ids].sort((a,b)=>a-b);}
function omsEnabled(){if(import.meta.env.VITE_ERP_IDENTITY_MODE!=='laravel'||import.meta.env.VITE_ERP_P0_OMS_ENABLED!=='1')throw Error('ERP2_OMS_DISABLED');}
async function omsJson(path:string,fetcher:typeof fetch,body?:unknown,cookieSource:string=typeof document==='undefined'?'':document.cookie){
 omsEnabled();const r=await fetcher(path,{method:body===undefined?'GET':'POST',credentials:'same-origin',cache:'no-store',redirect:'manual',headers:body===undefined?{Accept:'application/json'}:allocationHeaders(cookieSource),...(body===undefined?{}:{body:JSON.stringify(body)})});
 if(!r.ok||!(r.headers.get('content-type')||'').includes('application/json'))throw Error('ERP2_OMS_HTTP_'+r.status);
 return obj(await r.json());
}
export async function previewOmsBatch(warehouse:OmsBatchWarehouse,ids:number[],fetcher:typeof fetch=fetch):Promise<OmsBatchPreview>{
 const selected=omsSelection(ids),v=await omsJson(omsBase(warehouse)+'/preview?scope=selected&order_ids='+selected.join(','),fetcher);
 if(v.scope!=='selected'||v.dry_run!==true||typeof v.overflow!=='boolean'||typeof v.fingerprint!=='string'||!/^[a-f0-9]{64}$/i.test(v.fingerprint)||![v.count,v.scan_count,v.excluded_count].every(n=>Number.isSafeInteger(n)&&Number(n)>=0)||v.scan_count!==selected.length||Number(v.count)+Number(v.excluded_count)!==selected.length)throw Error('ERP2_OMS_PREVIEW_CONTRACT_OR_NOT_SIMULATION');
 const seen=new Set<number>();const items=Array.isArray(v.items)?v.items.map(raw=>{const i=obj(raw),id=i.order_id as number;if(!selected.includes(id)||seen.has(id)||typeof i.ready!=='boolean'||!Array.isArray(i.blockers)||!i.blockers.every(b=>typeof b==='string'))throw Error('ERP2_OMS_PREVIEW_ROW_INVALID');seen.add(id);return {order_id:id,order_no:str(i.order_no),ready:i.ready,blockers:i.blockers as string[]};}):[];
 if(warehouse==='de-tianyun'&&(items.length!==selected.length||items.filter(i=>i.ready).length!==v.count))throw Error('ERP2_OMS_PREVIEW_ROWS_MISSING');
 return {warehouse,ids:selected,count:Number(v.count),scan_count:Number(v.scan_count),excluded_count:Number(v.excluded_count),overflow:v.overflow,fingerprint:v.fingerprint,dry_run:true,items};
}
export async function startOmsBatch(preview:OmsBatchPreview,fetcher:typeof fetch=fetch,cookieSource:string=typeof document==='undefined'?'':document.cookie){
 const ids=omsSelection(preview.ids);if(preview.dry_run!==true||preview.overflow||!Number.isSafeInteger(preview.count)||preview.count<1||preview.count>ids.length||!/^[a-f0-9]{64}$/i.test(preview.fingerprint))throw Error('ERP2_OMS_SIMULATION_PREVIEW_INVALID');
 const v=await omsJson(omsBase(preview.warehouse)+'/start',fetcher,{scope:'selected',order_ids:ids.join(','),fingerprint:preview.fingerprint,confirmation:(preview.warehouse==='de-tianyun'?'CONFIRM_DE_TIANYUN_BATCH_':'CONFIRM_MOREPLUS_BATCH_')+preview.count},cookieSource);
 if(v.dry_run!==true||v.status!=='queued'||v.total!==preview.count||v.erp_shipment_unchanged!==true||v.woo_unchanged!==true||!validBatchId(str(v.batch_id)))throw Error('ERP2_OMS_START_UNCONFIRMED_DO_NOT_RETRY');
 return {batch_id:str(v.batch_id),status:'queued',dry_run:true,total:Number(v.total),processed:0,items:[]} as OmsBatchStatus;
}
export async function readOmsBatch(warehouse:OmsBatchWarehouse,id:string,fetcher:typeof fetch=fetch):Promise<OmsBatchStatus>{
 if(!validBatchId(id))throw Error('ERP2_OMS_BATCH_ID_INVALID');
 const v=await omsJson(omsBase(warehouse)+'/'+id+'/status',fetcher);
 if(v.batch_id!==id||v.dry_run!==true||typeof v.status!=='string'||![v.total,v.processed].every(n=>Number.isSafeInteger(n)&&Number(n)>=0)||Number(v.processed)>Number(v.total)||!Array.isArray(v.items)||v.items.length!==v.total)throw Error('ERP2_OMS_STATUS_INVALID');
 const seen=new Set<number>();const items=v.items.map(raw=>{const i=obj(raw),n=i.order_id as number;if(!Number.isSafeInteger(n)||n<1||seen.has(n)||typeof i.status!=='string')throw Error('ERP2_OMS_STATUS_ROW_INVALID');seen.add(n);return {order_id:n,order_no:str(i.order_no),status:i.status,result_code:str(i.result_code),provider_order_id:str(i.provider_order_id)}});
 return {batch_id:id,status:v.status,dry_run:true,total:Number(v.total),processed:Number(v.processed),items};
}
export function original3smManualExport(id:number){if(!Number.isSafeInteger(id)||id<1)throw Error('ERP2_3SM_ORDER_INVALID');return '/logistics/oversea/us-3sm/orders/'+id+'/manual-export';}
export async function preflight3sm(id:number,fetcher:typeof fetch=fetch){
 original3smManualExport(id);const v=await omsJson('/logistics/oversea/us-3sm/orders/'+id+'/xlwms/preflight',fetcher);
 if(v.order_id!==id||typeof v.ready!=='boolean'||typeof v.already_attempted!=='boolean'||!Array.isArray(v.blockers)||!v.blockers.every(b=>typeof b==='string'))throw Error('ERP2_3SM_PREFLIGHT_INVALID');
 return {ready:v.ready,already_attempted:v.already_attempted,blockers:v.blockers as string[],oms_warehouse:str(v.oms_warehouse)};
}
