import { requestClient } from '#/api/request';

/** Reuses BYL ERP's existing Laravel/Inertia GET /orders controller.
 * No separate order source, no privileged gateway, no client-side credentials.
 * Must run at same origin as authenticated ERP Laravel in Dev/UAT.
 */
export interface ErpOrderRow {
  id: number;
  order_number: string;
  order_kind: string;
  order_date: string;
  status: string;
  warehouse: string;
  sku: string;
  tracking_number: string;
  latest_note: string;
}
export interface ErpOrderPage {
  rows: ErpOrderRow[];
  page: number;
  perPage: number;
  total: number;
  lastPage: number;
  filters: Record<string, string>;
}
type UnknownRecord = Record<string, unknown>;
const asObject = (v: unknown): UnknownRecord =>
  v !== null && typeof v === 'object' && !Array.isArray(v) ? v as UnknownRecord : {};
const asText = (v: unknown): string => typeof v === 'string' ? v : v == null ? '' : String(v);
const safeInt = (v: unknown, fallback: number): number => {
  const n = Number(v);
  return Number.isSafeInteger(n) && n >= 0 ? n : fallback;
};
const allowedSizes = [20, 50, 100];
export const orderReadOnlyPath = (input: {
  search?: string; page?: number; perPage?: number; view?: string;
  scope?: 'all' | 'customers' | 'kol';
}) => {
  const params = new URLSearchParams();
  if (input.search?.trim()) params.set('search', input.search.trim());
  // The current Orders/Master controller only accepts these exact view values.
  if ((input.scope ?? 'all') === 'all' && input.view &&
      ['all', 'normal', 'operational', 'trash', 'pending'].includes(input.view))
    params.set('view', input.view);
  params.set('page', String(Math.max(1, input.page ?? 1)));
  if (allowedSizes.includes(input.perPage ?? 50)) params.set('per_page', String(input.perPage));
  const path = input.scope === 'customers' ? '/customers/orders' :
    input.scope === 'kol' ? '/kol/orders' : '/orders';
  return path + '?' + params.toString();
};
export function normalizeExistingInertiaOrders(value: unknown): ErpOrderPage {
  const page = asObject(value);
  const isMaster = page.component === 'Orders/Master';
  if (!isMaster && page.component !== 'Orders/Index') throw new Error('ERP_INERTIA_COMPONENT_MISMATCH');
  const props = asObject(page.props);
  const pager = asObject(props.orders);
  if (!Array.isArray(pager.data)) throw new Error('ERP_ORDERS_PAYLOAD_INVALID');
  const raw = pager.data as unknown[];
  const rows = raw.map((entry): ErpOrderRow => {
    const row = asObject(entry), shipment = asObject(row.shipment);
    if (isMaster) {
      if (!safeInt(row.id, 0) || !asText(row.number).trim())
        throw new Error('ERP_MASTER_ROW_CONTRACT_INVALID');
      return {
        id: safeInt(row.id, 0), order_number: asText(row.number),
        order_kind: asText(row.kind), order_date: asText(row.date),
        status: asText(row.status), warehouse: asText(row.warehouse),
        sku: asText(row.skus), tracking_number: '', latest_note: '',
      };
    }
    const workflow = asObject(row.workflow_status_projection);
    const items = Array.isArray(row.items) ? row.items.map(asObject) : [];
    const number = asText(row.erp_order_id).trim() ||
      asText(row.external_order_number).trim() ||
      asText(row.internal_order_number).trim();
    return {
      id: safeInt(row.id, 0),
      order_number: number || '—',
      order_kind: asText(row.order_kind_label || row.order_kind),
      order_date: asText(row.order_date),
      status: asText(workflow.zh || row.order_status_label || row.order_status),
      warehouse: asText(shipment.warehouse_name),
      sku: items.map(item => asText(item.sku)).filter(Boolean).join(', '),
      tracking_number: asText(shipment.tracking_number),
      latest_note: asText(row.latest_order_note),
    };
  });
  const filters = Object.fromEntries(Object.entries(asObject(props.filters))
    .filter((pair): pair is [string,string] => typeof pair[1] === 'string'));
  return {
    rows, page: safeInt(pager.current_page,1), lastPage: safeInt(pager.last_page,1),
    perPage: safeInt(pager.per_page,50), total: safeInt(pager.total,rows.length),
    filters,
  };
}
export async function getExistingErpOrders(
  input: { search?:string; page?:number; perPage?:number; view?:string; scope?:'all'|'customers'|'kol' },
  fetcher: typeof fetch = fetch,
): Promise<ErpOrderPage> {
  const response = await fetcher(orderReadOnlyPath(input), {
    method: 'GET',
    credentials: 'same-origin',
    redirect: 'manual',
    headers: { 'X-Inertia': 'true', 'X-Requested-With': 'XMLHttpRequest',
      'Accept': 'text/html, application/xhtml+xml' },
  });
  if (response.status === 401 || response.status === 302 || response.status === 403)
    throw new Error('ERP_LOGIN_OR_PERMISSION_REQUIRED');
  if (!response.ok) throw new Error('ERP_ORDER_GET_FAILED_' + response.status);
  if (!(response.headers.get('content-type') || '').includes('application/json'))
    throw new Error('ERP_INERTIA_JSON_NOT_AVAILABLE');
  return normalizeExistingInertiaOrders(await response.json());
}

export async function getMacDevReadOnlyOrders(input: {
  search?:string; page?:number; perPage?:number; view?:string;
}): Promise<ErpOrderPage> {
  const result=await requestClient.get<{
    source:string;scope:string;real_dev_rows:boolean;inertia:unknown;
  }>('/erp/dev/orders',{params:{
    search:input.search||'',page:input.page??1,per_page:input.perPage??50,view:input.view??'all',
  }});
  if(result.source!=='MAC_LOCAL_DEV_SQLITE'||result.scope!=='DEV_UAT_OWNER_ONLY'||!result.real_dev_rows)
    throw new Error('ERP_DEV_DATA_SOURCE_REJECTED');
  return normalizeExistingInertiaOrders(result.inertia);
}
export interface ErpDevMenuNode {
  key: string; label: string; path: string; children?: ErpDevMenuNode[];
}
export interface ErpCurrentDevPage extends ErpOrderPage {
  navigation: ErpDevMenuNode[];
  role: string;
}
export async function getCurrentDevReadOnlyOrders(input: {
  search?: string; page?: number; perPage?: number;
  role?: 'owner' | 'staff' | 'kol' | 'manager';
  scope?: 'all' | 'customers';
}): Promise<ErpCurrentDevPage> {
  const result = await requestClient.get<{
    source: string; authenticatedByLaravel: boolean; rowsAreIsolatedUat: boolean;
    role: string; inertia: unknown;
  }>('/erp/current/orders', { params: {
    search: input.search ?? '', page: input.page ?? 1, per_page: input.perPage ?? 50,
    role: input.role ?? 'owner', scope: input.scope ?? 'all',
  }, timeout: 45_000 });
  if (result.source !== 'CURRENT_LARAVEL_MAIN_DEV_B07885E3' ||
      result.authenticatedByLaravel !== true || result.rowsAreIsolatedUat !== true) {
    throw new Error('ERP_CURRENT_DEV_AUTH_SOURCE_REJECTED');
  }
  const props = asObject(asObject(result.inertia).props);
  const navigation = props.shellNavigation;

  if (!Array.isArray(navigation)) throw new Error('ERP_LARAVEL_NAV_MISSING');
  const menus = navigation.map((raw): ErpDevMenuNode => {
    const node = asObject(raw);
    const children = Array.isArray(node.children) ? node.children.map((child) => {
      const c = asObject(child);
      return { key: asText(c.key), label: asText(c.label), path: asText(c.path) };
    }) : [];
    return { key: asText(node.key), label: asText(node.label),
      path: asText(node.path), children };
  });
  return { ...normalizeExistingInertiaOrders(result.inertia), navigation: menus, role: result.role };
}