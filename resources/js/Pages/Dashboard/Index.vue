<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type ProcurementRow = { id:number; procurement_number:string; status:string; source_order_number:string|null; carrier:string|null; tracking_number:string|null; updated_at:string|null }
type ProcurementProgress = { active:number; counts:Record<'pending'|'purchasing'|'ordered'|'completed'|'cancelled', number>; recent:ProcurementRow[] }
defineProps<{ metrics: Record<'pending_orders'|'pending_shipment'|'shipped_pending_sync'|'completed_today', number>; procurementProgress?: ProcurementProgress|null }>()
const statusMeta = [
  { value:'pending', label:'待采购' }, { value:'purchasing', label:'采购中' }, { value:'ordered', label:'已下单' },
  { value:'completed', label:'已完成' }, { value:'cancelled', label:'已取消' },
] as const
const statusLabel = (value:string) => statusMeta.find(item => item.value === value)?.label || value
</script>
<template>
  <Head title="仪表盘 · BYL ERP" />
  <AuthenticatedLayout title="仪表盘" :breadcrumbs="['BYL ERP', '仪表盘']">
    <section class="mvp-page">
      <div class="mvp-page-heading"><div><p class="mvp-kicker">业务工作台</p><h2>BYL ERP 应用工作台</h2><p>订单、发货和人工同步状态集中在这里。</p></div></div>
      <div class="mvp-stat-grid">
        <a class="mvp-stat-card" href="/customers/orders?order_status=pending_shipment"><span>待处理订单</span><strong>{{ metrics.pending_orders }}</strong></a>
        <a class="mvp-stat-card" href="/logistics/pending"><span>待发货</span><strong>{{ metrics.pending_shipment }}</strong></a>
        <a class="mvp-stat-card" href="/sync?status=pending"><span>已发货待同步网站</span><strong>{{ metrics.shipped_pending_sync }}</strong></a>
        <a class="mvp-stat-card" href="/sync?status=completed"><span>今日已完成</span><strong>{{ metrics.completed_today }}</strong></a>
      </div>

      <section v-if="procurementProgress" class="mvp-card procurement-progress">
        <div class="procurement-heading"><div><p class="mvp-kicker">PURCHASE OS</p><h3>采购进度</h3><p>查看当前采购状态和最近采购订单。</p></div><a class="mvp-button" href="/purchase">查看全部采购</a></div>
        <div class="mvp-stat-grid procurement-stat-grid">
          <a class="mvp-stat-card" href="/purchase"><span>活跃采购</span><strong>{{ procurementProgress.active }}</strong></a>
          <a v-for="item in statusMeta" :key="item.value" class="mvp-stat-card" :href="`/purchase?status=${item.value}`"><span>{{ item.label }}</span><strong>{{ procurementProgress.counts[item.value] }}</strong></a>
        </div>
        <div class="procurement-recent">
          <a v-for="row in procurementProgress.recent" :key="row.id" class="procurement-row" :href="`/purchase/orders/${row.id}`">
            <div class="procurement-id"><strong>{{ row.procurement_number }}</strong><span>源订单 {{ row.source_order_number || '—' }}</span></div>
            <span class="procurement-status">{{ statusLabel(row.status) }}</span>
            <span class="procurement-logistics">{{ row.carrier || '暂无物流' }}<template v-if="row.tracking_number"> · {{ row.tracking_number }}</template></span>
          </a>
          <p v-if="procurementProgress.recent.length === 0" class="procurement-empty">暂无采购订单。</p>
        </div>
      </section>
    </section>
  </AuthenticatedLayout>
</template>
<style scoped>
.procurement-progress{margin-top:24px}.procurement-heading{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}.procurement-heading h3{margin:4px 0 6px;font-size:22px}.procurement-heading p{margin:0}.procurement-stat-grid{margin-bottom:18px}.procurement-recent{display:grid;gap:10px}.procurement-row{display:grid;grid-template-columns:minmax(180px,1.2fr) 100px minmax(180px,1fr);gap:14px;align-items:center;padding:14px 16px;border:1px solid var(--erp-border,#e5e7eb);border-radius:12px;text-decoration:none;color:inherit}.procurement-id{display:grid;gap:4px}.procurement-id span,.procurement-logistics{font-size:13px;color:#667085}.procurement-status{font-weight:700}.procurement-empty{margin:0;color:#667085}@media(max-width:640px){.procurement-heading{display:grid}.procurement-row{grid-template-columns:1fr;gap:6px}.procurement-status{justify-self:start}}
</style>
