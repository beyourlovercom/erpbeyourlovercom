<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Progress = { code: 'unsynced' | 'failed' | 'synced' | 'notified'; label: string; reason: string | null }
type Order = {
  id: number; erp_order_id: string | null; external_order_number: string | null
  order_date: string | null; recipient_name: string | null; source_type: string
  fulfillment_suffix: string | null; root_order_id: number | null; archived: boolean
  tracking_number: string | null; shipment_status: string | null; carrier: string | null
  status: string; synced_at: string | null
  tracking_outbound_status: string | null; tracking_outbound_attempts: number | null
  tracking_sync_status: string | null; tracking_sync_reason: string | null; tracking_sync_verified_at: string | null
  progress: Progress; can_sync: boolean; can_reconcile: boolean; can_operate: boolean
}
type SyncRun = {
  id: number; run_type: string; status: string; requested_items: number
  succeeded_items: number; retrying_items: number; failed_items: number
  conflict_items: number; skipped_items: number; started_at: string | null
}
type QueueSummary = { retry_due: number; retry_waiting: number; processing: number; failed: number; conflicts: number }
type Pagination = { total: number; current_page: number; last_page: number; per_page: number; from: number | null; to: number | null }

const props = withDefaults(defineProps<{
  orders: Order[]
  canOperate?: boolean
  filters: { search?: string; source?: string; per_page?: number }
  pagination?: Pagination
  syncRuns?: SyncRun[]
  queueSummary?: QueueSummary
  wooOrderSync?: {
    health: 'ok' | 'stale' | 'failed' | 'disabled'
    last_success_at: string | null
    last_failure: { run_id: number; at: string | null; error_code: string | null; message: string | null } | null
    can_import_today: boolean
  }
}>(), {
  syncRuns: () => [],
  queueSummary: () => ({ retry_due: 0, retry_waiting: 0, processing: 0, failed: 0, conflicts: 0 }),
  pagination: () => ({ total: 0, current_page: 1, last_page: 1, per_page: 50, from: null, to: null }),
})

const filters = reactive({
  search: props.filters.search ?? '',
  source: props.filters.source ?? '',
  per_page: Number(props.filters.per_page ?? props.pagination.per_page ?? 50),
})
const selected = ref<number[]>([])
const bulkMode = ref(false)
const syncingWoo = ref(false)
const checkingWoo = ref(false)
const wooReconcile = ref<{ unimported_count: number | null; message?: string } | null>(null)

const submit = (page = 1) => {
  selected.value = []
  router.get('/sync/orders', { ...filters, page }, { preserveState: true, preserveScroll: true, replace: true })
}
const sourceName = (source: string) => ({
  woocommerce: '官网 Woo', website: '网站导入', kol: '网红',
  manual: '手工订单', manual_other: '其他/外部导入',
} as Record<string, string>)[source] ?? source

const pushTracking = (id: number) => {
  if (!window.confirm('确认将此订单物流同步到官网？系统仍执行付款、物流审核及官网状态门禁，成功后可能触发网站邮件。')) return
  router.post(`/sync/orders/${id}/tracking-outbound`)
}
const reconcile = (id: number) => router.post('/sync/orchestration/reconcile', { order_ids: [id] })
const notifyReason = (order: Order) => window.alert(order.progress.reason || '请打开订单详情核对官网物流状态')
const copyPayload = async (id: number) => {
  const response = await fetch(`/sync/orders/${id}/payload`, { headers: { Accept: 'application/json' } })
  if (!response.ok) { window.alert('读取同步详情失败'); return }
  await navigator.clipboard.writeText(JSON.stringify(await response.json(), null, 2))
}
const update = (id: number, action: string) => {
  const note = window.prompt('备注（可选），取消则不执行')
  if (note === null) return
  router.post(`/sync/orders/${id}/${action}`, { sync_note: note })
}
const toggleSelected = (id: number, checked: boolean) => {
  selected.value = checked ? [...new Set([...selected.value, id])] : selected.value.filter((value) => value !== id)
}
const batchSelected = () => router.post('/sync/orchestration/tracking/selected', { order_ids: selected.value })
const batchAllPending = () => {
  if (!window.confirm('确认尝试同步最多 100 条符合门禁的待处理物流？请先核对当前官网状态。')) return
  router.post('/sync/orchestration/tracking/all-pending', { limit: 100 })
}
const retryPending = () => router.post('/sync/orchestration/retry', { limit: 100 })
const recoverStale = () => router.post('/sync/orchestration/recover-stale', { age_seconds: 300, limit: 100 })
const reconcileSelected = () => router.post('/sync/orchestration/reconcile', { order_ids: selected.value })
const canOperate = () => props.canOperate ?? props.orders.some((item) => item.can_operate)
const wooHealthLabel = (health: 'ok' | 'stale' | 'failed' | 'disabled') =>
  ({ ok: '正常', failed: '最近执行失败', stale: '超时或暂无成功记录', disabled: '未启用' })[health]

const syncWooToday = () => {
  if (!props.wooOrderSync?.can_import_today || syncingWoo.value) return
  syncingWoo.value = true
  router.post('/sync/woocommerce/incremental', {}, { preserveScroll: true, onFinish: () => { syncingWoo.value = false } })
}
const checkWooBacklog = async () => {
  if (!props.wooOrderSync?.can_import_today || checkingWoo.value) return
  checkingWoo.value = true
  try {
    const response = await fetch('/sync/woocommerce/reconcile-today', {
      credentials: 'same-origin', headers: { Accept: 'application/json' },
    })
    const data = await response.json()
    wooReconcile.value = { ...data, message: response.ok ? data.message : (data.message || '核对失败') }
  } catch {
    wooReconcile.value = { unimported_count: null, message: '网络异常，无法核对' }
  } finally {
    checkingWoo.value = false
  }
}
</script>

<template>
  <Head title="订单同步总表 · BYL ERP" />
  <AuthenticatedLayout title="订单同步总表" :breadcrumbs="['BYL ERP', '同步', '订单同步总表']">
    <section class="mvp-page simple-sync">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">ORDER SYNC OVERVIEW</p>
          <h2>订单同步总表</h2>
          <p>查看所有 ERP 订单的官网物流同步进度。已同步和已通知分别核验，不把人工标记当作官网回执。</p>
        </div>
        <div class="total-counter">全部订单 <strong>{{ props.pagination.total.toLocaleString('zh-CN') }}</strong> 单</div>
      </div>

      <form class="overview-filters" @submit.prevent="submit(1)">
        <input v-model="filters.search" placeholder="搜索订单号、原始单号或收件人" aria-label="搜索订单">
        <select v-model="filters.source" aria-label="订单来源" @change="submit(1)">
          <option value="">所有订单来源</option>
          <option value="woocommerce">官网 WooCommerce</option>
          <option value="website">网站导入</option>
          <option value="kol">网红订单</option>
          <option value="manual">手工订单</option>
          <option value="manual_other">其他/外部导入</option>
        </select>
        <button class="mvp-button mvp-button-primary" type="submit">搜索</button>
        <button class="mvp-button" type="button" @click="filters.search = ''; filters.source = ''; submit(1)">清除</button>
      </form>

      <div class="status-help" aria-label="同步状态说明">
        <span><i class="status-dot unsynced" />未同步</span>
        <span><i class="status-dot failed" />同步失败</span>
        <span><i class="status-dot synced" />已同步</span>
        <span><i class="status-dot notified" />已通知</span>
        <small>已通知代表官网已确认客户通知请求，不保证邮件到达收件箱。</small>
      </div>

      <div class="mvp-table-wrap overview-table">
        <table class="mvp-table">
          <thead>
            <tr>
              <th>订单号</th><th>日期</th><th>订单来源</th><th>收件人</th>
              <th>承运商</th><th>物流单号</th><th>操作</th><th>同步进度</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="order in props.orders" :key="order.id">
              <td class="order-number">
                <a :href="`/customers/orders/${order.id}`">{{ order.erp_order_id || order.external_order_number || '#' + order.id }}</a>
                <small v-if="order.external_order_number && order.external_order_number !== order.erp_order_id" :title="order.external_order_number">原单号：{{ order.external_order_number }}</small>
                <small v-if="order.fulfillment_suffix">包裹 {{ order.fulfillment_suffix }}</small>
                <small class="mobile-order-meta">{{ order.order_date?.slice(5) ?? '—' }} · {{ sourceName(order.source_type) }} · {{ order.recipient_name || '—' }}</small>
                <small v-if="order.archived">已归档 / 已移除</small>
              </td>
              <td>{{ order.order_date?.slice(5) ?? '—' }}</td>
              <td>{{ sourceName(order.source_type) }}</td>
              <td>{{ order.recipient_name || '—' }}</td>
              <td>{{ order.carrier || '—' }}</td>
              <td class="tracking-number" :title="order.tracking_number || ''">{{ order.tracking_number || '—' }}</td>
              <td class="operational-actions">
                <a class="mvp-button" :href="`/customers/orders/${order.id}`">查看订单</a>
                <button v-if="order.can_sync" type="button" class="mvp-button mvp-button-primary" @click="pushTracking(order.id)">同步物流</button>
                <button v-else-if="order.can_reconcile" type="button" class="mvp-button" @click="reconcile(order.id)">核对官网</button>
                <button v-if="order.progress.code === 'failed'" type="button" class="mvp-button" @click="notifyReason(order)">查看原因</button>
              </td>
              <td class="progress-column">
                <span class="progress-badge" :class="order.progress.code" :title="order.progress.reason || ''">{{ order.progress.label }}</span>
              </td>
            </tr>
            <tr v-if="props.orders.length === 0"><td colspan="8" class="mvp-empty">没有符合搜索条件的订单</td></tr>
          </tbody>
        </table>
      </div>

      <div class="page-footer">
        <span>共 {{ props.pagination.total.toLocaleString('zh-CN') }} 单，当前 {{ props.pagination.from ?? 0 }}–{{ props.pagination.to ?? 0 }} 单</span>
        <div class="page-controls">
          <label for="sync-per-page">每页</label>
          <select id="sync-per-page" v-model.number="filters.per_page" @change="submit(1)">
            <option :value="20">20</option><option :value="50">50</option><option :value="100">100</option>
          </select>
          <button type="button" class="mvp-button" :disabled="props.pagination.current_page <= 1" @click="submit(props.pagination.current_page - 1)">上一页</button>
          <span>第 {{ props.pagination.current_page }} / {{ props.pagination.last_page }} 页</span>
          <button type="button" class="mvp-button" :disabled="props.pagination.current_page >= props.pagination.last_page" @click="submit(props.pagination.current_page + 1)">下一页</button>
        </div>
      </div>

      <details class="advanced-panel">
        <summary>高级同步工具与任务记录 <span>（运营日常无需操作）</span></summary>
        <div class="advanced-body">
          <div v-if="props.wooOrderSync" class="advanced-line">
            <strong>网站订单导入</strong>
            <span>当前：{{ wooHealthLabel(props.wooOrderSync.health) }}</span>
            <span>最近成功：{{ props.wooOrderSync.last_success_at ? new Date(props.wooOrderSync.last_success_at).toLocaleString('zh-CN', { hour12: false }) : '尚无记录' }}</span>
            <a v-if="props.wooOrderSync.last_failure" :href="`/sync/runs/${props.wooOrderSync.last_failure.run_id}`">最近失败详情</a>
            <span v-if="wooReconcile">今日未导入：{{ wooReconcile.unimported_count === null ? (wooReconcile.message || '无法核对') : wooReconcile.unimported_count + ' 单' }}</span>
            <button v-if="props.wooOrderSync.can_import_today" type="button" class="mvp-button" :disabled="checkingWoo" @click="checkWooBacklog">核对网站未导入订单</button>
            <button v-if="props.wooOrderSync.can_import_today" type="button" class="mvp-button" :disabled="syncingWoo" @click="syncWooToday">导入网站新订单</button>
          </div>

          <div v-if="canOperate()" class="advanced-line">
            <strong>物流同步维护</strong>
            <label class="bulk-check"><input v-model="bulkMode" type="checkbox"> 开启本页批量选择</label>
            <button class="mvp-button" type="button" @click="batchAllPending">同步符合条件的待处理物流（最多100）</button>
            <button class="mvp-button" type="button" @click="retryPending">重试到期任务</button>
            <button class="mvp-button" type="button" @click="recoverStale">恢复超时任务</button>
          </div>
          <div v-if="bulkMode && canOperate()" class="advanced-line">
            <strong>选择本页订单</strong>
            <label v-for="order in props.orders.filter((item) => item.can_sync)" :key="order.id" class="bulk-check">
              <input type="checkbox" :checked="selected.includes(order.id)" @change="toggleSelected(order.id, ($event.target as HTMLInputElement).checked)"> {{ order.erp_order_id || '#' + order.id }}
            </label>
            <button class="mvp-button mvp-button-primary" type="button" :disabled="!selected.length" @click="batchSelected">写回所选 {{ selected.length }} 单</button>
            <button class="mvp-button" type="button" :disabled="!selected.length" @click="reconcileSelected">核对所选物流</button>
          </div>
          <div class="advanced-line summary-line">
            <strong>后台任务</strong>
            <span>到期重试 {{ props.queueSummary.retry_due }}</span>
            <span>等待重试 {{ props.queueSummary.retry_waiting }}</span>
            <span>处理中 {{ props.queueSummary.processing }}</span>
            <span>失败 {{ props.queueSummary.failed }}</span>
            <span>冲突 {{ props.queueSummary.conflicts }}</span>
          </div>
          <div class="mvp-table-wrap">
            <table class="mvp-table">
              <thead><tr><th>任务</th><th>任务类型</th><th>状态</th><th>请求</th><th>成功</th><th>重试</th><th>冲突</th><th>失败</th></tr></thead>
              <tbody>
                <tr v-for="run in props.syncRuns" :key="run.id">
                  <td><a :href="`/sync/runs/${run.id}`">#{{ run.id }}</a></td>
                  <td>{{ run.run_type }}</td><td>{{ run.status }}</td><td>{{ run.requested_items }}</td><td>{{ run.succeeded_items }}</td>
                  <td>{{ run.retrying_items }}</td><td>{{ run.conflict_items }}</td><td>{{ run.failed_items }}</td>
                </tr>
                <tr v-if="!props.syncRuns.length"><td colspan="8" class="mvp-empty">暂无后台同步任务</td></tr>
              </tbody>
            </table>
          </div>
          <details v-if="canOperate()" class="legacy-tools">
            <summary>人工同步标记（不代表官网已核验）</summary>
            <div v-for="order in props.orders.filter((item) => item.can_operate)" :key="order.id" class="legacy-line">
              <strong>{{ order.erp_order_id || '#' + order.id }}</strong>
              <button type="button" class="mvp-button" @click="copyPayload(order.id)">复制网站数据</button>
              <button v-if="order.status !== 'completed'" type="button" class="mvp-button" @click="update(order.id, 'complete')">人工标记完成</button>
              <button v-else type="button" class="mvp-button" @click="update(order.id, 'reopen')">重开</button>
              <button v-if="order.status !== 'returned'" type="button" class="mvp-button" @click="update(order.id, 'return')">退回更正</button>
            </div>
          </details>
        </div>
      </details>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.simple-sync { padding-bottom: 24px; }
.total-counter { border: 1px solid var(--byl-border-subtle, #d9e1e5); background: #fff; border-radius: 9px; padding: 9px 14px; white-space: nowrap; font-size: 13px; color: #526875; }
.total-counter strong { color: #075c59; margin-left: 4px; font-size: 19px; font-variant-numeric: tabular-nums; }
.overview-filters { display: flex; flex-wrap: wrap; gap: 8px; padding: 12px; background: #fff; border: 1px solid var(--byl-border-subtle, #dae2e7); border-radius: 9px; align-items: center; }
.overview-filters input { flex: 1 1 270px; min-width: 220px; height: 34px; border: 1px solid #cbd5df; border-radius: 7px; padding: 6px 10px; }
.overview-filters select, .page-controls select { height: 34px; border: 1px solid #cbd5df; border-radius: 7px; padding: 5px 8px; background: #fff; }
.status-help { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 22px; padding: 11px 2px; font-size: 12px; color: #47616b; }
.status-help span { display: inline-flex; align-items: center; gap: 7px; white-space: nowrap; }
.status-help small { font-size: 11px; color: #667783; margin-left: auto; }
.status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; background: #cbd5e1; }
.status-dot.failed { background: #e05a55; } .status-dot.synced { background: #208e78; } .status-dot.notified { background: #2475c0; }
.overview-table { background: #fff; border: 1px solid var(--byl-border-subtle, #d9e1e5); border-radius: 10px; overflow-x: auto; }
.overview-table .mvp-table { min-width: 1010px; }
.overview-table .mvp-table th, .overview-table .mvp-table td { padding: 10px 12px; vertical-align: middle; white-space: nowrap; }
.overview-table .mvp-table th:last-child, .overview-table .mvp-table td:last-child { text-align: center; }
.order-number a { color: #176f72; font-weight: 700; text-decoration: none; }
.order-number .mobile-order-meta { display: none; }
.order-number small { display: block; color: #70828b; font-size: 11px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; }
.tracking-number { max-width: 190px; overflow: hidden; text-overflow: ellipsis; font-variant-numeric: tabular-nums; }
.operational-actions { white-space: nowrap; } .operational-actions .mvp-button { margin: 0 4px 0 0; padding: 5px 9px; font-size: 12px; }
.progress-column { width: 118px; } .progress-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 70px; border-radius: 5px; padding: 5px 7px; font-weight: 700; font-size: 12px; white-space: nowrap; }
.progress-badge.unsynced { color: #5f6b76; background: #edf0f3; }
.progress-badge.failed { color: #a72626; background: #fff1ef; }
.progress-badge.synced { color: #176b56; background: #def5ea; }
.progress-badge.notified { color: #14579e; background: #e6f1ff; }
.page-footer { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: center; padding: 12px 2px; color: #667783; font-size: 12px; }
.page-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.page-controls .mvp-button { padding: 5px 10px; }
.advanced-panel { border: 1px solid #d9e1e5; background: #fff; border-radius: 9px; margin-top: 12px; }
.advanced-panel > summary { cursor: pointer; padding: 12px 14px; font-weight: 700; font-size: 13px; color: #506a74; }
.advanced-panel > summary span { font-weight: 400; font-size: 12px; color: #83939b; }
.advanced-body { padding: 2px 12px 12px; display: grid; gap: 12px; }
.advanced-line { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; font-size: 12px; color: #5a6975; }
.advanced-line strong { color: #304b55; } .advanced-line .mvp-button { padding: 5px 8px; }
.bulk-check { display: inline-flex; align-items: center; gap: 4px; }
.legacy-tools { border-top: 1px solid #e2e7e9; padding: 10px 0 0; color: #556875; font-size: 12px; }
.legacy-tools summary { cursor: pointer; } .legacy-line { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 6px 0; border-bottom: 1px solid #edf0f3; }
.legacy-line .mvp-button { padding: 5px 8px; }
@media (max-width: 740px) {
  .status-help small { width: 100%; }
  .total-counter { margin-top: 8px; }
  .overview-filters input { min-width: 180px; }
  .page-footer { justify-content: center; }
  .overview-table .mvp-table { min-width: 0; width: 100%; }
  .overview-table .mvp-table th:nth-child(n+2):nth-child(-n+7),
  .overview-table .mvp-table td:nth-child(n+2):nth-child(-n+7) { display: none; }
  .overview-table .mvp-table th:first-child, .overview-table .mvp-table td:first-child { white-space: normal; width: auto; }
  .overview-table .mvp-table th:last-child, .overview-table .mvp-table td:last-child { width: 94px; padding: 8px 6px; }
  .order-number .mobile-order-meta { display: block; }
}
</style>
