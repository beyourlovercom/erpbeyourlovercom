<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, defineAsyncComponent, reactive, ref, watch } from 'vue'
import type { ColDef } from 'ag-grid-community'
import GlobalGridCell from '../../Components/DataGrid/GlobalGridCell.vue'
import { gridColumn, useGlobalGridListAdapter, type GridListRow } from '../../Components/DataGrid/useGlobalGridListAdapter'
const GlobalGrid = defineAsyncComponent(() => import('../../Components/DataGrid/GlobalGrid.vue'))
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import StatusBar from '../../Components/Logistics/StatusBar.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../Composables/useAccountGridPreferences'
type Item = { sku:string|null; product_summary:string|null; product_url:string|null }
type Row = { order_id:number; external_order_number:string|null; erp_order_id:string|null; order_url:string|null; reason:string; trigger:string; requested_at:string|null; items:Item[]; warehouse_name:string|null; carrier:string|null; service:string|null; tracking_number:string|null; shipment_status:string }
const props = defineProps<{ reviews:{data:Row[];current_page:number;last_page:number;per_page?:number;total:number}; statusBar:{key:string;label:string;url:string;count:number}[]; canOperate:boolean; globalGridPilotAvailable?:boolean; globalGridPilotEnabled?:boolean; gridSearch?:string }>()
type ColumnKey = 'order_number' | 'items' | 'current_logistics' | 'tracking' | 'trigger' | 'requested_at' | 'reason' | 'destination' | 'note'
const definitions: GridColumnDefinition<ColumnKey>[] = [{ key: 'order_number', label: '订单号' }, { key: 'items', label: '商品 / SKU' }, { key: 'current_logistics', label: '当前物流' }, { key: 'tracking', label: '追踪号' }, { key: 'trigger', label: '触发' }, { key: 'requested_at', label: '提交时间' }, { key: 'reason', label: '原因' }, { key: 'destination', label: '处理结果' }, { key: 'note', label: '复核说明' }]
const { columns, visibleColumns, syncState, toggle, startDrag, drop, reset } = useAccountGridPreferences<ColumnKey>({ namespace: 'grid.logistics.review.v1', definitions, requiredKeys: ['order_number'] })
const selectedReviewOrderId = ref<number|null>(null)
const reviewSearchText = ref(props.gridSearch ?? '')
watch(() => props.gridSearch, value => { reviewSearchText.value=value??'' })
const searchReviewGrid = (value:string) => { reviewSearchText.value=value; selectedReviewOrderId.value=null; gridNavigation.search(value) }
const gridNavigation = useGlobalGridListAdapter({
  path:'/logistics/review',
  enabled:()=>!!props.globalGridPilotEnabled,
  search:()=>reviewSearchText.value,
  pageSize:()=>props.reviews.per_page??50,
})
const reviewGridRows = computed<GridListRow[]>(()=>props.reviews.data.map(row=>({
  ...row,
  order_number:row.erp_order_id??'—',
  sku_summary:row.items.map(item=>item.sku??item.product_summary??'无 SKU').join(' / '),
  logistics_summary:[row.warehouse_name,row.carrier,row.service].filter(Boolean).join(' / ')||'—',
  tracking:row.tracking_number??'—',
  trigger_label:row.trigger==='redispatch'?'重新派件':row.trigger,
  time:row.requested_at?.replace('T',' ').slice(0,16)??'—',
})))
const reviewGridColumns: ColDef<GridListRow>[] = [
  gridColumn({colId:'order',field:'order_number',headerName:'订单号',width:180,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'link',hrefFor:(r:GridListRow)=>r.order_url}}),
  gridColumn({colId:'sku',field:'sku_summary',headerName:'商品 / SKU',width:195}),
  gridColumn({colId:'logistics',field:'logistics_summary',headerName:'当前物流',width:205}),
  gridColumn({colId:'tracking',field:'tracking',headerName:'追踪号',width:165}),
  gridColumn({colId:'trigger',field:'trigger_label',headerName:'触发',width:125}),
  gridColumn({colId:'time',field:'time',headerName:'提交时间',width:170}),
  gridColumn({colId:'reason',field:'reason',headerName:'原因',width:240}),
  gridColumn({colId:'actions',field:'order_id',headerName:'操作',width:130,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'actions',actionsFor:(r:GridListRow)=>
      props.canOperate ? [{label:'填写复核',onClick:()=>{selectedReviewOrderId.value=Number(r.order_id)}}] : []
    }}),
]
const reviewPager = computed(()=>({current_page:props.reviews.current_page,last_page:props.reviews.last_page,per_page:props.reviews.per_page??50,total:props.reviews.total}))
const reviewOperationRows = computed(()=>props.globalGridPilotEnabled
  ? props.reviews.data.filter(row=>row.order_id===selectedReviewOrderId.value)
  : props.reviews.data)

const forms = reactive<Record<number,{destination:string;note:string}>>({})
const form = (row:Row) => forms[row.order_id] ??= { destination:'', note:'' }
const triggerLabels:Record<string,string> = { redispatch:'重新派件' }
const labels:Record<string,string> = { pending:'退回待发货', awaiting_tracking:'已发待单号', in_transit:'运输中', exception:'保留异常', completed:'已完结', cancelled:'取消订单（不退款）' }
const decide = (row:Row) => { if (!props.canOperate) return; const value=form(row); if (!value.destination || !value.note.trim()) return; router.post(`/logistics/orders/${row.order_id}/review`, { destination:value.destination, decision_note:value.note.trim() }, { preserveScroll:true }) }
</script>
<template><Head title="复核单 · BYL ERP"/><AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP','物流','复核单']"><section class="mvp-page"><StatusBar :statuses="props.statusBar"/><div class="mvp-page-heading"><div><p class="mvp-kicker">Logistics Review</p><h2>复核单</h2><p>复核中的订单会阻断正常履约操作。<strong v-if="!props.canOperate"> 当前角色为只读模式。</strong></p></div></div><div v-if="globalGridPilotAvailable" style="margin:8px 0">
  <button type="button" class="mvp-button" data-testid="review-global-grid-toggle" @click="gridNavigation.toggle()">{{ globalGridPilotEnabled?'返回原表':'体验 Global Grid V1' }}</button>
</div>
<GlobalGrid v-if="globalGridPilotEnabled" namespace="grid.logistics.review.global.v1"
  title="物流复核 · Global Grid V1" :rows="reviewGridRows" :columns="reviewGridColumns"
  :required-column-ids="['order']" :page="reviewPager" :search="gridSearch??''"
  search-placeholder="搜索全部复核订单号 / 收件人..."
  @search-change="searchReviewGrid($event)" @page-change="gridNavigation.page($event)"
  @page-size-change="gridNavigation.pageSize($event)" />
<div v-if="globalGridPilotEnabled && selectedReviewOrderId!==null" style="margin:10px 0">
  <button type="button" class="mvp-button" @click="selectedReviewOrderId=null">收起复核操作</button>
  <p>以下保留原有复核表单与服务端权限校验，仅显示当前选择的订单。</p>
</div>
<div v-if="!globalGridPilotEnabled || selectedReviewOrderId!==null" class="mvp-table-wrap"><table class="mvp-table"><thead><tr><th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th><th>操作</th><ColumnSettingsHeader :columns="columns" :required-keys="['order_number']" :sync-state="syncState" @toggle="toggle" @start-drag="startDrag" @drop="drop" @reset="reset" /></tr></thead><tbody><tr v-for="row in reviewOperationRows" :key="row.order_id"><td v-for="column in visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link v-if="row.order_url" :href="row.order_url" class="mvp-link">{{ row.erp_order_id ?? '—' }}</Link><span v-else>{{ row.erp_order_id ?? '—' }}</span></template><template v-else-if="column.key === 'items'"><template v-for="item in row.items" :key="item.product_url ?? item.sku"><Link v-if="item.product_url" :href="item.product_url" class="mvp-link">{{ item.sku ?? item.product_summary }}</Link><span v-else>{{ item.sku ?? item.product_summary }}</span> </template></template><template v-else-if="column.key === 'current_logistics'">{{ [row.warehouse_name, row.carrier, row.service].filter(Boolean).join(' / ') || '—' }}</template><template v-else-if="column.key === 'tracking'">{{ row.tracking_number ?? '—' }}</template><template v-else-if="column.key === 'trigger'">{{ triggerLabels[row.trigger] ?? row.trigger }}</template><template v-else-if="column.key === 'requested_at'">{{ row.requested_at ? row.requested_at.replace('T', ' ').slice(0, 16) : '—' }}</template><template v-else-if="column.key === 'reason'">{{ row.reason }}</template><template v-else-if="column.key === 'destination'"><select v-if="props.canOperate" v-model="form(row).destination"><option value="" disabled>请选择处理结果</option><option v-for="(label,value) in labels" :key="value" :value="value">{{ label }}</option></select><span v-else>待操作角色处理</span></template><template v-else><textarea v-if="props.canOperate" v-model="form(row).note" rows="2" required placeholder="必填" /><span v-else>—</span></template></td><td><button v-if="props.canOperate" class="mvp-button mvp-button-primary" :disabled="!form(row).destination || !form(row).note.trim()" @click="decide(row)">确认复核</button><span v-else>只读</span></td></tr><tr v-if="!props.reviews.data.length"><td :colspan="visibleColumns.length + 2" class="mvp-empty">暂无复核单</td></tr></tbody></table></div></section></AuthenticatedLayout></template>
