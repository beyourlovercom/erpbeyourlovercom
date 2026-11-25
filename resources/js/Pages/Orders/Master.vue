<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import type { ColDef } from 'ag-grid-community'
import { computed, defineAsyncComponent, ref, watch } from 'vue'
import GlobalGridCell from '../../Components/DataGrid/GlobalGridCell.vue'
import OrderOmsTransfer from '../../Components/Orders/OrderOmsTransfer.vue'
import OrderWorkflowGridCell from '../../Components/Orders/OrderWorkflowGridCell.vue'
import { gridColumn, type GridListRow } from '../../Components/DataGrid/useGlobalGridListAdapter'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

const GlobalGrid = defineAsyncComponent(() => import('../../Components/DataGrid/GlobalGrid.vue'))
type MasterOrder = {
  id: number; number: string; external_number: string | null; kind: string | null
  source: string | null; collaboration_partner: string | null; status: string | null; archive_state: string
  workflow_status_projection?: { code: string; en: string; zh: string; color: string; source: string }
  workflow_revision?: number; can_edit_workflow_status?: boolean
  workflow_status_options?: { code: string; en: string; zh: string; color: string; mutable: boolean }[]
  warehouse: string | null; recipient: string | null; email: string | null
  skus: string; material_full_name: string; date: string | null; owner: string | null; contact_user_name?: string | null; detail_url: string
}
type Pager = { data: MasterOrder[]; current_page: number; last_page: number; per_page: number; total: number }
type MasterFilters = Record<string, string>
const props = defineProps<{
  orders: Pager
  counts: Record<string, number>
  filters: MasterFilters
  canSeeTrash: boolean
  canImportOmsOrders: boolean
  canConfirmOmsOrders: boolean
}>()

const search = ref(props.filters.search ?? '')
const kind = ref(props.filters.kind ?? 'all')
const source = ref(props.filters.source ?? 'all')
const view = computed(() => props.filters.view ?? 'all')
const viewOptions = [
  { key: 'all', label: '全部' },
  { key: 'normal', label: '正常订单' },
  { key: 'operational', label: '运营归档' },
  { key: 'trash', label: '回收站' },
  { key: 'pending', label: '待发货' },
]
const kinds = [
  { key: 'all', label: '全部订单类型' },
  { key: 'customer', label: '客户订单' },
  { key: 'kol_fulfillment', label: '网红订单' },
  { key: 'fulfillment_derived', label: '履约子订单' },
]
const sources = [
  { key: 'all', label: '全部来源' },
  { key: 'woocommerce', label: 'WooCommerce' },
  { key: 'website', label: '网站' },
  { key: 'manual', label: '手工' },
  { key: 'manual_other', label: '其他手工' },
  { key: 'collaboration', label: '合作订单' },
  { key: 'kol', label: '网红' },
]
const params = (overrides: Record<string, string | number> = {}) => ({
  ...props.filters,
  search: search.value.trim(),
  kind: kind.value,
  source: source.value,
  ...overrides,
})
const go = (overrides: Record<string, string | number> = {}) => router.get(
  '/orders', params(overrides), { preserveState: true, preserveScroll: true, replace: true },
)
let searchTimeout: ReturnType<typeof setTimeout> | undefined
const submitSearch = () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  go({ page: 1 })
}
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(submitSearch, 450)
})
watch(() => props.filters.search, (next) => { if (next !== search.value) search.value = next ?? '' })

const kindsZh: Record<string, string> = { customer: '客户订单', kol_fulfillment: '网红订单', fulfillment_derived: '履约子订单' }
const archiveZh: Record<string, string> = { normal: '正常', operational: '运营归档', trash: '回收站' }
const statusZh: Record<string, string> = {
  pending_shipment: '待发货', shipped: '已发货', completed: '已完结',
  completed_delivered: '已送达', cancelled: '已取消',
}
const rows = computed<GridListRow[]>(() => props.orders.data.map(order => ({
  id: order.id,
  order_number: order.number,
  external_number: order.external_number ?? '—',
  kind: kindsZh[order.kind ?? ''] ?? order.kind ?? '—',
  source: order.source ?? '—',
  collaboration_partner: order.collaboration_partner ?? '—',
  status: statusZh[order.status ?? ''] ?? order.status ?? '—',
  workflow_status_projection: order.workflow_status_projection,
  workflow_revision: order.workflow_revision,
  can_edit_workflow_status: order.can_edit_workflow_status,
  workflow_status_options: order.workflow_status_options,
  archive_state: archiveZh[order.archive_state] ?? '—',
  warehouse: order.warehouse ?? '未分仓',
  recipient: order.recipient ?? '—',
  email: order.email ?? '—',
  sku: order.skus || '—',
  material_full_name: order.material_full_name || '—',
  date: order.date ?? '—',
  owner: order.owner ?? '—',
  contact: order.contact_user_name ?? '未分配',
  order_url: order.detail_url,
})))
const columns: ColDef<GridListRow>[] = [
  gridColumn({ colId: 'order_number', field: 'order_number', headerName: 'ERP订单号', width: 210, sortable: true,
    cellRenderer: GlobalGridCell, cellRendererParams: { mode: 'link', hrefFor: (row: GridListRow) => String(row.order_url) } }),
  gridColumn({ colId: 'contact', field: 'contact', headerName: '订单联络人', width: 140 }),
  gridColumn({ colId: 'external_number', field: 'external_number', headerName: '来源单号', width: 188 }),
  gridColumn({ colId: 'kind', field: 'kind', headerName: '订单类型', width: 140 }),
  gridColumn({ colId: 'collaboration_partner', field: 'collaboration_partner', headerName: '合作商', width: 160 }),
  gridColumn({ colId: 'status', field: 'status', headerName: '真实订单状态', width: 154,
    cellRenderer: GlobalGridCell, cellRendererParams: { mode: 'badge' } }),
  gridColumn({ colId: 'workflow_status', field: 'workflow_status_projection', headerName: '工作状态', width: 184,
    cellRenderer: OrderWorkflowGridCell }),
  gridColumn({ colId: 'archive_state', field: 'archive_state', headerName: '归档状态', width: 130,
    cellRenderer: GlobalGridCell, cellRendererParams: { mode: 'badge' } }),
  gridColumn({ colId: 'warehouse', field: 'warehouse', headerName: '所属分仓', width: 155 }),
  gridColumn({ colId: 'date', field: 'date', headerName: '订单日期', width: 145, sortable: true }),
  gridColumn({ colId: 'source', field: 'source', headerName: '来源', width: 145, sortable: true }),
  gridColumn({ colId: 'recipient', field: 'recipient', headerName: '收件人', width: 152 }),
  gridColumn({ colId: 'email', field: 'email', headerName: '客户邮箱', width: 210 }),
  gridColumn({ colId: 'sku', field: 'sku', headerName: 'SKU', width: 225 }),
  gridColumn({ colId: 'material_full_name', field: 'material_full_name', headerName: '物料全称', width: 350, hide: true, tooltipField: 'material_full_name' }),
  gridColumn({ colId: 'owner', field: 'owner', headerName: '负责人', width: 120 }),
  gridColumn({ colId: 'actions', field: 'id', headerName: '操作', width: 116,
    cellRenderer: GlobalGridCell, cellRendererParams: { mode: 'actions',
      actionsFor: (row: GridListRow) => [{ label: row.archive_state === '回收站' ? '回收站' : '查看', href: String(row.order_url) }] } }),
]
const filterColumns = ['order_number', 'contact', 'external_number', 'recipient', 'sku', 'warehouse', 'status', 'date', 'source']
const filterValues = computed(() => Object.fromEntries(filterColumns.map(key => [key, props.filters['grid_' + key] ?? ''])))
const changeColumnSearch = ({ colId, value }: { colId: string; value: string }) => {
  if (filterColumns.includes(colId)) go({ ['grid_' + colId]: value, page: 1 })
}
const changeSort = ({ colId, direction }: { colId: string; direction: 'asc' | 'desc' | null }) => {
  if (!['order_number', 'date', 'source', 'status'].includes(colId)) return
  go({ sort: direction ? colId : 'date', direction: direction ?? 'desc', page: 1 })
}
</script>

<template>
  <Head title="订单总表 · BYL ERP" />
  <AuthenticatedLayout title="订单总表" :breadcrumbs="['BYL ERP', '订单', '订单总表']">
    <section class="mvp-page orders-master">
      <div class="master-heading">
        <div><h2>订单总表</h2><span class="master-total">全部可查看记录 {{ props.counts.all.toLocaleString('en-US') }} 单</span></div>
        <div class="mvp-actions page-header-actions"><Link class="mvp-button" href="/orders/archive">归档清单</Link></div>
      </div>
      <form class="master-toolbar" role="search" @submit.prevent="submitSearch">
        <div class="master-search"><span aria-hidden="true">⌕</span><input v-model="search" aria-label="搜索所有订单" maxlength="120" placeholder="搜索 ERP 单号 / 来源单号 / 收件人 / SKU / 邮箱…" /><button v-if="search" aria-label="清空搜索" class="clear-search" type="button" @click="search = ''">×</button></div>
        <select v-model="kind" aria-label="订单类型" @change="go({ page: 1 })"><option v-for="item in kinds" :key="item.key" :value="item.key">{{ item.label }}</option></select>
        <select v-model="source" aria-label="订单来源" @change="go({ page: 1 })"><option v-for="item in sources" :key="item.key" :value="item.key">{{ item.label }}</option></select>
      </form>
      <div class="quick-filters" aria-label="快速筛选">
        <span class="quick-label">快捷筛选</span>
        <button v-for="item in viewOptions.filter(item => item.key !== 'trash' || canSeeTrash)" :key="item.key"
          type="button" class="quick-chip" :class="{ active: view === item.key }"
          @click="go({ view: item.key, page: 1 })">{{ item.label }} <span class="chip-count">{{ props.counts[item.key].toLocaleString('en-US') }}</span></button>
      </div>
      <Suspense>
        <GlobalGrid
          namespace="grid.orders.master.v1" title="订单总表" :rows="rows" :columns="columns" :required-column-ids="['order_number']"
          :page="orders" :show-search="false" :enable-selection="false"
          :sort="{ colId: props.filters.sort ?? 'date', direction: props.filters.direction === 'asc' ? 'asc' : 'desc' }"
          :filter-columns="filterColumns" :filter-values="filterValues"
          @page-change="page => go({ page })"
          @page-size-change="size => { if (size !== 'all') go({ per_page: size, page: 1 }) }"
          @sort-change="changeSort" @column-search-change="changeColumnSearch">
          <template #actions>
            <OrderOmsTransfer export-url="" :can-import="canImportOmsOrders"
              :can-confirm-import="canConfirmOmsOrders" :can-export="false"
              :total="orders.total" :selected-order-ids="[]" />
          </template>
        </GlobalGrid>
        <template #fallback><div class="master-loading" role="status">正在加载订单总表…</div></template>
      </Suspense>
      <p class="master-help">说明：真实订单状态始终只读；“工作状态”下拉统一使用订单权限和审计接口，不能凭显示名称生成发货或签收记录。运营归档保留历史；回收站仅管理员可查看。</p>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.orders-master{padding-top:4px}
.master-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0 0 10px}
.master-heading>div:first-child{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap}
.master-heading h2{font-size:22px;line-height:1.3;margin:0}
.master-total{font-size:12px;color:#64748b}
.master-toolbar{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:0 0 8px}
.master-search{min-width:240px;flex:1;display:flex;align-items:center;gap:8px;border:1px solid #cad6df;border-radius:8px;padding:0 10px;background:white;min-height:38px;color:#64748b}
.master-search input{border:0;outline:none;background:transparent;min-width:0;flex:1;font-size:13px}
.master-toolbar select{height:38px;min-width:154px;border:1px solid #cad6df;border-radius:8px;padding:0 9px;background:white;font-size:13px}
.clear-search{border:0;background:transparent;cursor:pointer;color:#64748b;font-size:19px}
.quick-filters{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:0 0 8px}
.quick-label{font-size:12px;color:#7b8793;margin-right:3px}
.quick-chip{border:1px solid #e2e8f0;border-radius:20px;padding:6px 10px;background:#f8fafc;cursor:pointer;color:#475569;font-size:12px}
.quick-chip.active{background:#e2f5f0;border-color:#a4dccd;color:#087d67;font-weight:700}
.chip-count{margin-left:3px;opacity:.72;font-variant-numeric:tabular-nums}
.master-loading{padding:38px;text-align:center;border:1px dashed #cad6df;border-radius:8px}
.master-help{font-size:12px;color:#8a96a3;margin:10px 0 2px}
@media(max-width:680px){.master-toolbar select{flex:1;min-width:135px}.master-search{flex-basis:100%}}
</style>
