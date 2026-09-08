<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import StatusBar from '../../Components/Logistics/StatusBar.vue'

type ShipmentRow = {
  shipment_id: number
  order_id: number
  external_order_number: string | null
  erp_order_id: string | null
  recipient_name: string | null
  country: string | null
  warehouse_name: string | null
  carrier: string | null
  service: string | null
  tracking_number: string | null
  shipped_at: string | null
  logistics_note: string | null
  shipment_status: string
  order_url: string | null
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number }

const props = defineProps<{ shipments: Paginated<ShipmentRow>; statusBar?: { key: string; label: string; url: string; count: number }[] }>()
const trackingInputs = ref<Record<number, string>>({})
const getTracking = (row: ShipmentRow) => trackingInputs.value[row.order_id] ?? ''
const setTracking = (row: ShipmentRow, v: string) => { trackingInputs.value[row.order_id] = v }

const saveTracking = (row: ShipmentRow) => {
  const t = (trackingInputs.value[row.order_id] ?? '').trim()
  if (!t) return
  router.post(`/logistics/orders/${row.order_id}/update-tracking`, { tracking_number: t }, { preserveScroll: true })
}

const goToPage = (page: number) => router.get('/logistics/awaiting-tracking', { page }, { preserveState: true, replace: true })
</script>

<template>
  <Head title="已发待单号 · BYL ERP" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '已发待单号']">
    <section class="mvp-page">
      <StatusBar :statuses="props.statusBar ?? []" />
      <div class="mvp-page-heading"><div><p class="mvp-kicker">Logistics OS V1</p><h2>已发待单号</h2><p>已确认发货但追踪号尚未填入的物流单。</p></div></div>
      <div class="mvp-table-wrap"><table class="mvp-table"><thead><tr>
        <th>订单号</th><th>收件人</th><th>国家</th><th>仓库</th><th>物流商 / 渠道</th><th>发货时间</th><th>追踪号</th><th>操作</th>
      </tr></thead><tbody>
        <tr v-for="row in shipments.data" :key="row.shipment_id">
          <td><Link v-if="row.order_url" :href="row.order_url" class="mvp-link">{{ row.external_order_number ?? '—' }}</Link><span v-else>{{ row.external_order_number ?? '—' }}</span></td>
          <td>{{ row.recipient_name ?? '—' }}</td>
          <td>{{ row.country ?? '—' }}</td>
          <td>{{ row.warehouse_name ?? '—' }}</td>
          <td>{{ [row.carrier, row.service].filter(Boolean).join(' / ') || '—' }}</td>
          <td>{{ row.shipped_at ? row.shipped_at.slice(0, 10) : '—' }}</td>
          <td><input class="tracking-input" :value="getTracking(row)" @input="setTracking(row, ($event.target as HTMLInputElement).value)" placeholder="填入追踪号" /></td>
          <td class="mvp-actions"><button class="mvp-button mvp-button-primary" type="button" @click="saveTracking(row)">保存</button></td>
        </tr>
        <tr v-if="shipments.data.length === 0"><td colspan="8" class="mvp-empty">暂无已发待单号订单</td></tr>
      </tbody></table></div>
      <div v-if="shipments.last_page > 1" class="mvp-pagination">
        <button class="mvp-button" :disabled="shipments.current_page <= 1" @click="goToPage(shipments.current_page - 1)">上一页</button>
        <span>第 {{ shipments.current_page }} / {{ shipments.last_page }} 页 · 共 {{ shipments.total }} 条</span>
        <button class="mvp-button" :disabled="shipments.current_page >= shipments.last_page" @click="goToPage(shipments.current_page + 1)">下一页</button>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.tracking-input { border: 1px solid #cfdade; border-radius: 7px; padding: 6px 10px; background: #fff; color: #142126; width: 180px; }
</style>
