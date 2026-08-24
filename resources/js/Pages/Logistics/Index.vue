<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
type Order = { id: number; external_order_number: string; internal_order_number: string; order_kind_label: string; recipient_name: string; phone: string; country: string; owner: string | null; item_count: number; shipment: { carrier: string | null; tracking_number: string | null; shipped_at: string | null; logistics_note: string | null } | null }
type Paginated<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number }
const props = defineProps<{ orders: Paginated<Order> }>()
const copyState = ref<Record<number, 'success' | 'error'>>({})

function copyToClipboardFallback(text: string): boolean {
  const textarea = document.createElement('textarea')
  textarea.value = text
  textarea.setAttribute('readonly', '')
  textarea.style.position = 'fixed'
  textarea.style.left = '-9999px'
  document.body.appendChild(textarea)
  textarea.select()
  let succeeded = false
  try {
    succeeded = document.execCommand('copy')
  } catch {
    succeeded = false
  }
  document.body.removeChild(textarea)
  return succeeded
}

async function writeClipboard(text: string): Promise<boolean> {
  if (window.isSecureContext && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
    try {
      await navigator.clipboard.writeText(text)
      return true
    } catch {
      // fall through to the execCommand fallback below
    }
  }
  return copyToClipboardFallback(text)
}

const copy = async (order: Order, mode: string) => {
  delete copyState.value[order.id]
  const response = await fetch(`/logistics/orders/${order.id}/payload`, { headers: { Accept: 'application/json' } })
  if (!response.ok) {
    copyState.value[order.id] = 'error'
    return
  }
  const payload = await response.json()
  const recipient = payload.recipient_name + '\n' + payload.phone + '\n' + [payload.country, payload.province_state, payload.city, payload.address_line_1, payload.address_line_2, payload.postal_code].filter(Boolean).join(', ')
  const full = `${payload.order_number}\n${recipient}\n${payload.items.map((item: { product_summary: string; sku: string | null; quantity: number }) => `${item.product_summary} (${item.sku ?? '无 SKU'}) × ${item.quantity}`).join('\n')}\n${payload.carrier ?? ''} ${payload.tracking_number ?? ''}`
  const text = mode === 'recipient' ? recipient : mode === 'tracking' ? `${payload.carrier ?? ''} ${payload.tracking_number ?? ''}`.trim() : full
  const succeeded = await writeClipboard(text)
  copyState.value[order.id] = succeeded ? 'success' : 'error'
  window.setTimeout(() => { delete copyState.value[order.id] }, 2500)
}
const ship = (order: Order) => {
  const carrier = window.prompt('承运商', order.shipment?.carrier ?? '')
  const tracking_number = window.prompt('追踪号', order.shipment?.tracking_number ?? '')
  if (!carrier || !tracking_number) return
  router.post(`/logistics/orders/${order.id}/ship`, { carrier, tracking_number, shipped_at: new Date().toISOString() })
}
const goToPage = (page: number) => router.get('/logistics/pending', { page }, { preserveState: true, replace: true })
</script>
<template>
  <Head title="物流 · BYL ERP" /><AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '待发货']"><section class="mvp-page"><div class="mvp-page-heading"><div><p class="mvp-kicker">Bounded Logistics</p><h2>待发货</h2><p>复制收件人和发货信息，记录承运商与追踪号。</p></div></div><div class="mvp-table-wrap"><table class="mvp-table"><thead><tr><th>订单号</th><th>类型</th><th>收件人</th><th>国家</th><th>商品数</th><th>负责人</th><th>操作</th></tr></thead><tbody><tr v-for="order in orders.data" :key="order.id"><td>{{ order.external_order_number }}</td><td>{{ order.order_kind_label }}</td><td>{{ order.recipient_name }}<small>{{ order.phone }}</small></td><td>{{ order.country }}</td><td>{{ order.item_count }}</td><td>{{ order.owner ?? '—' }}</td><td class="mvp-actions"><button class="mvp-button" @click="copy(order, 'recipient')">复制收件人</button><button class="mvp-button" @click="copy(order, 'full')">复制完整信息</button><button class="mvp-button" @click="copy(order, 'tracking')">复制追踪</button><button class="mvp-button mvp-button-primary" @click="ship(order)">标记发货</button><span v-if="copyState[order.id] === 'success'" class="mvp-success">已复制</span><span v-else-if="copyState[order.id] === 'error'" class="mvp-error">复制失败，请手动选择文本复制</span></td></tr><tr v-if="orders.data.length === 0"><td colspan="7" class="mvp-empty">暂无待发货订单</td></tr></tbody></table></div>
  <div v-if="orders.last_page > 1" class="mvp-pagination"><button class="mvp-button" type="button" :disabled="orders.current_page <= 1" @click="goToPage(orders.current_page - 1)">上一页</button><span>第 {{ orders.current_page }} / {{ orders.last_page }} 页 · 共 {{ orders.total }} 条</span><button class="mvp-button" type="button" :disabled="orders.current_page >= orders.last_page" @click="goToPage(orders.current_page + 1)">下一页</button></div>
  </section></AuthenticatedLayout>
</template>
