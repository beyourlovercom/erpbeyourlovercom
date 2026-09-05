<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive, ref } from 'vue'
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
  paused: boolean
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number }

const props = defineProps<{ shipments: Paginated<ShipmentRow>; statusBar?: { key: string; label: string; url: string; count: number }[] }>()
const activeAction = ref<{ orderId:number; path:string; label:string } | null>(null)
const notes = reactive<Record<number,string>>({})

const resolve = (row: ShipmentRow) => {
  router.post(`/logistics/orders/${row.order_id}/resolve-exception`, {}, { preserveScroll: true })
}
const action = (row: ShipmentRow, path: string, label: string) => { activeAction.value = { orderId: row.order_id, path, label }; notes[row.order_id] ??= '' }
const submitAction = (row: ShipmentRow) => { const note = (notes[row.order_id] ?? '').trim(); if (!note) return; router.post(`/logistics/orders/${row.order_id}/${activeAction.value?.path}`, { logistics_note: note }, { preserveScroll: true }); activeAction.value = null }

const goToPage = (page: number) => router.get('/logistics/exceptions', { page }, { preserveState: true, replace: true })
</script>

<template>
  <Head title="异常订单 · BYL ERP" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '异常订单']">
    <section class="mvp-page">
      <StatusBar :statuses="props.statusBar ?? []" />
      <div class="mvp-page-heading"><div><p class="mvp-kicker">Logistics OS V1</p><h2>异常订单</h2><p>物流状态异常的待处理订单。</p></div></div>
      <div class="mvp-table-wrap"><table class="mvp-table"><thead><tr>
        <th>订单号</th><th>收件人</th><th>国家</th><th>仓库</th><th>物流商 / 渠道</th><th>追踪号</th><th>发货时间</th><th>异常原因</th><th>操作</th>
      </tr></thead><tbody>
        <tr v-for="row in shipments.data" :key="row.shipment_id">
          <td><Link v-if="row.order_url" :href="row.order_url" class="mvp-link">{{ row.external_order_number ?? row.erp_order_id ?? '—' }}</Link><span v-else>—</span></td>
          <td>{{ row.recipient_name ?? '—' }}</td>
          <td>{{ row.country ?? '—' }}</td>
          <td>{{ row.warehouse_name ?? '—' }}</td>
          <td>{{ [row.carrier, row.service].filter(Boolean).join(' / ') || '—' }}</td>
          <td>{{ row.tracking_number ?? '—' }}</td>
          <td>{{ row.shipped_at ? row.shipped_at.slice(0, 10) : '—' }}</td>
          <td class="exception-note"><strong v-if="row.paused">已暂停 · </strong>{{ row.logistics_note ?? '—' }}</td>
          <td class="mvp-actions"><button class="mvp-button" type="button" @click="action(row, 'pause', '暂停订单')">暂停订单</button><button class="mvp-button" type="button" @click="action(row, 'redispatch', '重新派件')">重新派件</button><button class="mvp-button" type="button" @click="action(row, 'reship', '重新发货')">重新发货</button><button class="mvp-button" type="button" @click="action(row, 'contact-user', '联系用户')">联系用户</button><button class="mvp-button" type="button" @click="action(row, 'cancel-exception', '退款取消')">退款取消</button><button class="mvp-button mvp-button-primary" type="button" @click="resolve(row)">解除异常</button></td>
        </tr>
        <tr v-for="row in shipments.data.filter((item) => activeAction?.orderId === item.order_id)" :key="`${row.shipment_id}-action`"><td colspan="9"><label>{{ activeAction?.label }}说明（必填）<textarea v-model="notes[row.order_id]" rows="2" required /></label><button class="mvp-button mvp-button-primary" :disabled="!(notes[row.order_id] ?? '').trim()" @click="submitAction(row)">确认{{ activeAction?.label }}</button><button class="mvp-button" @click="activeAction = null">取消</button></td></tr>
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
