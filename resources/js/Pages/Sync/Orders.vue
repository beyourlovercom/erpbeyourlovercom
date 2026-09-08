<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../Composables/useAccountGridPreferences'

type Order = {
  id: number
  erp_order_id?: string | null
  recipient_name: string | null
  status: string
  synced_at: string | null
  tracking_number: string | null
  shipment_status: string | null
  carrier: string | null
  source_type: string
}

const props = defineProps<{
  orders: Order[]
  filters: { status?: string; search?: string }
}>()

const filters = reactive({ status: props.filters.status ?? '', search: props.filters.search ?? '' })
const submit = () => router.get('/sync/orders', filters, { preserveState: true, replace: true })
const approve = (id: number) => router.post(`/sync/orders/${id}/approve`, {})
const syncWebsite = (id: number) => router.post(`/sync/orders/${id}/tracking-outbound`)

const syncStatusLabel = (status: string) => ({
  needs_confirmation: '待审核',
  pending: '已审核 · 待同步',
  completed: '已同步',
  returned: '待物流修改',
  failed: '同步失败',
}[status] ?? status)

const canSync = (order: Order) =>
  order.status === 'pending'
  && order.source_type === 'woocommerce'
  && order.shipment_status === 'shipped'
  && !!order.tracking_number

type ColumnKey = 'order_number' | 'recipient' | 'carrier' | 'tracking' | 'status' | 'synced_at'
const definitions: GridColumnDefinition<ColumnKey>[] = [
  { key: 'order_number', label: 'ERP订单号' },
  { key: 'recipient', label: '收件人' },
  { key: 'carrier', label: '物流商' },
  { key: 'tracking', label: '物流单号' },
  { key: 'status', label: '状态' },
  { key: 'synced_at', label: '同步时间' },
]

const { columns, visibleColumns, syncState, toggle, startDrag, drop, reset } = useAccountGridPreferences<ColumnKey>({
  namespace: 'grid.sync.orders.v1',
  definitions,
  requiredKeys: ['order_number'],
})
</script>

<template>
  <Head title="发货审核 · BYL ERP" />
  <AuthenticatedLayout title="发货审核" :breadcrumbs="['BYL ERP', '同步', '发货审核']">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">ORDER FULFILLMENT REVIEW</p>
          <h2>发货审核</h2>
          <p>审核物流员提交的物流商和运单号；审核通过后，再同步到正式网站。</p>
        </div>
      </div>

      <form class="mvp-toolbar" @submit.prevent="submit">
        <input v-model="filters.search" placeholder="搜索ERP订单号或收件人" aria-label="搜索发货审核">
        <select v-model="filters.status" aria-label="审核状态">
          <option value="">全部状态</option>
          <option value="needs_confirmation">待审核</option>
          <option value="pending">已审核 · 待同步</option>
          <option value="completed">已同步</option>
          <option value="returned">待物流修改</option>
          <option value="failed">同步失败</option>
        </select>
        <button class="mvp-button mvp-button-primary">筛选</button>
      </form>

      <div class="mvp-table-wrap">
        <table class="mvp-table">
          <thead>
            <tr>
              <th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th>
              <th>操作</th>
              <ColumnSettingsHeader
                :columns="columns"
                :required-keys="['order_number']"
                :sync-state="syncState"
                @toggle="toggle"
                @start-drag="startDrag"
                @drop="drop"
                @reset="reset"
              />
            </tr>
          </thead>
          <tbody>
            <tr v-for="order in props.orders" :key="order.id">
              <td v-for="column in visibleColumns" :key="column.key">
                <template v-if="column.key === 'order_number'">
                  <a :href="`/customers/orders/${order.id}`"><strong>{{ order.erp_order_id ?? '—' }}</strong></a>
                </template>
                <template v-else-if="column.key === 'recipient'">{{ order.recipient_name ?? '—' }}</template>
                <template v-else-if="column.key === 'carrier'">{{ order.carrier ?? '—' }}</template>
                <template v-else-if="column.key === 'tracking'">{{ order.tracking_number ?? '—' }}</template>
                <template v-else-if="column.key === 'status'"><span class="mvp-status">{{ syncStatusLabel(order.status) }}</span></template>
                <template v-else>{{ order.synced_at ?? '—' }}</template>
              </td>
              <td class="mvp-actions">
                <button
                  v-if="order.status === 'needs_confirmation'"
                  class="mvp-button mvp-button-primary"
                  type="button"
                  @click="approve(order.id)"
                >审核通过</button>
                <button
                  v-else-if="canSync(order)"
                  class="mvp-button mvp-button-primary"
                  type="button"
                  @click="syncWebsite(order.id)"
                >同步到网站</button>
                <span v-else-if="order.status === 'completed'" class="mvp-status">已完成</span>
                <span v-else class="mvp-status">等待下一步</span>
              </td>
            </tr>
            <tr v-if="props.orders.length === 0">
              <td :colspan="visibleColumns.length + 2" class="mvp-empty">暂无待处理订单</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
