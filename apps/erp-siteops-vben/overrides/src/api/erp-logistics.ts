import { laravelInertiaVersion } from './erp-orders';

/** ERP2 Dev-only read projections. Laravel Session and logistics.view are the authority. */
export type LogisticsScope = 'allocation' | 'domestic' | 'overseas';
export type DomesticView =
  | 'review' | 'stockout' | 'outsource' | 'pending' | 'today'
  | 'today-4px' | 'today-weship' | 'exceptions' | 'all';
export type OverseasView =
  | 'review' | 'stockout' | 'pending' | 'pending_dispatch' | 'issued'
  | 'dispatched' | 'shipped' | 'exceptions' | 'all';
export interface LogisticsItem {
  sku: string; source_sku: string; original_sku: string;
  resolved_sku: string; final_sku: string; quantity: number;
}
export interface LogisticsRow {
  key: string; order_id: number; shipment_id: null | number;
  order_number: string; order_date: string; status: string;
  fulfillment_suffix: string; fulfillment_parent_id: null | number;
  warehouse_code: string; carrier: string; service: string; tracking: string;
  review_status: string; oms_status: string; oms_order_number: string;
  items: LogisticsItem[]; sku: string; actual_sku: string; qty: number;
}
export interface LogisticsPage {
  rows: LogisticsRow[]; page: number; perPage: number; total: number; lastPage: number;
  canReview?: boolean; canOperate?: boolean;
}
const ENDPOINT: Record<LogisticsScope, string> = {
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
      resolved_sku: str(i.resolved_sku), final_sku: str(i.final_sku),
      quantity: integer(i.quantity),
    };
  });
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
      key: isOverseas ? 'shipment-'+shipmentId : 'order-'+orderId,
      order_id: orderId, shipment_id: shipmentId,
      order_number: str(r.order_number), order_date: str(r.order_date),
      status: str(isOverseas ? r.workflow_status || r.shipment_status : r.status),
      fulfillment_suffix: str(r.fulfillment_suffix),
      fulfillment_parent_id: optionalId(r.fulfillment_parent_id),
      warehouse_code: str(r.warehouse_code), carrier: str(r.carrier),service: str(r.service),
      tracking: str(r.tracking), review_status: str(r.review_status),
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
      key:'order-'+orderId,order_id:orderId,shipment_id:optionalId(r.shipment_id),
      order_number:orderNumber,order_date:str(r.order_date || r.paid_date),
      status:str(status.label || status.code || r.shipment_status),
      fulfillment_suffix:str(r.fulfillment_suffix),
      fulfillment_parent_id:optionalId(r.fulfillment_parent_id),
      warehouse_code:str(r.warehouse_code || route.code || (scope==='domestic'?'FACTORY':'')),
      carrier:str(r.carrier),service:str(r.service),tracking:str(r.tracking_number),
      review_status:str(r.review_status),oms_status:str(oms.oms_status_code || oms.state),
      oms_order_number:str(oms.oms_order_no),
      items,sku:items.map(i=>i.sku).filter(Boolean).join(' / '),
      actual_sku:items.map(i=>i.final_sku || i.resolved_sku || i.sku)
        .filter(Boolean).join(' / '),
      qty:items.reduce((sum,i)=>sum+i.quantity,0),
    };
  });
  return {rows,page:integer(pagination.current_page,1),perPage:integer(pagination.per_page,20),
    total:integer(pagination.total),lastPage:integer(pagination.last_page,1),
    canReview:props.canReview===true,canOperate:props.canOperate===true};
}
export interface LogisticsQuery {
  scope: LogisticsScope; page?: number; perPage?: 20 | 50 | 100;
  search?: string; view?: DomesticView | OverseasView; warehouse?: string;
}
export async function getLogisticsPage(query: LogisticsQuery, fetcher: typeof fetch = fetch): Promise<LogisticsPage> {
  // Fail closed: the local-JWT SiteOps fixture must never impersonate an ERP employee.
  if (import.meta.env.VITE_ERP_IDENTITY_MODE !== 'laravel') throw new Error('ERP2_LARAVEL_SESSION_REQUIRED');
  const original = query.scope !== 'allocation' &&
    import.meta.env.VITE_ERP_M2_ORIGINAL_INERTIA_ENABLED === '1';
  const params = new URLSearchParams({
    page:String(Math.max(1,query.page ?? 1)),per_page:String(query.perPage ?? 20),
  });
  if (query.search?.trim()) params.set('search',query.search.trim().slice(0,100));
  if (original || query.scope === 'overseas') params.set('view',query.view ?? 'review');
  const warehouse = query.warehouse?.trim() || '';
  if (warehouse && !['us-ny','us-sm','us-3sm','us-moreplus','de-tianyun','ca'].includes(warehouse))
    throw new Error('ERP2_LOGISTICS_WAREHOUSE_INVALID');
  const endpoint = original
    ? query.scope === 'domestic' ? '/logistics/china' :
      '/logistics/oversea'+(warehouse ? '/'+(warehouse==='us-sm'?'us-3sm':warehouse) : '')
    : ENDPOINT[query.scope];
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
    return original ? parseOriginalLogisticsInertiaPage(query.scope as 'domestic'|'overseas',payload)
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
  const success_count=results.filter(row=>row.ok).length,failed_count=results.length-success_count;
  if (success_count!==raw.success_count || failed_count!==raw.failed_count)
    throw new Error('ERP2_REVIEW_LARAVEL_COUNTS_MISMATCH');
  return {results,success_count,failed_count};
}
