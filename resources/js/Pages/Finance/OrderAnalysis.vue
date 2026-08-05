<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
type Row = { id: number; internal_order_number: string; source_type: string; recipient_name: string | null; summary: { completeness: string; profit_status: string; net_revenue: string | null; total_cost: string | null; contribution_profit: string | null; margin: number | null } }
const props = defineProps<{ orders: Row[]; fields: string[] }>()
const exportCsv = () => { window.location.href = `/accounting/order-costs/export?${new URLSearchParams(window.location.search)}` }
const edit = (row: Row) => { const amount = window.prompt('Amount Paid', row.summary.net_revenue ?? ''); if (amount === null) return; router.put(`/accounting/order-costs/${row.id}`, { amount_paid: amount, refund_amount: '0.00' }) }
</script>
<template>
  <Head title="订单分析 · BYL ERP" /><AuthenticatedLayout title="订单分析" :breadcrumbs="['BYL ERP', '会计', '每一单成本表']"><section class="mvp-page"><div class="mvp-page-heading"><div><p class="mvp-kicker">Finance / Order Analysis</p><h2>订单分析</h2><p>成本不完整时不显示 confirmed profit。</p></div><button class="mvp-button" @click="exportCsv">导出 CSV</button></div><div class="mvp-table-wrap"><table class="mvp-table"><thead><tr><th>订单号</th><th>来源</th><th>收件人</th><th>净收入</th><th>总成本</th><th>贡献利润</th><th>Margin</th><th>完整性</th><th>操作</th></tr></thead><tbody><tr v-for="row in props.orders" :key="row.id"><td>{{ row.internal_order_number }}</td><td>{{ row.source_type }}</td><td>{{ row.recipient_name }}</td><td>{{ row.summary.net_revenue ?? '—' }}</td><td>{{ row.summary.total_cost ?? '—' }}</td><td>{{ row.summary.contribution_profit ?? '未确认' }}</td><td>{{ row.summary.margin ?? '—' }}</td><td>{{ row.summary.completeness }}</td><td><button class="mvp-button" @click="edit(row)">录入收入</button></td></tr><tr v-if="props.orders.length === 0"><td colspan="9" class="mvp-empty">暂无订单分析数据</td></tr></tbody></table></div></section></AuthenticatedLayout>
</template>
