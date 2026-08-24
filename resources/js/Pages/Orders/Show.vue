<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
type Order = { id: number; internal_order_number: string; external_order_number: string; source_label: string; order_kind: string | null; order_kind_label: string | null; order_date: string; order_status_label: string; order_note: string | null; owner?: { name: string }; address: Record<string, string>; items: { product_summary: string; sku: string | null; quantity: number; unit_price: string }[]; shipment: { shipment_status: string; carrier: string | null; tracking_number: string | null; shipped_at: string | null } | null; manual_sync: { status: string } | null }
const props = defineProps<{ order: Order; audit: { action: string; actor_id: number | null; created_at: string }[] }>()
const form = useForm({ order_note: props.order.order_note ?? '', recipient_name: props.order.address?.recipient_name ?? '', phone: props.order.address?.phone ?? '', country: props.order.address?.country ?? '', province_state: props.order.address?.province_state ?? '', city: props.order.address?.city ?? '', address_line_1: props.order.address?.address_line_1 ?? '', address_line_2: props.order.address?.address_line_2 ?? '', postal_code: props.order.address?.postal_code ?? '' })
const basePath = props.order.order_kind === 'kol_fulfillment' ? '/kol/orders' : '/customers/orders'
const submit = () => form.patch(`${basePath}/${props.order.id}`)
</script>
<template>
  <Head :title="`${order.external_order_number} · BYL ERP`" /><AuthenticatedLayout title="订单详情" :breadcrumbs="['BYL ERP', '客户', '订单', order.external_order_number]"><section class="mvp-page"><div class="mvp-page-heading"><div><p class="mvp-kicker">订单详情</p><h2>{{ order.external_order_number }}</h2><p>{{ order.order_kind_label }} · {{ order.source_label }} · {{ order.order_date }} · <span class="mvp-status">{{ order.order_status_label }}</span></p><p class="mvp-muted">ERP编号：{{ order.internal_order_number }}</p></div><a class="mvp-button" :href="basePath">返回列表</a></div>

    <article class="mvp-card mvp-card-wide mvp-fulfillment-summary">
      <h3>履约工作台</h3>
      <div class="mvp-fulfillment-grid">
        <div><small>发货状态</small><strong>{{ order.shipment?.shipment_status === 'shipped' ? '已发货' : '待发货' }}</strong></div>
        <div><small>承运商</small><strong>{{ order.shipment?.carrier ?? '—' }}</strong></div>
        <div><small>追踪号</small><strong>{{ order.shipment?.tracking_number ?? '—' }}</strong></div>
        <div><small>网站同步</small><strong>{{ order.manual_sync?.status ?? '未进入队列' }}</strong></div>
      </div>
      <button v-if="order.shipment?.tracking_number" class="mvp-button" type="button" @click="router.post(`/logistics/orders/${order.id}/reopen`)">重开发货</button>
    </article>

    <div class="mvp-detail-grid"><article class="mvp-card"><h3>收件人地址快照</h3><form class="mvp-form-grid" @submit.prevent="submit"><label>姓名<input v-model="form.recipient_name"></label><label>电话<input v-model="form.phone"></label><label>国家<input v-model="form.country"></label><label>省/州<input v-model="form.province_state"></label><label>城市<input v-model="form.city"></label><label>邮编<input v-model="form.postal_code"></label><label class="mvp-form-wide">地址<input v-model="form.address_line_1"></label><label class="mvp-form-wide">补充<input v-model="form.address_line_2"></label><label class="mvp-form-wide">备注<textarea v-model="form.order_note"></textarea></label><button class="mvp-button mvp-button-primary">保存修正</button></form></article>
    <article class="mvp-card"><h3>商品</h3><ul class="mvp-list"><li v-for="item in order.items" :key="item.product_summary + item.sku"><span>{{ item.product_summary }}<small>{{ item.sku ?? '无 SKU' }}</small></span><strong>{{ item.quantity }} × {{ item.unit_price }}</strong></li></ul></article></div>
    <article class="mvp-card mvp-card-wide"><h3>操作审计</h3><ul class="mvp-list"><li v-for="event in audit" :key="event.created_at + event.action"><span>{{ event.action }}</span><small>{{ event.created_at }} · actor {{ event.actor_id ?? 'system' }}</small></li></ul></article>
  </section></AuthenticatedLayout>
</template>
