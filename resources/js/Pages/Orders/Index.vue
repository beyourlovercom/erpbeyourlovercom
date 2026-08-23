<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Order = { id: number; internal_order_number: string; source_label: string; order_date: string; order_status_label: string; address?: { recipient_name: string; country: string }; owner?: { name: string } }
const props = defineProps<{ orders: Order[]; filters: Record<string, string>; sourceTypes: { value: string; label: string }[] }>()
const filters = reactive({ search: props.filters.search ?? '', source_type: props.filters.source_type ?? '', order_status: props.filters.order_status ?? '' })
const exportUrl = () => `/customers/orders/export?${new globalThis.URLSearchParams(filters).toString()}`
const submit = () => router.get('/customers/orders', filters, { preserveState: true, replace: true })
</script>
<template>
  <Head title="订单 · BYL ERP" />
  <AuthenticatedLayout title="订单" :breadcrumbs="['BYL ERP', '客户', '订单']">
    <section class="mvp-page page-content">
      <div class="mvp-page-heading page-header"><div><p class="mvp-kicker">订单核心</p><h2>订单</h2><p>统一维护 Website、KOL 和 Manual Other 订单。</p></div><Link class="mvp-button mvp-button-primary" href="/customers/orders/create">新建订单</Link></div>
      <form class="mvp-toolbar page-toolbar" @submit.prevent="submit"><input v-model="filters.search" aria-label="搜索订单" placeholder="搜索订单号、外部单号或收件人"><select v-model="filters.source_type" aria-label="来源"><option value="">全部来源</option><option v-for="source in sourceTypes" :key="source.value" :value="source.value">{{ source.label }}</option></select><select v-model="filters.order_status" aria-label="状态"><option value="">全部状态</option><option value="pending_shipment">待发货</option><option value="shipped">已发货</option><option value="cancelled">已取消</option></select><button class="mvp-button" type="submit">筛选</button><a class="mvp-button" :href="exportUrl()">导出 CSV</a></form>
      <div class="mvp-table-wrap"><table class="mvp-table"><thead><tr><th>订单号</th><th>来源</th><th>订单日期</th><th>收件人</th><th>国家</th><th>状态</th><th>负责人</th><th></th></tr></thead><tbody><tr v-for="order in orders" :key="order.id"><td><Link :href="`/customers/orders/${order.id}`">{{ order.internal_order_number }}</Link></td><td>{{ order.source_label }}</td><td>{{ order.order_date }}</td><td>{{ order.address?.recipient_name }}</td><td>{{ order.address?.country }}</td><td><span class="mvp-status">{{ order.order_status_label }}</span></td><td>{{ order.owner?.name ?? '—' }}</td><td><Link :href="`/customers/orders/${order.id}`">查看</Link></td></tr><tr v-if="orders.length === 0"><td colspan="8" class="mvp-empty">暂无符合条件的订单</td></tr></tbody></table></div>
    </section>
  </AuthenticatedLayout>
</template>
