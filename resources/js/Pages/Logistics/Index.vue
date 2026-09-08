<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref, watch } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import StatusBar from '../../Components/Logistics/StatusBar.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition, type GridPreferenceValue } from '../../Composables/useAccountGridPreferences'

type Availability = { warehouse_id: number; warehouse_slug: string; warehouse_name: string; inventory_state: string; can_auto_allocate: boolean; quantity_on_hand: number | null; quantity_reserved: number | null; quantity_available: number | null }
type Item = { id: number; product_id: number | null; sku: string | null; product_summary: string | null; quantity: number; availability: Availability[] }
type Shipment = { warehouse_id: number | null; warehouse_name: string | null; warehouse_selection_source: string | null; carrier: string | null; service: string | null; tracking_number: string | null; shipped_at: string | null; logistics_note: string | null }
type RecommendationWarehouse = { id: number; slug: string; code: string; name: string }
type Recommendation = { status: 'recommended' | 'blocked'; reason_code: string; reason_label: string; country_code: string | null; required_sku_count: number | null; candidate_count: number; warehouse: RecommendationWarehouse | null }
type AutomaticAllocation = { state: string; status: 'ready' | 'blocked' | 'allocated'; reason_code: string; reason_label: string; country_code: string | null; warehouse: RecommendationWarehouse | null; can_apply: boolean }
type Order = { id: number; erp_order_id: string | null; external_order_number: string | null; order_kind_label: string; order_url?: string | null; recipient_name: string | null; phone: string | null; country: string | null; owner_label: string | null; item_count: number; items: Item[]; warehouse_recommendation: Recommendation; automatic_allocation: AutomaticAllocation; shipment: Shipment | null }
type Warehouse = { id: number; slug: string; name: string; code: string; country_code: string | null; priority: number; inventory_state: string; can_auto_allocate: boolean; service_regions: { country_code: string; priority: number }[] }
type CarrierOption = { value: string; label: string }
type Paginated<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number }
type Draft = { warehouse_id: number | ''; carrier: string; service: string; tracking_number: string; shipped_at: string; logistics_note: string }
type ColumnKey = 'order_number' | 'type' | 'recipient' | 'country' | 'item_count' | 'warehouse'

const props = defineProps<{ orders: Paginated<Order>; warehouses: Warehouse[]; carrierOptions: CarrierOption[]; statusBar?: { key: string; label: string; url: string; count: number }[]; gridPreferenceNamespace?: string; gridPreference?: GridPreferenceValue<ColumnKey> | null }>()
const drafts = reactive<Record<number, Draft>>({})
const openOrderId = ref<number | null>(null)
const copyState = ref<Record<number, 'success' | 'error'>>({})
const shipValidation = ref<Record<number, string>>({})
const localWarehouseNames = reactive<Record<number, string>>({})
const adoptError = ref<Record<number, string>>({})
const defaultColumns: GridColumnDefinition<ColumnKey>[] = [
  { key: 'order_number', label: '订单号' },
  { key: 'type', label: '类型' },
  { key: 'recipient', label: '收件人' },
  { key: 'country', label: '国家' },
  { key: 'item_count', label: '商品数' },
  { key: 'warehouse', label: '仓库' },
]
const {
  columns: columnPreferences,
  syncState: columnPreferenceSyncState,
  toggle: toggleColumn,
  startDrag: startColumnDrag,
  drop: dropColumn,
  reset: resetColumns,
} = useAccountGridPreferences<ColumnKey>({
  namespace: props.gridPreferenceNamespace ?? 'grid.logistics.pending.v1',
  definitions: defaultColumns,
  serverValue: props.gridPreference ?? null,
})
const visibleColumns = computed(() => columnPreferences.value.filter(column => column.key === 'order_number' || column.visible))

const localDateTime = (value: string | null) => value ? value.slice(0, 16) : new Date().toISOString().slice(0, 16)
const makeDraft = (order: Order): Draft => ({
  warehouse_id: order.shipment?.warehouse_id ?? '',
  carrier: order.shipment?.carrier ?? '',
  service: order.shipment?.service ?? '',
  tracking_number: order.shipment?.tracking_number ?? '',
  shipped_at: localDateTime(order.shipment?.shipped_at ?? null),
  logistics_note: order.shipment?.logistics_note ?? '',
})
watch(() => props.orders.data, (orders) => {
  for (const order of orders) {
    if (!drafts[order.id]) {
      drafts[order.id] = makeDraft(order)
    } else if (['recommended', 'automatic'].includes(order.shipment?.warehouse_selection_source ?? '') && order.shipment?.warehouse_id != null) {
      drafts[order.id].warehouse_id = order.shipment.warehouse_id
    }
  }
}, { immediate: true, deep: true })

const toggle = (orderId: number) => { openOrderId.value = openOrderId.value === orderId ? null : orderId }

function csrfHeaders(): Record<string, string> {
  const metaToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
  if (metaToken) return { 'X-CSRF-TOKEN': metaToken }
  const xsrfToken = decodeURIComponent(document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '')
  return xsrfToken ? { 'X-XSRF-TOKEN': xsrfToken } : {}
}

const adoptRecommendation = async (order: Order) => {
  if (order.warehouse_recommendation.status !== 'recommended' || !order.warehouse_recommendation.warehouse) return
  delete adoptError.value[order.id]
  try {
    const resp = await fetch(`/logistics/orders/${order.id}/adopt-recommendation`, {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', ...csrfHeaders() },
      credentials: 'same-origin',
      body: JSON.stringify({}),
    })
    if (!resp.ok) {
      const body = await resp.json().catch(() => ({}))
      adoptError.value[order.id] = (body as { message?: string }).message ?? `操作失败（${resp.status}）`
      return
    }
    const data = await resp.json() as { warehouse_id: number; warehouse_name: string | null }
    if (drafts[order.id]) {
      drafts[order.id].warehouse_id = data.warehouse_id
    }
    if (data.warehouse_name) {
      localWarehouseNames[order.id] = data.warehouse_name
    }
  } catch {
    adoptError.value[order.id] = '网络错误，请重试'
  }
}
const saveDraft = (order: Order) => router.post(`/logistics/orders/${order.id}/draft`, drafts[order.id], { preserveScroll: true })
const ship = (order: Order) => {
  const draft = drafts[order.id]
  delete shipValidation.value[order.id]
  if (!draft.warehouse_id) { shipValidation.value[order.id] = '请先选择发货仓库'; return }
  if (!draft.carrier.trim()) { shipValidation.value[order.id] = '请先选择物流商'; return }
  if (!draft.service.trim()) { shipValidation.value[order.id] = '请填写物流渠道'; return }
  if (!draft.shipped_at) { shipValidation.value[order.id] = '请选择发货时间'; return }
  router.post(`/logistics/orders/${order.id}/ship`, draft, { preserveScroll: true })
}

function copyToClipboardFallback(text: string): boolean {
  const textarea = document.createElement('textarea')
  textarea.value = text
  textarea.setAttribute('readonly', '')
  textarea.style.position = 'fixed'; textarea.style.left = '-9999px'
  document.body.appendChild(textarea); textarea.select()
  let succeeded = false
  try { succeeded = document.execCommand('copy') } catch { succeeded = false }
  document.body.removeChild(textarea)
  return succeeded
}
async function writeClipboard(text: string): Promise<boolean> {
  if (window.isSecureContext && navigator.clipboard?.writeText) {
    try { await navigator.clipboard.writeText(text); return true } catch { /* fallback */ }
  }
  return copyToClipboardFallback(text)
}
const copy = async (order: Order, mode: 'recipient' | 'full' | 'tracking') => {
  delete copyState.value[order.id]
  const response = await fetch(`/logistics/orders/${order.id}/payload`, { headers: { Accept: 'application/json' } })
  if (!response.ok) { copyState.value[order.id] = 'error'; return }
  const p = await response.json()
  const recipient = `${p.recipient_name ?? '—'}\n${p.phone ?? '—'}\n${[p.country, p.province_state, p.city, p.address_line_1, p.address_line_2, p.postal_code].filter(Boolean).join(', ')}`
  const full = `${p.order_number}\n${recipient}\n${p.items.map((item: { product_summary: string; sku: string | null; quantity: number }) => `${item.product_summary} (${item.sku ?? '无 SKU'}) × ${item.quantity}`).join('\n')}\n仓库: ${p.warehouse ?? ''}\n物流: ${[p.carrier, p.service].filter(Boolean).join(' ')}\nTracking: ${p.tracking_number ?? ''}`
  const text = mode === 'recipient' ? recipient : mode === 'tracking' ? `${[p.carrier, p.service].filter(Boolean).join(' ')} ${p.tracking_number ?? ''}`.trim() : full
  copyState.value[order.id] = await writeClipboard(text) ? 'success' : 'error'
  window.setTimeout(() => { delete copyState.value[order.id] }, 2500)
}
const goToPage = (page: number) => router.get('/logistics/pending', { page }, { preserveState: true, replace: true })
</script>

<template>
  <Head title="物流 · BYL ERP" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '待发货']">
    <section class="mvp-page">
      <StatusBar :statuses="props.statusBar ?? []" />
      <div class="mvp-page-heading"><div><p class="mvp-kicker">Assisted Fulfillment</p><h2>待发货</h2><p>系统按服务区域与确认库存推荐仓库，由人工采用并完成履约。</p></div></div>
      <div class="mvp-table-wrap"><table class="mvp-table logistics-pending-grid"><thead><tr><th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th><th>操作</th><ColumnSettingsHeader :columns="columnPreferences" :required-keys="['order_number']" :sync-state="columnPreferenceSyncState" @toggle="toggleColumn" @start-drag="startColumnDrag" @drop="dropColumn" @reset="resetColumns" /></tr></thead><tbody>
        <template v-for="order in orders.data" :key="order.id">
          <tr>
            <td v-for="column in visibleColumns" :key="column.key">
              <template v-if="column.key === 'order_number'"><Link v-if="order.order_url" :href="order.order_url" class="mvp-link">{{ order.erp_order_id ?? '—' }}</Link><span v-else>{{ order.erp_order_id ?? '—' }}</span><small v-if="order.external_order_number">来源订单：{{ order.external_order_number }}</small></template>
              <template v-else-if="column.key === 'type'">{{ order.order_kind_label }}</template>
              <template v-else-if="column.key === 'recipient'">{{ order.recipient_name ?? '—' }}<small>{{ order.phone ?? '—' }}</small></template>
              <template v-else-if="column.key === 'country'">{{ order.country ?? '—' }}</template>
              <template v-else-if="column.key === 'item_count'">{{ order.item_count }}</template>
              <template v-else-if="column.key === 'warehouse'">{{ localWarehouseNames[order.id] ?? order.shipment?.warehouse_name ?? '未选择' }}</template>
            </td>
            <td class="mvp-actions"><button class="mvp-button mvp-button-primary" type="button" @click="toggle(order.id)">{{ openOrderId === order.id ? '收起' : '履约处理' }}</button></td>
          </tr>
          <tr v-if="openOrderId === order.id"><td :colspan="visibleColumns.length + 1" class="fulfillment-cell">
            <div class="fulfillment-workspace">
              <div class="recommendation-card" :class="{ blocked: order.warehouse_recommendation.status !== 'recommended' }">
                <div><small>自动分配 · {{ order.automatic_allocation.status === 'allocated' ? '已完成' : order.automatic_allocation.status === 'ready' ? '可执行' : '暂阻塞' }}</small><strong>{{ order.automatic_allocation.warehouse?.name ?? order.warehouse_recommendation.warehouse?.name ?? '暂不推荐' }}</strong><p>{{ order.automatic_allocation.reason_label }}</p><p v-if="order.automatic_allocation.status === 'ready'" class="auto-preview-hint">当前为 Preview，不修改库存；自动模式启用后将创建 Reservation + Shipment Draft。</p><p v-else-if="order.automatic_allocation.status === 'allocated'" class="reservation-hint">已自动选择仓库并预留库存；确认发货时才扣减 QOH。</p><p v-else class="manual-warehouse-hint">自动分配未执行时，仍可人工选择任一启用仓库发货。</p><p class="recommendation-detail">系统仓库建议：{{ order.warehouse_recommendation.reason_label }}</p></div>
                <button v-if="order.warehouse_recommendation.status === 'recommended'" class="mvp-button mvp-button-primary" type="button" :disabled="['recommended', 'automatic'].includes(order.shipment?.warehouse_selection_source ?? '')" @click="adoptRecommendation(order)">{{ order.shipment?.warehouse_selection_source === 'automatic' ? '已自动分配并预留' : order.shipment?.warehouse_selection_source === 'recommended' ? '已采用推荐并预留' : '采用推荐并预留库存' }}</button>
                <span v-if="adoptError[order.id]" class="mvp-error" role="alert">{{ adoptError[order.id] }}</span>
              </div>
              <div><h3>库存可用量</h3><p v-if="['recommended', 'automatic'].includes(order.shipment?.warehouse_selection_source ?? '')" class="reservation-hint">库存已预留；确认发货时会自动提交预留并扣减在库库存。</p><div class="inventory-grid" v-for="item in order.items" :key="item.id"><strong><Link v-if="item.product_id" :href="`/products/${item.product_id}`" class="mvp-link">{{ item.sku ?? item.product_summary ?? '无 SKU' }}</Link><span v-else>{{ item.sku ?? item.product_summary ?? '无 SKU' }}</span> × {{ item.quantity }}</strong><span v-for="row in item.availability" :key="row.warehouse_id" :class="{ shortage: row.quantity_available !== null && row.quantity_available < item.quantity, unknown: row.quantity_available === null }">{{ row.warehouse_name }}：{{ row.quantity_available === null ? '库存未知' : '可用 ' + row.quantity_available }} <small v-if="row.quantity_available !== null">(QOH {{ row.quantity_on_hand }} / Reserved {{ row.quantity_reserved }})</small><small v-else>未建立该 SKU 权威库存</small></span></div></div>
              <div class="fulfillment-form">
                <label>发货仓库<select v-model="drafts[order.id].warehouse_id"><option value="">请选择</option><option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }} ({{ warehouse.code }}){{ warehouse.can_auto_allocate ? '' : ' · 库存未确认' }}</option></select></label>
                <label>物流商<select v-model="drafts[order.id].carrier" aria-label="物流商"><option value="">请选择承运商</option><option v-for="carrier in carrierOptions" :key="carrier.value" :value="carrier.value">{{ carrier.label }}</option></select></label>
                <label>物流渠道<input v-model="drafts[order.id].service" placeholder="例如 Ground"></label>
                <label>Tracking<input v-model="drafts[order.id].tracking_number" placeholder="追踪号"></label>
                <label>发货时间<input v-model="drafts[order.id].shipped_at" type="datetime-local"></label>
                <label class="wide">物流备注<textarea v-model="drafts[order.id].logistics_note" rows="2" /></label>
              </div>
              <div class="mvp-actions"><button class="mvp-button" @click="copy(order, 'recipient')">复制收件人</button><button class="mvp-button" @click="copy(order, 'full')">复制完整信息</button><button class="mvp-button" @click="copy(order, 'tracking')">复制追踪</button><button class="mvp-button" @click="saveDraft(order)">保存草稿</button><button class="mvp-button mvp-button-primary" @click="ship(order)">确认发货</button><span v-if="shipValidation[order.id]" class="mvp-error" role="alert">{{ shipValidation[order.id] }}</span><span v-if="copyState[order.id] === 'success'" class="mvp-success">已复制</span><span v-else-if="copyState[order.id] === 'error'" class="mvp-error">复制失败</span></div>
            </div>
          </td></tr>
        </template>
        <tr v-if="orders.data.length === 0"><td :colspan="visibleColumns.length + 1" class="mvp-empty">暂无待发货订单</td></tr>
      </tbody></table></div>
      <div v-if="orders.last_page > 1" class="mvp-pagination"><button class="mvp-button" :disabled="orders.current_page <= 1" @click="goToPage(orders.current_page - 1)">上一页</button><span>第 {{ orders.current_page }} / {{ orders.last_page }} 页 · 共 {{ orders.total }} 条</span><button class="mvp-button" :disabled="orders.current_page >= orders.last_page" @click="goToPage(orders.current_page + 1)">下一页</button></div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>

.logistics-grid-toolbar { display: flex; justify-content: flex-end; margin: -4px 0 8px; }
.column-settings-anchor { position: relative; }
.column-settings-trigger { width: 42px; height: 32px; border: 1px solid #DCE5E8; border-radius: 8px; background: #fff; color: #344B53; font-size: 22px; line-height: 1; cursor: pointer; }
.column-settings-trigger:hover { background: #F4F7F8; }
.column-settings-panel { position: absolute; top: 40px; right: 0; width: 300px; max-height: 520px; overflow-y: auto; padding: 12px; background: #fff; border: 1px solid #DCE5E8; border-radius: 10px; box-shadow: 0 12px 32px rgba(20, 33, 38, 0.14); text-align: left; z-index: 40; }
.column-settings-heading { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
.column-settings-help { margin: 8px 0; font-size: 11px; line-height: 1.45; color: #7A8C94; }
.column-reset-btn { border: 0; background: transparent; color: #2D8C84; font-size: 12px; cursor: pointer; }
.column-setting-row { display: grid; grid-template-columns: 22px 22px 1fr auto; align-items: center; gap: 6px; min-height: 34px; padding: 4px 5px; border-radius: 6px; font-size: 13px; cursor: grab; }
.column-setting-row:hover { background: #F4F7F8; }
.column-setting-row:active { cursor: grabbing; }
.column-setting-row small { color: #7A8C94; font-size: 10px; }
.column-drag-handle { color: #7A8C94; letter-spacing: -2px; user-select: none; }
.column-sync-state { margin: 6px 0; font-size: 11px; color: #2D8C84; }
.column-sync-state[data-state='error'] { color: #B42318; }
.recommendation-card { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 14px; border: 1px solid #b8ddca; border-radius: 8px; background: #eff9f3; }
.recommendation-card.blocked { border-color: #e4d7b5; background: #fff9eb; }
.recommendation-card div { display: grid; gap: 3px; }
.recommendation-card small { color: #627780; }
.recommendation-card strong { font-size: 14px; color: #173c2b; }
.recommendation-card p { margin: 0; font-size: 12px; color: #5f737c; }.recommendation-card .manual-warehouse-hint{margin-top:3px;font-weight:600;color:#7a5f18}.recommendation-card .auto-preview-hint{margin-top:3px;font-weight:600;color:#285d75}
.fulfillment-cell { background: #f8fafb; padding: 18px !important; }
.fulfillment-workspace { display: grid; gap: 18px; }
.fulfillment-workspace h3 { margin: 0 0 10px; font-size: 14px; }
.reservation-hint{margin:0 0 8px;color:#166534;font-size:12px;font-weight:600}.inventory-grid { display: grid; grid-template-columns: minmax(140px, 1fr) repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; padding: 8px 0; border-bottom: 1px solid #e4eaed; font-size: 13px; }
.inventory-grid span { padding: 6px 8px; border-radius: 6px; background: #eef7f2; }
.inventory-grid span.shortage { background: #fff1f2; }
.inventory-grid span.unknown { background: #fff9eb; }
.inventory-grid small { display: block; color: #6b7f87; }
.fulfillment-form { display: grid; grid-template-columns: repeat(3, minmax(180px, 1fr)); gap: 12px; }
.fulfillment-form label { display: grid; gap: 5px; font-size: 12px; color: #5f737c; }
.fulfillment-form input, .fulfillment-form select, .fulfillment-form textarea { width: 100%; border: 1px solid #cfdade; border-radius: 7px; padding: 8px 10px; background: #fff; color: #142126; }
.fulfillment-form .wide { grid-column: 1 / -1; }
@media (max-width: 900px) { .fulfillment-form { grid-template-columns: 1fr; } .inventory-grid { grid-template-columns: 1fr; } }
</style>
