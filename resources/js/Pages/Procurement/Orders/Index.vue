<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type ProcurementRow = {
  id: number
  procurement_number: string
  status: string
  source_order_id: number
  source_order_number: string
  created_at: string | null
}

defineProps<{ orders: ProcurementRow[] }>()
const formatDate = (value: string | null) => value ? new Date(value).toLocaleString('zh-CN') : '—'
</script>

<template>
  <Head title="采购订单 · BYL ERP" />
  <AuthenticatedLayout title="采购" :breadcrumbs="[{ label: '采购', href: '/purchase' }]">
    <section class="mvp-page procurement-index">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">Purchase OS</p>
          <h2>采购订单</h2>
          <p>这里只展示由真实缺货订单创建的采购记录；新采购单必须从源订单进入创建流程。</p>
        </div>
      </div>

      <div class="mvp-card purchase-table-wrap desktop-list">
        <table class="mvp-table">
          <thead><tr><th>采购单号</th><th>源订单</th><th>状态</th><th>创建时间</th><th></th></tr></thead>
          <tbody>
            <tr v-for="order in orders" :key="order.id">
              <td><strong>{{ order.procurement_number }}</strong></td>
              <td><Link :href="`/customers/orders/${order.source_order_id}`">{{ order.source_order_number }}</Link></td>
              <td><span class="mvp-status">{{ order.status }}</span></td>
              <td>{{ formatDate(order.created_at) }}</td>
              <td><Link :href="`/purchase/orders/${order.id}`">查看</Link></td>
            </tr>
            <tr v-if="orders.length === 0"><td colspan="5" class="mvp-empty">暂无采购订单</td></tr>
          </tbody>
        </table>
      </div>

      <div class="mobile-list">
        <article v-for="order in orders" :key="order.id" class="mvp-card purchase-card">
          <div class="purchase-card-head"><strong>{{ order.procurement_number }}</strong><span class="mvp-status">{{ order.status }}</span></div>
          <dl><dt>源订单</dt><dd><Link :href="`/customers/orders/${order.source_order_id}`">{{ order.source_order_number }}</Link></dd><dt>创建时间</dt><dd>{{ formatDate(order.created_at) }}</dd></dl>
          <Link class="erp-btn erp-btn-secondary" :href="`/purchase/orders/${order.id}`">查看采购订单</Link>
        </article>
        <div v-if="orders.length === 0" class="mvp-card mvp-empty">暂无采购订单</div>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.purchase-table-wrap{overflow:auto}.purchase-table-wrap table{width:100%;border-collapse:collapse}.purchase-table-wrap th,.purchase-table-wrap td{padding:12px;border-bottom:1px solid #e5e7eb;text-align:left;white-space:nowrap}.purchase-table-wrap th{font-size:12px;color:#64748b}.mobile-list{display:none}.purchase-card{padding:14px;display:grid;gap:12px}.purchase-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.purchase-card dl{display:grid;grid-template-columns:86px 1fr;gap:8px;margin:0}.purchase-card dt{color:#7a8c94}.purchase-card dd{margin:0;min-width:0;overflow-wrap:anywhere}@media(max-width:767px){.desktop-list{display:none}.mobile-list{display:grid;gap:10px}.purchase-card .erp-btn{width:100%}}
</style>
