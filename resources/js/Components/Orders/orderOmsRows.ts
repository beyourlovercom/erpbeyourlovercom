export type OrderItemSummary = {
  sku?: string | null
  product_summary?: string | null
  quantity?: number | string | null
  unit_price?: number | string | null
  line_revenue?: number | string | null
}
export type OrderGridOrder = Record<string, unknown> & {
  id: number
  erp_order_id: string | null
  external_order_number?: string | null
  internal_order_number?: string | null
  source_label?: string
  order_date?: string | null
  order_status_label?: string
  order_kind_label?: string | null
  workflow_status_projection?: { zh?: string; code?: string }
  owner_label?: string | null
  order_note?: string | null
  currency?: string | null
  items?: OrderItemSummary[] | null
  address?: {
    recipient_name?: string | null
    phone?: string | null
    country?: string | null
    province_state?: string | null
    city?: string | null
    postal_code?: string | null
    address_line_1?: string | null
    address_line_2?: string | null
  } | null
  shipment?: {
    warehouse_name?: string | null
    warehouse_id?: number | null
    shipment_status?: string | null
    warehouse_selection_source?: string | null
    carrier?: string | null
    carrier_selection_source?: string | null
    service?: string | null
    tracking_number?: string | null
    shipped_at?: string | null
    logistics_note?: string | null
    handled_by?: number | null
  } | null
  fulfillment_parent?: { erp_order_id?: string | null } | null
  manual_sync?: { status?: string | null; synced_at?: string | null } | null
  operational_markers?: string[] | null
  domestic_reject_reason?: string | null
  domestic_rejected_at?: string | null
}

const distinct = (values: Array<string | null | undefined>): string[] =>
  Array.from(new Set(values.map(value => (value ?? '').trim()).filter(Boolean)))

const validNumber = (value: unknown): number | null => {
  if (value === null || value === undefined || value === '') return null
  const number = Number(value)
  return Number.isFinite(number) ? number : null
}

/**
 * Project only existing order-list response fields. Never compute a fake order total:
 * summed line_revenue excludes order-level discounts, tax and shipping.
 */
export function toOrderOmsRow(order: OrderGridOrder): Record<string, unknown> {
  const items = Array.isArray(order.items) ? order.items : []
  const hasItems = items.length > 0
  const skus = distinct(items.map(item => item.sku))
  const productNames = distinct(items.map(item => item.product_summary))
  const quantity = hasItems
    ? items.reduce((total, item) => total + (validNumber(item.quantity) ?? 0), 0)
    : null
  const subtotalLines = items.map(item => validNumber(item.line_revenue))
  const subtotal = subtotalLines.some(value => value !== null)
    ? subtotalLines.reduce<number>((total, value) => total + (value ?? 0), 0)
    : null
  const currency = order.currency || 'USD'
  const money = (value: number | null) => value === null ? '' : currency + ' ' + value.toFixed(2)
  const itemDescriptors = items.map(item => {
    const sku = item.sku?.trim() || item.product_summary?.trim() || '未填写 SKU'
    const units = validNumber(item.quantity)
    return units === null ? sku : sku + ' × ' + units
  })

  return {
    ...order,
    order_db_id: order.id,
    erp_order_id: order.erp_order_id ?? '—',
    sku_list: skus.length ? skus.join(', ') : '—',
    order_quantity: quantity,
    sku_quantity_detail: itemDescriptors.length ? itemDescriptors.join('; ') : '—',
    product_names: productNames.length ? productNames.join(', ') : '—',
    item_line_count: hasItems ? items.length : null,
    sku_type_count: hasItems ? skus.length : null,
    item_subtotal: subtotal,
    unit_price_detail: items.length
      ? items.map(item => (item.sku?.trim() || '未填写 SKU') + ' ' + money(validNumber(item.unit_price))).join('; ')
      : '—',
    recipient: order.address?.recipient_name || '—',
    country: order.address?.country || '—',
    phone: order.address?.phone || '—',
    province_state: order.address?.province_state || '—',
    city: order.address?.city || '—',
    postal_code: order.address?.postal_code || '—',
    address_line_1: order.address?.address_line_1 || '—',
    address_line_2: order.address?.address_line_2 || '—',
    warehouse_name: order.shipment?.warehouse_name || '—',
    warehouse_id: order.shipment?.warehouse_id ?? null,
    shipment_status: order.shipment?.shipment_status || '—',
    warehouse_selection_source: order.shipment?.warehouse_selection_source || '—',
    carrier: order.shipment?.carrier || '—',
    carrier_selection_source: order.shipment?.carrier_selection_source || '—',
    service: order.shipment?.service || '—',
    tracking_number: order.shipment?.tracking_number || '—',
    shipped_at: order.shipment?.shipped_at || '—',
    logistics_note: order.shipment?.logistics_note || '—',
    handled_by: order.shipment?.handled_by ?? null,
    status_label: order.workflow_status_projection?.zh ?? order.order_status_label ?? '—',
    owner_label: order.owner_label ?? '—',
    order_note: order.order_note || '—',
    manual_sync_status: order.manual_sync?.status || '—',
    manual_sync_at: order.manual_sync?.synced_at || '—',
    fulfillment_parent_number: order.fulfillment_parent?.erp_order_id || '—',
    operational_markers_label: order.operational_markers?.length ? order.operational_markers.join(', ') : '—',
    domestic_reject_reason: order.domestic_reject_reason || '—',
    domestic_rejected_at: order.domestic_rejected_at || '—',
  }
}
