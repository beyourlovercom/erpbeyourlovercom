<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type ProcurementRow = {
  id: number
  procurement_number: string
  status: string
  status_label: string
  source_order_id: number
  source_order_number: string
  item_summary: string
  item_count: number
  purchase_quantity: number
  carrier: string | null
  tracking_number: string | null
  updated_at: string | null
}
type StatusOption = { value: string; label: string }
type Filters = { search?: string; status?: string }

const props = defineProps<{
  orders: ProcurementRow[]
  filters: Filters
  statusOptions: StatusOption[]
  canOperate: boolean
}>()
const search = ref(props.filters.search || '')
const status = ref(props.filters.status || '')

const apply = () => router.get('/purchase', {
  search: search.value || undefined,
  status: status.value || undefined,
}, { preserveState: true, replace: true })
const clear = () => {
  search.value = ''
  status.value = ''
  apply()
}
const formatDate = (value: string | null) => value ? new Date(value).toLocaleString('zh-CN') : '—'
const logistics = (order: ProcurementRow) => {
  if (!order.carrier && !order.tracking_number) return '—'
  return [order.carrier, order.tracking_number].filter(Boolean).join(' · ')
}
</script>

<template>
  <Head title="采购订单 · BYL ERP" />
  <AuthenticatedLayout title="采购" :breadcrumbs="[{ label: '采购', href: '/purchase' }]">
    <section class="mvp-page procurement-index">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">Purchase OS</p>
          <h2>采购订单</h2>
          <p>跟进真实缺货订单的采购状态与采购物流。</p>
        </div>
      </div>

      <form class="mvp-card purchase-filters" @submit.prevent="apply">
        <label>
          <span>搜索</span>
          <input v-model="search" class="erp-input" placeholder="采购单 / 订单 / SKU / 商品" />
        </label>
        <label>
          <span>状态</span>
          <select v-model="status" class="erp-input">
            <option value="">全部状态</option>
            <option v-for="option in props.statusOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </label>
        <div class="filter-actions">
          <button class="erp-btn erp-btn-primary" type="submit">筛选</button>
          <button class="erp-btn erp-btn-secondary" type="button" @click="clear">清除</button>
        </div>
      </form>

      <div class="mvp-card purchase-table-wrap desktop-list">
        <table class="mvp-table">
          <thead>
            <tr><th>采购单</th><th>源订单</th><th>商品</th><th>数量</th><th>状态</th><th>采购物流</th><th>更新</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="order in props.orders" :key="order.id">
              <td><strong>{{ order.procurement_number }}</strong></td>
              <td><Link :href="`/customers/orders/${order.source_order_id}`">{{ order.source_order_number }}</Link></td>
              <td>{{ order.item_summary || '—' }}<span v-if="order.item_count > 2"> 等 {{ order.item_count }} 项</span></td>
              <td>{{ order.purchase_quantity }}</td>
              <td><span class="mvp-status">{{ order.status_label }}</span></td>
              <td class="logistics-cell">{{ logistics(order) }}</td>
              <td>{{ formatDate(order.updated_at) }}</td>
              <td><Link :href="`/purchase/orders/${order.id}`">查看</Link></td>
            </tr>
            <tr v-if="props.orders.length === 0"><td colspan="8" class="mvp-empty">暂无匹配的采购订单</td></tr>
          </tbody>
        </table>
      </div>

      <div class="mobile-list">
        <article v-for="order in props.orders" :key="order.id" class="mvp-card purchase-card">
          <div class="purchase-card-head">
            <strong>{{ order.procurement_number }}</strong>
            <span class="mvp-status">{{ order.status_label }}</span>
          </div>
          <dl>
            <dt>源订单</dt><dd><Link :href="`/customers/orders/${order.source_order_id}`">{{ order.source_order_number }}</Link></dd>
            <dt>商品</dt><dd>{{ order.item_summary || '—' }}<span v-if="order.item_count > 2"> 等 {{ order.item_count }} 项</span></dd>
            <dt>采购数量</dt><dd>{{ order.purchase_quantity }}</dd>
            <dt>采购物流</dt><dd>{{ logistics(order) }}</dd>
            <dt>更新时间</dt><dd>{{ formatDate(order.updated_at) }}</dd>
          </dl>
          <Link class="erp-btn erp-btn-secondary" :href="`/purchase/orders/${order.id}`">查看采购订单</Link>
        </article>
        <div v-if="props.orders.length === 0" class="mvp-card mvp-empty">暂无匹配的采购订单</div>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.purchase-filters{display:grid;grid-template-columns:minmax(220px,1fr) 200px auto;gap:12px;align-items:end;padding:14px;margin-bottom:14px}.purchase-filters label{display:grid;gap:6px;font-size:12px;color:#64748b}.filter-actions{display:flex;gap:8px}.purchase-table-wrap{overflow:auto}.purchase-table-wrap table{width:100%;border-collapse:collapse}.purchase-table-wrap th,.purchase-table-wrap td{padding:12px;border-bottom:1px solid #e5e7eb;text-align:left;white-space:nowrap}.purchase-table-wrap th{font-size:12px;color:#64748b}.logistics-cell{max-width:220px;overflow:hidden;text-overflow:ellipsis}.mobile-list{display:none}.purchase-card{padding:14px;display:grid;gap:12px}.purchase-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.purchase-card dl{display:grid;grid-template-columns:86px 1fr;gap:8px;margin:0}.purchase-card dt{color:#7a8c94}.purchase-card dd{margin:0;min-width:0;overflow-wrap:anywhere}
@media(max-width:767px){.purchase-filters{grid-template-columns:1fr}.filter-actions{display:grid;grid-template-columns:1fr 1fr}.desktop-list{display:none}.mobile-list{display:grid;gap:10px}.purchase-card .erp-btn{width:100%}}
</style>
