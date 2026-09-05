<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

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
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number }

const props = defineProps<{ shipments: Paginated<ShipmentRow> }>()

const resolve = (row: ShipmentRow) => {
  router.post(`/logistics/orders/${row.order_id}/resolve-exception`, {}, { preserveScroll: true })
}

const goToPage = (page: number) => router.get('/logistics/exceptions', { page }, { preserveState: true, replace: true })
</script>

<template>
  <Head title="异常订单 · BYL ERP" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '异常订单']">
    <section class="mvp-page">
      <div class="mvp-page-heading"><div><p class="mvp-kicker">Logistics OS V1</p><h2>异常订单</h2><p>物流状态异常的待处理订单。</p></div></div>
      <div class="mvp-table-wrap"><table class="mvp-table"><thead><tr>
        <th>订单号</th><th>收件人</th><th>国家</th><th>仓库</th><th>物流商 / 渠道</th><th>追踪号</th><th>发货时间</th><th>异常原因</th><th>操作</th>
      </tr></thead><tbody>
        <tr v-for="row in shipments.data" :key="row.shipment_id">
          <td>{{ row.external_order_number ?? row.erp_order_id ?? '—' }}</td>
          <td>{{ row.recipient_name ?? '—' }}</td>
          <td>{{ row.country ?? '—' }}</td>
          <td>{{ row.warehouse_name ?? '—' }}</td>
          <td>{{ [row.carrier, row.service].filter(Boolean).join(' / ') || '—' }}</td>
          <td>{{ row.tracking_number ?? '—' }}</td>
          <td>{{ row.shipped_at ? row.shipped_at.slice(0, 10) : '—' }}</td>
          <td class="exception-note">{{ row.logistics_note ?? '—' }}</td>
          <td class="mvp-actions"><button class="mvp-button mvp-button-primary" type="button" @click="resolve(row)">解除异常</button></td>
        </tr>
        <tr v-if="shipments.data.length === 0"><td colspan="9" class="mvp-empty">暂无异常订单</td></tr>
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
.exception-note { max-width: 220px; white-space: pre-wrap; font-size: 12px; color: #7a5f18; }
</style>
