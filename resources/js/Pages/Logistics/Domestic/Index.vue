<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, defineAsyncComponent, nextTick, ref, watch } from 'vue'
import type { ColDef } from 'ag-grid-community'
import GlobalGridCell from '../../../Components/DataGrid/GlobalGridCell.vue'
import DomesticGridActionCell from './DomesticGridActionCell.vue'
import DomesticGridPendingCell from './DomesticGridPendingCell.vue'
import DomesticGridPendingSkuCell from './DomesticGridPendingSkuCell.vue'
import DomesticGridPendingRouteCell from './DomesticGridPendingRouteCell.vue'
import GridBatchFooterControls from '../../../Components/DataGrid/GridBatchFooterControls.vue'
import { gridColumn, useGlobalGridListAdapter, type GridListRow } from '../../../Components/DataGrid/useGlobalGridListAdapter'
const GlobalGrid = defineAsyncComponent(() => import('../../../Components/DataGrid/GlobalGrid.vue'))
import { publishGlobalFeedback } from '../../../lib/globalFeedback'
import { currentCsrfHeaders } from '../../../lib/csrfHeaders'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import OrderWorkflowStatusPill from '../../../Components/Orders/OrderWorkflowStatusPill.vue'
import CompactWorkspaceToolbar from '../../../Components/UI/CompactWorkspaceToolbar.vue'
import CompactWorkspaceHelp from '../../../Components/UI/CompactWorkspaceHelp.vue'

type Item = { sku:string|null; quantity:number }
type Row = {
  line:number; order_id:number; paid_date:string|null; paid_date_source?:string|null; amount?:string|null; amount_currency?:string|null; amount_source?:string|null; erp_order_id:string|null; provider_reference:string|null; order_url:string; source_type?:string; tracking_sync_status?:string|null; tracking_sync_reason?:string|null; tracking_sync_verified_at?:string|null
  workflow_status_projection?:{ code:string; zh:string; en:string; color:string; source:string; mutable?:boolean }
  recipient_name:string|null; phone:string|null; country:string|null; state:string|null; city:string|null; postal_code:string|null
  carrier:string|null; service:string|null; carrier_selection_source?:string|null; carrier_ready?:boolean; tracking_number:string|null; shipment_status:string|null; shipped_at:string|null
  review_status:'approved'|'pending'|'stockout'|'conflict'; export_blockers:string[]; items:Item[]
}
type PageRows = { data:Row[]; current_page:number; last_page:number; per_page?:number; total:number }
type PreviewRow = { row:number; order_id:number|null; order_number:string; tracking_number:string; carrier:string; provider_status:string|null; status:string; message:string }
type Preview = { token:string; rows:PreviewRow[]; apply_count:number; noop_count:number; blocked_count:number }
type ProviderStatus = Record<'4px'|'weship',{export_ready:boolean;return_ready:boolean;label:string;note?:string;reason?:string}>
type CarrierOption = {value:string;label:string;default_service_value?:string|null;services:{value:string;label:string;name_zh?:string|null}[]}

const props = defineProps<{
  view:string; rows:PageRows; viewCounts:Record<string,number>; canOperate:boolean; canReview:boolean; canArchive?:boolean; canTrackingSync?:boolean
  trackingImportProvider:'4px'|'weship'|'generic'|null; trackingImportPreview:Preview|null; providerStatus:ProviderStatus
  carrierOptions:CarrierOption[]
  globalGridPilotAvailable?:boolean
  globalGridPilotEnabled?:boolean
  gridSearch?:string
}>()

type BulkChoice = {value:string; label:string; reasonRequired?:boolean}
const selectedBulkOrderIds = ref<number[]>([])
const bulkBusy = ref(false)
const isDailyProviderView = (view:string)=>['today','today-4px','today-weship'].includes(view)
const bulkOptions = computed<BulkChoice[]>(()=>{
  const result:BulkChoice[] = []
  if (props.canReview && props.view==='review') {
    result.push({value:'approve',label:'批量通过'},{value:'stockout',label:'批量缺货'},
      {value:'reject',label:'批量驳回',reasonRequired:true})
  }
  if (props.canReview && props.view==='stockout') result.push({value:'restock',label:'批量已到货-可发货'})
  // Reuse the existing single footer; only previously eligible shipped Woo
  // orders may be sent to the existing tracking-sync endpoint.
  if (props.canTrackingSync && isDailyProviderView(props.view) && props.rows.data.some(row=>
    selectedBulkOrderIds.value.includes(row.order_id) && eligibleForBatchTracking(row))) {
    result.push({value:'sync-tracking',label:'批量同步官网物流'})
  }
  if (props.canArchive && props.view!=='stockout') result.push({value:'archive',label:'批量归档'})
  return result
})
const onBulkSelectionChanged = (rows:GridListRow[])=>{
  selectedBulkOrderIds.value=[...new Set(rows.map(row=>Number(row.id)).filter(id=>Number.isSafeInteger(id)&&id>0))]
}
watch(()=>[props.view,props.rows.current_page,props.rows.per_page,props.gridSearch],()=>{
  selectedBulkOrderIds.value=[]
})
watch(()=>props.rows.data, rows=>{
  const present=new Set(rows.map(row=>row.order_id))
  selectedBulkOrderIds.value=selectedBulkOrderIds.value.filter(id=>present.has(id))
})
const domesticGridSearch = ref(props.gridSearch ?? '')
watch(() => props.gridSearch, value => { domesticGridSearch.value=value??'' })
const searchDomesticGrid = (value:string) => { domesticGridSearch.value=value; gridNavigation.search(value) }
const activeOperationId = ref<number|null>(null)
const showBatchLegacyOperations = ref(false)
const gridNavigation = useGlobalGridListAdapter({
  path:'/logistics/china',
  enabled:()=>!!props.globalGridPilotEnabled,
  search:()=>domesticGridSearch.value,
  pageSize:()=>props.rows.per_page??50,
  context:()=>({view:props.view}),
})
const nativeOperationRows = computed(()=>!props.globalGridPilotEnabled || showBatchLegacyOperations.value
  ? props.rows.data : props.rows.data.filter(row=>row.order_id===activeOperationId.value))
const domesticGridRows = computed<GridListRow[]>(()=>props.rows.data.map(row=>({
  id:row.order_id,
  line:row.line,
  order_number:row.erp_order_id??'—',
  order_url:row.order_url,
  workflow_status:row.workflow_status_projection?.zh ?? '—',
  review_status:row.review_status==='approved'?'已通过':row.review_status==='stockout'?'缺货':row.review_status==='conflict'?'状态待对账':'待审核',
  shipment_status:row.shipment_status??'',
  paid_date:row.paid_date??'—',
  sku:row.items.map(item=>item.sku??'无SKU').join(' / ') || '—',
  country:row.country??'',
  source_type:row.source_type??'',
  tracking_sync_code:row.tracking_sync_status??'',
  tracking_sync_reason:row.tracking_sync_reason??'',

  quantity:row.items.map(item=>item.quantity).join(' / ') || '—',
  amount:row.amount!==null&&row.amount!==undefined ? row.amount+' '+(row.amount_currency??'USD'):'—',
  export_status:row.export_blockers.length ? row.export_blockers.join(' / '):'可导出',
  provider_reference:row.provider_reference??'—',
  carrier:row.carrier??'—',
  service:row.service??'—',
  tracking_number:row.tracking_number??'—',
  recipient_name:row.recipient_name??'—',
  phone:row.phone??'缺电话',
  destination:[row.country,row.state,row.city].filter(Boolean).join(' / ')||'—',
  tracking_sync_status:row.source_type==='woocommerce'?trackingLabel(row.tracking_sync_status):'—',
})))
const pendingHasSyncAction = computed(()=>props.view==='pending' && props.canTrackingSync
  && props.rows.data.some(row=>eligibleForTracking(row)))
const domesticGridColumns = computed<ColDef<GridListRow>[]>(()=>[
  gridColumn({colId:'line',field:'line',headerName:'#',width:74}),
  gridColumn({colId:'order',field:'order_number',headerName:'ERP 订单',width:186,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'link',hrefFor:(r:GridListRow)=>r.order_url}}),
  gridColumn({colId:'status',field:'workflow_status',headerName:'订单状态',width:143}),
  gridColumn({colId:'review',field:'review_status',headerName:'审核',width:120,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'badge'}}),
  gridColumn({colId:'paid_date',field:'paid_date',headerName:'成交日期',width:135}),
  gridColumn({colId:'sku',field:'sku',headerName:'SKU',width:['pending','today','today-4px','today-weship'].includes(props.view)?230:178,
    ...(['pending','today','today-4px','today-weship'].includes(props.view)?{
      cellRenderer:DomesticGridPendingSkuCell,
      cellRendererParams:{
        showBarcode:(id:number)=>{
          const source=props.rows.data.find(item=>item.order_id===id)
          if(source)void showBarcode(source)
        },
        preview:(id:number)=>barcodePreview.value?.orderId===id?barcodePreview.value.items:null,
        error:(id:number)=>barcodeErrorOrderId.value===id?barcodeError.value:'',
        loading:()=>barcodeLoading.value,
      },
    }:{}),
  }),
  gridColumn({colId:'quantity',field:'quantity',headerName:'数量',width:100}),
  gridColumn({colId:'amount',field:'amount',headerName:'金额',width:134}),
  gridColumn({colId:'export',field:'export_status',headerName:'导出状态',width:175}),
  gridColumn({colId:'provider_ref',field:'provider_reference',headerName:'承运商单号',width:180}),
  gridColumn({colId:'carrier',field:'carrier',headerName:'Carrier',width:156,
    ...(props.view==='pending'?{cellRenderer:DomesticGridPendingRouteCell,cellRendererParams:{
      source:(id:number)=>props.rows.data.find(item=>item.order_id===id),
      carrierOptions:props.carrierOptions,canOperate:props.canOperate,
      carrierBusy:(id:number)=>!!carrierBusy.value[id],
      saveCarrier:(id:number,carrier:string,service:string)=>{
        const row=props.rows.data.find(item=>item.order_id===id)
        if(!row||!canQuickShip(row))return
        carrierDrafts.value[id]={carrier,service}
        saveCarrier(row)
      },
    }}:{}),
  }),
  gridColumn({colId:'service',field:'service',headerName:'服务',width:200,
    ...(props.view==='pending'?{cellRenderer:DomesticGridPendingRouteCell,cellRendererParams:{
      source:(id:number)=>props.rows.data.find(item=>item.order_id===id),
      carrierOptions:props.carrierOptions,canOperate:props.canOperate,
      carrierBusy:(id:number)=>!!carrierBusy.value[id],
      saveCarrier:(id:number,carrier:string,service:string)=>{
        const row=props.rows.data.find(item=>item.order_id===id)
        if(!row||!canQuickShip(row))return
        carrierDrafts.value[id]={carrier,service}
        saveCarrier(row)
      },
    }}:{}),
  }),
  gridColumn({colId:'tracking',field:'tracking_number',headerName:'Tracking',width:186}),
  gridColumn({colId:'recipient',field:'recipient_name',headerName:'收件人',width:140}),
  gridColumn({colId:'phone',field:'phone',headerName:'电话',width:150}),
  gridColumn({colId:'destination',field:'destination',headerName:'国家 / 地区',width:177}),
  gridColumn({colId:'sync',field:'tracking_sync_status',headerName:'官网同步',width:140}),
  gridColumn({colId:'actions',field:'id',headerName:'操作',
    width:props.view==='review'?185:props.view==='stockout'?140:props.view==='pending'?(props.canReview?252:170)+(pendingHasSyncAction.value?105:0):isDailyProviderView(props.view)?(props.canReview&&props.canTrackingSync?242:170):210,
    minWidth:props.view==='review'?185:props.view==='stockout'?136:props.view==='pending'?(props.canReview?248:166)+(pendingHasSyncAction.value?105:0):isDailyProviderView(props.view)?(props.canReview&&props.canTrackingSync?235:162):190,
    maxWidth:props.view==='review'?190:props.view==='stockout'?148:props.view==='pending'?(props.canReview?256:175)+(pendingHasSyncAction.value?105:0):isDailyProviderView(props.view)?(props.canReview&&props.canTrackingSync?250:178):210,
    pinned:'right',lockPinned:true,lockVisible:true,suppressMovable:true,
    cellRenderer:props.view==='pending'?DomesticGridPendingCell:DomesticGridActionCell,cellRendererParams:{
      carrierOptions:props.carrierOptions,
      canTrackingSync:props.canTrackingSync,
      syncEligible:(id:number)=>{
        const row=props.rows.data.find(item=>item.order_id===id)
        return !!row&&eligibleForTracking(row)
      },
      syncBusy:()=>trackingBusy.value,
      syncTracking:(id:number)=>{
        const row=props.rows.data.find(item=>item.order_id===id)
        if(row&&props.canTrackingSync&&eligibleForTracking(row))pushTracking([id],true)
      },
      source:(id:number)=>props.rows.data.find(item=>item.order_id===id),
      carrierBusy:(id:number)=>!!carrierBusy.value[id],
      trackingBusy:(id:number)=>!!inlineBusy.value[id],
      feedback:(id:number)=>carrierErrors.value[id]||inlineErrors.value[id]||carrierSaved.value[id]||inlineSaved.value[id]||'',
      saveCarrier:(id:number,carrier:string,service:string)=>{
        const row=props.rows.data.find(item=>item.order_id===id)
        if(!row||!canQuickShip(row))return
        carrierDrafts.value[id]={carrier,service}
        saveCarrier(row)
      },
      saveTracking:(id:number,tracking:string)=>{
        const row=props.rows.data.find(item=>item.order_id===id)
        if(!row||!canQuickShip(row))return
        inlineTracking.value[id]=tracking
        saveInlineTracking(row)
      },
      confirmShip:(id:number,tracking:string)=>{
        const row=props.rows.data.find(item=>item.order_id===id)
        if(!row||!canQuickShip(row))return
        inlineTracking.value[id]=tracking
        confirmInlineShip(row)
      },
      reopenPending:(id:number)=>{
        const row=props.rows.data.find(item=>item.order_id===id)
        if(row && props.canReview && row.shipment_status==='pending' && row.review_status==='approved')reopen(row)
      },
      view:props.view, canReview:props.canReview, canOperate:props.canOperate,
      get busy(){return reviewBusy.value},
      native:(row:GridListRow)=>openNativeOperation(Number(row.id)),
      approve:(row:GridListRow)=>sourceAction(row,source=>decide(source,'approve')),
      stockout:(row:GridListRow)=>sourceAction(row,source=>decide(source,'stockout')),
      restock:(row:GridListRow)=>sourceAction(row,source=>restock(source)),
      reopen:(row:GridListRow)=>sourceAction(row,source=>reopen(source)),
      reject:(row:GridListRow,reason:string)=>sourceAction(row,source=>rejectInline(source,reason)),
    }}),
])
const sourceAction = (grid:GridListRow, callback:(row:Row)=>void) => {
  const row=props.rows.data.find(item=>item.order_id===Number(grid.id))
  if (row) callback(row)
}
const openNativeOperation = (id:number) => {
  activeOperationId.value=id
  showBatchLegacyOperations.value=false
  void nextTick(()=>document.querySelector('.table-wrap.orders')?.scrollIntoView({block:'start',behavior:'smooth'}))
}
const domesticPager = computed(()=>({
  current_page:props.rows.current_page,
  last_page:props.rows.last_page,
  per_page:props.rows.per_page??50,
  total:props.rows.total,
}))

const trackingSelection = ref<number[]>([])
const trackingBusy = ref(false)
const trackingFeedback = ref('')
const eligibleForTracking = (row:Row) => !!row.tracking_number?.trim() && !['completed','conflict'].includes(row.tracking_sync_status ?? '')
const eligibleForBatchTracking = (row:Row) => row.source_type === 'woocommerce'
  && row.shipment_status === 'shipped' && !!row.tracking_number && !!row.carrier && !!row.shipped_at
  && !['completed','conflict'].includes(row.tracking_sync_status ?? '')
const chooseTracking = (id:number, checked:boolean) => {
  trackingSelection.value = checked ? [...new Set([...trackingSelection.value, id])] : trackingSelection.value.filter(v=>v!==id)
}
const pushTracking = (ids:number[], manualOverride = false) => {
  if (!props.canTrackingSync || !ids.length || trackingBusy.value) return
  trackingBusy.value = true
  trackingFeedback.value = ''
  router.post('/logistics/tracking-outbound/selected', { order_ids:ids, manual_override:manualOverride }, {
    preserveScroll:true,
    onSuccess:(page)=>{
      trackingSelection.value=[]
      if (isDailyProviderView(props.view)) selectedBulkOrderIds.value=[]
      const flash = page.props.flash as {warning?:string;error?:string;success?:string}|undefined
      trackingFeedback.value = flash?.warning || flash?.error || flash?.success || '同步请求已处理，请核对官网同步状态'
      if (isDailyProviderView(props.view)) publishGlobalFeedback({
        state:flash?.error?'ERROR':flash?.warning?'BLOCKED':'SUCCESS',
        title:'官网物流同步请求已处理',message:trackingFeedback.value,
        next:'核对最新官网同步状态；不要重复提交同一单',
      })
    },
    onError:(errors)=>{
      trackingFeedback.value=Object.values(errors).join('；')||'官网同步请求验证未通过'
      if (isDailyProviderView(props.view)) publishGlobalFeedback({
        state:'ERROR',title:'官网物流同步请求失败',message:trackingFeedback.value,
      })
    },
    onFinish:()=>{trackingBusy.value=false},
  })
}
const trackingLabel = (status:string|null|undefined) => status==='completed'?'已同步':status==='failed'?'失败':status==='retry_wait'?'待重试':status==='processing'?'同步中':status==='partial'?'部分同步':status==='conflict'?'冲突待核对':'待同步'
// Saving a Tracking draft does not ship. Physical dispatch requires explicit confirmation.
const inlineTracking = ref<Record<number,string>>({})
const inlineBusy = ref<Record<number,boolean>>({})
const inlineErrors = ref<Record<number,string>>({})
const inlineSaved = ref<Record<number,string>>({})
const trackingDraft = (row:Row):string => inlineTracking.value[row.order_id] ??= row.tracking_number ?? ''
const canQuickShip = (row:Row):boolean => props.canOperate && row.review_status === 'approved'
  && row.shipment_status === 'pending'
type CarrierDraft = {carrier:string;service:string}
const carrierDrafts = ref<Record<number,CarrierDraft>>({})
const carrierBusy = ref<Record<number,boolean>>({})
const carrierErrors = ref<Record<number,string>>({})
const carrierSaved = ref<Record<number,string>>({})
const confirmedCarrier = (row:Row):CarrierDraft => ({carrier:row.carrier ?? '',service:row.service ?? ''})
const carrierDraft = (row:Row):CarrierDraft => carrierDrafts.value[row.order_id] ??= confirmedCarrier(row)
const carrierChanged = (row:Row):boolean => {
  const saved=confirmedCarrier(row),draft=carrierDraft(row)
  return draft.carrier!==saved.carrier || draft.service!==saved.service
}
const chooseCarrier = (row:Row,event:Event) => {
  carrierDrafts.value[row.order_id]={carrier:(event.target as HTMLSelectElement).value,service:''}
  carrierErrors.value[row.order_id]=''
  carrierSaved.value[row.order_id]=''
}
const chooseService = (row:Row,event:Event) => {
  carrierDraft(row).service=(event.target as HTMLSelectElement).value
  carrierErrors.value[row.order_id]=''
  carrierSaved.value[row.order_id]=''
}
const serviceOptions = (row:Row) => props.carrierOptions.find(c=>c.value===carrierDraft(row).carrier)?.services ?? []
const carrierReady = (row:Row) => row.carrier_ready === true && !carrierChanged(row)
const saveCarrier = (row:Row) => {
  const draft=carrierDraft(row)
  if (!canQuickShip(row) || carrierBusy.value[row.order_id] || !draft.carrier || !draft.service || !carrierChanged(row)) return
  carrierBusy.value[row.order_id]=true
  carrierErrors.value[row.order_id]=''
  router.post('/logistics/china/orders/' + row.order_id + '/carrier-service', {...draft}, {
    preserveScroll:true,preserveState:true,
    onSuccess:()=>{delete carrierDrafts.value[row.order_id];carrierSaved.value[row.order_id]='已人工确认'},
    onError:(errors)=>{carrierErrors.value[row.order_id]=errors.carrier ?? errors.service ?? '物流商/服务保存失败'},
    onFinish:()=>{carrierBusy.value[row.order_id]=false},
  })
}
const trackingTextChanged = (row:Row) => trackingDraft(row).trim() !== (row.tracking_number ?? '')
const inlineTrackingInput = (row:Row, event:Event) => {
  inlineTracking.value[row.order_id] = (event.target as HTMLInputElement).value
  inlineErrors.value[row.order_id] = ''
  inlineSaved.value[row.order_id] = ''
}
const saveInlineTracking = (row:Row) => {
  if (!canQuickShip(row) || inlineBusy.value[row.order_id] || !trackingTextChanged(row)) return
  inlineBusy.value[row.order_id] = true
  inlineErrors.value[row.order_id] = ''
  const tracking = trackingDraft(row).trim()
  router.post('/logistics/china/orders/' + row.order_id + '/tracking-draft', {tracking_number:tracking || null}, {
    preserveScroll:true, preserveState:true,
    onSuccess:()=>{ inlineSaved.value[row.order_id] = tracking ? '已暂存' : '已清空' },
    onError:(errors)=>{ inlineErrors.value[row.order_id] = errors.tracking_number ?? '保存失败，请核对单号和审核状态。' },
    onFinish:()=>{ inlineBusy.value[row.order_id] = false },
  })
}
const confirmInlineShip = (row:Row) => {
  if (!canQuickShip(row) || inlineBusy.value[row.order_id]) return
  const tracking = trackingDraft(row).trim()
  if (!carrierReady(row)) { inlineErrors.value[row.order_id] = '请先人工确认物流商及服务类型。'; return }
  if (!tracking) { inlineErrors.value[row.order_id] = '请先填写真实发货单号。'; return }
  inlineBusy.value[row.order_id] = true
  inlineErrors.value[row.order_id] = ''
  router.post('/logistics/china/orders/' + row.order_id + '/quick-ship', {tracking_number:tracking}, {
    preserveScroll:true, preserveState:true,
    onError:(errors)=>{ inlineErrors.value[row.order_id] = errors.tracking_number ?? '确认发货失败，请核对审核状态。' },
    onFinish:()=>{ inlineBusy.value[row.order_id] = false },
  })
}
const uploadInput = ref<HTMLInputElement|null>(null)
const uploadBusy = ref(false)
const applyBusy = ref(false)
const tabs = [
  ['review','待审核'], ['stockout','缺货单'], ['pending','待发货'], ['today','今日订单'],
  ['today-4px','今日速达'], ['today-weship','今日纬狮'], ['exceptions','异常订单'],
  ['4px','速达订单'], ['weship','纬狮订单'],
] as const
const previewRows = computed(() => props.trackingImportPreview?.rows.slice(0, 30) ?? [])
const itemText = (row:Row) => row.items.map(item => (item.sku ?? '无SKU') + ' × ' + item.quantity).join(' / ') || '—'
const europeanCountries = new Set(['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','GB','UK','NO','CH','IS','LI','AD','MC','SM','VA','AL','BA','ME','MK','RS','MD','UA','TR'])
const barcodePreview = ref<{orderId:number; items:{sku:string;quantity:number;barcode_69:string}[]}|null>(null)
const barcodeError = ref('')
const barcodeErrorOrderId = ref<number|null>(null)
const barcodeLoading = ref(false)
const showBarcode = async (row:Row) => {
  barcodePreview.value = null
  barcodeError.value = ''
  barcodeErrorOrderId.value = row.order_id
  barcodeLoading.value = true
  try {
    const response = await fetch('/logistics/china/orders/' + row.order_id + '/barcode-69', { headers:{Accept:'application/json'} })
    const data = await response.json()
    if (!response.ok) throw new Error(data.message || '条码不存在或订单不符合条件')
    barcodePreview.value = { orderId:row.order_id, items:data.items }
  } catch (error) {
    barcodeError.value = error instanceof Error ? error.message : '无法读取69条形码'
  } finally { barcodeLoading.value = false }
}

const switchView = (view:string) => {
  activeOperationId.value=null
  showBatchLegacyOperations.value=false
  // Never opt an employee into unreviewed carrier/tracking/dispatch Grid
  // just because they switched from the approved review view.
  const mode = view === props.view
    ? (props.globalGridPilotEnabled ? '1' : '0')
    : (['review','stockout','pending','today','today-4px'].includes(view) ? '1' : '0')
  router.visit('/logistics/china?view=' + encodeURIComponent(view)
    + '&global_grid=' + mode
    + (view === props.view && mode === '1' && domesticGridSearch.value
      ? '&search='+encodeURIComponent(domesticGridSearch.value) : ''), {
    preserveScroll:true,preserveState:true,
  })
}
const reviewBusy = ref(false)
const reviewError = ref('')
const rejectTarget = ref<Row|null>(null)
const rejectReason = ref('')
const decide = (row:Row, action:'approve'|'stockout') => {
  if (reviewBusy.value || !props.canReview) return
  reviewBusy.value = true
  router.post('/logistics/china/orders/' + row.order_id + '/' + action, {}, {
    preserveScroll:true, preserveState:true, onFinish:()=>{reviewBusy.value=false},
  })
}
const restock = (row:Row) => {
  if (reviewBusy.value || !props.canReview || row.review_status !== 'stockout' || row.shipment_status !== 'pending') return
  reviewBusy.value = true
  router.post('/logistics/china/orders/' + row.order_id + '/restock', {}, {
    preserveScroll:false,
    onFinish:()=>{ reviewBusy.value=false },
  })
}
const reopen = (row:Row) => router.post('/logistics/china/orders/' + row.order_id + '/reopen-review', {}, { preserveScroll:true, preserveState:true })
const openReject = (row:Row) => { if (!props.canReview || reviewBusy.value) return; rejectTarget.value=row; rejectReason.value=''; reviewError.value='' }
const rejectInline = (row:Row, reason:string) => {
  if (!props.canReview || reviewBusy.value || reason.trim().length<3) return
  reviewBusy.value=true
  router.post('/logistics/china/orders/' + row.order_id + '/reject', {reason:reason.trim()}, {
    preserveScroll:true, preserveState:true,
    onError:(errors)=>{reviewError.value=errors.reason??'驳回失败，请核对订单状态。'},
    onFinish:()=>{reviewBusy.value=false},
  })
}

const submitReject = () => {
  if (!rejectTarget.value || reviewBusy.value || !props.canReview) return
  if (rejectReason.value.trim().length<3) { reviewError.value='请填写至少 3 个字的驳回原因。'; return }
  reviewBusy.value = true
  router.post('/logistics/china/orders/' + rejectTarget.value.order_id + '/reject',
    {reason:rejectReason.value.trim()}, {
      preserveScroll:true, preserveState:true,
      onSuccess:()=>{rejectTarget.value=null;rejectReason.value=''},
      onError:(errors)=>{reviewError.value=errors.reason??'驳回失败，请核对订单状态。'},
      onFinish:()=>{reviewBusy.value=false},
    })
}
const applyGridBatch = async (payload:{action:string;reason:string}) => {
  if (bulkBusy.value || !selectedBulkOrderIds.value.length
    || !bulkOptions.value.some(option=>option.value===payload.action)
    || (payload.action==='reject' && payload.reason.trim().length<3)) return
  if (payload.action==='sync-tracking') {
    const eligible=props.rows.data.filter(row=>selectedBulkOrderIds.value.includes(row.order_id)
      && eligibleForBatchTracking(row)).map(row=>row.order_id)
    if (eligible.length) pushTracking(eligible)
    return
  }
  const ids=[...selectedBulkOrderIds.value]
  bulkBusy.value=true
  try {
    const archive=payload.action==='archive'
    const result=await importPost(archive?'/orders/archive/batch':'/logistics/china/batch-review',
      JSON.stringify({order_ids:ids,...(!archive?{action:payload.action,reason:payload.reason}:{})}),true)
    const results=(result.results??[]) as {order_id:number;ok:boolean;message:string}[]
    const success=results.filter(row=>row.ok)
    const errors=results.filter(row=>!row.ok)
    selectedBulkOrderIds.value=errors.map(row=>row.order_id)
    publishGlobalFeedback({
      state:errors.length?'BLOCKED':'SUCCESS',
      title:'批量操作：成功 '+success.length+' 条，失败 '+errors.length+' 条',
      message:errors.length?errors.slice(0,8).map(row=>row.order_id+'：'+row.message).join('；'):'按原业务规则保存。',
    })
    router.reload({only:['rows','viewCounts']})
  } catch (error) {
    publishGlobalFeedback({state:'ERROR',title:'批量操作未完成',
      message:error instanceof Error?error.message:'请求失败，请刷新核对后再试。'})
  } finally {bulkBusy.value=false}
}
const chooseUpload = () => {
  if (!props.canOperate || uploadBusy.value || applyBusy.value) return
  uploadInput.value?.click()
}
const importPost = async (url:string, body:BodyInit, isJson = false):Promise<Record<string,any>> => {
  const response = await fetch(url, {
    method:'POST', credentials:'same-origin', cache:'no-store',
    headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest',...currentCsrfHeaders(),
      ...(isJson ? {'Content-Type':'application/json'} : {})},
    body,
  })
  let data:Record<string,any> = {}
  try { data = await response.json() } catch { /* 500 HTML / session expiration */ }
  if (!response.ok) {
    // Never retry a write automatically: the session may have rotated and
    // we must not duplicate a partially completed batch operation.
    if (response.status === 419) {
      throw new Error('登录安全令牌已过期或会话已更新。本次请求未通过校验，请刷新页面后重试。')
    }
    const requestId = response.headers.get('X-ERP-Request-ID')
    const validation = response.status < 500
      ? Object.values(data.errors ?? {}).flat().find(value => typeof value === 'string') : null
    const reason = response.status < 500 && (validation || data.message)
      ? String(validation || data.message) : (response.status >= 500 ? '服务器处理失败' : '请求未完成')
    throw new Error(reason + (requestId ? '（错误编号 '+requestId+'）' : ''))
  }
  if (response.redirected && new URL(response.url).pathname === '/login') {
    throw new Error('登录已过期，请重新登录。')
  }
  return data
}
const importError = (error:unknown, apply:boolean) => {
  publishGlobalFeedback({
    state:'ERROR', title:apply ? '批量登记结果未确认' : '上传失败',
    message:(error instanceof Error ? error.message : '请求未完成') +
      (apply ? '；可能存在部分已发货订单，请先刷新核对，切勿直接重复提交。' : '；请检查文件并重试。'),
    next:apply ? '刷新待发货列表，核对真实发货与官网同步状态' : '检查表格格式后重新上传',
  })
}
const previewFile = async (event:Event) => {
  const input=event.target as HTMLInputElement
  const file=input.files?.[0]; if(!file || uploadBusy.value) return
  uploadBusy.value=true
  const data=new FormData(); data.append('file',file)
  try {
    const result=await importPost('/logistics/china/tracking-import/preview',data)
    router.reload({ only:['trackingImportPreview','trackingImportProvider','rows','viewCounts'] })
    publishGlobalFeedback({state:'SUCCESS',title:'已生成回传预览',
      message:String(result.message ?? '请先核对可应用、重复与阻断订单。'),next:'确认可应用订单，再点击批量登记'})
  } catch (error) { importError(error,false) }
  finally { uploadBusy.value=false; input.value='' }
}
const applyPreview = async () => {
  if(!props.trackingImportPreview || !props.trackingImportProvider || applyBusy.value) return
  applyBusy.value=true
  const url=props.trackingImportProvider==='generic'
    ? '/logistics/china/pending-batch/tracking-import/apply'
    : '/logistics/china/' + props.trackingImportProvider + '/tracking-import/apply'
  try {
    const result=await importPost(url,JSON.stringify({token:props.trackingImportPreview.token}),true)
    router.reload({ only:['trackingImportPreview','trackingImportProvider','rows','viewCounts'] })
    const failed=Number(result.failed_count ?? 0)
    const queued=Number(result.sync_pending_count ?? 0)
    const queueFailed=Number(result.sync_handoff_failed_count ?? 0)
    publishGlobalFeedback({
      state:failed || queueFailed ? 'BLOCKED':'SUCCESS',
      title:failed || queueFailed ? '部分订单需要人工核对' : 'ERP 批量发货登记完成',
      message:'ERP 登记成功 '+(result.applied_count ?? 0)+' 单；重复跳过 '+(result.noop_count ?? 0)+
        ' 单；阻断 '+(result.blocked_count ?? 0)+' 单；登记失败 '+failed+' 单。'+
        (queued ? '其中 '+queued+' 单官网物流已登记待同步，由同步模块每 30 分钟处理一次，尚不代表官网已同步。' : '')+
        (queueFailed ? '另外 '+queueFailed+' 单未能登记待同步任务，ERP 发货登记仍然有效，请联系管理员核对。' : ''),
      next:failed || queueFailed ? '核对实际发货与官网同步状态，勿直接重复提交' : '在列表查看官网同步最终状态',
    })
  } catch (error) { importError(error,true) }
  finally { applyBusy.value=false }
}
const statusLabel = (status:string) => status==='apply' ? '可应用' : status==='noop' ? '重复跳过' : status==='applied' ? '已应用' : '阻断'
const previewLabel = computed(() => props.trackingImportProvider==='generic' ? '待发货批量登记' : props.trackingImportProvider ? props.providerStatus[props.trackingImportProvider].label : '')
</script>

<template>
  <Head title="国内发货 · BYL ERP" />
  <AuthenticatedLayout title="国内发货" :breadcrumbs="['BYL ERP','物流','国内发货']">
    <section class="domestic-page domestic-compact-workspace">
      <CompactWorkspaceToolbar>
        <template #navigation>
          <nav class="tabs" aria-label="国内发货业务视图">
            <button v-for="([key,label]) in tabs" :key="key" type="button" :class="{active:view===key}" @click="switchView(key)">
              {{ label }} <span>{{ viewCounts[key] ?? 0 }}</span>
            </button>
          </nav>
        </template>
        <template #actions>
          <div class="domestic-head-actions">
            <a class="secondary" href="/logistics/wait-stock">待分配 →</a>
            <button v-if="globalGridPilotAvailable" type="button" class="secondary"
              data-testid="domestic-global-grid-toggle" @click="gridNavigation.toggle()">
              {{ globalGridPilotEnabled ? '返回原表' : '切换新版表' }}
            </button>
          </div>
        </template>
      </CompactWorkspaceToolbar>

      <section v-if="view==='pending'" class="pending-batch-card">
        <div>
          <strong>待发货 · 批量登记</strong>
        </div>
        <div class="actions">
          <a class="primary" href="/logistics/china/pending-batch/export">下载待发货批量表</a>
          <button v-if="canOperate" type="button" class="secondary" :disabled="uploadBusy || applyBusy" @click="chooseUpload">⏫上传物流单号</button>
        </div>
      </section>

      <section v-if="view==='today'" class="today-shortcuts" aria-label="今日订单操作">
        <article class="today-shortcut today-shortcut-order">
          <a href="/logistics/china/today/export" data-testid="download-today-orders" title="下载这批订单 XLSX">⬇️这批订单</a>
        </article>
        <article class="today-shortcut">
          <a href="/logistics/china/export/4px" data-testid="download-today-4px">⏬今日速达</a>

        </article>
        <article class="today-shortcut">
          <a v-if="providerStatus.weship.export_ready" href="/logistics/china/export/weship" data-testid="download-today-weship">⏬今日纬狮</a>
          <span v-else class="today-shortcut-disabled">⏬今日纬狮</span>

        </article>
      </section>

      <section v-if="view==='today-4px' || view==='today-weship'" class="provider-grid single">
        <article v-if="view!=='today-weship'" class="provider-card">
          <div><strong>速达 / 4PX</strong></div>
          <div class="actions">
            <a class="primary" href="/logistics/china/export/4px">下载速达下单表</a>

          </div>
        </article>
        <article v-if="view!=='today-4px'" class="provider-card">
          <div><strong>纬狮 / Weship</strong><small v-if="!providerStatus.weship.export_ready" role="alert">{{ providerStatus.weship.reason }}</small></div>
          <div class="actions">
            <a v-if="providerStatus.weship.export_ready" class="primary" href="/logistics/china/export/weship">下载纬狮下单表</a>

          </div>
        </article>
      </section>
      <input v-if="view==='pending' && canOperate" ref="uploadInput" class="hidden" type="file" accept=".xls,.xlsx" aria-label="上传物流单号表" @change="previewFile" />

      <section v-if="view==='pending' && trackingImportPreview && trackingImportProvider" class="preview">
        <div class="preview-head">
          <div><strong>{{ previewLabel }} · 回传预览</strong><span>可应用 {{ trackingImportPreview.apply_count }} · 重复 {{ trackingImportPreview.noop_count }} · 阻断 {{ trackingImportPreview.blocked_count }}</span></div>
          <button v-if="canOperate && trackingImportPreview.apply_count>0" class="primary" type="button" :disabled="applyBusy || uploadBusy" @click="applyPreview">{{ applyBusy ? '登记中…' : '确认发货（仅可应用订单）' }}</button>
        </div>
        <p>阻断订单不参与发货。可应用订单逐单登记 ERP Shipment；官网物流由同步模块每 30 分钟处理一次，最终状态以官网回读为准。</p>
        <div class="table-wrap">
        <div v-if="trackingFeedback" class="tracking-action-feedback" role="status">{{ trackingFeedback }}</div>
          <table><thead><tr><th>行</th><th>订单号</th><th>Tracking</th><th>Carrier</th><th>Provider 状态</th><th>结果</th><th>说明</th></tr></thead>
          <tbody><tr v-for="row in previewRows" :key="row.row + '-' + row.order_number">
            <td>{{ row.row }}</td><td>{{ row.order_number }}</td><td>{{ row.tracking_number || '—' }}</td><td>{{ row.carrier }}</td><td>{{ row.provider_status ?? '—' }}</td>
            <td><b :class="'status-' + row.status">{{ statusLabel(row.status) }}</b></td><td>{{ row.message || '—' }}</td>
          </tr></tbody></table>
        </div>
      </section>

      <div v-if="view!=='stockout' && canTrackingSync && trackingSelection.length" class="tracking-selection-bar">
        已选 {{ trackingSelection.length }} 项
        <button type="button" class="primary" :disabled="trackingBusy" @click="pushTracking(trackingSelection)">批量同步官网物流</button>
        <button type="button" class="secondary" @click="trackingSelection=[]">清除选择</button>
      </div>
      <div v-if="globalGridPilotEnabled" class="domestic-grid-main">
        <GlobalGrid :key="'domestic-grid-'+view"
          :namespace="'grid.logistics.domestic.'+view+'.global.v1'"
          :legacy-preference-namespaces="['grid.logistics.domestic.global.v1']"
          :title="'国内发货 · '+(tabs.find(tab=>tab[0]===view)?.[1]??view)"
          :columns="domesticGridColumns" :rows="domesticGridRows" :required-column-ids="['order','actions']"
          :enable-selection="true" :selected-order-ids="selectedBulkOrderIds"
          :page="domesticPager" :search="gridSearch??''"
          search-placeholder="搜索当前视图全部订单号 / 收件人..."
          @search-change="searchDomesticGrid($event)"
          @selection-change="onBulkSelectionChanged($event)"
          @page-change="gridNavigation.page($event)"
          @page-size-change="gridNavigation.pageSize($event)">
          <template #footer-left>
            <GridBatchFooterControls :selected-count="selectedBulkOrderIds.length" :options="bulkOptions"
              :busy="bulkBusy" @apply="applyGridBatch" />
          </template>
        </GlobalGrid>
        <div v-if="view!=='review' && view!=='stockout' && !isDailyProviderView(view)" class="domestic-grid-legacy-tools">
          <button type="button" class="secondary" data-testid="domestic-native-batch-toggle"
            @click="showBatchLegacyOperations=!showBatchLegacyOperations;activeOperationId=null">
            {{ showBatchLegacyOperations?'收起原批量操作':'使用原批量操作' }}
          </button>
          <button v-if="activeOperationId!==null && !showBatchLegacyOperations" type="button" class="secondary"
            @click="activeOperationId=null">收起单笔操作</button>
        </div>
      </div>
      <div v-if="!globalGridPilotEnabled || (view!=='review' && view!=='stockout' && (activeOperationId!==null || showBatchLegacyOperations))" class="table-wrap orders">
        <table>
          <thead><tr><th v-if="canTrackingSync && view!=='stockout'">选择</th><th>#</th><th>{{ view==='stockout' ? '操作' : '审核' }}</th><th>ERP 订单</th><th>订单状态</th><th v-if="view==='review'">成交日期</th><th v-if="view==='review'">SKU</th><th v-if="view==='review'">数量</th><th v-if="view==='review'">金额</th><th>导出状态</th><th>承运商单号</th><th>Carrier</th><th>服务</th><th>Tracking</th><th>收件人</th><th>电话</th><th>国家/地区</th><th v-if="view==='pending'">SKU</th><th v-if="view==='pending'">数量</th><th v-if="view==='pending'">金额</th><th v-if="view!=='review' && view!=='pending'">SKU / 数量</th><th>官网同步</th><th v-if="canTrackingSync && view!=='stockout'">操作</th></tr></thead>
          <tbody>
            <tr v-for="row in nativeOperationRows" :key="row.order_id">
              <td v-if="canTrackingSync && view!=='stockout'"><input v-if="eligibleForBatchTracking(row)" type="checkbox"
                :aria-label="'选择同步 '+(row.erp_order_id??row.order_id)" :checked="trackingSelection.includes(row.order_id)"
                @change="chooseTracking(row.order_id, ($event.target as HTMLInputElement).checked)" /></td>
              <td>{{ row.line }}</td>
              <td>
                <div v-if="view==='stockout' && canReview && row.review_status==='stockout' && row.shipment_status==='pending'" class="review-actions">
                  <button class="approve" type="button" :disabled="reviewBusy" @click="restock(row)">已到货-可发货</button>
                </div>
                <div v-else-if="canReview && view==='review' && row.shipment_status==='pending'" class="review-actions">
                  <button class="approve" type="button" :disabled="reviewBusy" @click="decide(row,'approve')">通过</button>
                  <button v-if="row.review_status!=='stockout'" class="stockout-btn" type="button" :disabled="reviewBusy" @click="decide(row,'stockout')">缺货</button>
                  <button class="reject-btn" type="button" :disabled="reviewBusy" @click="openReject(row)">驳回</button>
                </div>
                <div v-else-if="row.review_status==='approved'" class="approved">已通过 <button v-if="canReview && row.shipment_status==='pending'" type="button" @click="reopen(row)">重新审核</button></div>
                <span v-else :class="row.review_status==='conflict' ? 'danger' : ''">{{ row.review_status==='conflict' ? '状态待对账' : row.review_status==='stockout' ? '缺货' : '待审核' }}</span>
              </td>
              <td><a :href="row.order_url">{{ row.erp_order_id ?? '—' }}</a></td><td><OrderWorkflowStatusPill v-if="row.workflow_status_projection" :order-id="row.order_id" :projection="row.workflow_status_projection" :can-edit="false" /><span v-else>—</span></td>
              <td v-if="view==='review'" :title="row.paid_date_source ?? ''">{{ row.paid_date ?? '—' }}</td><td v-if="view==='review'"><div v-for="(item,index) in row.items" :key="item.sku+'-'+index">{{ item.sku ?? '无SKU' }}</div><button v-if="europeanCountries.has((row.country ?? '').toUpperCase())" type="button" class="secondary" :disabled="barcodeLoading" @click="showBarcode(row)">69条码</button><div v-if="barcodePreview?.orderId===row.order_id" class="barcode-preview"><div v-for="item in barcodePreview.items" :key="item.sku">{{ item.sku }} × {{ item.quantity }}：{{ item.barcode_69 }}</div></div><small v-if="barcodeError && barcodeErrorOrderId===row.order_id && !barcodeLoading" class="danger">{{ barcodeError }}</small></td><td v-if="view==='review'"><div v-for="(item,index) in row.items" :key="item.sku+'-'+index">{{ item.quantity }}</div></td><td v-if="view==='review'" :title="row.amount_source ?? ''">{{ row.amount !== null && row.amount !== undefined ? `${row.amount} ${row.amount_currency ?? 'USD'}` : '—' }}</td>
              <td><span v-if="row.export_blockers.length" class="danger">{{ row.export_blockers.join(' / ') }}</span><span v-else class="ready">可导出</span></td>
              <td>{{ row.provider_reference ?? '—' }}</td>
              <td class="domestic-carrier-cell">
                <select v-if="view==='pending' && canQuickShip(row)" :value="carrierDraft(row).carrier"
                  :aria-label="'选择物流商 '+(row.erp_order_id??row.order_id)" :disabled="!!carrierBusy[row.order_id]"
                  @change="chooseCarrier(row,$event)">
                  <option value="">请选择物流商</option>
                  <option v-for="option in carrierOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <span v-else>{{ row.shipment_status==='pending' && row.carrier_selection_source!=='manual' ? '—' : (row.carrier ?? '—') }}</span>
              </td>
              <td class="domestic-service-cell">
                <template v-if="view==='pending' && canQuickShip(row)">
                  <div class="domestic-service-entry">
                    <select :value="carrierDraft(row).service" :aria-label="'选择服务类型 '+(row.erp_order_id??row.order_id)"
                      :disabled="!!carrierBusy[row.order_id] || !carrierDraft(row).carrier" @change="chooseService(row,$event)">
                      <option value="">请选择服务类型</option>
                      <option v-for="service in serviceOptions(row)" :key="service.value" :value="service.value">{{ carrierDraft(row).carrier === '4PX' ? (service.name_zh?.trim() || service.value) : service.label }}</option>
                    </select>
                    <button type="button" class="secondary" :disabled="!!carrierBusy[row.order_id] || !carrierDraft(row).carrier || !carrierDraft(row).service || !carrierChanged(row)"
                      @click="saveCarrier(row)">确认</button>
                  </div>
                  <small v-if="carrierErrors[row.order_id]" class="domestic-tracking-error">{{ carrierErrors[row.order_id] }}</small>
                  <small v-else-if="carrierSaved[row.order_id]" class="domestic-tracking-note">{{ carrierSaved[row.order_id] }}</small>
                </template>
                <span v-else>{{ row.shipment_status==='pending' && row.carrier_selection_source!=='manual' ? '—' : (row.service ?? '—') }}</span>
              </td>
              <td class="domestic-tracking-cell">
                <div v-if="canQuickShip(row)" class="domestic-tracking-entry">
                  <input :value="trackingDraft(row)" :aria-label="'填写发货单号 '+(row.erp_order_id ?? row.order_id)"
                    type="text" maxlength="128" class="domestic-tracking-input" placeholder="填写发货单号"
                    :disabled="!!inlineBusy[row.order_id]"
                    @input="inlineTrackingInput(row,$event)"
                    @blur="saveInlineTracking(row)" @keyup.enter="($event.target as HTMLInputElement).blur()" />
                  <button type="button" class="secondary" :disabled="!!inlineBusy[row.order_id] || !trackingTextChanged(row)"
                    @mousedown.prevent @click="saveInlineTracking(row)">暂存</button>
                  <button type="button" class="primary" :disabled="!!inlineBusy[row.order_id] || !trackingDraft(row).trim() || !carrierReady(row)"
                    @mousedown.prevent @click="confirmInlineShip(row)">确认发货</button>
                </div>
                <span v-else>{{ row.tracking_number || (row.review_status==='pending' ? '待审核' : row.review_status==='stockout' ? '缺货' : row.review_status==='conflict' ? '状态待对账' : '—') }}</span>
                <small v-if="inlineErrors[row.order_id]" class="domestic-tracking-error">{{ inlineErrors[row.order_id] }}</small>
                <small v-else-if="inlineBusy[row.order_id]" class="domestic-tracking-note">处理中…</small>
                <small v-else-if="inlineSaved[row.order_id]" class="domestic-tracking-note">{{ inlineSaved[row.order_id] }} · 尚未发货</small>
              </td>
              <td>{{ row.recipient_name ?? '—' }}</td><td :class="{danger:!row.phone}">{{ row.phone || '缺电话' }}</td>
              <td>{{ [row.country,row.state,row.city].filter(Boolean).join(' / ') || '—' }}</td><td v-if="view==='pending'"><div v-for="(item,index) in row.items" :key="item.sku+'-'+index">{{ item.sku ?? '无SKU' }}</div><button v-if="europeanCountries.has((row.country ?? '').toUpperCase())" type="button" class="secondary" :disabled="barcodeLoading" @click="showBarcode(row)">69条码</button><div v-if="barcodePreview?.orderId===row.order_id" class="barcode-preview"><div v-for="item in barcodePreview.items" :key="item.sku">{{ item.sku }} × {{ item.quantity }}：{{ item.barcode_69 }}</div></div><small v-if="barcodeError && barcodeErrorOrderId===row.order_id && !barcodeLoading" class="danger">{{ barcodeError }}</small></td><td v-if="view==='pending'"><div v-for="(item,index) in row.items" :key="item.sku+'-'+index">{{ item.quantity }}</div></td><td v-if="view==='pending'" :title="row.amount_source ?? ''">{{ row.amount !== null && row.amount !== undefined ? `${row.amount} ${row.amount_currency ?? 'USD'}` : '—' }}</td>
              <td v-if="view!=='review' && view!=='pending'">{{ itemText(row) }} <button v-if="view!=='stockout' && europeanCountries.has((row.country ?? '').toUpperCase())" type="button" class="secondary" :disabled="barcodeLoading" @click="showBarcode(row)">69条码</button><div v-if="barcodePreview?.orderId===row.order_id" class="barcode-preview"><div v-for="item in barcodePreview.items" :key="item.sku">{{ item.sku }} × {{ item.quantity }}：{{ item.barcode_69 }}</div></div><small v-if="barcodeError && barcodeErrorOrderId===row.order_id && !barcodeLoading" class="danger">{{ barcodeError }}</small></td>
              <td><span v-if="row.source_type==='woocommerce'" class="sync-status" :title="[row.tracking_sync_reason, row.tracking_sync_verified_at ? '最近官网验证：' + new Date(row.tracking_sync_verified_at).toLocaleString('zh-CN', { hour12:false }) : '尚无官网验证记录'].filter(Boolean).join('；')" :class="row.tracking_sync_status??'pending'">{{ trackingLabel(row.tracking_sync_status) }}</span><span v-else>—</span><small v-if="row.tracking_sync_reason" class="sync-failure">{{ row.tracking_sync_reason }}</small></td>
              <td v-if="canTrackingSync && view!=='stockout'"><button v-if="eligibleForTracking(row)" type="button" class="secondary"
                :disabled="trackingBusy" @click="pushTracking([row.order_id], true)">{{ (['failed','retry_wait'].includes(row.tracking_sync_status ?? '') || !!row.tracking_sync_reason) ? '重新同步' : '同步官网物流' }}</button><span v-else>—</span></td>
            </tr>
            <tr v-if="!rows.data.length"><td :colspan="(view==='review'?17:view==='pending'?16:14)+(canTrackingSync && view!=='stockout'?2:0)" class="empty">当前视图暂无订单</td></tr>
          </tbody>
        </table>
      </div>
      <footer>共 {{ rows.total }} 条</footer>
      <CompactWorkspaceHelp>
        <span>FACTORY · 中国工厂。Vita 分仓 → Kaven / 运营确认有货 → Liang 发货 → 下载承运商文件 → 回传 Tracking → Preview → Apply。</span>
        <span>生产连续性：正常订单继续发货；缺电话 / 单号冲突 / Tracking 冲突只阻断对应订单，不阻断整批。单个异常订单不会阻断其他正常订单；以 ERP 最新状态及官网同步回读为准。</span>
        <span v-if="view==='pending'">支持速达、纬狮和 ERP 批量表（自动识别格式）。先上传预览，再确认发货；不改变承运商下单模板。</span>
        <span v-if="view==='today-4px'">新版 27 列模板 · 所有订单按现有数据填写 SKU / 69条码（空缺留空） · XLSX 下载 · XLS/XLSX 回传。</span>
        <span v-if="view==='today-weship' && providerStatus.weship.export_ready">{{ providerStatus.weship.note ?? '纬狮下单导出可用' }}</span>
      </CompactWorkspaceHelp>
      <div v-if="rejectTarget" class="review-reject-backdrop" @click.self="rejectTarget=null">
        <section class="review-reject-dialog" role="dialog" aria-modal="true" aria-label="驳回国内订单">
          <h2>驳回到 Vita 待分仓</h2>
          <p>订单 {{ rejectTarget.erp_order_id }} 将撤销当前工厂分配。驳回原因会显示在订单详情及待分仓列表中。</p>
          <div class="reason-shortcuts">
            <button v-for="reason in ['缺少收件信息','收件人地址有误','税号不匹配','其他信息需核对']" :key="reason" type="button" class="secondary" @click="rejectReason=reason">{{ reason }}</button>
          </div>
          <label for="domestic-reject-reason">驳回原因（必填）</label>
          <textarea id="domestic-reject-reason" v-model="rejectReason" rows="3" maxlength="500" placeholder="请写清需要 Vita 修改或重新确认的内容"></textarea>
          <small v-if="reviewError" class="danger">{{ reviewError }}</small>
          <div class="actions">
            <button class="secondary" type="button" :disabled="reviewBusy" @click="rejectTarget=null">取消</button>
            <button class="reject-btn" type="button" :disabled="reviewBusy || rejectReason.trim().length<3" @click="submitReject">{{ reviewBusy ? '处理中…' : '确认驳回' }}</button>
          </div>
        </section>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.domestic-grid-main{margin:8px 0 14px;min-width:0}
.domestic-grid-legacy-tools{display:flex;justify-content:flex-end;gap:8px;margin-top:8px}

.domestic-page{min-width:0;color:#111827}
.domestic-page.domestic-compact-workspace{display:flex;flex-direction:column;min-height:calc(100dvh - 168px);padding:9px 12px}
.domestic-compact-workspace .tabs{display:flex;gap:5px;overflow-x:auto;min-width:0;margin:0;border-bottom:0}
.domestic-compact-workspace .tabs button{border:0;background:transparent;padding:6px 10px;color:#536174;font-weight:700;white-space:nowrap;border-bottom:2px solid transparent}
.domestic-compact-workspace .tabs button.active{color:#0f766e;border-bottom-color:#0f766e}
.domestic-compact-workspace .tabs span{color:#94a3b8;margin-left:3px}
.pending-batch-card{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:7px 0;padding:7px 10px;border:1px solid #b7ded8;border-radius:8px;background:#f2fbf9}
.pending-batch-card>div:first-child{display:flex;flex-direction:column;gap:4px}
.pending-batch-card small{color:#64748b;max-width:760px}
.domestic-head-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex:0 0 auto;white-space:nowrap}
.domestic-carrier-cell select,.domestic-service-cell select{min-width:138px;max-width:215px;height:34px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#1e293b;padding:4px 7px;font-size:12px}
.domestic-service-entry{display:flex;gap:5px;align-items:center}.domestic-service-entry button{min-height:32px;padding:0 9px;white-space:nowrap}
.domestic-tracking-cell{min-width:355px}.domestic-tracking-entry{display:flex;gap:5px;align-items:center}.domestic-tracking-input{min-width:155px;width:175px;height:34px;padding:4px 8px;border:1px solid #cbd5e1;border-radius:6px;background:white;color:#1e293b}.domestic-tracking-entry button{white-space:nowrap;min-height:32px;padding:0 8px}.domestic-tracking-error,.domestic-tracking-note{display:block;font-size:11px;margin-top:4px;white-space:normal}.domestic-tracking-error{color:#b91c1c}.domestic-tracking-note{color:#0f766e}
.tracking-selection-bar{display:flex;align-items:center;gap:10px;margin-bottom:10px;padding:8px 12px;background:#eef6ff;border:1px solid #c9dffc;border-radius:8px;font-size:12px}
button:disabled{opacity:.5;cursor:not-allowed}
.sync-status{font-size:11px;font-weight:700;padding:4px 7px;border-radius:5px;background:#fff1d6;color:#92400e}
.sync-status.completed{background:#dcfce7;color:#166534}.sync-status.failed{background:#fee2e2;color:#991b1b}.sync-status.retry_wait{background:#fef3c7;color:#92400e}.sync-status.processing{background:#dbeafe;color:#1d4ed8}.sync-failure{display:block;max-width:240px;white-space:normal;color:#b91c1c;font-size:10px;margin-top:4px}
.domestic-compact-workspace .today-shortcut{min-height:36px;padding:4px 8px;border-radius:6px}
.domestic-compact-workspace .today-shortcuts{margin:7px 0}
.domestic-compact-workspace .provider-card{padding:8px 10px}
.domestic-compact-workspace :deep(.compact-workspace-help span+span)::before{content:' · '}
.today-shortcuts{display:grid;grid-template-columns:minmax(160px,1fr) minmax(290px,1.4fr) minmax(290px,1.4fr);gap:8px;margin:0 0 10px}.today-shortcut{display:flex;align-items:center;justify-content:space-around;gap:10px;min-width:0;min-height:52px;padding:8px 12px;border:1px solid #dbe4e8;border-radius:9px;background:#fff}.today-shortcut-order{justify-content:flex-start}.today-shortcut a,.today-shortcut button,.today-shortcut span{border:0;background:transparent;color:#0f766e;font-size:14px;font-weight:800;white-space:nowrap;padding:3px 6px;text-decoration:none;cursor:pointer}.today-shortcut a:hover,.today-shortcut button:hover{text-decoration:underline}.today-shortcut a:focus-visible,.today-shortcut button:focus-visible{outline:2px solid #0f8f82;outline-offset:3px;border-radius:4px}.today-shortcut .today-shortcut-disabled{opacity:.5;cursor:default}.provider-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}.provider-grid.single{grid-template-columns:1fr}.provider-card{display:flex;justify-content:space-between;gap:10px;padding:12px;border:1px solid #dbe4e8;border-radius:10px;background:#fff}.provider-card>div:first-child{display:flex;flex-direction:column;gap:4px}.provider-card small{color:#64748b}.actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.primary,.secondary,.approve,.approved button{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 11px;border-radius:7px;font-size:12px;font-weight:800;text-decoration:none}.primary,.approve{border:1px solid #0f8f82;background:#0f8f82;color:#fff}.secondary,.approved button{border:1px solid #b8c6cc;background:#fff;color:#334155}.hidden{display:none}.preview{margin-bottom:12px;padding:12px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc}.preview-head{display:flex;justify-content:space-between;gap:12px}.preview-head>div{display:flex;flex-direction:column}.preview p{color:#64748b;font-size:12px}.table-wrap{overflow:auto;border:1px solid #dfe6ea;border-radius:10px;background:#fff}.table-wrap table{width:max-content;min-width:100%;border-collapse:collapse;font-size:12px}.table-wrap th,.table-wrap td{padding:8px;border-bottom:1px solid #e9eef1;text-align:left;white-space:nowrap}.table-wrap th{background:#f8fafc;color:#475569}.orders table{min-width:1500px}.orders a{color:#0f766e;font-weight:800;text-decoration:none}.approved{display:flex;gap:6px;align-items:center;color:#047857;font-weight:800}.approved button{min-height:26px;padding:0 7px;font-size:10px}.danger{color:#b91c1c;font-weight:800}.ready{color:#047857;font-weight:800}.empty{text-align:center!important;padding:30px!important;color:#94a3b8}.status-apply{color:#047857}.status-noop{color:#64748b}.status-blocked{color:#b91c1c}footer{padding:10px 2px;color:#64748b;font-size:12px}
@media(max-width:900px){.today-shortcuts{grid-template-columns:1fr}.today-shortcut{justify-content:flex-start;gap:22px}}
@media(max-width:800px){.domestic-page.domestic-compact-workspace{padding:8px;min-height:calc(100dvh - 150px)}.pending-batch-card{flex-direction:column;align-items:stretch}.provider-grid{grid-template-columns:1fr}.provider-card{flex-direction:column}.preview-head{flex-direction:column}}

.review-actions{display:flex;align-items:center;gap:5px}.stockout-btn,.reject-btn{border:1px solid #e3ac78;background:#fff9f1;color:#9a3412;border-radius:6px;min-height:30px;padding:0 8px;font-size:12px;font-weight:800}.reject-btn{border-color:#efb2b2;background:#fff;color:#b42318}.review-reject-backdrop{position:fixed;inset:0;z-index:1000;background:rgba(16,24,40,.5);display:flex;align-items:center;justify-content:center;padding:16px}.review-reject-dialog{background:#fff;width:min(480px,100%);border-radius:12px;padding:20px;box-shadow:0 24px 80px rgba(16,24,40,.2);display:grid;gap:12px}.review-reject-dialog h2{margin:0;font-size:19px}.review-reject-dialog p{margin:0;font-size:13px;color:#64748b}.review-reject-dialog label{font-size:12px;font-weight:700}.review-reject-dialog textarea{width:100%;padding:10px;border:1px solid #d4dae1;border-radius:7px;font-size:13px}.review-reject-dialog .actions{justify-content:flex-end}.reason-shortcuts{display:flex;flex-wrap:wrap;gap:6px}
</style>
