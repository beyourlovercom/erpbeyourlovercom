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
  scope?: 'all' | 'customers' | 'kol' | 'collaboration' | 'procurement' | 'archive';
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
    input.scope === 'kol' ? '/kol/orders' : input.scope === 'collaboration' ? '/orders/collaboration' : input.scope === 'procurement' ? '/orders/procurement' : input.scope === 'archive' ? '/orders/archive' : '/orders';
  return path + '?' + params.toString();
};
export function normalizeExistingInertiaOrders(value: unknown): ErpOrderPage {
  const page = asObject(value);
  const isMaster = page.component === 'Orders/Master';
  const isArchive = page.component === 'Orders/Archive';
  if (!isMaster && !isArchive && page.component !== 'Orders/Index') throw new Error('ERP_INERTIA_COMPONENT_MISMATCH');
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
    if (isArchive) {
      return { id: safeInt(row.id, 0), order_number: asText(row.erp_order_id) || '—',
        order_kind: asText(row.order_kind), order_date: asText(row.operational_archived_at),
        status: asText(row.order_status), warehouse: '', sku: '', tracking_number: '',
        latest_note: asText(row.archived_by),
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
// Laravel requires the current Inertia asset version even for read-only GETs.
// The Vben SPA is served independently, so it cannot reuse Laravel's page boot.
const inertiaVersionCache = new WeakMap<typeof fetch, Promise<string>>();
export async function laravelInertiaVersion(fetcher: typeof fetch, refresh = false): Promise<string> {
  if (refresh) inertiaVersionCache.delete(fetcher);
  let pending = inertiaVersionCache.get(fetcher);
  if (!pending) {
    pending = (async () => {
      const response = await fetcher('/dashboard', {
        method: 'GET', credentials: 'same-origin', redirect: 'manual',
        headers: { Accept: 'text/html' },
      });
      if ([302, 401, 403].includes(response.status))
        throw new Error('ERP_LOGIN_OR_PERMISSION_REQUIRED');
      if (!response.ok) throw new Error('ERP_INERTIA_BOOTSTRAP_FAILED_' + response.status);
      const html = (await response.text()).replace(/&quot;|&#0*34;|&#x0*22;/gi, '"');
      const version = html.match(/"version"\s*:\s*"([A-Za-z0-9._-]{1,128})"/)?.[1];
      if (!version) throw new Error('ERP_INERTIA_VERSION_NOT_AVAILABLE');
      return version;
    })();
    inertiaVersionCache.set(fetcher, pending);
  }
  try { return await pending; }
  catch (error) {
    if (inertiaVersionCache.get(fetcher) === pending) inertiaVersionCache.delete(fetcher);
    throw error;
  }
}
export async function getExistingErpOrders(
  input: { search?:string; page?:number; perPage?:number; view?:string; scope?:'all'|'customers'|'kol'|'collaboration'|'procurement'|'archive' },
  fetcher: typeof fetch = fetch,
): Promise<ErpOrderPage> {
  for (const refresh of [false, true]) {
    const version = await laravelInertiaVersion(fetcher, refresh);
    const response = await fetcher(orderReadOnlyPath(input), {
      method: 'GET',
      credentials: 'same-origin',
      redirect: 'manual',
      headers: { 'X-Inertia': 'true', 'X-Inertia-Version': version,
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'text/html, application/xhtml+xml' },
    });
    // A Laravel asset deployment invalidates the cached version. Retry once.
    if (response.status === 409 && !refresh) continue;
    if ([302, 401, 403].includes(response.status))
      throw new Error('ERP_LOGIN_OR_PERMISSION_REQUIRED');
    if (!response.ok) throw new Error('ERP_ORDER_GET_FAILED_' + response.status);
    if (!(response.headers.get('content-type') || '').includes('application/json'))
      throw new Error('ERP_INERTIA_JSON_NOT_AVAILABLE');
    return normalizeExistingInertiaOrders(await response.json());
  }
  throw new Error('ERP_INERTIA_VERSION_RETRY_EXHAUSTED');
}

export interface ErpOrderDetail {
  id: number; order_number: string; order_kind: string; status: string;
  order_date: string; owner: string; currency: string;
  items: { sku: string; quantity: number; product_summary: string }[];
  shipment: { warehouse_name: string; carrier: string; service: string; tracking_number: string };
  orderNotes: { id: number; author_name: string; body: string; created_at: string }[];
  canAppendOrderNote: boolean;
  fulfillment: {
    aggregate_status_label: string;
    segments: { order_id: number; erp_order_id: string; suffix: string;
      role_label: string; reason_label: string;
      items: { sku: string; quantity: number }[];
      shipment: { warehouse_name: string; carrier: string; service: string;
        tracking_number: string; status_label: string } }[];
  } | null;
}
export async function getExistingErpOrderDetail(
  id: number, kind: 'customer' | 'kol', fetcher: typeof fetch = fetch,
): Promise<ErpOrderDetail> {
  if (!Number.isSafeInteger(id) || id < 1) throw new Error('ERP_ORDER_ID_INVALID');
  const path = (kind === 'kol' ? '/kol/orders/' : '/customers/orders/') + id;
  for (const refresh of [false, true]) {
    const version = await laravelInertiaVersion(fetcher, refresh);
    const response = await fetcher(path, {
      method: 'GET', credentials: 'same-origin', redirect: 'manual',
      headers: { 'X-Inertia': 'true', 'X-Inertia-Version': version,
        'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml' },
    });
    if (response.status === 409 && !refresh) continue;
    if ([302, 401, 403, 404].includes(response.status))
      throw new Error('ERP_ORDER_DETAIL_NOT_AUTHORIZED');
    if (!response.ok) throw new Error('ERP_ORDER_DETAIL_GET_FAILED_' + response.status);
    const payload = asObject(await response.json());
    if (payload.component !== 'Orders/Show') throw new Error('ERP_ORDER_DETAIL_COMPONENT_MISMATCH');
    const order = asObject(asObject(payload.props).order);
    if (safeInt(order.id, 0) !== id) throw new Error('ERP_ORDER_DETAIL_ID_MISMATCH');
    const props = asObject(payload.props);
    const shipment = asObject(order.shipment), owner = asObject(order.owner);
    return {
      id, order_number: asText(order.erp_order_id || order.external_order_number || order.internal_order_number),
      order_kind: asText(order.order_kind_label || order.order_kind),
      status: asText(asObject(order.workflow_status_projection).zh || order.order_status_label),
      order_date: asText(order.order_date), owner: asText(owner.name), currency: asText(order.currency),
      canAppendOrderNote: props.canAppendOrderNote === true,
      orderNotes: (Array.isArray(props.orderNotes) ? props.orderNotes : []).map(note => {
        const n = asObject(note);
        return { id: safeInt(n.id, 0), author_name: asText(n.author_name),
          body: asText(n.body), created_at: asText(n.created_at) };
      }),
      items: (Array.isArray(order.items) ? order.items : []).map(item => {
        const row = asObject(item);
        return { sku: asText(row.sku), quantity: safeInt(row.quantity, 0),
          product_summary: asText(row.product_summary) };
      }),
      shipment: { warehouse_name: asText(shipment.warehouse_name),
        carrier: asText(shipment.carrier), service: asText(shipment.service),
        tracking_number: asText(shipment.tracking_number) },
      fulfillment: order.fulfillment ? (() => {
        const fulfillment = asObject(order.fulfillment);
        return { aggregate_status_label: asText(fulfillment.aggregate_status_label),
          segments: (Array.isArray(fulfillment.segments) ? fulfillment.segments : []).map(segment => {
            const item = asObject(segment), shipping = asObject(item.shipment);
            return {
              order_id: safeInt(item.order_id, 0), erp_order_id: asText(item.erp_order_id),
              suffix: asText(item.suffix), role_label: asText(item.role_label),
              reason_label: asText(item.reason_label),
              items: (Array.isArray(item.items) ? item.items : []).map(value => {
                const part = asObject(value);
                return { sku: asText(part.sku), quantity: safeInt(part.quantity, 0) };
              }),
              shipment: { warehouse_name: asText(shipping.warehouse_name),
                carrier: asText(shipping.carrier), service: asText(shipping.service),
                tracking_number: asText(shipping.tracking_number),
                status_label: asText(shipping.status_label) },
            };
          }),
        };
      })() : null,
    };
  }
  throw new Error('ERP_ORDER_DETAIL_VERSION_RETRY_EXHAUSTED');
}

/** Opt-in isolated Dev write. Laravel owns CSRF, ACL, validation, transaction and audit. */
export async function appendExistingErpOrderNote(
  input: { id: number; kind: 'customer' | 'kol'; body: string },
  fetcher: typeof fetch = fetch,
  cookieSource: string = typeof document === 'undefined' ? '' : document.cookie,
): Promise<ErpOrderDetail> {
  if (!Number.isSafeInteger(input.id) || input.id < 1) throw new Error('ERP_NOTE_ORDER_ID_INVALID');
  const body = input.body.trim();
  if (!body || body.length > 2000) throw new Error('ERP_NOTE_BODY_INVALID');
  const before = await getExistingErpOrderDetail(input.id, input.kind, fetcher);
  if (!before.canAppendOrderNote) throw new Error('ERP_NOTE_SERVER_DISALLOWED');
  const cookie = cookieSource.split(';').map(s => s.trim()).find(s => s.startsWith('XSRF-TOKEN='));
  if (!cookie) throw new Error('ERP_NOTE_CSRF_COOKIE_REQUIRED');
  let xsrf: string;
  try { xsrf = decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)); }
  catch { throw new Error('ERP_NOTE_CSRF_COOKIE_INVALID'); }
  const path = (input.kind === 'kol' ? '/kol/orders/' : '/customers/orders/') + input.id + '/notes';
  const response = await fetcher(path, {
    method: 'POST', credentials: 'same-origin', redirect: 'manual',
    headers: { 'Content-Type': 'application/json', Accept: 'text/html, application/xhtml+xml',
      'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf },
    body: JSON.stringify({ body }),
  });
  if ([401, 403, 404, 419].includes(response.status)) throw new Error('ERP_NOTE_NOT_AUTHORIZED_' + response.status);
  if (response.status === 409 || response.status === 422) throw new Error('ERP_NOTE_SERVER_REJECTED_' + response.status);
  // Browsers intentionally mask redirect:manual as status 0 / opaqueredirect.
  // Never retry the POST: an apparent gateway error can follow a committed write.
  const redirectAccepted = [302, 303].includes(response.status) ||
    (response.status === 0 && response.type === 'opaqueredirect');
  const location = response.headers.get('location') || '';
  if (location && !location.startsWith('/') && !location.startsWith(globalThis.location?.origin + '/'))
    throw new Error('ERP_NOTE_EXTERNAL_REDIRECT_DENIED');
  // Readback is the source of truth, even after an ambiguous 502 from the Dev gateway.
  const after = await getExistingErpOrderDetail(input.id, input.kind, fetcher);
  const persisted = after.orderNotes.some(note =>
    note.body === body && !before.orderNotes.some(prev => prev.id === note.id));
  if (!persisted) throw new Error(redirectAccepted
    ? 'ERP_NOTE_POST_NOT_CONFIRMED_BY_SERVER' : 'ERP_NOTE_POST_FAILED_' + response.status);
  return after;
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