<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import type { ColDef } from 'ag-grid-community'
import GlobalGridCell from '../../Components/DataGrid/GlobalGridCell.vue'
import { gridColumn, type GridListRow } from '../../Components/DataGrid/useGlobalGridListAdapter'
import SyncProgressGridCell from './SyncProgressGridCell.vue'
import SyncProgressServerFilter from './SyncProgressServerFilter.vue'
const GlobalGrid = defineAsyncComponent(() => import('../../Components/DataGrid/GlobalGrid.vue'))
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
  progress: Progress; can_sync: boolean; sync_block_reason?: string | null; can_reconcile: boolean; can_operate: boolean
}
type SyncRun = {
  id: number; run_type: string; status: string; requested_items: number
  succeeded_items: number; retrying_items: number; failed_items: number
  conflict_items: number; skipped_items: number; started_at: string | null
}
type QueueSummary = { retry_due: number; retry_waiting: number; processing: number; failed: number; conflicts: number }
type Pagination = { total: number; current_page: number; last_page: number; per_page: number; from: number | null; to: number | null }

const props = withDefaults(defineProps<{
  globalGridPilotAvailable?: boolean
  globalGridPilotEnabled?: boolean
  orders: Order[]
  carrierLabels?: Record<string,string>
  canOperate?: boolean
  progressFilterSnapshot?: {
    generated_at: string; total: number; candidates: number; remote_configured: boolean
  } | null
  filters: {
    search?: string; source?: string; per_page?: number; sort?: string; direction?: 'asc' | 'desc'
    grid_order_number?: string; grid_date?: string; grid_source?: string
    grid_recipient?: string; grid_carrier?: string; grid_tracking?: string; grid_progress?: string
  }
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
  grid_order_number: props.filters.grid_order_number ?? '',
  grid_date: props.filters.grid_date ?? '',
  grid_source: props.filters.grid_source ?? '',
  grid_recipient: props.filters.grid_recipient ?? '',
  grid_carrier: props.filters.grid_carrier ?? '',
  grid_tracking: props.filters.grid_tracking ?? '',
  grid_progress: props.filters.grid_progress ?? '',
})
const compactMobile = ref(typeof window !== 'undefined' && window.innerWidth <= 740)
const syncViewport = () => { compactMobile.value = window.innerWidth <= 740 }
onMounted(() => window.addEventListener('resize', syncViewport))
onBeforeUnmount(() => window.removeEventListener('resize', syncViewport))
const gridSearch = ref(props.filters.search ?? '')
const gridSort = ref({
  colId: props.filters.sort && ['date', 'order_number', 'source'].includes(props.filters.sort) ? props.filters.sort : 'date',
  direction: props.filters.direction === 'asc' ? 'asc' as const : 'desc' as const,
})
watch(() => props.filters.search, (value) => { gridSearch.value = value ?? '' })
watch(() => props.filters, (next) => {
  for (const col of supportedColumnFilters) {
    const key = ('grid_' + col) as keyof typeof filters
    ;(filters as Record<string, string | number>)[key] = next[key] ?? ''
  }
}, { deep: true })

watch(() => [props.filters.sort, props.filters.direction] as const, ([sort, direction]) => {
  gridSort.value = {
    colId: sort && ['date', 'order_number', 'source'].includes(sort) ? sort : 'date',
    direction: direction === 'asc' ? 'asc' : 'desc',
  }
})
const selected = ref<number[]>([])
const bulkMode = ref(false)
const syncingWoo = ref(false)
const checkingWoo = ref(false)
const wooReconcile = ref<{ unimported_count: number | null; message?: string } | null>(null)

const submit = (page = 1, overrides: Record<string, string | number | undefined> = {}) => {
  selected.value = []
  router.get('/sync/orders', {
    ...filters,
    sort: gridSort.value.colId,
    direction: gridSort.value.direction,
    // Retain the user's explicit legacy fallback across search/pagination;
    // otherwise a legacy page navigation would silently re-enable AG Grid.
    global_grid: props.globalGridPilotEnabled ? '1' : '0',
    page,
    ...overrides,
  }, { preserveState: true, preserveScroll: true, replace: true })
}
const toggleGrid = () => {
  selected.value = []
  router.get('/sync/orders', {
    ...filters,
    sort: gridSort.value.colId,
    direction: gridSort.value.direction,
    ...(props.globalGridPilotEnabled ? { global_grid: '0' } : { global_grid: '1' }),
    page: 1,
  }, { preserveScroll: true, preserveState: true, replace: true })
}
const onGridSearch = (value: string) => {
  gridSearch.value = value
  filters.search = value
  submit(1)
}
const onGridSort = (next: { colId: string; direction: 'asc' | 'desc' | null }) => {
  if (!['date', 'order_number', 'source'].includes(next.colId)) return
  gridSort.value = { colId: next.colId, direction: next.direction ?? 'desc' }
  submit(1)
}
const onGridPageSize = (size: number | 'all') => {
  if (typeof size !== 'number' || ![20, 50, 100].includes(size)) return
  filters.per_page = size
  submit(1)
}
const supportedColumnFilters = ['order_number', 'date', 'source', 'recipient', 'carrier', 'tracking', 'progress'] as const
const gridFilterValues = computed(() => Object.fromEntries(
  supportedColumnFilters.map((key) => [key, String(filters[(('grid_' + key) as keyof typeof filters)] ?? '')]),
))
const onGridColumnFilter = ({ colId, value }: { colId: string; value: string }) => {
  if (!supportedColumnFilters.includes(colId as typeof supportedColumnFilters[number])) return
  const key = ('grid_' + colId) as keyof typeof filters
  // These keys are all strings. Preserve other filters and reset page.
  ;(filters as Record<string, string | number>)[key] = value
  submit(1)
}
const sourceName = (source: string) => ({
  woocommerce: '官网 Woo', website: '网站导入', kol: '网红',
  manual: '手工订单', manual_other: '其他/外部导入',
} as Record<string, string>)[source] ?? source

const pushTracking = (id: number) => {
  if (!window.confirm('确认将此订单物流同步到官网？系统仍执行付款、物流审核及官网状态门禁，成功后可能触发网站邮件。')) return
  router.post(`/sync/orders/${id}/tracking-outbound`)
}
const syncWebsite = (order: Order) => {
  if (!order.can_sync) {
    window.alert(order.sync_block_reason || order.progress.reason || '当前订单未满足官网同步条件，请打开订单详情检查。')
    return
  }
  pushTracking(order.id)
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
const syncOrder = (row: GridListRow): Order | undefined =>
  props.orders.find((order) => order.id === Number(row.id))
const gridRows = computed<GridListRow[]>(() => props.orders.map((order) => ({
  ...order,
  order_number: order.erp_order_id || order.external_order_number || '#' + order.id,
  source_label: sourceName(order.source_type),
  original_number: order.external_order_number || '—',
  date_label: order.order_date || '—',
  recipient: order.recipient_name || '—',
  carrier_label: order.carrier ? (props.carrierLabels?.[order.carrier] ?? order.carrier) : '—',
  tracking_label: order.tracking_number || '—',
  progress_code: order.progress.code,
  progress_label: order.progress.label,
  progress_reason: order.progress.reason,
  archived_label: order.archived ? '已归档' : '—',
  root_number: order.root_order_id ?? '',
  parcel: order.fulfillment_suffix || '—',
  last_verified_at: order.tracking_sync_verified_at || '—',
  last_synced_at: order.synced_at || '—',
  outbound_status: order.tracking_outbound_status || '—',
  sync_attempts: order.tracking_outbound_attempts ?? 0,
  block_reason: order.sync_block_reason || '—',
})))
const c = (colId: string, field: string, headerName: string, width: number, opts: Record<string, unknown> = {}): ColDef<GridListRow> =>
  gridColumn({ colId, field, headerName, width, minWidth: 85, ...opts })
// Sync is a daily operation list: do not hide 查看 / 同步网站 behind
// an extra menu click. Size the one action column to what THIS page needs,
// instead of reserving the 300px four-action maximum for every order.
const visibleSyncActionCount = computed(() => compactMobile.value ? 1 : props.orders.reduce((max, order) => {
  const canTry = order.can_operate && ['unsynced','failed'].includes(order.progress.code)
  return Math.max(max, 1 + Number(canTry)
    + Number(canTry && order.can_reconcile && !order.can_sync)
    + Number(order.progress.code === 'failed'))
}, 1))
const syncActionColumnWidth = computed(() =>
  compactMobile.value ? 100 : [112, 178, 246, 310][Math.min(3, visibleSyncActionCount.value - 1)])
const gridColumns = computed<ColDef<GridListRow>[]>(() => [
  c('order_number', 'order_number', '订单号', compactMobile.value ? 132 : 190, {
    sortable: true, sortingOrder: ['asc', 'desc'], tooltipField: 'original_number',
    cellRenderer: GlobalGridCell, cellRendererParams: {
      mode: 'link', hrefFor: (row: GridListRow) => '/customers/orders/' + row.id,
    },
  }),
  c('original_number', 'original_number', '原始订单号', 160),
  c('date', 'date_label', '订单日期', 128, { sortable: true, sortingOrder: ['asc', 'desc'] }),
  c('source', 'source_label', '订单来源', 125, { sortable: true, sortingOrder: ['asc', 'desc'] }),
  c('recipient', 'recipient', '收件人', 145),
  c('carrier', 'carrier_label', '物流商', 120),
  c('tracking', 'tracking_label', '物流单号', 180),
  c('shipment_status', 'shipment_status', '发货状态', 120, { hide: true }),
  c('parcel', 'parcel', '包裹 A/B/C', 115, { hide: true }),
  c('root_number', 'root_number', '母订单 ID', 120, { hide: true }),
  c('archived', 'archived_label', '归档状态', 105, { hide: true }),
  c('last_verified_at', 'last_verified_at', '官网核验时间', 186, { hide: true }),
  c('last_synced_at', 'last_synced_at', '同步时间', 186, { hide: true }),
  c('outbound_status', 'outbound_status', '写回任务状态', 125, { hide: true }),
  c('sync_attempts', 'sync_attempts', '写回尝试次数', 116, { hide: true }),
  c('block_reason', 'block_reason', '同步限制原因', 260, { hide: true }),
  c('actions', 'id', '操作', syncActionColumnWidth.value, {
    pinned: 'right',
    filter: false, sortable: false,
    minWidth: compactMobile.value ? 95 : syncActionColumnWidth.value,
    maxWidth: compactMobile.value ? 134 : 330,
    cellRenderer: GlobalGridCell,
    cellRendererParams: { mode: 'actions', actionsFor: (row: GridListRow) => {
      const order = syncOrder(row)
      if (!order) return []
      const actions: { label: string; href?: string; onClick?: () => void }[] = compactMobile.value
        ? []
        : [{ label: '查看', href: '/customers/orders/' + order.id }]
      if (order.can_operate && ['unsynced', 'failed'].includes(order.progress.code)) {
        actions.push({ label: '同步网站', onClick: () => syncWebsite(order) })
        if (!compactMobile.value && order.can_reconcile && !order.can_sync)
          actions.push({ label: '核对官网', onClick: () => reconcile(order.id) })
      }
      if (!compactMobile.value && order.progress.code === 'failed')
        actions.push({ label: '原因', onClick: () => notifyReason(order) })
      return actions
    } },
  }),
  c('progress', 'progress_label', '同步进度', compactMobile.value ? 96 : 118, {
    pinned: 'right',
    filter: true, sortable: false, cellRenderer: SyncProgressGridCell,
    floatingFilterComponent: SyncProgressServerFilter,
    tooltipField: 'progress_reason',
  }),
])
const gridPager = computed(() => ({
  current_page: props.pagination.current_page,
  last_page: props.pagination.last_page,
  per_page: props.pagination.per_page,
  total: props.pagination.total,
}))
const gridEnabled = computed(() => props.globalGridPilotAvailable === true && props.globalGridPilotEnabled === true)
const hasActiveFilters = computed(() => Boolean(
  filters.search.trim() || filters.source || supportedColumnFilters.some((key) =>
    String(filters[(('grid_' + key) as keyof typeof filters)] ?? '').trim() !== ''),
))

</script>

<template>
  <Head title="订单同步总表 · BYL ERP" />
  <AuthenticatedLayout title="订单同步总表" :breadcrumbs="['BYL ERP', '同步', '订单同步总表']">
    <section class="mvp-page simple-sync">
      <div class="mvp-page-heading">
        <div>
          <h2>订单同步总表</h2>
        </div>
        <div class="sync-heading-actions">
          <button v-if="props.globalGridPilotAvailable" class="mvp-button" type="button" data-testid="sync-global-grid-toggle" @click="toggleGrid">{{ gridEnabled ? '返回原表' : '体验 AG 表格' }}</button>
          <div class="total-counter">{{ hasActiveFilters ? '筛选结果' : '全部订单' }} <strong>{{ props.pagination.total.toLocaleString('zh-CN') }}</strong> 单</div>
        </div>
      </div>

      <form v-if="!gridEnabled" class="overview-filters" @submit.prevent="submit(1)">
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
      </div>

      <GlobalGrid
        v-if="gridEnabled"
        :key="compactMobile ? 'sync-mobile' : 'sync-desktop'"
        :namespace="compactMobile ? 'grid.sync.orders.global.mobile.v1' : 'grid.sync.orders.global.v1'"
        title="全部订单同步进度"
        :rows="gridRows"
        :columns="gridColumns"
        :required-column-ids="['order_number']"
        :default-pinned-right="2"
        :page="gridPager"
        :search="gridSearch"
        :sort="gridSort"
        :filter-columns="[...supportedColumnFilters]"
        :filter-values="gridFilterValues"
        :show-search="true"
        :enable-selection="false"
        :client-csv-export="false"
        search-placeholder="搜索全部订单号 / 原始单号 / 收件人..."
        @page-change="submit($event)"
        @page-size-change="onGridPageSize"
        @sort-change="onGridSort"
        @search-change="onGridSearch"
        @column-search-change="onGridColumnFilter"
      >
        <template #actions>
          <div class="sync-grid-source-filter">
            <label for="sync-grid-source">订单来源</label>
            <select id="sync-grid-source" v-model="filters.source" aria-label="AG Grid 订单来源" @change="submit(1)">
              <option value="">所有订单来源</option>
              <option value="woocommerce">官网 WooCommerce</option>
              <option value="website">网站导入</option>
              <option value="kol">网红订单</option>
              <option value="manual">手工订单</option>
              <option value="manual_other">其他 / 外部导入</option>
            </select>
          </div>
        </template>
      </GlobalGrid>
      <p v-if="gridEnabled && props.progressFilterSnapshot" class="sync-filter-snapshot">
        全库状态筛选：{{ props.progressFilterSnapshot.total.toLocaleString('zh-CN') }} 单 ·
        核验快照 {{ new Date(props.progressFilterSnapshot.generated_at).toLocaleString('zh-CN', { hour12: false }) }}
        <template v-if="!props.progressFilterSnapshot.remote_configured"> · 测试环境未连接官网，仅按 ERP 已有凭证判定</template>
      </p>
      <div v-if="!gridEnabled" class="mvp-table-wrap overview-table">
        <table class="mvp-table">
          <thead>
            <tr>
              <th>订单号</th><th>日期</th><th>订单来源</th><th>收件人</th>
              <th>物流商</th><th>物流单号</th><th>操作</th><th>同步进度</th>
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
              <td>{{ order.carrier ? (carrierLabels?.[order.carrier] ?? order.carrier) : '—' }}</td>
              <td class="tracking-number" :title="order.tracking_number || ''">{{ order.tracking_number || '—' }}</td>
              <td class="operational-actions">
                <a class="mvp-button" :href="`/customers/orders/${order.id}`">查看订单</a>
                <button
                  v-if="order.can_operate && ['unsynced', 'failed'].includes(order.progress.code)"
                  type="button"
                  class="mvp-button"
                  :class="{ 'mvp-button-primary': order.can_sync, 'sync-blocked': !order.can_sync }"
                  :title="order.can_sync ? '同步当前订单物流到官网' : (order.sync_block_reason || '该订单暂时不能写回官网')"
                  @click="syncWebsite(order)"
                >同步网站</button>
                <button v-if="order.can_reconcile && !order.can_sync && ['unsynced', 'failed'].includes(order.progress.code)" type="button" class="mvp-button" @click="reconcile(order.id)">核对官网</button>
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

      <div v-if="!gridEnabled" class="page-footer">
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
      <p class="sync-bottom-help">同步进度基于官网物流回读或可信凭证；ERP 人工标记不等于已同步。已通知仅表示官网接受了客户通知请求，不保证邮件到达收件箱。</p>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.simple-sync { padding-bottom: 24px; }
.simple-sync .mvp-page-heading { margin-bottom: 8px; }
.simple-sync .mvp-page-heading h2 { margin: 0; }
.sync-bottom-help { margin: 12px 0 0; font-size: 11px; line-height: 1.5; color: #83939b; }

.sync-heading-actions { display:flex; align-items:center;gap:10px;flex-wrap:wrap; }
.sync-grid-source-filter { display:flex; align-items:center;gap:5px;margin:0;color:#526875;font-size:12px;white-space:nowrap; }
.sync-grid-source-filter select {height:32px;border:1px solid #cbd5df;border-radius:6px;padding:4px 9px;background:#fff; }

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
.operational-actions { white-space: nowrap; } .operational-actions .sync-blocked { color: #657681; background: #f0f3f4; border-color: #d4dde1; } .operational-actions .mvp-button { margin: 0 4px 0 0; padding: 5px 9px; font-size: 12px; }
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
.sync-filter-snapshot{margin:5px 2px 9px;font-size:11px;color:#718096}
</style>
