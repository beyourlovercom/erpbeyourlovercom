<script setup lang="ts">
import { computed } from 'vue'
import type { ColDef } from 'ag-grid-community'
import OmsGrid from '../../Components/DataGrid/OmsGrid.vue'
import OrderOmsCell from './OrderOmsCell.vue'

type Workflow = { code: string; en: string; zh: string; color: string; source: string; mutable?: boolean }
type Option = { code: string; en: string; zh: string; color: string; mutable: boolean }
type Order = Record<string, unknown> & {
  id: number; erp_order_id: string | null; external_order_number: string;
  order_kind?: string; source_label: string; order_date: string;
  order_status_label: string; workflow_status_projection?: Workflow;
  workflow_revision?: number; address?: { recipient_name: string; country: string };
  owner_label?: string | null
}
type Pagination = { data: Order[]; current_page: number; last_page: number; per_page: number; total: number }
const props = defineProps<{
  title: string
  orders: Pagination
  filters: Record<string, string>
  canEditWorkflowStatus?: boolean
  workflowStatusOptions?: Option[]
}>()
const emit = defineEmits<{
  (event: 'page-change', page: number): void
  (event: 'page-size-change', size: number | 'all'): void
  (event: 'sort-change', sort: { colId: string; direction: 'asc' | 'desc' | null }): void
}>()
const rows = computed<Record<string, unknown>[]>(() => props.orders.data.map(order => ({
  ...order,
  recipient: order.address?.recipient_name ?? '—',
  country: order.address?.country ?? '—',
  status_label: order.workflow_status_projection?.zh ?? order.order_status_label ?? '—',
  owner_label: order.owner_label ?? '—',
})))
const columns = computed<ColDef<Record<string, unknown>>[]>(() => [
  { colId: 'order_number', field: 'erp_order_id', headerName: 'ERP订单号', width: 180,
    sortable: true, cellRenderer: OrderOmsCell, cellRendererParams: { mode: 'number' } },
  { colId: 'source', field: 'source_label', headerName: '来源', width: 125, sortable: true },
  { colId: 'date', field: 'order_date', headerName: '订单日期', width: 165, sortable: true },
  { colId: 'recipient', field: 'recipient', headerName: '收件人', width: 145, sortable: false },
  { colId: 'country', field: 'country', headerName: '国家', width: 100, sortable: false },
  { colId: 'status', field: 'status_label', headerName: '工作状态', width: 185, sortable: false,
    cellRenderer: OrderOmsCell, cellRendererParams: { mode: 'status',
      canEdit: props.canEditWorkflowStatus === true, options: props.workflowStatusOptions ?? [] } },
  { colId: 'owner', field: 'owner_label', headerName: '负责人', width: 130, sortable: false },
  { colId: 'actions', headerName: '操作', width: 92, sortable: false, filter: false,
    cellRenderer: OrderOmsCell, cellRendererParams: { mode: 'action' } },
])
const sort = computed(() => ({
  colId: props.filters.sort && ['order_number', 'date', 'source'].includes(props.filters.sort)
    ? props.filters.sort : 'date',
  direction: props.filters.direction === 'asc' ? 'asc' as const : 'desc' as const,
}))
const pagination = computed(() => ({
  current_page: props.orders.current_page,
  last_page: props.orders.last_page,
  per_page: props.orders.per_page,
  total: props.orders.total,
  page_size_mode: props.filters.per_page === 'all' ? 'all' as const : props.orders.per_page,
}))
</script>

<template>
  <OmsGrid
    namespace="grid.orders.all.oms.v1"
    :title="title"
    :columns="columns"
    :rows="rows"
    :server-pagination="pagination"
    :server-sort="sort"
    preference-scope="account"
    :default-pinned-left="1"
    :default-pinned-right="1"
    :required-column-ids="['order_number', 'actions']"
    @page-change="emit('page-change', $event)"
    @page-size-change="emit('page-size-change', $event)"
    @sort-change="emit('sort-change', $event)"
  >
    <template #actions><span class="oms-orders-server-authority">订单查询和导出由 ERP 服务器处理</span></template>
  </OmsGrid>
</template>
<style scoped>
.oms-orders-server-authority{color:#64748b;font-size:11px}
</style>
