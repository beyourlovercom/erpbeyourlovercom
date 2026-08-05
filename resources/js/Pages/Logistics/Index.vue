<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
type Order = { id: number; internal_order_number: string; recipient_name: string; phone: string; country: string; owner: string | null; item_count: number; shipment: { carrier: string | null; tracking_number: string | null; shipped_at: string | null; logistics_note: string | null } | null }
const props = defineProps<{ orders: Order[] }>()
const copied = ref('')
const copy = async (order: Order, mode: string) => {
  const response = await fetch(`/logistics/orders/${order.id}/payload`, { headers: { Accept: 'application/json' } })
  const payload = await response.json()
  const recipient = payload.recipient_name + '\n' + payload.phone + '\n' + [payload.country, payload.province_state, payload.city, payload.address_line_1, payload.address_line_2, payload.postal_code].filter(Boolean).join(', ')
  const full = `${payload.order_number}\n${recipient}\n${payload.items.map((item: { product_summary: string; sku: string | null; quantity: number }) => `${item.product_summary} (${item.sku ?? '无 SKU'}) × ${item.quantity}`).join('\n')}\n${payload.carrier ?? ''} ${payload.tracking_number ?? ''}`
  await navigator.clipboard.writeText(mode === 'recipient' ? recipient : mode === 'tracking' ? `${payload.carrier ?? ''} ${payload.tracking_number ?? ''}`.trim() : full)
  copied.value = order.internal_order_number
  window.setTimeout(() => { copied.value = '' }, 1800)
}
const ship = (order: Order) => {
  const carrier = window.prompt('承运商', order.shipment?.carrier ?? '')
  const tracking_number = window.prompt('追踪号', order.shipment?.tracking_number ?? '')
  if (!carrier || !tracking_number) return
  router.post(`/logistics/orders/${order.id}/ship`, { carrier, tracking_number, shipped_at: new Date().toISOString() })
}
</script>
<template>
  <Head title="物流 · BYL ERP" /><AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '待发货']"><section class="mvp-page"><div class="mvp-page-heading"><div><p class="mvp-kicker">Bounded Logistics</p><h2>待发货</h2><p>复制收件人和发货信息，记录承运商与追踪号。</p></div></div><div class="mvp-table-wrap"><table class="mvp-table"><thead><tr><th>订单号</th><th>收件人</th><th>国家</th><th>商品数</th><th>负责人</th><th>操作</th></tr></thead><tbody><tr v-for="order in props.orders" :key="order.id"><td>{{ order.internal_order_number }}</td><td>{{ order.recipient_name }}<small>{{ order.phone }}</small></td><td>{{ order.country }}</td><td>{{ order.item_count }}</td><td>{{ order.owner ?? '—' }}</td><td class="mvp-actions"><button class="mvp-button" @click="copy(order, 'recipient')">复制收件人</button><button class="mvp-button" @click="copy(order, 'full')">复制完整信息</button><button class="mvp-button" @click="copy(order, 'tracking')">复制追踪</button><button class="mvp-button mvp-button-primary" @click="ship(order)">标记发货</button><span v-if="copied === order.internal_order_number" class="mvp-success">已复制</span></td></tr><tr v-if="props.orders.length === 0"><td colspan="6" class="mvp-empty">暂无待发货订单</td></tr></tbody></table></div></section></AuthenticatedLayout>
</template>
