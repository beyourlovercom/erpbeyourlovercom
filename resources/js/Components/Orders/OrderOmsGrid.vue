<script setup lang="ts">
import { computed, ref } from 'vue'
import type { ColDef } from 'ag-grid-community'
import OmsGrid from '../../Components/DataGrid/OmsGrid.vue'
import OrderOmsCell from './OrderOmsCell.vue'
import OrderOmsTransfer from './OrderOmsTransfer.vue'
import { toOrderOmsItemRows, type OrderGridOrder } from './orderOmsRows'

type Option = { code: string; en: string; zh: string; color: string; mutable: boolean }
type Pagination = {
  data: OrderGridOrder[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}
const props = defineProps<{
  title: string
  orders: Pagination
  filters: Record<string, string>
  canEditWorkflowStatus?: boolean
  workflowStatusOptions?: Option[]
  exportUrl: string
  canImport: boolean
  canExport: boolean
}>()
const emit = defineEmits<{
  (event: 'page-change', page: number): void
  (event: 'page-size-change', size: number | 'all'): void
  (event: 'sort-change', sort: { colId: string; direction: 'asc' | 'desc' | null }): void
  (event: 'search-change', value: string): void
  (event: 'column-search-change', filter: { colId: string; value: string }): void
}>()

const selectedOrderIds = ref<number[]>([])
const onSelectionChange = (selectedRows: Record<string, unknown>[]) => {
  const pageIds = new Set(props.orders.data.map(order => Number(order.id)))
  const newlySelected = new Set(selectedRows.map(row => Number(row.id)).filter(id => Number.isSafeInteger(id) && id > 0))
  selectedOrderIds.value = [...selectedOrderIds.value.filter(id => !pageIds.has(id)), ...newlySelected]
    .filter((id, index, all) => all.indexOf(id) === index)
}
const clearSelected = () => { selectedOrderIds.value = [] }
const rows = computed<Record<string, unknown>[]>(() => props.orders.data.flatMap(order =>
  toOrderOmsItemRows(order, {
    sku: props.filters.grid_sku,
    quantity: props.filters.grid_quantity,
    product_names: props.filters.grid_product_names,
  })))
const basic = (id: string, field: string, title: string, width: number, visible = false, sortable = false): ColDef<Record<string, unknown>> => ({
  colId: id, field, headerName: title, width, hide: !visible, sortable,
  tooltipField: field,
})
const columns = computed<ColDef<Record<string, unknown>>[]>(() => [
  // One AG Grid row per SKU line; server pagination and status remain per original order.
  { ...basic('order_number', 'erp_order_id', 'ERP订单号', 170, true, true),
    cellRenderer: OrderOmsCell, cellRendererParams: { mode: 'number' } },
  basic('sku', 'sku_list', 'SKU', 174, true),
  basic('quantity', 'order_quantity', 'SKU数量', 94, true),
  basic('order_total_quantity', 'order_total_quantity', '订单总件数', 110),
  basic('source', 'source_label', '来源', 116, true, true),
  basic('date', 'order_date', '订单日期', 126, true, true),
  basic('recipient', 'recipient', '收件人', 135, true),
  basic('country', 'country', '国家', 92, true),
  basic('warehouse', 'warehouse_name', '仓库', 132, true),
  { ...basic('status', 'status_label', '工作状态', 172, true),
    cellRenderer: OrderOmsCell, cellRendererParams: {
      mode: 'status', canEdit: props.canEditWorkflowStatus === true,
      options: props.workflowStatusOptions ?? [],
    } },
  basic('tracking', 'tracking_number', '物流单号', 158, true),
  // Optional: Product detail and revenue from authoritative order item lines.
  basic('sku_qty_detail', 'sku_quantity_detail', '整单SKU×数量', 210),
  { ...basic('order_line_subtotal', 'order_line_subtotal', '整单商品行金额', 142),
    valueFormatter: p => p.value === null || p.value === undefined ? '—' : String(p.data?.currency ?? 'USD') + ' ' + Number(p.value).toFixed(2) },
  basic('product_names', 'product_names', '产品名称', 190),
  basic('item_line_count', 'item_line_count', '商品行数', 92),
  basic('sku_type_count', 'sku_type_count', 'SKU种类数', 100),
  { ...basic('item_subtotal', 'item_subtotal', '商品行金额', 135),
    valueFormatter: p => p.value === null || p.value === undefined ? '—' : String(p.data?.currency ?? 'USD') + ' ' + Number(p.value).toFixed(2) },
  basic('unit_price_detail', 'unit_price_detail', '产品单价明细', 195),
  basic('currency', 'currency', '币种', 92),
  // Optional: identities and authority.
  basic('external_number', 'external_order_number', '网站/来源订单号', 165),
  basic('internal_number', 'internal_order_number', '内部订单号', 170),
  basic('db_id', 'order_db_id', '订单数据库ID', 110),
  basic('order_kind', 'order_kind_label', '订单类型', 120),
  basic('source_type', 'source_type', '来源类型代码', 126),
  basic('real_status', 'order_status_label', '订单真实状态', 125),
  basic('owner', 'owner_label', '负责人', 140),
  basic('parent_order', 'fulfillment_parent_number', '原订单号', 160),
  basic('operational_markers', 'operational_markers_label', '异常/业务标记', 170),
  // Optional: address fields; never confuse blank with valid data.
  basic('phone', 'phone', '收件电话', 152),
  basic('province', 'province_state', '州/省', 132),
  basic('city', 'city', '城市', 132),
  basic('postal_code', 'postal_code', '邮编', 112),
  basic('address_1', 'address_line_1', '地址第1行', 240),
  basic('address_2', 'address_line_2', '地址第2行', 220),
  // Optional: existing logistics and allocation fields; no new warehouse API.
  basic('warehouse_id', 'warehouse_id', '仓库ID', 94),
  basic('shipment_status', 'shipment_status', '发货状态', 122),
  basic('warehouse_selection_source', 'warehouse_selection_source', '分仓来源', 140),
  basic('carrier', 'carrier', '物流商', 113),
  basic('service', 'service', '物流渠道', 135),
  basic('carrier_selection_source', 'carrier_selection_source', '物流商选择来源', 155),
  basic('shipped_at', 'shipped_at', '实际发货时间', 170),
  basic('handled_by', 'handled_by', '发货处理人ID', 130),
  basic('logistics_note', 'logistics_note', '物流备注', 215),
  // Optional: notes, sync and exceptional review.
  basic('order_note', 'order_note', '订单备注', 230),
  basic('manual_sync_status', 'manual_sync_status', '官网同步状态', 130),
  basic('manual_sync_at', 'manual_sync_at', '官网同步时间', 168),
  basic('domestic_reject_reason', 'domestic_reject_reason', '国内驳回原因', 210),
  basic('domestic_rejected_at', 'domestic_rejected_at', '国内驳回时间', 170),
  { ...basic('actions', 'erp_order_id', '操作', 84, true),
    filter: false, cellRenderer: OrderOmsCell, cellRendererParams: { mode: 'action' } },
])
const sort = computed(() => ({
  colId: props.filters.sort && ['order_number', 'date', 'source'].includes(props.filters.sort)
    ? props.filters.sort : 'date',
  direction: props.filters.direction === 'asc' ? 'asc' as const : 'desc' as const,
}))
const pagination = computed(() => ({
  current_page: props.orders.current_page,
  last_page: props.orders.last_page,
  per_page: props.orders.per_page,
  total: props.orders.total,
  page_size_mode: props.filters.per_page === 'all' ? 'all' as const : props.orders.per_page,
}))
const columnSearchKeys = [
  'order_number', 'sku', 'quantity', 'product_names', 'source', 'date',
  'recipient', 'country', 'warehouse', 'status', 'tracking', 'owner',
  'city', 'postal_code', 'carrier', 'external_number',
]
const columnFilterValues = computed(() =>
  Object.fromEntries(columnSearchKeys.map(key => [key, props.filters['grid_' + key] ?? ''])))
</script>

<template>
  <OmsGrid
    namespace="grid.orders.all.oms.v1"
    :title="title"
    :columns="columns"
    :rows="rows"
    :server-pagination="pagination"
    :server-sort="sort"
    :server-search="props.filters.search ?? ''"
    :server-column-filters="columnFilterValues"
    :server-filter-columns="columnSearchKeys"
    preference-scope="account"
    :default-pinned-left="1"
    :default-pinned-right="1"
    :required-column-ids="['order_number', 'actions']"
    row-identity-key="_oms_line_key"
    selection-order-key="id"
    :selected-order-ids="selectedOrderIds"
    @selection-change="onSelectionChange"
    @page-change="emit('page-change', $event)"
    @page-size-change="emit('page-size-change', $event)"
    @sort-change="emit('sort-change', $event)"
    @search-change="emit('search-change', $event)"
    @column-search-change="emit('column-search-change', $event)"
  >
    <template #actions><OrderOmsTransfer :export-url="exportUrl" :can-import="canImport" :can-export="canExport" :total="orders.total" :selected-order-ids="selectedOrderIds" @clear-selection="clearSelected" /></template>
  </OmsGrid>
</template>
<style scoped>
.oms-orders-server-authority{color:#64748b;font-size:11px}
</style>
