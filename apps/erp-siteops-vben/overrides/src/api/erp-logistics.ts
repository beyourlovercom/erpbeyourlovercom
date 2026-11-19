/** ERP2 Dev-only read projections. Laravel Session and logistics.view are the authority. */
export type LogisticsScope = 'allocation' | 'domestic' | 'overseas';
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
  warehouse_code: string; carrier: string; tracking: string;
  review_status: string; oms_status: string; oms_order_number: string;
  items: LogisticsItem[]; sku: string; actual_sku: string; qty: number;
}
export interface LogisticsPage {
  rows: LogisticsRow[]; page: number; perPage: number; total: number; lastPage: number;
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
      warehouse_code: str(r.warehouse_code), carrier: str(r.carrier),
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
export interface LogisticsQuery {
  scope: LogisticsScope; page?: number; perPage?: 20 | 50 | 100;
  search?: string; view?: OverseasView; warehouse?: string;
}
export async function getLogisticsPage(query: LogisticsQuery, fetcher: typeof fetch = fetch): Promise<LogisticsPage> {
  // Fail closed: the local-JWT SiteOps fixture must never impersonate an ERP employee.
  if (import.meta.env.VITE_ERP_IDENTITY_MODE !== 'laravel') throw new Error('ERP2_LARAVEL_SESSION_REQUIRED');
  const params = new URLSearchParams({
    page:String(Math.max(1,query.page ?? 1)),per_page:String(query.perPage ?? 20),
  });
  if (query.search?.trim()) params.set('search',query.search.trim().slice(0,100));
  if (query.scope === 'overseas') {
    params.set('view',query.view ?? 'review');
    if (query.warehouse) params.set('warehouse',query.warehouse);
  }
  const response = await fetcher(ENDPOINT[query.scope]+'?'+params.toString(),{
    method:'GET',credentials:'same-origin',cache:'no-store',redirect:'manual',
    headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if ([0,302,401,403].includes(response.status)) throw new Error('ERP2_LOGISTICS_LOGIN_OR_PERMISSION_REQUIRED');
  if (response.status === 404) throw new Error('ERP2_LOGISTICS_DEV_FEATURE_UNAVAILABLE');
  if (!response.ok || !(response.headers.get('content-type')||'').includes('application/json'))
    throw new Error('ERP2_LOGISTICS_GET_FAILED_'+response.status);
  return parseLogisticsPage(query.scope,await response.json());
}
