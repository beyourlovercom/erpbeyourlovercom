<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { reactive, ref, watch } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Availability = { warehouse_id: number; warehouse_slug: string; warehouse_name: string; quantity_on_hand: number; quantity_reserved: number; quantity_available: number }
type Item = { id: number; product_id: number | null; sku: string | null; product_summary: string | null; quantity: number; availability: Availability[] }
type Shipment = { warehouse_id: number | null; warehouse_name: string | null; carrier: string | null; service: string | null; tracking_number: string | null; shipped_at: string | null; logistics_note: string | null }
type Order = { id: number; external_order_number: string; order_kind_label: string; recipient_name: string | null; phone: string | null; country: string | null; owner_label: string | null; item_count: number; items: Item[]; shipment: Shipment | null }
type Warehouse = { id: number; slug: string; name: string; code: string; country_code: string | null }
type Paginated<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number }
type Draft = { warehouse_id: number | ''; carrier: string; service: string; tracking_number: string; shipped_at: string; logistics_note: string }

const props = defineProps<{ orders: Paginated<Order>; warehouses: Warehouse[] }>()
const drafts = reactive<Record<number, Draft>>({})
const openOrderId = ref<number | null>(null)
const copyState = ref<Record<number, 'success' | 'error'>>({})

const localDateTime = (value: string | null) => value ? value.slice(0, 16) : new Date().toISOString().slice(0, 16)
const makeDraft = (order: Order): Draft => ({
  warehouse_id: order.shipment?.warehouse_id ?? '',
  carrier: order.shipment?.carrier ?? '',
  service: order.shipment?.service ?? '',
  tracking_number: order.shipment?.tracking_number ?? '',
  shipped_at: localDateTime(order.shipment?.shipped_at ?? null),
  logistics_note: order.shipment?.logistics_note ?? '',
})
watch(() => props.orders.data, (orders) => { for (const order of orders) if (!drafts[order.id]) drafts[order.id] = makeDraft(order) }, { immediate: true })

const toggle = (orderId: number) => { openOrderId.value = openOrderId.value === orderId ? null : orderId }
const saveDraft = (order: Order) => router.post(`/logistics/orders/${order.id}/draft`, drafts[order.id], { preserveScroll: true })
const ship = (order: Order) => {
  const draft = drafts[order.id]
  if (!draft.warehouse_id || !draft.carrier.trim() || !draft.service.trim() || !draft.tracking_number.trim()) return
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
      <div class="mvp-page-heading"><div><p class="mvp-kicker">Manual Fulfillment</p><h2>待发货</h2><p>查看各仓库存，人工选择发货仓库与物流，并保存履约记录。</p></div></div>
      <div class="mvp-table-wrap"><table class="mvp-table"><thead><tr><th>订单号</th><th>类型</th><th>收件人</th><th>国家</th><th>商品数</th><th>仓库</th><th>操作</th></tr></thead><tbody>
        <template v-for="order in orders.data" :key="order.id">
          <tr><td>{{ order.external_order_number }}</td><td>{{ order.order_kind_label }}</td><td>{{ order.recipient_name ?? '—' }}<small>{{ order.phone ?? '—' }}</small></td><td>{{ order.country ?? '—' }}</td><td>{{ order.item_count }}</td><td>{{ order.shipment?.warehouse_name ?? '未选择' }}</td><td class="mvp-actions"><button class="mvp-button mvp-button-primary" type="button" @click="toggle(order.id)">{{ openOrderId === order.id ? '收起' : '履约处理' }}</button></td></tr>
          <tr v-if="openOrderId === order.id"><td colspan="7" class="fulfillment-cell">
            <div class="fulfillment-workspace">
              <div><h3>库存可用量</h3><div class="inventory-grid" v-for="item in order.items" :key="item.id"><strong>{{ item.sku ?? '无 SKU' }} × {{ item.quantity }}</strong><span v-for="row in item.availability" :key="row.warehouse_id" :class="{ shortage: row.quantity_available < item.quantity }">{{ row.warehouse_name }}：可用 {{ row.quantity_available }} <small>(QOH {{ row.quantity_on_hand }} / Reserved {{ row.quantity_reserved }})</small></span></div></div>
              <div class="fulfillment-form">
                <label>发货仓库<select v-model="drafts[order.id].warehouse_id"><option value="">请选择</option><option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }} ({{ warehouse.code }})</option></select></label>
                <label>物流商<input v-model="drafts[order.id].carrier" placeholder="例如 UPS"></label>
                <label>物流渠道<input v-model="drafts[order.id].service" placeholder="例如 Ground"></label>
                <label>Tracking<input v-model="drafts[order.id].tracking_number" placeholder="追踪号"></label>
                <label>发货时间<input v-model="drafts[order.id].shipped_at" type="datetime-local"></label>
                <label class="wide">物流备注<textarea v-model="drafts[order.id].logistics_note" rows="2" /></label>
              </div>
              <div class="mvp-actions"><button class="mvp-button" @click="copy(order, 'recipient')">复制收件人</button><button class="mvp-button" @click="copy(order, 'full')">复制完整信息</button><button class="mvp-button" @click="copy(order, 'tracking')">复制追踪</button><button class="mvp-button" @click="saveDraft(order)">保存草稿</button><button class="mvp-button mvp-button-primary" :disabled="!drafts[order.id].warehouse_id || !drafts[order.id].carrier || !drafts[order.id].service || !drafts[order.id].tracking_number" @click="ship(order)">确认发货</button><span v-if="copyState[order.id] === 'success'" class="mvp-success">已复制</span><span v-else-if="copyState[order.id] === 'error'" class="mvp-error">复制失败</span></div>
            </div>
          </td></tr>
        </template>
        <tr v-if="orders.data.length === 0"><td colspan="7" class="mvp-empty">暂无待发货订单</td></tr>
      </tbody></table></div>
      <div v-if="orders.last_page > 1" class="mvp-pagination"><button class="mvp-button" :disabled="orders.current_page <= 1" @click="goToPage(orders.current_page - 1)">上一页</button><span>第 {{ orders.current_page }} / {{ orders.last_page }} 页 · 共 {{ orders.total }} 条</span><button class="mvp-button" :disabled="orders.current_page >= orders.last_page" @click="goToPage(orders.current_page + 1)">下一页</button></div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.fulfillment-cell { background: #f8fafb; padding: 18px !important; }
.fulfillment-workspace { display: grid; gap: 18px; }
.fulfillment-workspace h3 { margin: 0 0 10px; font-size: 14px; }
.inventory-grid { display: grid; grid-template-columns: minmax(140px, 1fr) repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; padding: 8px 0; border-bottom: 1px solid #e4eaed; font-size: 13px; }
.inventory-grid span { padding: 6px 8px; border-radius: 6px; background: #eef7f2; }
.inventory-grid span.shortage { background: #fff1f2; }
.inventory-grid small { display: block; color: #6b7f87; }
.fulfillment-form { display: grid; grid-template-columns: repeat(3, minmax(180px, 1fr)); gap: 12px; }
.fulfillment-form label { display: grid; gap: 5px; font-size: 12px; color: #5f737c; }
.fulfillment-form input, .fulfillment-form select, .fulfillment-form textarea { width: 100%; border: 1px solid #cfdade; border-radius: 7px; padding: 8px 10px; background: #fff; color: #142126; }
.fulfillment-form .wide { grid-column: 1 / -1; }
@media (max-width: 900px) { .fulfillment-form { grid-template-columns: 1fr; } .inventory-grid { grid-template-columns: 1fr; } }
</style>
