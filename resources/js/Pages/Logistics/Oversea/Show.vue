<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import { computed, defineAsyncComponent, nextTick, reactive, ref, watch, onMounted, onUnmounted } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import OrderWorkflowStatusPill from '../../../Components/Orders/OrderWorkflowStatusPill.vue'
import ColumnSettingsHeader from '../../../Components/DataGrid/ColumnSettingsHeader.vue'
import type { ColDef } from 'ag-grid-community'
import GlobalGridCell from '../../../Components/DataGrid/GlobalGridCell.vue'
import DomesticGridActionCell from '../Domestic/DomesticGridActionCell.vue'
import GridBatchFooterControls from '../../../Components/DataGrid/GridBatchFooterControls.vue'
import { currentCsrfHeaders } from '../../../lib/csrfHeaders'
import { gridColumn, type GridListRow } from '../../../Components/DataGrid/useGlobalGridListAdapter'
const GlobalGrid = defineAsyncComponent(() => import('../../../Components/DataGrid/GlobalGrid.vue'))
import { useAccountGridPreferences, type GridColumnDefinition } from '../../../Composables/useAccountGridPreferences'
import { publishGlobalFeedback } from '../../../lib/globalFeedback'
import { resolveWarehousePortal } from '../../../lib/warehousePortal'

type WarehouseOption = { key:string; id:number; code:string; label:string; carrier:string|null; url:string }
type Item = { sku:string|null; quantity:number }
type CarrierServiceOption = { id:number; key:string; value:string; label:string; name_en:string|null; name_zh:string|null }
type CarrierOption = { value:string; label:string; default_service_id:number|null; default_service_value:string|null; services:CarrierServiceOption[] }
type DispatchProjection = {status:string;label:string;detail:string;oms_order_no:string|null;last_checked_at:string|null;failure_code:string|null;can_retry_local_block:boolean}
type OmsSnapshot = {
  state:string; oms_order_no:string|null; oms_status_code:string|null
  oms_status_checked_at:string|null; oms_tracking_no:string|null
  failure_code:string|null; oms_status_failure_code:string|null
}
type WebDraft = {state:string;oms_order_no:string|null;attempt_ref:string;failure_code:string|null}
type ShipOutDispatchState = { attempted:boolean; is_oms_created:boolean; status:string; label:string; provider_order_id:string|null }
type OmsPreflight = {
  order_id:number; blockers:string[]; ready:boolean; payload_hash:string
  oms_warehouse:string; items:Item[]; already_attempted:boolean
}
type OmsStatusResult = {
  read_only:boolean; fresh:boolean; oms_order_no:string; oms_status_code:string|null
  oms_status_label:string; oms_tracking_no:string|null; oms_status_checked_at:string|null
  oms_status_failure_code:string|null; erp_status_unchanged:boolean; woo_status_unchanged:boolean
}
type ShipOutPreflight = {
  order_id:number; ship_date:string; shipping_mode?:'9'|'10'; order_no:string|null; items:{omsSku:string;qty:number}[]
  blockers:string[]; ready:boolean; payload_hash:string|null; already_attempted:boolean
}
type ShipOutStatus = {
  state:string; reason:string; order_no?:string; provider_order_id?:string
  provider_order_status?:number; tracking_number?:string|null; carrier_id?:number|null
  shipped_time_utc?:string|null; read_only:boolean; erp_shipment_unchanged?:boolean
}
type Row = {
  line:number; order_id:number; erp_order_id:string|null; order_url:string|null; order_date?:string|null; recipient_name:string|null; country:string|null
  warehouse_id:number; warehouse_name:string|null; carrier:string|null; service:string|null; carrier_selection_source:string|null; tracking_number:string|null
  progress:string; note:string|null; xlwms?:OmsSnapshot|null; warehouse_dispatch?:DispatchProjection; review_status:'approved'|'pending'|'stockout'; shipment_status:string; workflow_status_projection?:{code:string;zh:string;en:string;color:string;source:string;mutable:boolean}|null; workflow_revision?:number; can_edit_workflow_status?:boolean; source_type?:string; tracking_sync_status?:string|null; tracking_sync_reason?:string|null; tracking_sync_verified_at?:string|null; shipped_at?:string|null; items:Item[]
  web_draft?:WebDraft|null
  shipout_production?:ShipOutDispatchState|null
}
type PageRows = { data:Row[]; current_page:number; last_page:number; per_page:number; total:number; from:number|null; to:number|null; links?:{url:string|null;label:string;active:boolean}[] }
type ColumnKey = 'line'|'order'|'order_date'|'status'|'warehouse'|'carrier'|'service'|'tracking'|'note'|'recipient'|'country'|'items'
const columnDefinitions: GridColumnDefinition<ColumnKey>[] = [
  { key:'line', label:'#', defaultVisible:true },
  { key:'order', label:'订单号', defaultVisible:true },
  { key:'order_date', label:'订单日期', defaultVisible:true },
  { key:'status', label:'统一状态', defaultVisible:true },
  { key:'warehouse', label:'Stock 仓库', defaultVisible:true },
  { key:'carrier', label:'Carrier 承运商', defaultVisible:true },
  { key:'service', label:'运输 / 服务类型', defaultVisible:true },
  { key:'tracking', label:'追踪单号 Tracking No.', defaultVisible:true },
  { key:'note', label:'备注', defaultVisible:true },
  { key:'recipient', label:'收件人', defaultVisible:true },
  { key:'country', label:'国家', defaultVisible:true },
  { key:'items', label:'SKU / 数量', defaultVisible:true },
]
type TrackingImportRow = { row:number; order_id:number|null; order_number:string; tracking_number:string; carrier:string|null; shipped_at:string; shipped_time_source:'provider'|'apply'; provider_order_reference:string|null; provider_service:string|null; provider_status:string|null; status:'apply'|'noop'|'blocked'; message:string }
type TrackingImportPreview = { token:string; rows:TrackingImportRow[]; apply_count:number; noop_count:number; blocked_count:number }
const props = defineProps<{
  warehouseKey:string; warehouse:WarehouseOption|null; warehouseOptions:WarehouseOption[]; view:string
  globalGridEnabled?:boolean; gridSearch?:string; gridSort?:{colId:string;direction:'asc'|'desc'}|null; gridFilters?:Record<string,string>
  shipoutProductionBatchUi?:boolean; shipoutProductionBatchDryRun?:boolean; shipoutProductionBatchPaused?:boolean;
  shipoutProductionLatestBatch?:{id:string;status:string;dry_run:boolean;total:number}|null;
  shipoutProductionUi?:boolean; shipoutProductionReadEnabled?:boolean; shipoutProductionDispatchEnabled?:boolean; shipoutProductionMinOrderId?:number; shipoutProductionOperatorAllowed?:boolean
  workflowStatusOptions?:{code:string;en:string;zh:string;color:string;mutable:boolean}[];
  rows:PageRows; viewCounts:Record<string,number>; canReview:boolean; canOperate:boolean; canTrackingSync?:boolean; shipoutSandboxReadEnabled?:boolean; shipoutSandboxDispatchEnabled?:boolean; shipoutSandboxTrackingApplyEnabled?:boolean; shipoutSandboxAuthorizedOrderNo?:string; shipoutSandboxSpecifiedChannel?:{id:string;label:string}|null; shipoutSandboxOwnLabelEnabled?:boolean; carrierOptions:CarrierOption[]; trackingImportPreview:TrackingImportPreview|null; xlwmsAuthorizedOrderId?:number; xlwmsBatchEnabled?:boolean; xlwmsOmsWebEnabled?:boolean

}>()

const grid = reactive(useAccountGridPreferences<ColumnKey>({
  namespace:'grid.logistics.oversea.workbench.v1',
  definitions:columnDefinitions,
}))
const canEditRow = computed(() => props.canOperate || (props.canReview && props.view === 'review'))
const gridEnabled = computed(() => props.globalGridEnabled === true)
const activeOperationId = ref<number|null>(null)
const gridSearchText = ref(props.gridSearch??'')
watch(() => props.gridSearch, value => {gridSearchText.value=value??''})
watch(() => [props.warehouseKey, props.view, props.rows.current_page], () => {activeOperationId.value=null})
// Only the updated row is changed after save. Navigation and filtering
// still read the canonical server state.
const visibleRows = ref<Row[]>([...props.rows.data])
const visibleTotal = ref(props.rows.total)
const liveViewCounts = reactive<Record<string,number>>({...props.viewCounts})
watch(() => props.rows, next => {
  visibleRows.value=[...next.data]
  visibleTotal.value=next.total
})
watch(() => props.viewCounts, next => Object.assign(liveViewCounts,next))
const inlineSaveBusy = reactive<Record<number,boolean>>({})
const inlineSaveFeedback = reactive<Record<number,string>>({})
const trackingSelection = ref<number[]>([])
const reviewSelection = ref<number[]>([])
const reviewBusy = ref(false)
const bulkBusy = ref(false)
const gridReviewError = ref('')
const trackingBusy = ref(false)
const trackingFeedback = ref('')
const eligibleForTracking = (row:Row) => !!row.tracking_number?.trim() && !['completed','conflict'].includes(row.tracking_sync_status ?? '')
const eligibleForBatchTracking = (row:Row) => row.source_type === 'woocommerce'
  && row.shipment_status === 'shipped' && !!row.tracking_number && !!row.carrier && !!row.shipped_at
  && !['completed','conflict'].includes(row.tracking_sync_status ?? '')
const chooseTracking = (id:number, checked:boolean) => {
  trackingSelection.value=checked?[...new Set([...trackingSelection.value,id])]:trackingSelection.value.filter(v=>v!==id)
}
const pushTracking = (ids:number[], manualOverride = false) => {
  if(!props.canTrackingSync || !ids.length || trackingBusy.value)return
  trackingBusy.value=true
  trackingFeedback.value=''
  router.post('/logistics/tracking-outbound/selected',{order_ids:ids,manual_override:manualOverride},{
    preserveScroll:true,
    onSuccess:(page)=>{
      trackingSelection.value=[]
      const flash = page.props.flash as {warning?:string;error?:string;success?:string}|undefined
      trackingFeedback.value = flash?.warning || flash?.error || flash?.success || '同步请求已处理，请刷新核对官网同步状态'
    },
    onError:(errors)=>{trackingFeedback.value=Object.values(errors).join('；')||'官网同步请求验证未通过'},
    onFinish:()=>{trackingBusy.value=false},
  })
}
const trackingLabel=(status:string|null|undefined)=>status==='completed'?'已同步':status==='failed'?'失败':status==='retry_wait'?'待重试':status==='processing'?'同步中':status==='partial'?'部分同步':status==='conflict'?'冲突待核对':'待同步'
const drafts = reactive<Record<number,{ note:string; carrier:string; service:string; tracking_number:string; outbound_ref:string }>>({})
const trackingFileInput = ref<HTMLInputElement|null>(null)
const trackingPreviewRows = computed(() => props.trackingImportPreview?.rows.slice(0, 20) ?? [])
const canUploadTrackingFile = computed(() => props.warehouseKey !== 'us-3sm')
const trackingFileHint = computed(() => props.warehouseKey === 'us-3sm'
  ? '下载订单文件后，由物流人员在 OMS 人工导入；仓库补充真实 USPS 面单后再发货。'
  : '上传当前仓库真实回传 XLSX；兼容旧 CSV。上传只生成预览，确认后才会标记已发货。')
const rowErrors = reactive<Record<number,string>>({})
// MorePlus uses independent Sandbox and Production V1 providers; never the XLWMS route.
type ShipOutProductionStatus = {
  order_id:number; order_no:string; attempt_status:string; provider_order_id?:string|null
  provider_status?:string|number|null; reconciliation:string; provider_failure_code?:string|null; shipped:false
  tracking_apply:false; read_only:true
  oms_persisted_state?:'pending'|'visible'|'confirmed'|'warehouse_awaiting'|'provider_shipped'|'cancelled'|'unverified'
  oms_checked_at?:string
}
type ShipOutBatchPreview = {
  count:number; overflow:boolean; fingerprint:string; dry_run:boolean
  scope?:'selected'|'all'; scan_count?:number; stockout_count?:number; excluded_count?:number
}
type ShipOutBatchItem = { order_id:number; order_no:string; status:string; result_code:string|null; provider_order_id:string|null }
type ShipOutBatchStatus = { batch_id:string; status:string; dry_run:boolean; total:number; processed:number; items:ShipOutBatchItem[] }
const shipoutBatchSelection = ref<number[]>([])
const shipoutBatchPreviewIds = ref<number[]>([])
const showShipoutSelection = computed(() => !!props.shipoutProductionBatchUi
  && props.warehouseKey==='us-moreplus' && props.view==='pending')
const shipoutPageSelectable = computed(() => visibleRows.value
  .filter(row => row.shipment_status==='pending' && !row.shipout_production?.attempted)
  .map(row=>row.order_id))
const shipoutPageAllSelected = computed(() => shipoutPageSelectable.value.length>0
  && shipoutPageSelectable.value.every(id=>shipoutBatchSelection.value.includes(id)))
function selectShipout(id:number, checked:boolean) {
  shipoutBatchSelection.value = checked
    ? [...new Set([...shipoutBatchSelection.value,id])]
    : shipoutBatchSelection.value.filter(v=>v!==id)
  shipoutBatchPreview.value=null
}
function selectShipoutPage(checked:boolean) {
  const ids=shipoutPageSelectable.value
  shipoutBatchSelection.value = checked
    ? [...new Set([...shipoutBatchSelection.value,...ids])]
    : shipoutBatchSelection.value.filter(id=>!ids.includes(id))
  shipoutBatchPreview.value=null
}
const shipoutBatchPreview = ref<ShipOutBatchPreview|null>(null)
const shipoutBatchStatus = ref<ShipOutBatchStatus|null>(null)
const shipoutBatchBusy = ref(false)
const shipoutBatchError = ref('')
let shipoutBatchTimer:ReturnType<typeof setInterval>|null=null
// Polls below are GET-only status checks. Skip them while this Chrome tab is
// hidden; on becoming visible, read the latest status immediately.
const pollShipoutBatchIfVisible=()=>{if(document.visibilityState!=='hidden')void shipoutBatchPoll()}
const onShipoutBatchVisible=()=>{
  if(document.visibilityState!=='visible'||!shipoutBatchStatus.value)return
  if(['completed','completed_partial','paused'].includes(shipoutBatchStatus.value.status))return
  void shipoutBatchPoll()
}
onUnmounted(()=>{ if(shipoutBatchTimer)clearInterval(shipoutBatchTimer);document.removeEventListener('visibilitychange',onShipoutBatchVisible) })
onMounted(()=>{
  document.addEventListener('visibilitychange',onShipoutBatchVisible)
  const last=props.shipoutProductionLatestBatch
  if(last&&props.shipoutProductionBatchUi) {
    shipoutBatchStatus.value={batch_id:last.id,status:last.status,dry_run:last.dry_run,
      total:last.total,processed:0,items:[]}
    pollShipoutBatchIfVisible()
    if(!['completed','completed_partial','paused'].includes(last.status))
      shipoutBatchTimer=setInterval(pollShipoutBatchIfVisible,5000)
  }
})
async function shipoutBatchApi(url:string, data?:Record<string,string>) {
  const csrf=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  if(data&&!csrf)throw Error('CSRF 校验信息缺失')
  const response=await fetch(url,{method:data?'POST':'GET',credentials:'same-origin',cache:'no-store',
    headers:{Accept:'application/json',...(data?{'Content-Type':'application/json','X-CSRF-TOKEN':csrf||''}:{})},
    ...(data?{body:JSON.stringify(data)}:{})})
  const result=await response.json().catch(()=>({}))
  if(!response.ok)throw Error(response.status===429?'请求频繁，请稍后重新预览；本次没有提交海外仓':
    ({SHIPOUT_BATCH_STALE:'订单列表已变化，请重新预览',SHIPOUT_BATCH_ALREADY_RUNNING:'已有批次执行中，请勿重复提交',
      SHIPOUT_BATCH_LOCKED:'正在创建批次，请勿重复点击',SHIPOUT_BATCH_SELECTION_INVALID:'勾选的订单列表无效，请重新选择',
      SHIPOUT_BATCH_QUEUE_UNAVAILABLE:'任务队列不可用，已暂停，不会自动重试',
      SHIPOUT_BATCH_ASYNC_QUEUE_REQUIRED:'生产环境缺少异步队列，批量推送已禁止'} as Record<string,string>)[result.error_code]
    || '批量操作未完成：'+(result.error_code||response.status))
  return result
}
async function shipoutBatchCheck() {
  if(shipoutBatchBusy.value)return
  // Never use invisible selections from another workflow tab.
  const ids=(props.view==='pending' ? [...shipoutBatchSelection.value] : []).sort((a,b)=>a-b)
  if(!ids.length && !window.confirm('未勾选订单。是否选择美西 MorePlus 仓全部待发货订单（包含缺货订单）进入预检？缺货、未审核、已提交及有重复风险的订单会逐单隔离，绝不强制发货。'))return
  if(ids.length>200){shipoutBatchError.value='单批最多选择 200 单，请分批处理';return}
  shipoutBatchPreviewIds.value=ids
  shipoutBatchBusy.value=true;shipoutBatchError.value='';shipoutBatchPreview.value=null
  try {
    const query=ids.length ? '?scope=selected&order_ids='+ids.join(',') : '?scope=all'
    shipoutBatchPreview.value=await shipoutBatchApi('/logistics/oversea/us-moreplus/shipout/batch/preview'+query) as ShipOutBatchPreview
  }catch(e){shipoutBatchError.value=e instanceof Error?e.message:'批次预览失败'}
  finally{shipoutBatchBusy.value=false}
}
async function shipoutBatchPoll() {
  if(!shipoutBatchStatus.value)return
  try {
    shipoutBatchStatus.value=await shipoutBatchApi('/logistics/oversea/us-moreplus/shipout/batch/'+shipoutBatchStatus.value.batch_id+'/status') as ShipOutBatchStatus
    if(['completed','completed_partial','paused'].includes(shipoutBatchStatus.value.status)&&shipoutBatchTimer) {
      clearInterval(shipoutBatchTimer);shipoutBatchTimer=null
    }
  }catch(e){shipoutBatchError.value=e instanceof Error?e.message:'查询批次状态失败'}
}
async function shipoutBatchStart() {
  const preview=shipoutBatchPreview.value
  if(props.shipoutProductionBatchPaused||!preview||preview.overflow||preview.count<1||shipoutBatchBusy.value)return
  const ids=shipoutBatchPreviewIds.value
  if(preview.scope==='selected' && !ids.length)return
  const message=(preview.dry_run?'Dev 模拟批次（不会访问海外仓）':'真实向 MorePlus 仓库逐单推送')
    +'：扫描 '+(preview.scan_count??preview.count)+' 单，进入逐单校验 '+preview.count
    +' 单，排除 '+(preview.excluded_count??0)+' 单；再次确认仅提交安全门禁通过的订单？'
  if(!window.confirm(message))return
  shipoutBatchBusy.value=true;shipoutBatchError.value=''
  try {
    const reply=await shipoutBatchApi('/logistics/oversea/us-moreplus/shipout/batch/start',{
      fingerprint:preview.fingerprint,confirmation:'CONFIRM_MOREPLUS_BATCH_'+preview.count,
      scope:preview.scope||'all',order_ids:ids.join(','),
    }) as {batch_id:string;status:string;dry_run:boolean;total:number}
    shipoutBatchStatus.value={batch_id:reply.batch_id,status:reply.status,dry_run:reply.dry_run,
      total:reply.total,processed:0,items:[]}
    shipoutBatchPreview.value=null
    await shipoutBatchPoll()
    if(!shipoutBatchTimer && shipoutBatchStatus.value && !['completed','completed_partial','paused'].includes(shipoutBatchStatus.value.status))
      shipoutBatchTimer=setInterval(pollShipoutBatchIfVisible,5000)
  }catch(e){shipoutBatchError.value=e instanceof Error?e.message:'批次创建失败'}
  finally{shipoutBatchBusy.value=false}
}
const shipoutBatchLabel=(status:string)=>({
  success:'历史 ACK，仅代表 API 受理，未核实 OMS',
  pending_oms:'API 已受理 · OMS 异步核实中',
  oms_visible_provisional:'OMS 可见 · 正在再次核实',
  oms_visible:'OMS 订单可见 · 待实际出库（非发货成功）',
  provider_cancelled:'OMS 已取消／中止 · 禁止重发',
  needs_review:'OMS 核实未完成 · 人工处理',
  skipped:'跳过（未下单）',uncertain:'结果不确定 · 禁止重发',
  failed:'提交失败（禁止自动重试）',
  simulated:'Dev 模拟通过',processing:'处理中',queued:'待处理',
} as Record<string,string>)[status]||status
const shipoutBatchReason=(code:string|null)=>({
  PROVIDER_DUPLICATE_ORDER:'仓库异步判定重复订单，请与 MorePlus 核对原有记录',
  PROVIDER_EZEESHIP_SHIPPING_ERROR:'EzeeShip 运输服务异常，请仓库核对账户和渠道',
  PROVIDER_CANCELLED_OTHER:'仓库异步取消，请人工查看 OMS 原因',
  OMS_VISIBLE_PENDING_REAL_FULFILLMENT:'OMS 有单，但不代表已经出库',
  OMS_VISIBLE_PROVISIONAL_RECHECK:'订单可见，等待二次 OMS 复核',
  ASYNC_ACCEPTED_QUERY_REQUIRED:'接口 ACK 已受理，并非最终业务成功',
} as Record<string,string>)[code||'']|| (code?'需核对：'+code:'')
const shipoutBatchState=(status:string)=>({
  queued:'等待队列执行',running:'正在逐单提交',
  awaiting_oms:'等待正式 OMS 异步回读',
  completed:'本批次核实完成',completed_partial:'本批次结束 · 异常单已隔离',
  paused:'已暂停 · 人工核实',
} as Record<string,string>)[status]||status

const shipoutProdPreview = reactive<Record<number,ShipOutPreflight>>({})
const shipoutProdStatus = reactive<Record<number,ShipOutProductionStatus>>({})
const shipoutProdBusy = reactive<Record<number,boolean>>({})
const shipoutProdSentOnce = reactive<Record<number,boolean>>({})
const shipoutProdErrors = reactive<Record<number,string>>({})
const shipoutProdCanSend = (row:Row) => !!props.shipoutProductionOperatorAllowed
  && !!props.shipoutProductionDispatchEnabled
  && (props.shipoutProductionMinOrderId??0)>0
  && row.order_id>=(props.shipoutProductionMinOrderId??0)
  && row.review_status==='approved' && row.shipment_status==='pending'
  && !row.shipout_production?.attempted
  && !!shipoutProdPreview[row.order_id]?.ready
  && !!shipoutProdPreview[row.order_id]?.payload_hash
  && shipoutProdPreview[row.order_id]?.shipping_mode === '9'
  && !shipoutProdBusy[row.order_id] && !shipoutProdSentOnce[row.order_id]
async function shipoutProdRequest(row:Row, action:'preflight'|'send'|'status', payload:Record<string,string>={}) {
  const isGet=action==='preflight'
  const csrf=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  if(!isGet && !csrf) throw Error('CSRF 校验信息缺失')
  const url='/logistics/oversea/us-moreplus/orders/'+row.order_id+'/shipout/production/'+action
  const response=await fetch(url,{
    method:isGet?'GET':'POST',credentials:'same-origin',cache:'no-store',redirect:'error',
    headers:{Accept:'application/json',...(isGet?{}:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf||''})},
    ...(isGet?{}:{body:JSON.stringify(payload)}),
  })
  if(!response.ok) {
    const result=await response.json().catch(()=>null)
    const errors:Record<string,string>={
      SHIPOUT_V1_READ_DISABLED:'正式 API 查询尚未授权启用',
      SHIPOUT_V1_DISPATCH_DISABLED:'正式 API 下发尚未授权启用',
      SHIPOUT_V1_SUBMISSION_RATE_LIMITED:'仓库 API 提交限流，请稍后重新预检；当前没有提交该单',
      SHIPOUT_R10_NO_VERIFIED_ATTEMPT:'ERP 暂无本订单的 API 提交记录',
      SHIPOUT_R10_PRIOR_ATTEMPT_BLOCKED:'已有提交记录，禁止再次发送，请核对正式 OMS',
      SHIPOUT_R10_PREFLIGHT_STALE:'订单数据变化，请重新预检',
      SHIPOUT_R10_PHYSICAL_SKU_NOT_AVAILABLE:'仓库缺少精确匹配 SKU 的可用库存',
      SHIPOUT_R10_PROVIDER_ORDER_EXISTS:'ShipOut 正式 OMS 已有本单，禁止重复发送',
      SHIPOUT_R9_14D_CONTENT_DUPLICATE_RISK:'近14天同SKU/数量与收件信息疑似重复，已隔离交 Dede 人工核对',
      SHIPOUT_R9_HISTORY_UNAVAILABLE:'无法可靠读取海外仓14天历史记录；自动提交已禁止',
      SHIPOUT_R9_HISTORY_INCOMPLETE:'海外仓历史数据不完整；自动提交已禁止',
      SHIPOUT_R9_HISTORY_OVERFLOW:'海外仓历史数据超过可核验范围；交 Dede 人工处理',
      SHIPOUT_R9_HISTORY_DATE_UNVERIFIED:'海外仓历史订单日期不可靠；禁止自动提交',
      SHIPOUT_R9_HISTORY_CONTENT_UNVERIFIED:'海外仓历史订单缺少 SKU 或收件信息；禁止自动提交',
    }
    throw Error(errors[result?.error_code] ||
      (response.status===429?'请求过于频繁（HTTP 429），当前未下单，请稍后重试':response.status===403?'当前账号没有仓库操作权限':'正式 API 当前不可执行，请联系物流主管'))
  }
  return response.json()
}
async function shipoutProdPreflight(row:Row) {
  if(shipoutProdBusy[row.order_id] || !props.shipoutProductionReadEnabled
    || row.shipout_production?.attempted) return
  shipoutProdBusy[row.order_id]=true;shipoutProdErrors[row.order_id]=''
  delete shipoutProdPreview[row.order_id]
  try {
    const result=await shipoutProdRequest(row,'preflight') as ShipOutPreflight
    if(result.order_id!==row.order_id||!result.ready||result.shipping_mode!=='9') {
      const code=result.blockers?.[0] || 'SHIPOUT_R9_HISTORY_UNVERIFIED'
      const labels:Record<string,string>={
        SHIPOUT_R9_14D_CONTENT_DUPLICATE_RISK:'近14天相同商品与收件信息存在重复风险，已阻止自动提交，交 Dede 核对',
        SHIPOUT_R9_HISTORY_UNAVAILABLE:'仓库历史 API 不可用，14天重复风险未核实，禁止自动提交',
        SHIPOUT_R9_HISTORY_INCOMPLETE:'仓库历史分页不完整，禁止自动提交',
        SHIPOUT_R9_HISTORY_CONTENT_UNVERIFIED:'仓库历史内容不完整，禁止自动提交',
      }
      throw Error(labels[code] || '14天历史风险未通过：'+code+'；交 Dede 人工核对')
    }
    shipoutProdPreview[row.order_id]=result
  } catch(e) {shipoutProdErrors[row.order_id]=e instanceof Error?e.message:'正式 API 预检失败'}
  finally {shipoutProdBusy[row.order_id]=false}
}
async function shipoutProdSend(row:Row) {
  const preview=shipoutProdPreview[row.order_id]
  if(!preview?.payload_hash || !shipoutProdCanSend(row)) return
  // Never auto retry, including when provider timed out but accepted.
  shipoutProdSentOnce[row.order_id]=true;shipoutProdBusy[row.order_id]=true
  shipoutProdErrors[row.order_id]=''
  delete shipoutProdPreview[row.order_id]
  try {
    const result=await shipoutProdRequest(row,'send',{
      ship_date:preview.ship_date,shipping_mode:'9',payload_hash:preview.payload_hash,
      confirmation:'SUBMIT_SHIPOUT_ORDER_'+row.order_id,
    })
    shipoutProdErrors[row.order_id]=result.status==='acknowledged'
      ? 'API ACK 已受理 · 等待正式 OMS 异步复核。不是下单最终成功，禁止再次推送。'
      : '供应商未接单：'+(result.result_code||'请人工查询')
    if(result.status==='acknowledged' && result.provider_order_id) {
      // ACK is only a durable request receipt. Stay on the current view and
      // reload local audit state without claiming a warehouse-created order.
      router.reload({only:['rows','viewCounts']})
    }
  } catch(e) {
    const message=e instanceof Error?e.message:'结果未知'
    shipoutProdErrors[row.order_id]=message
      +(message.includes('结果未知')||message.includes('提交结果不确定')
        ? '。禁止重新发送，请人工核对正式 OMS。' : '')
  } finally {shipoutProdBusy[row.order_id]=false}
}
async function shipoutProdRefresh(row:Row) {
  if(!props.shipoutProductionReadEnabled || shipoutProdBusy[row.order_id]) return
  shipoutProdBusy[row.order_id]=true;shipoutProdErrors[row.order_id]=''
  try {
    const result=await shipoutProdRequest(row,'status') as ShipOutProductionStatus
    if(result.order_id!==row.order_id || result.shipped!==false || result.tracking_apply!==false)
      throw Error('OMS 回读结果不可信')
    shipoutProdStatus[row.order_id]=result
    // Reload persisted OMS classification/counters; no navigation based on
    // first ACK or single OMS visibility. Actual issued state requires two
    // independent signed GET observations.
    router.reload({ only:['rows','viewCounts'] })
  } catch(e){shipoutProdErrors[row.order_id]=e instanceof Error?e.message:'正式 OMS 查询失败'}
  finally {shipoutProdBusy[row.order_id]=false}
}

const shipoutModes = reactive<Record<number,'6'|'9'|'10'>>({})
const shipoutLabelFiles = reactive<Record<number,File|undefined>>({})
const shipoutLabelTracking = reactive<Record<number,string>>({})
const shipoutLabelCarriers = reactive<Record<number,string>>({})
const shipoutLabelTokens = reactive<Record<number,string>>({})
const shipoutModeFor = (row:Row):'6'|'9'|'10' => shipoutModes[row.order_id] === '10' ? '10' : (shipoutModes[row.order_id]==='6' ? '6' : '9')
const shipoutChangeMode = (row:Row,event:Event) => {
  const value=(event.target as HTMLSelectElement).value
  shipoutModes[row.order_id] = value==='10' ? '10' : value==='6' ? '6' : '9'
  // Label and preflight fingerprints never survive mode changes.
  delete shipoutLabelTokens[row.order_id]
  delete shipoutPreview[row.order_id]
}
const shipoutPreview = reactive<Record<number,ShipOutPreflight>>({})
const shipoutStatus = reactive<Record<number,ShipOutStatus>>({})
const shipoutErrors = reactive<Record<number,string>>({})
const shipoutBusy = reactive<Record<number,boolean>>({})
const shipoutSentOnce = reactive<Record<number,boolean>>({})
const shipoutLabel:Record<string,string> = {
  SANDBOX_API_READ_DISABLED:'沙盒查询未启用',
  SANDBOX_DISPATCH_DISABLED:'沙盒推送未启用',
  SANDBOX_WAREHOUSE_NOT_BOUND:'测试仓库尚未绑定',
  ALREADY_ATTEMPTED_QUERY_FIRST:'已有提交尝试，禁止重复推送',
  ORDER_NOT_IN_SANDBOX_ALLOWLIST:'此订单未获单笔沙盒下发授权',
  SHIPOUT_ERP_APPROVAL_OR_WAREHOUSE_BLOCKED:'需先完成 Dede 审核，且仓库必须为 MorePlus',
  SHIPOUT_SKU_NOT_VERIFIED:'SKU 尚未完成可信别名映射',
  SHIPOUT_US_ONLY_FIRST_RELEASE:'第一阶段只支持美国境内订单',
  PROVIDER_ORDER_NOT_FOUND:'ShipOut 暂未找到订单',
  PROVIDER_NO_SHIPMENT:'仓库未生成出库单',
  PROVIDER_NOT_SHIPPED_YET:'仓库尚未完成实际出库',
  PROVIDER_SHIPMENT_EVIDENCE_INCOMPLETE:'物流单号或出库时间不完整',
  PROVIDER_MULTISHIPMENT_MANUAL_REVIEW:'多包裹须人工核对',
  PROVIDER_CANCELLED_OR_ABORTED:'仓库订单已取消或中止',
  PROVIDER_ACCOUNT_BALANCE_INSUFFICIENT:'测试账号余额或信用额度不足，ShipOut 已取消此单；请联系测试仓库配置沙盒额度，不要重发相同单号',
  PROVIDER_LABEL_EXCEPTION:'面单异常',
  WAREHOUSE_SHIPPED_WITH_TRACKING:'仓库已发货，有物流单号（尚未回写 ERP）',
  ERP_TRACKING_UPDATED_WOO_PENDING:'已写入 ERP，等待 Vita 同步 WooCommerce',
  SHIPOUT_TRACKING_APPLY_DISABLED:'ERP 物流回写尚未获准启用',
  SHIPOUT_TRACKING_CONFIRMATION_MISMATCH:'Tracking 与仓库最新记录不一致',
  SHIPOUT_NOT_CONFIRMED_SHIPPED:'仓库尚未完成实际出库',
  SHIPOUT_SYNTHETIC_TRACKING_NOT_PUBLISHABLE:'这是沙盒 mock / VOID Tracking，不能同步到真实 ERP 或 WooCommerce',
  ASYNC_ACCEPTED_QUERY_REQUIRED:'提交已受理，仍需查询仓库状态',
  SHIPOUT_DUPLICATE_ATTEMPT_BLOCKED:'已有 API 发货记录，禁止重复提交，请查询仓库状态',
  SHIPOUT_PROVIDER_ORDER_ALREADY_EXISTS:'仓库已存在订单，禁止重复提交',
  SHIPOUT_PREFLIGHT_STALE:'预检数据变化，请重新预检',
  SHIPOUT_SUBMISSION_OUTCOME_UNKNOWN:'供应商响应未知，禁止重发，请核查 OMS',
  SHIPOUT_ORDER_NOT_APPROVED_IN_SANDBOX_ALLOWLIST:'此订单未获得沙盒单笔发货授权',
  SHIPOUT_HTTP_REJECTED:'API 凭证或接口权限被拒绝',
  SHIPOUT_BUSINESS_REJECTED:'仓库业务校验未通过',
  SHIPOUT_SHIPPING_MODE_NOT_ENABLED:'此运输方式没有获得沙盒授权',
  SHIPOUT_SERVICE_GROUP_NOT_VERIFIED:'指定渠道 ID 尚未与仓库绑定',
  SHIPOUT_LABEL_TOKEN_INVALID:'上传面单已经过期或无效，请重新上传',
  SHIPOUT_LABEL_PDF_INVALID:'仅支持 512KB 内完整 PDF 面单',
  SHIPOUT_LABEL_TRACKING_INVALID:'请提供有效且非模拟的 Tracking',
  SHIPOUT_STOCK_IDENTITY_UNAVAILABLE:'测试仓库缺少可信库存配置，禁止 API 发货；请先核对测试组织 ID',
  SHIPOUT_PROVIDER_SKU_NOT_FOUND:'当前 ERP SKU 未找到已验证的 ShipOut 实际 SKU',
  SHIPOUT_PROVIDER_SKU_AMBIGUOUS:'同一产品对应多个 ShipOut SKU，须先人工确认',
  SHIPOUT_PROVIDER_SKU_OUT_OF_STOCK:'ShipOut 测试仓库可用库存不足，不能直接 API 发货',
  SHIPOUT_PRODUCT_CATALOG_INCOMPLETE:'ShipOut SKU 列表未能完整读取，禁止发货',
  SHIPOUT_STOCK_CATALOG_INCOMPLETE:'ShipOut 库存列表不完整，禁止发货',
}
const shipoutMessage = (code:string) => shipoutLabel[code] || code
const shipoutIsSyntheticTracking = (tracking?:string|null) => /^(mock_|UATSHIPOUTVOID)/i.test(tracking||'')
const shipoutCarrierName = (carrier?:number|null) => ({
  1:'USPS',2:'UPS',3:'FedEx',4:'DHL',9:'Other',
} as Record<number,string>)[Number(carrier)] || '待核对'

const shipoutSetLabelFile=(row:Row,event:Event) => {
  shipoutLabelFiles[row.order_id]=(event.target as HTMLInputElement).files?.[0]
  delete shipoutLabelTokens[row.order_id]
  delete shipoutPreview[row.order_id]
}
const shipoutSetLabelTracking=(row:Row,event:Event) => {
  shipoutLabelTracking[row.order_id]=(event.target as HTMLInputElement).value
  delete shipoutLabelTokens[row.order_id]
  delete shipoutPreview[row.order_id]
}
const shipoutSetLabelCarrier=(row:Row,event:Event) => {
  shipoutLabelCarriers[row.order_id]=(event.target as HTMLSelectElement).value
  delete shipoutLabelTokens[row.order_id]
  delete shipoutPreview[row.order_id]
}
async function shipoutStageLabel(row:Row) {
  const file=shipoutLabelFiles[row.order_id]
  if (shipoutBusy[row.order_id] || !file || !props.shipoutSandboxDispatchEnabled
      || !props.shipoutSandboxOwnLabelEnabled) return
  shipoutBusy[row.order_id]=true;shipoutErrors[row.order_id]=''
  delete shipoutLabelTokens[row.order_id];delete shipoutPreview[row.order_id]
  try {
    const csrf=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
    if(!csrf) throw Error('CSRF 校验信息缺失')
    const data=new FormData()
    data.append('label',file)
    data.append('carrier_id',shipoutLabelCarriers[row.order_id]||'1')
    data.append('tracking_number',shipoutLabelTracking[row.order_id]||'')
    const r=await fetch('/logistics/oversea/us-moreplus/orders/'+row.order_id+'/shipout/label-stage',{
      method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',
      headers:{Accept:'application/json','X-CSRF-TOKEN':csrf},body:data,
    })
    const j=await r.json().catch(()=>null)
    if (!r.ok || !j?.label_token) throw Error(j?.message||'面单校验失败')
    shipoutLabelTokens[row.order_id]=j.label_token
    shipoutErrors[row.order_id]='PDF 已安全暂存（15 分钟有效），请点击「API 发货」预检'
  } catch(e) {
    shipoutErrors[row.order_id]=e instanceof Error?e.message:'上传失败'
  } finally {shipoutBusy[row.order_id]=false}
}
const shipoutCanSend = (row:Row) => !!props.canOperate && !!props.shipoutSandboxDispatchEnabled
  && row.review_status === 'approved' && row.shipment_status === 'pending'
  && !!shipoutPreview[row.order_id]?.ready
  && shipoutPreview[row.order_id]?.order_no === props.shipoutSandboxAuthorizedOrderNo
  && (shipoutPreview[row.order_id]?.shipping_mode ?? '9') === shipoutModeFor(row)
  && (shipoutModeFor(row)!=='6' || !!shipoutLabelTokens[row.order_id])
  && !shipoutBusy[row.order_id] && !shipoutSentOnce[row.order_id]
async function shipoutRequest(row:Row,action:'preflight'|'send'|'status'|'apply',payload:Record<string,string>={}) {
  const csrf=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  if ((action!=='preflight' || payload.mode==='6') && !csrf) throw Error('CSRF 校验信息缺失')
  const base='/logistics/oversea/us-moreplus/orders/'+row.order_id+'/shipout/'+action
  const privatePreflight=action==='preflight' && payload.mode==='6'
  const isGet=action==='preflight' && !privatePreflight
  const endpoint=isGet?base+'?mode='+encodeURIComponent(payload.mode||'9'):base
  const response=await fetch(endpoint,{
    method:isGet?'GET':'POST',credentials:'same-origin',cache:'no-store',redirect:'error',
    headers:{Accept:'application/json',...(isGet?{}:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf||''})},
    ...(isGet?{}:{body:JSON.stringify(payload)}),
  })
  if (!response.ok) {
    const failure=await response.json().catch(()=>null)
    const code=typeof failure?.error_code==='string'?failure.error_code:''
    throw Error(response.status===403?'当前账号没有仓库 API 操作权限'
      :response.status===429?'操作频繁，请稍后再试'
      :code?shipoutMessage(code):(typeof failure?.message==='string'?failure.message:'ShipOut '+action+' 失败（HTTP '+response.status+'）'))
  }
  return response.json()
}
async function shipoutPreflight(row:Row) {
  if (shipoutBusy[row.order_id]) return
  shipoutBusy[row.order_id]=true;shipoutErrors[row.order_id]=''
  try {
    const result=await shipoutRequest(row,'preflight',{
      mode:shipoutModeFor(row),...(shipoutModeFor(row)==='6'
        ? {label_token:shipoutLabelTokens[row.order_id]||''}:{})
    }) as ShipOutPreflight
    if (result.order_id!==row.order_id) throw Error('订单 ID 校验失败')
    shipoutPreview[row.order_id]=result
    // Preflight blockers are rendered once inside the preview; avoid a second red error line.
  } catch(e) {
    shipoutErrors[row.order_id]=e instanceof Error?e.message:'API 预检失败'
  } finally {shipoutBusy[row.order_id]=false}
}
async function shipoutSend(row:Row) {
  const pre=shipoutPreview[row.order_id]
  if (!pre?.payload_hash||!shipoutCanSend(row)) return
  shipoutBusy[row.order_id]=true;shipoutSentOnce[row.order_id]=true;shipoutErrors[row.order_id]=''
  delete shipoutPreview[row.order_id]
  try {
    const result=await shipoutRequest(row,'send',{
      ship_date:pre.ship_date,shipping_mode:shipoutModeFor(row),payload_hash:pre.payload_hash,
      ...(shipoutModeFor(row)==='6'?{label_token:shipoutLabelTokens[row.order_id]||''}:{}),
      confirmation:'SUBMIT_US_LA_ORDER_'+row.order_id,
    })
    shipoutErrors[row.order_id]=result.status==='acknowledged'
      ? 'ShipOut 已接收请求；请查询状态，尚不能标记已发货'
      : '供应商拒绝：'+shipoutMessage(result.result_code||'UNKNOWN')
  } catch(e) {
    shipoutErrors[row.order_id]=(e instanceof Error?e.message:'状态未知')
      +'。严禁重新推送，请查询仓库状态。'
  } finally {shipoutBusy[row.order_id]=false}
}
async function shipoutApply(row:Row) {
  const snapshot=shipoutStatus[row.order_id]
  if (!props.canOperate || !props.shipoutSandboxTrackingApplyEnabled
      || snapshot?.state!=='ready_for_erp_review'
      || !snapshot.tracking_number || shipoutBusy[row.order_id]) return
  shipoutBusy[row.order_id]=true;shipoutErrors[row.order_id]=''
  try {
    const response=await shipoutRequest(row,'apply',{
      tracking_number:snapshot.tracking_number,
      confirmation:'APPLY_US_LA_TRACKING_'+row.order_id,
    })
    if (response.status!=='erp_shipment_updated') throw Error('ERP 回写结果不明确')
    shipoutStatus[row.order_id]={...snapshot,state:'erp_shipment_updated',
      reason:'ERP_TRACKING_UPDATED_WOO_PENDING'}
    shipoutErrors[row.order_id]='ERP 发货记录已更新；Vita 仍需同步 WooCommerce'
  } catch(e) {
    shipoutErrors[row.order_id]=e instanceof Error?e.message:'ERP 回写未确认'
  } finally {shipoutBusy[row.order_id]=false}
}
async function shipoutRefresh(row:Row) {
  if (shipoutBusy[row.order_id]||!props.shipoutSandboxReadEnabled) return
  shipoutBusy[row.order_id]=true;shipoutErrors[row.order_id]=''
  try {
    const result=await shipoutRequest(row,'status') as ShipOutStatus
    if (result.order_no && !result.order_no.startsWith('C') && !result.order_no.startsWith('KOL'))
      throw Error('仓库订单号校验失败')
    shipoutStatus[row.order_id]=result
    // Provider state is already rendered once in the result row. Avoid
    // duplicate red alerts for the same cancellation / not-found reason.
  } catch(e) {
    shipoutErrors[row.order_id]=e instanceof Error?e.message:'状态查询失败'
  } finally {shipoutBusy[row.order_id]=false}
}

const omsBlockerLabels:Record<string,string> = {
  wrong_warehouse:'发货仓库不是世迈 US-SM',
  order_not_pending_shipment:'ERP 订单并非待发货',
  shipment_not_pending:'发货记录不是待发货',
  warehouse_review_not_approved:'Dede 尚未通过当前仓库审核',
  tracking_already_assigned:'已有 Tracking，禁止重复出库',
  api_credentials_missing:'世迈 API 凭据未配置',
  oms_warehouse_not_bound:'世迈 OMS 仓库未绑定',
  recipient_missing:'缺收件人',address_missing:'缺收货地址',
  city_missing:'缺城市',postal_code_missing:'缺邮编',
  country_iso2_missing:'国家代码不正确',
  province_missing:'缺州/省',province_code_not_confirmed:'州/省代码未核实',
  native_logistics_channel_missing:'未配置可用的 OMS 物流渠道',
  US_SM_NO_SHIPPING_CHANNEL_REQUIRED:'世迈仓仅允许无需物流服务渠道（No_Shipping_Service）',
  US_SM_NO_SHIPPING_CHANNEL_UNAVAILABLE:'OMS 未开放无需物流服务渠道，请联系仓库核实',

  logistics_channel_inactive:'物流渠道已停用',
  no_valid_order_items:'没有有效订单商品',
  invalid_item:'SKU 或商品数量不正确',
  inventory_unverified:'库存未核实',
  insufficient_warehouse_stock:'世迈仓库存不足',
  existing_dispatch_attempt:'已存在 API 发货尝试，禁止重复',
}
const omsBlockerText=(v:string)=> {
  const [key,detail]=v.split(':')
  return (omsBlockerLabels[key]||key)+(detail?' · '+detail:'')
}
const omsErrors = reactive<Record<number,string>>({})
const omsBusy = reactive<Record<number,boolean>>({})
const omsSnapshots = reactive<Record<number,OmsSnapshot>>({})
const omsPreflight = reactive<Record<number,OmsPreflight>>({})
const omsFinishedThisPage = reactive<Record<number,boolean>>({})
const batchBusy = ref(false)
const batchResult = ref<{count:number; results:{order_id:number; code:string; reason?:string}[];summary?:{created:number;existing:number;blocked:number;uncertain:number;other:number};blocked_reason?:string}|null>(null)
const refreshResult = ref<{count:number;refreshed:number;failed:number}|null>(null)
const refreshBusy = ref(false)
const batchError = ref('')
const batchCodeText:Record<string,string> = {
  created:'已创建', existing:'已存在', status_updated:'已更新发货状态', canceled:'OMS 已取消', unknown_status:'状态待核实',
  in_progress:'已有同步处理中', create_disabled:'未创建（开关关闭）', not_found:'OMS 暂未找到',
  identity_mismatch:'身份不匹配', preflight_blocked:'创建前检查阻断', blocked:'已阻断', uncertain:'结果待核实', error:'失败',
  carrier_verification_required:'需核对实际承运商', canary_order_not_authorized:'未获单笔下发授权',
}
const syncWarehouse = async () => {
  if (batchBusy.value || props.warehouseKey !== 'us-3sm' || !props.canOperate) return
  const token=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  batchBusy.value=true; batchError.value=''; batchResult.value=null
  try {
    const response=await fetch('/logistics/oversea/us-3sm/xlwms/sync',{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',headers:{Accept:'application/json','Content-Type':'application/json',...(token?{'X-CSRF-TOKEN':token}:{})},body:'{}'})
    if (!response.ok) throw Error(response.status===403?'当前账号没有此操作权限':response.status===429?'操作过于频繁，请稍后再试':'同步失败（HTTP '+response.status+'）')
    batchResult.value=await response.json()
    const created=batchResult.value?.summary?.created ?? 0
    const blocked=batchResult.value?.summary?.blocked ?? 0
    notifyOms(batchResult.value?.blocked_reason?'ERROR':'INFO','同步仓库结果',batchResult.value?.blocked_reason?'未下发：正式建单权限关闭':`成功新建 ${created} 单 · 阻断 ${blocked} 单`)
    router.reload({only:['rows','viewCounts']})
  } catch (e) { batchError.value=e instanceof Error?e.message:'同步失败，请稍后重试'; notifyOms('ERROR','同步仓库失败',batchError.value) }
  finally { batchBusy.value=false }
}
const refreshOmsStatuses = async () => {
  if(refreshBusy.value||props.warehouseKey!=='us-3sm'||!props.canOperate)return
  const token=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  refreshBusy.value=true;refreshResult.value=null
  try{
    const res=await fetch('/logistics/oversea/us-3sm/xlwms/refresh-statuses',{
      method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',
      headers:{Accept:'application/json',...(token?{'X-CSRF-TOKEN':token}:{})},
    })
    if(!res.ok)throw Error('更新状态失败（HTTP '+res.status+'）')
    refreshResult.value=await res.json()
    notifyOms('INFO','OMS 状态更新',`成功 ${refreshResult.value?.refreshed??0} · 失败 ${refreshResult.value?.failed??0}（单次最多 10 条）`)
    router.reload({only:['rows','viewCounts']})
  }catch(e){notifyOms('ERROR','OMS 状态更新失败',e instanceof Error?e.message:'查询失败')}
  finally{refreshBusy.value=false}
}
const omsRow = (row:Row): OmsSnapshot|null => omsSnapshots[row.order_id] ?? row.xlwms ?? null
const omsLabel = (row:Row) => {
  const record=omsRow(row)
  if(row.shipment_status==='shipped')return '已发货'
  if(!record?.oms_order_no)return row.warehouse_dispatch?.label ?? '待下发'
  if(record.oms_status_code==='0')return 'OMS 草稿 · 未提交仓库'
  if(record.oms_status_code==='5')return 'OMS 异常 · 待处理'
  if(record.oms_status_code==='3')return '已出库 · 待回写'
  if(record.oms_status_code==='4')return '上次仓库单已取消'
  if(record.state==='blocked_existing_oms_order'||record.state==='batch_identity_blocked')return 'OMS 身份冲突 · 待核实'
  return 'OMS 已建单 · 接单待核实'
}
const omsDetail=(row:Row)=> {
  const record=omsRow(row)
  if(record?.oms_order_no && record.oms_status_code==='0')return '无需面单；仓库接单状态仍需核对'
  if(record?.oms_order_no && record.oms_status_code==='5')return 'OMS 异常 · 需处理后重新提交'
  if(record?.oms_order_no && record.oms_status_code && !['3','4'].includes(record.oms_status_code))return `OMS 状态 ${record.oms_status_code} · 待核实`
  return row.warehouse_dispatch?.detail ?? ''
}

const omsFailureText = (code:string|null|undefined) => ({
  OMS_STATUS_IDENTITY_MISMATCH:'OMS 返回的订单或仓库不匹配，已保留原状态',
  OMS_STATUS_RESPONSE_INVALID:'OMS 查询结果不完整，已保留原状态',
  OMS_STATUS_NOT_FOUND:'OMS 尚未找到该出库单',
  OMS_BUSINESS_REJECTED:'OMS 拒绝操作，请核查仓库渠道或库存',
  OMS_TRANSPORT_UNCERTAIN:'OMS 网络结果不明，请只读核对，不要重发',
  OMS_STATUS_UNEXPECTED_ERROR:'OMS 查询失败，已保留原状态',
  OMS_STATUS_NOT_READY:'缺少 OMS 出库单号',
  OMS_STATUS_QUERY_INVALID:'OMS 出库单号无效',
}[code||''] || (code ? 'OMS 操作未完成，请核对后处理' : ''))
const notifyOms = (state:'SUCCESS'|'ERROR'|'INFO', title:string, message:string) =>
  publishGlobalFeedback({ state, title, message, copyable:state==='ERROR' })
const omsEndpoint = (row:Row, action:string) =>
  '/logistics/oversea/us-3sm/orders/'+row.order_id+'/xlwms/'+action
// US-SM Final V1: staff downloads a one-order XLSX and manually imports in
// OMS after checking its CURRENT draft inbox. No automatic browser/API CREATE.
const hasOmsHistory = (row:Row) => !!(row.web_draft?.oms_order_no || omsRow(row)?.oms_order_no)
const manualOrderDownload = (row:Row) =>
  '/logistics/oversea/us-3sm/orders/'+row.order_id+'/manual-export'
const manualDownloadText = (row:Row) => hasOmsHistory(row) ? '重新下载订单文件' : '下载订单文件'
async function omsRequest(row:Row,action:string,payload:Record<string,string>={}) {
  const token=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  if (!token && action!=='preflight') throw Error('CSRF_MISSING')
  const res=await fetch(omsEndpoint(row,action),{
    method:action==='preflight'?'GET':'POST',
    credentials:'same-origin',cache:'no-store',redirect:'error',
    headers:{Accept:'application/json',...(token?{'X-CSRF-TOKEN':token}:{}),
      ...(action!=='preflight'?{'Content-Type':'application/json'}:{})},
    ...(action==='preflight'?{}:{body:JSON.stringify(payload)}),
  })
  if (!res.ok) throw Error(res.status===423?'此订单没有单笔 API 发货授权'
    :res.status===429?'操作过于频繁，请稍后再试'
    :res.status===403?'当前账号没有此操作权限'
    :'请求失败（HTTP '+res.status+'）')
  return res.json()
}
const omsReadyToSend = (row:Row) => props.canOperate
  && props.warehouseKey==='us-3sm' && row.review_status==='approved'
  && row.shipment_status==='pending' && !omsRow(row)?.oms_order_no
  && (!omsRow(row) || row.warehouse_dispatch?.can_retry_local_block)
  && !omsFinishedThisPage[row.order_id] && props.xlwmsAuthorizedOrderId===row.order_id
const omsPreflightStart = async (row:Row) => {
  if (omsBusy[row.order_id] || (omsRow(row) && !row.warehouse_dispatch?.can_retry_local_block)) return
  omsBusy[row.order_id]=true;omsErrors[row.order_id]=''
  try {
    const result=await omsRequest(row,'preflight') as OmsPreflight
    if (result.order_id!==row.order_id || result.already_attempted)
      throw Error('订单已存在下发记录，禁止重复发货')
    if (!result.ready||result.blockers.length) {
      omsErrors[row.order_id]='发货前检查未通过：'+(result.blockers.map(omsBlockerText).join('；')||'存在未解决问题')
      notifyOms('ERROR','API 发货预检阻断',omsErrors[row.order_id])
      return
    }
    omsPreflight[row.order_id]=result
    if (!omsReadyToSend(row)) {
      omsErrors[row.order_id]='预检通过，但当前订单未获得单笔真实发货授权，不能提交'
      notifyOms('INFO','等待逐单授权',omsErrors[row.order_id])
    }
  } catch(e) {
    omsErrors[row.order_id]=e instanceof Error?e.message:'预检失败，请核查订单'
    notifyOms('ERROR','API 预检失败',omsErrors[row.order_id])
  } finally {omsBusy[row.order_id]=false}
}
const omsSend = async (row:Row) => {
  const plan=omsPreflight[row.order_id]
  if (!plan?.ready||!omsReadyToSend(row)||omsBusy[row.order_id]) return
  omsBusy[row.order_id]=true;omsErrors[row.order_id]=''
  delete omsPreflight[row.order_id]
  // Local double-click guard. The server also imposes exact-order auth,
  // a unique dispatch attempt and no blind retry on timeout.
  omsFinishedThisPage[row.order_id]=true
  try {
    const result=await omsRequest(row,'send',{
      payload_hash:plan.payload_hash,confirmation:'SUBMIT_US_SM_ORDER_'+row.order_id,
    }) as {state:string;status_label?:string;oms_order_no?:string|null;failure_code?:string|null}
    omsSnapshots[row.order_id]={
      state:result.state,oms_order_no:result.oms_order_no??null,
      oms_status_code:null,oms_status_checked_at:null,oms_tracking_no:null,
      failure_code:result.failure_code??null,oms_status_failure_code:null,
    }
    if (result.state==='oms_draft_created'||result.state==='submitted_oms'||result.state==='found_in_oms') {
      notifyOms('SUCCESS','OMS 建单成功',result.status_label||'已请求创建草稿，最终 OMS 状态待核实')
    } else {
      omsErrors[row.order_id]=result.status_label||'OMS 下发结果需核对，不能再次点击发货'
      notifyOms('ERROR','请核对 OMS 结果',omsErrors[row.order_id])
    }
  } catch(e) {
    omsErrors[row.order_id]=(e instanceof Error?e.message:'请求结果未知')+'。严禁直接重新发货，请刷新订单并查重。'
    notifyOms('ERROR','API 发货结果未知',omsErrors[row.order_id])
  } finally {omsBusy[row.order_id]=false}
}
const omsRefresh = async (row:Row) => {
  const old=omsRow(row)
  if (!old?.oms_order_no||omsBusy[row.order_id]) return
  omsBusy[row.order_id]=true;omsErrors[row.order_id]=''
  try {
    const result=await omsRequest(row,'status') as OmsStatusResult
    if (!result.read_only||!result.erp_status_unchanged||!result.woo_status_unchanged
      ||result.oms_order_no!==old.oms_order_no) throw Error('OMS 订单身份校验失败，未覆盖已有状态')
    if (!result.fresh) throw Error(omsFailureText(result.oms_status_failure_code)||'OMS 状态更新失败')
    omsSnapshots[row.order_id]={
      ...old,oms_status_code:result.oms_status_code,
      oms_status_checked_at:result.oms_status_checked_at,
      oms_tracking_no:result.oms_tracking_no,oms_status_failure_code:null,
    }
    notifyOms('SUCCESS','OMS 状态已更新',result.oms_status_label+' · 不修改 ERP/Woo')
  } catch(e) {
    omsErrors[row.order_id]=e instanceof Error?e.message:'OMS 查询失败，已保留上次状态'
    notifyOms('ERROR','OMS 状态查询失败',omsErrors[row.order_id])
  } finally {omsBusy[row.order_id]=false}
}
const omsReconcile = async (row:Row) => {
  if (!props.canOperate||omsBusy[row.order_id]) return
  omsBusy[row.order_id]=true;omsErrors[row.order_id]=''
  try {
    const result=await omsRequest(row,'reconcile') as {state:string;oms_order_no?:string|null;status_label?:string}
    const old=omsRow(row)
    omsSnapshots[row.order_id]={
      state:result.state,oms_order_no:result.oms_order_no??old?.oms_order_no??null,
      oms_status_code:old?.oms_status_code??null,oms_status_checked_at:old?.oms_status_checked_at??null,
      oms_tracking_no:old?.oms_tracking_no??null,
      failure_code:old?.failure_code??null,oms_status_failure_code:old?.oms_status_failure_code??null,
    }
    notifyOms('INFO','OMS 查重结果',result.status_label||'已完成只读查重，不会重发')
  } catch(e) {
    omsErrors[row.order_id]=e instanceof Error?e.message:'查重失败，不能重新发货'
    notifyOms('ERROR','OMS 查重失败',omsErrors[row.order_id])
  } finally {omsBusy[row.order_id]=false}
}

const carrierOption = (name:string) => props.carrierOptions.find(row => row.value === name)
const serviceOptionsFor = (name:string) => carrierOption(name)?.services ?? []
const defaultServiceFor = (name:string, country?:string|null) => {
  const carrier = carrierOption(name)
  if (!carrier) return ''
  if (name === 'DHL' && String(country ?? '').toUpperCase() === 'DE') {
    return carrier.services.find(service => service.key === 'paket_domestic')?.value ?? carrier.default_service_value ?? ''
  }
  return carrier.default_service_value ?? ''
}
const draft = (row:Row) => drafts[row.order_id] ??= { note:row.note ?? '', carrier:row.carrier ?? '', service:row.service ?? defaultServiceFor(row.carrier ?? '', row.country), tracking_number:row.tracking_number ?? '', outbound_ref:'' }
const viewTabs = [
  ['review','待审核'], ['stockout','缺货单'], ['pending','待发货'], ['issued','已下发'],
  ['shipped','已发货'], ['exceptions','异常订单'], ['all','全部订单'],
] as const


const pageTitle = computed(() => props.warehouse ? `海外仓发货 · ${props.warehouse.label}` : '海外仓发货 · 全部海外仓')
const warehousePortal = computed(() => resolveWarehousePortal(props.warehouse?.url ?? ''))
const basePath = computed(() => props.warehouse ? props.warehouse.url : '/logistics/oversea')
type GridQueryValue = string|number|null|undefined
const gridNavigate = (overrides:Record<string,GridQueryValue> = {}, path = basePath.value) => {
  const params:Record<string,GridQueryValue> = {
    view:props.view, page:props.rows.current_page, per_page:props.rows.per_page||50,
    global_grid:gridEnabled.value?'1':'0',search:gridSearchText.value,
    sort_col:props.gridSort?.colId??'line',sort_dir:props.gridSort?.direction??'desc',
    filter_carrier:props.gridFilters?.carrier??'',filter_tracking:props.gridFilters?.tracking??'',
    ...overrides,
  }
  const query = new URLSearchParams()
  Object.entries(params).forEach(([key,value])=>{if(value!==null&&value!==undefined&&value!=='')query.set(key,String(value))})
  router.visit(path+'?'+query.toString(),{preserveScroll:true,preserveState:true})
}
const switchView = (view:string) => {
  if(view!=='pending') {shipoutBatchSelection.value=[];shipoutBatchPreview.value=null}
  gridNavigate({view,page:1})
}
const switchWarehouse = (url:string) => gridNavigate({page:1},url)
const toggleGrid = () => gridNavigate({global_grid:gridEnabled.value?'0':'1',page:1})
const searchGrid = (value:string) => {gridSearchText.value=value;gridNavigate({search:value,page:1})}
const sortGrid = (sort:{colId:string;direction:'asc'|'desc'|null}) => gridNavigate({
  sort_col:sort.direction?sort.colId:'line',sort_dir:sort.direction??'desc',page:1,
})
const filterGrid = (filter:{colId:string;value:string}) => {
  if(!['carrier','tracking'].includes(filter.colId))return
  gridNavigate({['filter_'+filter.colId]:filter.value,page:1})
}
type InlineDraftSaved = {
  order_id:number;shipment_status:string;tracking_number:string|null
  carrier:string|null;service:string|null;note:string|null
  auto_shipped:boolean;website_sync:string|null;tracking_moved:boolean;message:string
}
const saveDraft = async (row:Row,payload:Record<string,string|number|null>) => {
  if(inlineSaveBusy[row.order_id]) return
  inlineSaveBusy[row.order_id]=true
  rowErrors[row.order_id]=''
  inlineSaveFeedback[row.order_id]=''
  try{
    const {data}=await axios.patch<InlineDraftSaved>('/logistics/oversea/orders/'+row.order_id+'/draft',payload,{
      headers:{Accept:'application/json'},
    })
    if(data.order_id!==row.order_id)throw new Error('订单保存响应不匹配')
    row.note=data.note
    row.carrier=data.carrier
    row.service=data.service
    row.tracking_number=data.tracking_number
    row.shipment_status=data.shipment_status
    const d=draft(row)
    d.note=data.note??''
    d.carrier=data.carrier??''
    d.service=data.service??''
    d.tracking_number=data.tracking_number??''
    inlineSaveFeedback[row.order_id]=data.message
    if(data.auto_shipped){
      publishGlobalFeedback({state:'SUCCESS',title:'已确认真实出库',
        message:'订单 '+(row.erp_order_id??row.order_id)+' 已进入已发货；官网由同步模块处理'})
      row.tracking_sync_status='pending'
    }
    if(data.auto_shipped && ['issued','pending','exceptions','review'].includes(props.view)){
      visibleRows.value=visibleRows.value.filter(item=>item.order_id!==row.order_id)
      visibleTotal.value=Math.max(0,visibleTotal.value-1)
      liveViewCounts[props.view]=Math.max(0,(liveViewCounts[props.view]??0)-1)
      liveViewCounts.shipped=(liveViewCounts.shipped??0)+1
    }
    return data
  }catch(error:unknown){
    const status=axios.isAxiosError(error)?error.response?.status:undefined
    rowErrors[row.order_id]=status===409?'保存冲突，请核对订单最新状态':
      status===403?'没有此字段的操作权限':
      status===422?'字段格式错误，请检查填写内容':'保存失败，未改变 ERP 数据'
    return null
  }finally{
    inlineSaveBusy[row.order_id]=false
  }
}
const changeWarehouse = (row:Row, warehouseId:number) => saveDraft(row, { warehouse_id:Number(warehouseId) })
const changeWarehouseFromEvent = (row:Row, event:Event) => changeWarehouse(row, Number((event.target as HTMLSelectElement).value))
const saveNote = (row:Row) => saveDraft(row,{note:draft(row).note.trim()||null})
const saveCarrier = (row:Row) => { const d=draft(row); d.service=defaultServiceFor(d.carrier, row.country); saveDraft(row, { carrier:d.carrier, service:d.service || null }) }
const saveService = (row:Row) => saveDraft(row, { carrier:draft(row).carrier, service:draft(row).service || null })
const saveTracking = (row:Row) => saveDraft(row, { tracking_number:draft(row).tracking_number.trim() || null })
const manualShipUsSm = (row:Row) => {
  // Manual ERP shipment and AfterShip writeback do not call or depend on OMS.
  const tracking = draft(row).tracking_number.trim()
  if (!tracking) { rowErrors[row.order_id] = '请先填写真实 Tracking'; return }
  rowErrors[row.order_id] = ''
  router.post(`/logistics/oversea/us-3sm/orders/${row.order_id}/manual-ship`, { tracking_number:tracking }, { preserveScroll:true })
}
const manualShipMorePlus = (row:Row) => {
  if (row.warehouse_dispatch?.failure_code==='PROVIDER_DUPLICATE_ORDER') {
    rowErrors[row.order_id]='仓库已确认重复订单，禁止再次发货';return
  }
  const tracking=draft(row).tracking_number.trim()
  const outbound=draft(row).outbound_ref.trim()
  if (!tracking || !outbound) {
    rowErrors[row.order_id]='先由 Dede 在仓库核实真实出库单，再填写 Tracking 与出库单号'
    return
  }
  rowErrors[row.order_id]=''
  router.post('/logistics/oversea/us-moreplus/orders/'+row.order_id+'/manual-ship',{
    tracking_number:tracking, outbound_reference:outbound,
    confirmation:'CONFIRM_MOREPLUS_MANUAL_SHIPPED_'+row.order_id,
  },{preserveScroll:true})
}
const chooseTrackingFile = () => trackingFileInput.value?.click()
const previewTrackingFile = (event:Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  const data = new FormData()
  data.append('file', file)
  router.post(`${basePath.value}/tracking-import/preview`, data, { forceFormData:true, preserveScroll:true, onFinish:() => { input.value = '' } })
}
const applyTrackingPreview = () => {
  if (!props.trackingImportPreview) return
  router.post(`${basePath.value}/tracking-import/apply`, { token:props.trackingImportPreview.token }, { preserveScroll:true })
}
const trackingStatusText = (status:TrackingImportRow['status']) => status === 'apply' ? '可应用' : (status === 'noop' ? '重复跳过' : '阻断')
const approve = (row:Row) => {
  rowErrors[row.order_id] = ''
  router.post(`/logistics/oversea/orders/${row.order_id}/approve`, { note:draft(row).note.trim() || null }, { preserveScroll:true, preserveState:true })
}
const reviewAction = (row:Row, action:'approve'|'stockout'|'restock') => {
  if(!props.canReview || reviewBusy.value || row.shipment_status!=='pending')return
  if(action==='stockout' && row.review_status!=='pending')return
  if(action==='restock' && row.review_status!=='stockout')return
  if(action==='approve' && row.review_status!=='pending')return
  reviewBusy.value=true
  gridReviewError.value=''
  router.post('/logistics/oversea/orders/'+row.order_id+'/'+action,{}, {
    preserveScroll:true,preserveState:true,
    onError:(errors)=>{gridReviewError.value=Object.values(errors).join('；')||'审核失败，请刷新核对。'},
    onFinish:()=>{reviewBusy.value=false},
  })
}
const rejectGrid = (row:Row,reason:string) => {
  if(!props.canReview||reviewBusy.value||props.view!=='review'||row.review_status!=='pending'||reason.trim().length<3)return
  reviewBusy.value=true
  gridReviewError.value=''
  router.post('/logistics/oversea/orders/'+row.order_id+'/reject',{note:reason.trim()}, {
    preserveScroll:true,preserveState:true,
    onError:(errors)=>{gridReviewError.value=Object.values(errors).join('；')||'驳回失败，请刷新核对。'},
    onFinish:()=>{reviewBusy.value=false},
  })
}
const reviewBulkOptions = computed(()=> {
  if(!props.canReview)return []
  if(props.view==='review')return [
    {value:'approve',label:'批量通过'}, {value:'stockout',label:'批量缺货'},
    {value:'reject',label:'批量驳回',reasonRequired:true},
  ]
  if(props.view==='stockout')return [{value:'restock',label:'批量已到货-可发货'}]
  return []
})
const applyReviewBulk = async (payload:{action:string;reason:string}) => {
  if(bulkBusy.value||!props.canReview||!reviewSelection.value.length
    ||!reviewBulkOptions.value.some(opt=>opt.value===payload.action)
    ||(payload.action==='reject'&&payload.reason.trim().length<3))return
  if(reviewSelection.value.length>50){
    publishGlobalFeedback({state:'BLOCKED',title:'每批最多处理 50 单',message:'当前选中 '+reviewSelection.value.length+' 单，请先减少勾选。'})
    return
  }
  bulkBusy.value=true
  const ids=[...reviewSelection.value]
  try{
    const response=await fetch('/logistics/oversea/batch-review', {
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{Accept:'application/json','Content-Type':'application/json','X-Requested-With':'XMLHttpRequest',...currentCsrfHeaders()},
      body:JSON.stringify({order_ids:ids,action:payload.action,reason:payload.reason}),
    })
    const data=await response.json() as {message?:string;results?:{order_id:number;ok:boolean;message:string}[]}
    if(!response.ok)throw new Error(response.status===419?'登录会话已过期，请刷新后核对。':data.message||'批量操作未完成')
    const result=data.results??[]
    const failed=result.filter(r=>!r.ok)
    reviewSelection.value=failed.map(r=>r.order_id)
    publishGlobalFeedback({state:failed.length?'BLOCKED':'SUCCESS',title:'海外仓批量审核：成功 '+(result.length-failed.length)+' 条，失败 '+failed.length+' 条',
      message:failed.length?failed.slice(0,8).map(r=>(r.order_id+'：'+r.message)).join('；'):'按海外仓原业务规则逐单审核完成。'})
    router.reload({only:['rows','viewCounts']})
  }catch(error){
    publishGlobalFeedback({state:'ERROR',title:'海外仓批量审核未完成',message:error instanceof Error?error.message:'请刷新后核对订单状态。'})
  }finally{bulkBusy.value=false}
}
const reopenReview = (row:Row) => router.post(`/logistics/oversea/orders/${row.order_id}/reopen-review`, {}, { preserveScroll:true })
const reject = (row:Row) => {
  const note = draft(row).note.trim()
  if (!note) { rowErrors[row.order_id] = '请先填写驳回原因'; return }
  rowErrors[row.order_id] = ''
  router.post(`/logistics/oversea/orders/${row.order_id}/reject`, { note }, { preserveScroll:true, preserveState:true })
}
const itemText = (row:Row) => row.items.map(item => `${item.sku ?? '无SKU'} × ${item.quantity}`).join(' / ') || '—'
const pageUrl = (page:number, perPage:number = props.rows.per_page || 50) => {
  if(!gridEnabled.value)return `${basePath.value}?view=${encodeURIComponent(props.view)}&page=${page}&per_page=${perPage}`
  const q=new URLSearchParams({view:props.view,page:String(page),per_page:String(perPage),global_grid:gridEnabled.value?'1':'0'})
  if(gridEnabled.value){
    if(gridSearchText.value)q.set('search',gridSearchText.value)
    if(props.gridSort?.colId)q.set('sort_col',props.gridSort.colId)
    if(props.gridSort?.direction)q.set('sort_dir',props.gridSort.direction)
    for(const key of ['carrier','tracking'])if(props.gridFilters?.[key])q.set('filter_'+key,props.gridFilters[key])
  }
  return basePath.value+'?'+q.toString()
}
const goPage = (page:number) => {
  const next = Math.max(1, Math.min(props.rows.last_page || 1, page))
  router.visit(pageUrl(next), { preserveScroll:true, preserveState:true })
}
const changePerPage = (event:Event) => {
  const perPage = Number((event.target as HTMLSelectElement).value)
  router.visit(pageUrl(1, perPage), { preserveScroll:true, preserveState:true })
}
const jumpPage = ref('')
const submitJump = () => {
  const page = Number(jumpPage.value)
  if (!Number.isInteger(page)) return
  goPage(page)
}
watch(()=>[props.view,props.warehouseKey,props.rows.current_page,props.rows.per_page],()=>{reviewSelection.value=[]})
watch(()=>visibleRows.value,rows=>{const present=new Set(rows.map(row=>row.order_id));reviewSelection.value=reviewSelection.value.filter(id=>present.has(id))})
const nativeOperationRows = computed(()=>gridEnabled.value
  ? visibleRows.value.filter(row=>row.order_id===activeOperationId.value)
  : visibleRows.value)
const openGridOperation = (id:number) => {
  activeOperationId.value=id
  void nextTick(()=>document.querySelector('.oversea-native-workflow')?.scrollIntoView({block:'nearest',behavior:'smooth'}))
}
const overseaGridRows = computed<GridListRow[]>(()=>visibleRows.value.map(row=>({
  id:row.order_id,line:row.line,order_number:row.erp_order_id??'—',order_url:row.order_url,order_date:row.order_date??'—',
  workflow_status:row.workflow_status_projection?.zh??'—',
  review_status:row.review_status==='approved'?'已通过':row.review_status==='stockout'?'缺货':'待审核',
  shipment_status:row.shipment_status==='shipped'?'已发货':row.shipment_status==='pending'?'待发货':row.shipment_status,
  warehouse_name:row.warehouse_name??'—',carrier:row.carrier??'—',service:row.service??'—',
  tracking_number:row.tracking_number??'—',note:row.note??'',
  recipient_name:row.recipient_name??'—',country:row.country??'—',
  sku:row.items.map(item=>item.sku??'无SKU').join(' / ')||'—',
  quantity:row.items.map(item=>item.quantity).join(' / ')||'—',
  tracking_sync_status:row.source_type==='woocommerce'?trackingLabel(row.tracking_sync_status):'—',
  dispatch_status:row.warehouse_dispatch?.label??'—',
  oms_order:row.xlwms?.oms_order_no??row.shipout_production?.provider_order_id??'—',
  shipped_at:row.shipped_at??'—',
})))
const overseaGridColumns = computed<ColDef<GridListRow>[]>(()=>[
  gridColumn({colId:'order',field:'order_number',headerName:'订单号',width:194,minWidth:165,
    pinned:'left',lockPinned:true,lockVisible:true,suppressMovable:true,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'link',hrefFor:(r:GridListRow)=>String(r.order_url??'')}}),
  gridColumn({colId:'line',field:'line',headerName:'#',width:70,sortable:true}),
  gridColumn({colId:'order_date',field:'order_date',headerName:'订单日期',width:128,minWidth:108}),
  gridColumn({colId:'status',field:'workflow_status',headerName:'统一状态',width:132}),
  gridColumn({colId:'review',field:'review_status',headerName:'审核状态',width:110}),
  gridColumn({colId:'shipment',field:'shipment_status',headerName:'发货状态',width:114}),
  gridColumn({colId:'warehouse',field:'warehouse_name',headerName:'仓库',width:82,minWidth:76,maxWidth:82,
    tooltipField:'warehouse_name',cellStyle:{overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap'}}),
  ...(props.warehouseKey==='us-moreplus'||props.warehouseKey==='us-3sm'?[gridColumn({
    colId:'dispatch',field:'dispatch_status',headerName:'仓库处理状态',width:230,tooltipField:'dispatch_status',
  }),gridColumn({colId:'oms_order',field:'oms_order',headerName:'OMS 单号',width:178})]:[]),
  gridColumn({colId:'carrier',field:'carrier',headerName:'承运商',width:145}),
  gridColumn({colId:'service',field:'service',headerName:'服务渠道',width:154}),
  gridColumn({colId:'tracking',field:'tracking_number',headerName:'Tracking',width:208,sortable:true,tooltipField:'tracking_number'}),
  gridColumn({colId:'note',field:'note',headerName:'备注',width:180,tooltipField:'note'}),
  gridColumn({colId:'recipient',field:'recipient_name',headerName:'收件人',width:140}),
  gridColumn({colId:'country',field:'country',headerName:'国家',width:106}),
  gridColumn({colId:'sku',field:'sku',headerName:'SKU',width:210,tooltipField:'sku'}),
  gridColumn({colId:'quantity',field:'quantity',headerName:'数量',width:95}),
  gridColumn({colId:'sync',field:'tracking_sync_status',headerName:'官网同步',width:126}),
  gridColumn({colId:'shipped_at',field:'shipped_at',headerName:'发货时间',width:188,sortable:true}),
  gridColumn({colId:'actions',field:'id',headerName:'操作',width:194,minWidth:190,maxWidth:203,
    pinned:'right',lockPinned:true,lockVisible:true,suppressMovable:true,
    cellRenderer:DomesticGridActionCell,cellRendererParams:{
      view:props.view,canReview:props.canReview,canOperate:props.canOperate,
      get busy(){return reviewBusy.value},
      native:(r:GridListRow)=>openGridOperation(Number(r.id)),
      approve:(r:GridListRow)=>{const source=visibleRows.value.find(row=>row.order_id===Number(r.id));if(source)reviewAction(source,'approve')},
      stockout:(r:GridListRow)=>{const source=visibleRows.value.find(row=>row.order_id===Number(r.id));if(source)reviewAction(source,'stockout')},
      restock:(r:GridListRow)=>{const source=visibleRows.value.find(row=>row.order_id===Number(r.id));if(source)reviewAction(source,'restock')},
      reopen:(r:GridListRow)=>{const source=visibleRows.value.find(row=>row.order_id===Number(r.id));if(source)reopenReview(source)},
      reject:(r:GridListRow,reason:string)=>{const source=visibleRows.value.find(row=>row.order_id===Number(r.id));if(source)rejectGrid(source,reason)},
    },
  }),
])
const onGridSelection = (rows:GridListRow[]) => {
  const ids=new Set(rows.map(row=>Number(row.id)).filter(id=>Number.isSafeInteger(id)&&id>0))
  if(props.canReview && ['review','stockout'].includes(props.view)){
    reviewSelection.value=visibleRows.value.filter(row=>ids.has(row.order_id) && row.shipment_status==='pending'
      && (props.view==='review'?row.review_status==='pending':row.review_status==='stockout')).map(row=>row.order_id)
  }else if(showShipoutSelection.value){
    shipoutBatchSelection.value=visibleRows.value.filter(row=>ids.has(row.order_id)
      && row.shipment_status==='pending' && !row.shipout_production?.attempted).map(row=>row.order_id)
  }else if(props.canTrackingSync){
    trackingSelection.value=visibleRows.value.filter(row=>ids.has(row.order_id)
      && eligibleForBatchTracking(row)).map(row=>row.order_id)
  }
}
const compactPages = computed<(number|string)[]>(() => {
  const last = props.rows.last_page || 1
  if (last <= 7) return Array.from({ length:last }, (_, index) => index + 1)
  const pages = new Set<number>([1, last])
  for (let page = props.rows.current_page - 2; page <= props.rows.current_page + 2; page++) {
    if (page > 1 && page < last) pages.add(page)
  }
  const ordered = [...pages].sort((a,b) => a-b)
  const result:(number|string)[] = []
  ordered.forEach((page, index) => {
    const previous = ordered[index - 1]
    if (index > 0 && previous !== undefined && page - previous > 1) result.push(`ellipsis-${previous}-${page}`)
    result.push(page)
  })
  return result
})
</script>

<template>
  <Head :title="`${pageTitle} · BYL ERP`" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP','物流','海外仓发货', warehouse?.label ?? '全部海外仓']">
    <section class="oversea-page">
      <div class="oversea-workbench">
        <aside class="warehouse-rail" aria-label="海外仓切换" data-testid="oversea-compact-warehouse-rail">
          <p class="warehouse-rail-title">海外仓</p>
          <button type="button" class="warehouse-link" title="全部海外仓" :aria-label="'切换仓库：全部海外仓'" :class="{ active: warehouseKey === 'all' }" @click="switchWarehouse('/logistics/oversea')">全部海外仓</button>
          <button v-for="option in warehouseOptions" :key="option.key" type="button" class="warehouse-link" :title="option.label" :aria-label="'切换仓库：'+option.label" :class="{ active: warehouseKey === option.key }" @click="switchWarehouse(option.url)">{{ option.label }}</button>
        </aside>
        <main class="oversea-main">
      <header class="oversea-heading">
        <div><h1>{{ pageTitle }}</h1><p v-if="!gridEnabled && warehouseKey === 'us-3sm'">审核通过后，下载订单文件，在世迈 OMS 人工导入。重复导入前先检查当前草稿箱，避免重复下单。</p><p v-else-if="!gridEnabled">审核 Vita 分仓结果；通过后进入真实下单，驳回则退回运营重新分配。</p><p v-if="!gridEnabled && warehouseKey === 'us-moreplus'" class="xlwms-channel-hint">ShipOut 自动渠道和指定渠道需核对仓库最终承运商，不能假定 USPS Priority。</p></div>
        <div class="oversea-file-actions">
          <button type="button" class="file-button" data-testid="oversea-global-grid-toggle" @click="toggleGrid">{{gridEnabled?'返回原表':'切换新版表'}}</button>
          <button v-if="shipoutProductionBatchUi && warehouseKey==='us-moreplus'" type="button"
            class="file-button primary" :disabled="shipoutBatchBusy || Boolean(shipoutBatchStatus && ['queued','running','awaiting_oms','paused'].includes(shipoutBatchStatus.status))"
            @click="shipoutBatchCheck">一键推送仓库</button>
          <a v-if="warehousePortal" class="file-button file-link warehouse-portal-link"
            :href="warehousePortal.href" target="_blank" rel="noopener noreferrer">
            {{ warehousePortal.label }} <span aria-hidden="true">↗</span>
          </a>
          <template v-if="warehouse && view === 'pending'">
          <button v-if="warehouseKey === 'us-3sm' && canOperate" type="button" class="file-button" :disabled="refreshBusy" @click="refreshOmsStatuses">{{refreshBusy?'查询中…':'刷新仓库状态'}}</button>
          <a v-if="warehouseKey === 'us-3sm'" class="file-button file-link" href="https://oms.xlwms.com/warehouse/packet" target="_blank" rel="noopener noreferrer">查看仓库草稿箱</a>
          <a v-if="warehouseKey==='us-3sm' && canOperate" class="file-button file-link"
            :href="`${basePath}/export`">⇩ 批量下载订单文件</a>
          <a v-else class="file-button file-link" :href="`${basePath}/export`">⇩ 下载仓库下单表</a>
          <input ref="trackingFileInput" class="hidden-file" type="file" accept=".xlsx,.csv,.txt" @change="previewTrackingFile" />
          <button v-if="canOperate && canUploadTrackingFile" type="button" class="file-button primary" @click="chooseTrackingFile">⇧ 上传物流回传表</button>
          </template>
        </div>
      </header>
      <section v-if="shipoutProductionBatchUi && warehouseKey==='us-moreplus' && (shipoutBatchPreview || shipoutBatchStatus || shipoutBatchError || shipoutProductionBatchPaused)"
        class="xlwms-batch-result" role="status">
        <small v-if="shipoutProductionBatchPaused" class="xlwms-error" role="alert">当前批量推送已安全暂停：仓库重复判定原因待核对。管理员可查看预览与历史结果，但不可提交新订单。</small>
        <strong v-if="shipoutBatchPreview">
          {{shipoutBatchPreview.dry_run?'Dev 模拟预览（不连接真实 OMS）':'MorePlus 真实推送预览'}}
          · {{shipoutBatchPreview.scope==='selected'?'手动选择':'全部待发货'}}
          扫描 {{shipoutBatchPreview.scan_count??shipoutBatchPreview.count}} 单
          · 其中缺货 {{shipoutBatchPreview.stockout_count??0}} 单
          · 待逐单校验 {{shipoutBatchPreview.count}} 单
          · 排除 {{shipoutBatchPreview.excluded_count??0}} 单
          <span v-if="shipoutBatchPreview.overflow">（超过单批 200 单限制，请分批处理）</span>
        </strong>
        <small v-if="shipoutBatchPreview" class="xlwms-note">包含缺货订单的扫描统计；库存不足、未审核、历史已提交及重复风险订单不向仓库提交。</small>
        <button v-if="shipoutBatchPreview && shipoutBatchPreview.count>0 && !shipoutBatchPreview.overflow"
          type="button" class="file-button primary" :disabled="shipoutBatchBusy || shipoutProductionBatchPaused" @click="shipoutBatchStart">
          {{shipoutBatchPreview.dry_run?'确认运行 Dev 模拟':'确认逐单推送 '+shipoutBatchPreview.count+' 单'}}
        </button>
        <strong v-if="shipoutBatchStatus">
          {{shipoutBatchStatus.dry_run?'Dev 模拟批次':'MorePlus 推送批次'}} ·
          已核实 {{shipoutBatchStatus.processed}} / {{shipoutBatchStatus.total}} · {{shipoutBatchState(shipoutBatchStatus.status)}}
        </strong>
        <div v-if="shipoutBatchStatus" class="shipout-batch-results">
          <span v-for="item in shipoutBatchStatus.items" :key="item.order_id">
            {{item.order_no}} · {{shipoutBatchLabel(item.status)}}
            <small v-if="item.result_code">（{{shipoutBatchReason(item.result_code)}}）</small>
          </span>
        </div>
        <small v-if="shipoutBatchError" class="xlwms-error" role="alert">{{shipoutBatchError}}</small>
        <small>自动逐单执行；订单接单不代表出库，不同步 Tracking。不确定结果绝不自动重试。</small>
      </section>
      <div v-if="warehouseKey === 'us-3sm' && (batchResult || batchError)" class="xlwms-batch-result" role="status">
        <strong v-if="batchResult">本次处理 {{ batchResult.count }} 单 · 新建 {{batchResult.summary?.created??0}} · 已存在 {{batchResult.summary?.existing??0}} · 阻断 {{batchResult.summary?.blocked??0}} · 待核实 {{batchResult.summary?.uncertain??0}}</strong>
        <small v-if="batchResult?.blocked_reason">正式建单权限关闭，本次没有发出任何新订单</small>
        <span v-if="batchResult" v-for="item in batchResult.results" :key="item.order_id">订单 {{ item.order_id }} · {{ batchCodeText[item.code] || item.code }}<small v-if="item.reason">（{{ item.reason }}）</small></span>
        <span v-if="batchError" class="xlwms-error">{{ batchError }}</span>
      </div>
      <div v-if="!gridEnabled && warehouse && view === 'pending'" class="oversea-file-flow">
        <template v-if="warehouseKey==='us-3sm'"><span>① 审核订单</span><b>→</b><span>② 下载订单文件</span><b>→</b><span>③ OMS 人工导入草稿</span><b>→</b><span>④ 仓库上传真实面单并发货</span></template>
        <template v-else><span>① 审核通过</span><b>→</b><span>② 下载 USPS 草稿导入表</span><b>→</b><span>③ OMS 草稿－待提交</span><b>→</b><span>④ 人工上传真实面单并提交</span><b>→</b><span>⑤ 仓库 Tracking 回写 ERP</span></template>
        <small v-if="warehouseKey!=='us-3sm'">{{ trackingFileHint }}</small>
      </div>

      <section v-if="warehouse && view === 'pending' && trackingImportPreview" class="tracking-preview">
        <div class="tracking-preview-head">
          <div>
            <strong>单号表预览</strong>
            <span>可应用 {{ trackingImportPreview.apply_count }} · 重复跳过 {{ trackingImportPreview.noop_count }} · 阻断 {{ trackingImportPreview.blocked_count }}</span>
          </div>
          <button type="button" class="file-button primary" :disabled="trackingImportPreview.blocked_count > 0 || trackingImportPreview.apply_count === 0" @click="applyTrackingPreview">确认导入并标记已发货</button>
        </div>
        <p class="tracking-hint">Tracking 是发货事实主键。仓库状态如“处理中 / Pending”不会阻止发货；有可靠发货时间则沿用，没有则以本次确认时间记为 shipped_at。阻断项必须修正后重新上传。</p>
        <div class="tracking-preview-table-wrap">
          <table class="tracking-preview-table">
            <thead><tr><th>行</th><th>订单号</th><th>Tracking</th><th>Carrier</th><th>仓库服务</th><th>仓库状态</th><th>发货时间</th><th>结果</th><th>说明</th></tr></thead>
            <tbody><tr v-for="row in trackingPreviewRows" :key="`${row.row}-${row.order_number}`"><td>{{ row.row }}</td><td>{{ row.order_number }}</td><td>{{ row.tracking_number }}</td><td>{{ row.carrier ?? '—' }}</td><td>{{ row.provider_service ?? '—' }}</td><td>{{ row.provider_status ?? '—' }}</td><td>{{ row.shipped_at }} <small>{{ row.shipped_time_source === 'provider' ? '仓库时间' : 'ERP确认时间' }}</small></td><td><span class="tracking-status" :class="row.status">{{ trackingStatusText(row.status) }}</span></td><td>{{ row.message || '—' }}</td></tr></tbody>
          </table>
        </div>
        <small v-if="trackingImportPreview.rows.length > trackingPreviewRows.length">仅展示前 {{ trackingPreviewRows.length }} 行；应用时仍会校验整份文件。</small>
      </section>

      <nav class="oversea-tabs" aria-label="海外仓状态视图">
        <button v-for="([key,label]) in viewTabs" :key="key" type="button" class="oversea-tab" :class="{ active:view === key }" @click="switchView(key)">
          {{ label }} <span>{{ liveViewCounts[key] ?? 0 }}</span>
        </button>
      </nav>

      <div v-if="showShipoutSelection && shipoutBatchSelection.length" class="tracking-selection-bar">
        已勾选 {{ shipoutBatchSelection.length }} 单（跨页保留）
        <button type="button" class="file-button primary" :disabled="shipoutBatchBusy" @click="shipoutBatchCheck">仅推送所选订单</button>
        <button type="button" class="file-button" @click="shipoutBatchSelection=[];shipoutBatchPreview=null">清空勾选</button>
      </div>
      <div v-if="canTrackingSync && trackingSelection.length" class="tracking-selection-bar">
        已选 {{ trackingSelection.length }} 项
        <button type="button" class="file-button primary" :disabled="trackingBusy" @click="pushTracking(trackingSelection)">批量同步官网物流</button>
        <button type="button" class="file-button" @click="trackingSelection=[]">清除选择</button>
      </div>
      <div v-if="trackingFeedback" class="tracking-action-feedback" role="status">{{ trackingFeedback }}</div>
      <div v-if="gridReviewError" class="tracking-action-feedback" role="alert">{{gridReviewError}}</div>
      <div v-if="gridEnabled" class="oversea-grid-main" data-testid="oversea-global-grid-workbench">
        <GlobalGrid :key="'oversea-grid-'+warehouseKey+'-'+view"
          :namespace="'grid.logistics.oversea.'+view+'.global.v1'"
          :title="pageTitle" :columns="overseaGridColumns" :rows="overseaGridRows"
          :required-column-ids="['order','actions']" :page="rows" row-identity-key="id"
          :search="gridSearch??''" :sort="gridSort??undefined"
          :filter-columns="['carrier','tracking']" :filter-values="gridFilters??{}"
          :enable-selection="Boolean((canReview && ['review','stockout'].includes(view)) || canTrackingSync || showShipoutSelection)"
          :selected-order-ids="(canReview && ['review','stockout'].includes(view))?reviewSelection:showShipoutSelection?shipoutBatchSelection:trackingSelection"
          search-placeholder="搜索全部订单号 / Tracking / 收件人 / SKU..."
          @search-change="searchGrid" @sort-change="sortGrid" @column-search-change="filterGrid"
          @selection-change="onGridSelection" @page-change="gridNavigate({page:$event})"
          @page-size-change="($event!=='all')&&gridNavigate({per_page:$event,page:1})">
          <template #footer-left>
            <GridBatchFooterControls :selected-count="reviewSelection.length" :options="reviewBulkOptions"
              :busy="bulkBusy" @apply="applyReviewBulk" />
          </template>
        </GlobalGrid>
        <div v-if="activeOperationId!==null" class="native-operation-heading">
          <strong>当前订单操作 · 完整原有业务功能</strong>
          <button type="button" class="file-button" @click="activeOperationId=null">收起订单操作</button>
        </div>
      </div>
      <div v-if="!gridEnabled || activeOperationId!==null" class="oversea-table-wrap oversea-native-workflow">
        <table class="oversea-table">
          <thead><tr>
            <th v-if="canTrackingSync || showShipoutSelection">
              <label v-if="showShipoutSelection" class="shipout-select-all">
                <input type="checkbox" aria-label="选择当前页待发货订单"
                  :checked="shipoutPageAllSelected" @change="selectShipoutPage(($event.target as HTMLInputElement).checked)" />选择
              </label>
              <template v-else>选择</template>
            </th>
            <th class="decision-col sticky-decision">审核</th>
            <th v-if="warehouseKey === 'us-3sm'" class="xlwms-header">世迈仓 · 人工下单</th>
            <th v-if="warehouseKey === 'us-moreplus'" class="xlwms-header">MorePlus ShipOut · API / 物流</th>
            <th v-for="column in grid.visibleColumns" :key="column.key" :class="{ 'line-col':column.key === 'line' }">{{ column.label }}</th>
            <th>官网同步</th><th v-if="canTrackingSync">操作</th>
            <ColumnSettingsHeader :columns="grid.columns" :sync-state="grid.syncState" @toggle="grid.toggle" @start-drag="grid.startDrag" @drop="grid.drop" @reset="grid.reset" />
          </tr></thead>
          <tbody>
            <tr v-for="row in nativeOperationRows" :key="row.order_id">
              <td v-if="canTrackingSync || showShipoutSelection">
                <input v-if="showShipoutSelection && row.shipment_status==='pending'" type="checkbox"
                  :aria-label="'选择推送 '+(row.erp_order_id??row.order_id)"
                  :checked="shipoutBatchSelection.includes(row.order_id)"
                  @change="selectShipout(row.order_id, ($event.target as HTMLInputElement).checked)" />
                <input v-else-if="canTrackingSync && eligibleForBatchTracking(row)" type="checkbox"
                  :aria-label="'选择同步 '+(row.erp_order_id??row.order_id)" :checked="trackingSelection.includes(row.order_id)"
                  @change="chooseTracking(row.order_id, ($event.target as HTMLInputElement).checked)" />
              </td>
              <td class="decision-cell sticky-decision">
                <template v-if="canReview && view === 'review'">
                  <button type="button" class="decision approve" @mousedown.prevent @click="approve(row)">通过</button>
                  <button type="button" class="decision stockout" @mousedown.prevent @click="reviewAction(row,'stockout')">缺货</button>
                  <button type="button" class="decision reject" title="如需驳回，请先填写备注原因" @mousedown.prevent @click="reject(row)">驳回</button>
                </template>
                <template v-else-if="canReview && view === 'stockout' && row.review_status==='stockout'">
                  <button type="button" class="decision approve" @click="reviewAction(row,'restock')">已到货-可发货</button>
                </template>
                <div v-else-if="row.review_status === 'approved'" class="decision-approved"><span class="decision-state approved">已通过</span><button v-if="canOperate" type="button" class="reopen-review" @click="reopenReview(row)">重新审核</button></div>
                <span v-else class="decision-state">{{row.review_status==='stockout'?'缺货':'待审核'}}</span>
              </td>
              <td v-if="warehouseKey === 'us-3sm'" class="xlwms-cell">
                <div class="xlwms-inline" :data-oms-order-id="row.order_id">
                  <template v-if="canOperate && row.review_status === 'approved' && view==='pending'">
                    <strong class="xlwms-state">人工导入 OMS</strong>
                    <a class="xlwms-action primary xlwms-manual-download" :href="manualOrderDownload(row)"
                      >{{manualDownloadText(row)}}</a>
                    <small class="xlwms-note">导入前先查看当前草稿箱；已有同一订单就不要重复导入。</small>
                    <small class="xlwms-note">文件使用 USPS 上传面单渠道，仓库人员后续上传真实面单。</small>
                  </template>
                  <span v-else class="xlwms-note">{{row.review_status !== 'approved' ? '审核通过后可下载' : view==='issued'?'已下发 · 等待真实 Tracking，禁止重新导入 OMS':'请联系物流主管'}}</span>
                  <details v-if="hasOmsHistory(row)" class="xlwms-history">
                    <summary>查看历史仓库记录</summary>
                    <small v-if="omsRow(row)?.oms_order_no">历史 OMS 单号：{{omsRow(row)?.oms_order_no}} · {{omsLabel(row)}}</small>
                    <small v-if="row.web_draft?.oms_order_no">曾自动创建的 OMS 单号：{{row.web_draft.oms_order_no}}</small>
                    <button v-if="omsRow(row)?.oms_order_no" type="button" class="xlwms-action" :disabled="omsBusy[row.order_id]"
                      @click="omsRefresh(row)">{{omsBusy[row.order_id]?'查询中…':'查询历史状态'}}</button>
                  </details>
                  <small v-if="omsErrors[row.order_id]" role="alert" class="xlwms-error">{{omsErrors[row.order_id]}}</small>
                </div>
              </td>
              <td v-if="warehouseKey === 'us-moreplus'" class="xlwms-cell">
                <div class="xlwms-inline" :data-shipout-order-id="row.order_id">
                  <template v-if="shipoutProductionUi">
                    <strong v-if="row.shipout_production?.status==='oms_quarantined'" class="xlwms-error" role="alert">下发异常 · {{ row.shipout_production.label }}</strong>
                    <strong v-else-if="row.shipout_production?.status==='oms_shipped_pending_tracking'" class="xlwms-state">OMS 已发货 · Tracking 待回写</strong>
                    <strong v-else-if="row.shipout_production?.status==='oms_warehouse_awaiting'" class="xlwms-state">已下发 · 待仓库处理</strong>
                    <strong v-else-if="row.shipout_production?.status==='oms_confirmed'" class="xlwms-state">已下发 · OMS 已核实（未实际出库）</strong>
                    <strong v-else class="xlwms-state">{{ row.shipout_production?.attempted?'下发中 · 禁止重新提交':'待发货 · 可预检' }}</strong>
                    <small v-if="row.warehouse_dispatch?.last_checked_at" class="xlwms-note">最近 OMS 回读：{{new Date(row.warehouse_dispatch.last_checked_at).toLocaleString('zh-CN',{hour12:false})}}</small>
                    <template v-if="!shipoutProductionReadEnabled">
                      <small class="xlwms-note">正式 API 已部署，当前未开通；请继续使用人工下载与物流回传流程。</small>
                    </template>
                    <template v-else>
                      <small v-if="row.order_id<(shipoutProductionMinOrderId||0) || !(shipoutProductionMinOrderId||0)" class="xlwms-note">历史订单不纳入正式 API V1 自动化范围，沿用仓库人工发货流程。</small>
                      <div class="xlwms-buttons">
                        <button v-if="row.review_status==='approved' && row.shipment_status==='pending' && shipoutProductionOperatorAllowed && !row.shipout_production?.attempted && row.order_id>=(shipoutProductionMinOrderId||0) && (shipoutProductionMinOrderId||0)>0"
                          type="button" class="xlwms-action" :disabled="shipoutProdBusy[row.order_id] || shipoutProdSentOnce[row.order_id]"
                          @click="shipoutProdPreflight(row)">{{shipoutProdBusy[row.order_id]?'预检中…':'正式 API 预检'}}</button>
                        <button type="button" class="xlwms-action" :disabled="shipoutProdBusy[row.order_id]"
                          @click="shipoutProdRefresh(row)">{{shipoutProdBusy[row.order_id]?'查询中…':'查询正式 OMS'}}</button>
                      </div>
                      <div v-if="shipoutProdPreview[row.order_id]" class="xlwms-confirm">
                        <small>订单 {{shipoutProdPreview[row.order_id].order_no}} · {{shipoutProdPreview[row.order_id].items.map(i=>i.omsSku+' × '+i.qty).join(' / ')}} · {{shipoutProdPreview[row.order_id].ship_date}}（LA）</small>
                        <button v-if="shipoutProdCanSend(row)" type="button" class="xlwms-action primary"
                          :disabled="shipoutProdBusy[row.order_id]" @click="shipoutProdSend(row)">确认提交正式仓 · 仅一次</button>
                        <small v-else>正式发货未授权；只能查询和预检，不会发送订单。</small>
                      </div>
                      <small v-if="row.shipout_production?.status==='oms_quarantined'" class="xlwms-error" role="alert">
                        下发异常已隔离，其他合格订单不受影响。本单禁止自动重发；请 Dede 核实原 OMS 后人工处理。
                      </small>
                      <small v-if="row.shipout_production?.provider_order_id && !['oms_quarantined','oms_confirmed','oms_shipped_pending_tracking','oms_warehouse_awaiting'].includes(row.shipout_production?.status??'') && !shipoutProdStatus[row.order_id]" class="xlwms-note">
                        API ACK：{{row.shipout_production.provider_order_id}} · 尚未证实 OMS 有效接单，禁止再次推送；请查询正式 OMS。
                      </small>
                      <small v-if="row.shipout_production?.status==='oms_shipped_pending_tracking'" class="xlwms-note">
                        ShipOut 官方 OMS 状态 4：Shipped。ERP 尚未录入真实 Tracking；须核对包裹号、实际出库时间后保存，官网由同步模块处理。
                      </small>
                      <small v-else-if="row.shipout_production?.status==='oms_warehouse_awaiting'" class="xlwms-note">
                        ShipOut 官方 OMS 状态 2：Awaiting Fulfillment。已下发仓库，等待仓库处理，不可重新提交。
                      </small>
                      <small v-else-if="row.shipout_production?.status==='oms_confirmed'" class="xlwms-note">
                        OMS 已下发并持续可见，但并未证明真实出库。须等仓库提供实际出库单号和 Tracking，随后再确认 ERP 发货。
                      </small>
                      <small v-else-if="row.shipout_production?.attempted && !shipoutProdStatus[row.order_id] && !row.shipout_production?.provider_order_id" class="xlwms-note">
                        提交结果待人工核对，禁止再次下发；请查询正式 OMS。
                      </small>
                      <small v-if="shipoutProdStatus[row.order_id]?.reconciliation==='PROVIDER_CANCELLED_OR_ABORTED_MANUAL_REVIEW'" class="xlwms-error" role="alert">
                        OMS 已取消／中止 · {{shipoutProdStatus[row.order_id].provider_order_id||'仓库订单 ID 未返回'}}。{{shipoutProdStatus[row.order_id].provider_failure_code==='PROVIDER_DUPLICATE_ORDER'?'仓库判定重复订单':shipoutProdStatus[row.order_id].provider_failure_code==='PROVIDER_EZEESHIP_SHIPPING_ERROR'?'EzeeShip 渠道异常':'请核对仓库取消原因'}}。禁止重发；不能同步 Tracking 或标记已发货。
                      </small>
                      <small v-else-if="shipoutProdStatus[row.order_id]" class="xlwms-note">
                        OMS：{{shipoutProdStatus[row.order_id].reconciliation==='NO_ERP_ATTEMPT_AND_NO_OMS_ORDER'?'ERP 未提交，OMS 无此单':shipoutProdStatus[row.order_id].reconciliation==='OMS_ORDER_WITHOUT_ERP_LEDGER_MANUAL_REVIEW'?'OMS 有单但 ERP 无记录，人工核对':shipoutProdStatus[row.order_id].reconciliation==='ORDER_VISIBLE_MANUAL_REVIEW_ONLY'?(shipoutProdStatus[row.order_id].oms_persisted_state==='provider_shipped'?'ShipOut 已发货 · ERP 待 Tracking 回写':shipoutProdStatus[row.order_id].oms_persisted_state==='warehouse_awaiting'?'仓库待处理':shipoutProdStatus[row.order_id].oms_persisted_state==='confirmed'?'仓库处理中（已核实）':'仓库订单可见 · 核实中'):'待仓库确认'}} · {{shipoutProdStatus[row.order_id].provider_order_id||'暂无仓库订单 ID'}} · {{shipoutProdStatus[row.order_id].oms_persisted_state==='provider_shipped'?'ERP 仍未完成 Tracking 同步':'ERP 未标记发货'}}
                      </small>
                    </template>
                    <small v-if="shipoutProdErrors[row.order_id]" role="alert" class="xlwms-error">{{shipoutProdErrors[row.order_id]}}</small>
                  </template>
                  <template v-else-if="!shipoutSandboxReadEnabled && !shipoutSandboxDispatchEnabled">
                    <span class="xlwms-state">API 发货未开通</span>
                    <small class="xlwms-note">当前请继续使用「下载仓库下单表」和「上传物流回传表」。</small>
                  </template>
                  <template v-else>
                    <span class="xlwms-state">{{ shipoutStatus[row.order_id]?.state === 'ready_for_erp_review' ? '仓库已发货 · 待人工回写' : shipoutStatus[row.order_id]?.state === 'pending' ? '仓库处理中' : shipoutStatus[row.order_id]?.state === 'not_found' ? 'OMS 未找到订单' : '待查询仓库状态' }}</span>
                    <small v-if="shipoutStatus[row.order_id]?.tracking_number" class="xlwms-track">Tracking {{shipoutStatus[row.order_id]?.tracking_number}}（未同步 Woo）</small>
                    <small v-if="shipoutStatus[row.order_id]?.carrier_id" class="xlwms-note">仓库实际物流商：{{shipoutCarrierName(shipoutStatus[row.order_id]?.carrier_id)}}</small>
                    <small v-if="shipoutStatus[row.order_id]?.shipped_time_utc" class="xlwms-note">仓库发货时间：{{shipoutStatus[row.order_id]?.shipped_time_utc}}（API 原始时间，时区待核实）</small>
                    <small v-if="shipoutStatus[row.order_id]?.state === 'ready_for_erp_review' && shipoutIsSyntheticTracking(shipoutStatus[row.order_id]?.tracking_number)" class="xlwms-note">沙盒模拟单号，仅验收 API 状态，不得写入真实 ERP / Woo</small>
                    <small v-if="shipoutStatus[row.order_id]?.reason" :class="shipoutStatus[row.order_id]?.state === 'blocked' ? 'xlwms-error' : 'xlwms-note'">{{shipoutMessage(shipoutStatus[row.order_id].reason)}}</small>
                    <div v-if="shipoutSandboxDispatchEnabled && row.review_status === 'approved' && row.shipment_status === 'pending' && canOperate && (shipoutSandboxSpecifiedChannel || shipoutSandboxOwnLabelEnabled)" class="xlwms-confirm">
                      <small>API 运输方式</small>
                      <select :value="shipoutModeFor(row)" :disabled="shipoutBusy[row.order_id] || shipoutSentOnce[row.order_id]" :aria-label="'订单 '+row.order_id+' ShipOut 运输方式'" @change="shipoutChangeMode(row,$event)">
                        <option value="9">由仓库选择渠道</option>
                        <option v-if="shipoutSandboxSpecifiedChannel" value="10">指定渠道：{{shipoutSandboxSpecifiedChannel.label}}</option>
                        <option v-if="shipoutSandboxOwnLabelEnabled" value="6">上传自己的 PDF 面单</option>
                      </select>
                    </div>
                    <div v-if="shipoutSandboxDispatchEnabled && shipoutSandboxOwnLabelEnabled && shipoutModeFor(row)==='6' && row.review_status==='approved' && canOperate" class="xlwms-confirm">
                      <small>仅沙盒 · 上传 PDF 面单（≤512KB）与真实格式 Tracking</small>
                      <input type="file" accept=".pdf,application/pdf" :disabled="shipoutBusy[row.order_id] || shipoutSentOnce[row.order_id]" @change="shipoutSetLabelFile(row,$event)" />
                      <input type="text" placeholder="面单 Tracking" :value="shipoutLabelTracking[row.order_id]||''" :disabled="shipoutBusy[row.order_id] || shipoutSentOnce[row.order_id]" @input="shipoutSetLabelTracking(row,$event)" />
                      <select :value="shipoutLabelCarriers[row.order_id]||'1'" @change="shipoutSetLabelCarrier(row,$event)">
                        <option value="1">USPS</option><option value="2">UPS</option><option value="3">FedEx</option><option value="4">DHL</option>
                      </select>
                      <button type="button" class="xlwms-action" :disabled="shipoutBusy[row.order_id] || shipoutSentOnce[row.order_id] || !shipoutLabelFiles[row.order_id] || !shipoutLabelTracking[row.order_id]" @click="shipoutStageLabel(row)">{{shipoutBusy[row.order_id]?'校验中…':'安全暂存面单'}}</button>
                      <small v-if="shipoutLabelTokens[row.order_id]" class="xlwms-note">面单暂存成功，15 分钟内预检并确认</small>
                    </div>
                    <div class="xlwms-buttons">
                      <button v-if="shipoutSandboxDispatchEnabled && row.review_status === 'approved' && row.shipment_status === 'pending' && canOperate" type="button" class="xlwms-action" :disabled="shipoutBusy[row.order_id] || shipoutSentOnce[row.order_id]" @click="shipoutPreflight(row)">{{shipoutBusy[row.order_id]?'预检中…':'API 发货'}}</button>
                      <button v-if="shipoutSandboxReadEnabled" type="button" class="xlwms-action" :disabled="shipoutBusy[row.order_id]" @click="shipoutRefresh(row)">{{shipoutBusy[row.order_id]?'查询中…':'查询物流状态'}}</button>
                    </div>
                  </template>
                  <button v-if="canOperate && shipoutSandboxTrackingApplyEnabled && shipoutStatus[row.order_id]?.state === 'ready_for_erp_review' && shipoutStatus[row.order_id]?.tracking_number && !shipoutIsSyntheticTracking(shipoutStatus[row.order_id]?.tracking_number)" type="button" class="xlwms-action primary" :disabled="shipoutBusy[row.order_id]" @click="shipoutApply(row)">确认 Tracking 回写 ERP</button>
                  <small v-if="shipoutStatus[row.order_id]?.state === 'erp_shipment_updated'" class="xlwms-note">已写入 ERP · Vita 待同步官网</small>
                  <small v-if="shipoutSandboxDispatchEnabled && !shipoutSandboxReadEnabled" class="xlwms-note">发货功能尚未准备就绪：请先开启安全查询</small>
                  <div v-if="shipoutPreview[row.order_id]" class="xlwms-confirm">
                    <small>订单 {{shipoutPreview[row.order_id].order_no || '未核实'}} · {{shipoutModeFor(row) === '6' ? '自有面单' : shipoutModeFor(row) === '10' ? '指定仓库渠道' : '仓库自动渠道'}} · {{shipoutPreview[row.order_id].items.map(i=>i.omsSku+' × '+i.qty).join(' / ')}}</small>
                    <button v-if="shipoutCanSend(row)" type="button" class="xlwms-action primary" @click="shipoutSend(row)">确认仅沙盒推送</button>
                    <small v-else>暂不可提交：{{shipoutPreview[row.order_id].blockers.map(shipoutMessage).join('；') || '等待授权'}}</small>
                  </div>
                  <small v-if="shipoutErrors[row.order_id]" role="alert" class="xlwms-error">{{shipoutErrors[row.order_id]}}</small>
                </div>
              </td>
              <td v-for="column in grid.visibleColumns" :key="column.key" :class="{ 'line-col':column.key === 'line', 'tracking-cell':column.key === 'tracking', 'items-cell':column.key === 'items' }">
                <template v-if="column.key === 'line'">{{ row.line }}</template>
                <template v-else-if="column.key === 'order'"><a class="order-link" :href="row.order_url ?? `/orders/${row.order_id}`">{{ row.erp_order_id ?? '—' }}</a></template>
                <template v-else-if="column.key === 'order_date'">{{row.order_date??'—'}}</template>
                <template v-else-if="column.key === 'status'"><OrderWorkflowStatusPill v-if="row.workflow_status_projection" :order-id="row.order_id" :projection="row.workflow_status_projection" :revision="row.workflow_revision" :can-edit="Boolean(row.can_edit_workflow_status)" :options="workflowStatusOptions ?? []" /><span v-else>—</span></template>
                <template v-else-if="column.key === 'warehouse'"><select :value="row.warehouse_id" :disabled="!canReview || view !== 'review'" class="cell-select warehouse" @change="changeWarehouseFromEvent(row, $event)"><option v-for="option in warehouseOptions" :key="option.id" :value="option.id">{{ option.label }}</option></select></template>
                <template v-else-if="column.key === 'carrier'"><select v-model="draft(row).carrier" :disabled="!canOperate || row.shipment_status === 'shipped' || inlineSaveBusy[row.order_id]" class="cell-select carrier" @change="saveCarrier(row)"><option v-for="carrier in carrierOptions" :key="carrier.value" :value="carrier.value">{{ carrier.label }}</option></select></template>
                <template v-else-if="column.key === 'service'"><select v-model="draft(row).service" :disabled="!canOperate || row.shipment_status === 'shipped' || !draft(row).carrier || inlineSaveBusy[row.order_id]" class="cell-select service" @change="saveService(row)"><option value="">请选择服务类型</option><option v-for="service in serviceOptionsFor(draft(row).carrier)" :key="service.id" :value="service.value">{{ service.label }}</option></select></template>
                <template v-else-if="column.key === 'tracking'">
                <div class="tracking-entry">
                  <input v-model="draft(row).tracking_number" :disabled="!canOperate || row.shipment_status === 'shipped' || inlineSaveBusy[row.order_id]" class="tracking-input" placeholder="填写真实 Tracking" @blur="saveTracking(row)" />
                  <button v-if="canOperate && warehouseKey === 'us-3sm' && view === 'pending' && row.review_status === 'approved' && row.shipment_status === 'pending'" type="button" class="manual-ship-button" @mousedown.prevent @click="manualShipUsSm(row)">确认发货</button>
                </div>
                <small v-if="inlineSaveFeedback[row.order_id]" class="xlwms-note">{{inlineSaveFeedback[row.order_id]}}</small>
                <div v-if="warehouseKey==='us-moreplus' && canOperate && ['issued','exceptions'].includes(view) && row.review_status==='approved' && row.shipment_status==='pending' && row.shipout_production?.attempted && row.warehouse_dispatch?.failure_code!=='PROVIDER_DUPLICATE_ORDER'"
                  class="tracking-entry" style="margin-top:5px">
                  <input v-model="draft(row).outbound_ref" class="tracking-input" placeholder="Dede 核实的人工出库单号" />
                  <button type="button" class="manual-ship-button" :disabled="inlineSaveBusy[row.order_id]" @click="manualShipMorePlus(row)">确认真实出库</button>
                </div>
                <small v-if="warehouseKey==='us-moreplus' && ['issued','exceptions'].includes(view) && row.shipout_production?.attempted && row.shipment_status==='pending' && row.warehouse_dispatch?.failure_code!=='PROVIDER_DUPLICATE_ORDER'" class="xlwms-note">先核对既有 OMS 原单，禁止重复推单；实际出库后再填真实 Tracking。网站由同步模块处理。</small>
              </template>
                <template v-else-if="column.key === 'note'"><div class="note-cell"><input v-model="draft(row).note" :disabled="!canEditRow || inlineSaveBusy[row.order_id]" class="note-input" :class="{ error: !!rowErrors[row.order_id] }"  :placeholder="warehouseKey==='us-moreplus'?'仅填备注；USPS单号自动转入Tracking':'等海运 / 等空运 / 缺货 / 太贵 / 送不到…'" @input="rowErrors[row.order_id] = ''" @blur="view === 'review' ? undefined : saveNote(row)" /><small v-if="rowErrors[row.order_id]" class="note-error">{{ rowErrors[row.order_id] }}</small></div></template>
                <template v-else-if="column.key === 'recipient'">{{ row.recipient_name ?? '—' }}</template>
                <template v-else-if="column.key === 'country'">{{ row.country ?? '—' }}</template>
                <template v-else>{{ itemText(row) }}</template>
              </td>
              <td><span v-if="row.source_type==='woocommerce'" class="tracking-sync-status" :title="[row.tracking_sync_reason, row.tracking_sync_verified_at ? '最近官网验证：' + new Date(row.tracking_sync_verified_at).toLocaleString('zh-CN', { hour12:false }) : '尚无官网验证记录'].filter(Boolean).join('；')" :class="row.tracking_sync_status??'pending'">{{ trackingLabel(row.tracking_sync_status) }}</span><span v-else>—</span><small v-if="row.tracking_sync_reason" class="tracking-sync-failure">{{ row.tracking_sync_reason }}</small></td>
              <td v-if="canTrackingSync"><button v-if="eligibleForTracking(row)" class="file-button"
                type="button" :disabled="trackingBusy" @click="pushTracking([row.order_id], true)">{{ (['failed','retry_wait'].includes(row.tracking_sync_status ?? '') || !!row.tracking_sync_reason) ? '重新同步' : '同步官网物流' }}</button><span v-else>—</span></td>
              <td class="settings-spacer" aria-hidden="true"></td>
            </tr>
            <tr v-if="!nativeOperationRows.length"><td :colspan="grid.visibleColumns.length + (['us-3sm','us-moreplus'].includes(warehouseKey) ? 3 : 2) + 1 + (canTrackingSync?2:0)" class="empty-cell">当前视图暂无订单</td></tr>

          </tbody>
        </table>
      </div>

      <footer v-if="!gridEnabled" class="oversea-footer">
        <div class="pagination-summary">
          <span>共 {{ visibleTotal }} 条</span>
          <span>第 {{ rows.current_page }} / {{ rows.last_page }} 页</span>
          <span v-if="rows.from !== null && rows.to !== null">{{ visibleTotal === 0 ? 0 : rows.from }}–{{ visibleTotal === 0 ? 0 : visibleRows.length ? rows.from + visibleRows.length - 1 : rows.to }} / {{ visibleTotal }}</span>
        </div>
        <div class="pagination-toolbar">
          <label class="page-size">每页
            <select :value="rows.per_page" @change="changePerPage">
              <option :value="20">20</option><option :value="50">50</option><option :value="100">100</option>
            </select> 条
          </label>
          <div class="pagination" v-if="rows.last_page > 1">
            <button type="button" :disabled="rows.current_page <= 1" @click="goPage(1)">首页</button>
            <button type="button" :disabled="rows.current_page <= 1" @click="goPage(rows.current_page - 1)">上一页</button>
            <template v-for="item in compactPages" :key="item">
              <button v-if="typeof item === 'number'" type="button" :class="{ active:item === rows.current_page }" @click="goPage(item)">{{ item }}</button>
              <span v-else class="page-ellipsis">…</span>
            </template>
            <button type="button" :disabled="rows.current_page >= rows.last_page" @click="goPage(rows.current_page + 1)">下一页</button>
            <button type="button" :disabled="rows.current_page >= rows.last_page" @click="goPage(rows.last_page)">末页</button>
          </div>
          <label class="page-jump">跳至第
            <input v-model="jumpPage" inputmode="numeric" aria-label="跳转页码" @keyup.enter="submitJump" />
            页 <button type="button" @click="submitJump">跳转</button>
          </label>
        </div>
      </footer>
      <p v-if="gridEnabled" class="oversea-compact-help">通过后方可出库；缺货订单暂停发货，到货后由 Dede 确认；驳回需填写原因并退回 Vita 重新分仓。真实出库和 Tracking 仍按原仓库操作流程核实。</p>
        </main>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.unified-status{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap}.status-neutral{background:#f1f4f2;color:#596861}.status-blue{background:#eaf4fc;color:#1768a5}.status-amber{background:#fff4df;color:#936012}.status-green{background:#e5f3e9;color:#17633b}.status-red{background:#fce9e9;color:#9b3030}
.oversea-page{padding:18px 22px 28px 8px;min-width:0}.oversea-workbench{display:grid;grid-template-columns:124px minmax(0,1fr);gap:10px;align-items:start}.oversea-main{min-width:0}.warehouse-rail{position:sticky;top:12px;border:1px solid #dfe6ea;border-radius:8px;background:#fff;padding:6px 5px}.warehouse-rail-title{margin:2px 6px 5px;color:#64748b;font-size:11px;font-weight:800;letter-spacing:.06em}.warehouse-link{width:100%;border:0;background:transparent;border-radius:6px;padding:7px 6px;text-align:left;color:#475569;font-size:12px;font-weight:700;white-space:nowrap}.warehouse-link:hover{background:#f1f5f9}.warehouse-link.active{background:#e7f6f3;color:#0f766e}.oversea-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:12px}.oversea-heading h1{margin:0;color:#132238;font-size:28px;line-height:1.2}.oversea-heading p{margin:6px 0 0;color:#64748b;font-size:14px}
.xlwms-channel-hint{font-size:12px!important;color:#8b5a16!important;line-height:1.4!important;max-width:660px}.oversea-file-actions{display:flex;gap:10px;justify-content:flex-end;align-items:flex-start;flex-wrap:wrap}.warehouse-portal-link{white-space:nowrap;gap:6px}.hidden-file{display:none}.file-button{height:40px;padding:0 16px;border:1px solid #0f8f82;border-radius:8px;background:white;color:#0d796f;font-weight:700}.file-button.primary{background:#0f8f82;color:white}.file-link{display:inline-flex;align-items:center;text-decoration:none}.file-button:disabled{opacity:.45;cursor:not-allowed}.oversea-file-flow{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:-2px 0 12px;padding:9px 11px;border:1px solid #dbe7e5;border-radius:8px;background:#f5fbfa;color:#395d59;font-size:12px}.oversea-file-flow b{color:#94a3b8}.oversea-file-flow small{flex-basis:100%;color:#64748b}
.tracking-preview{margin:0 0 14px;padding:12px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc}.tracking-preview-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.tracking-preview-head>div{display:flex;flex-direction:column;gap:3px}.tracking-preview-head strong{color:#0f172a}.tracking-preview-head span,.tracking-hint,.tracking-preview small{color:#64748b;font-size:12px}.tracking-hint{margin:8px 0}.tracking-preview-table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:8px;background:#fff}.tracking-preview-table{width:100%;min-width:980px;border-collapse:collapse;font-size:12px}.tracking-preview-table th,.tracking-preview-table td{padding:7px 8px;border-bottom:1px solid #edf2f7;text-align:left;white-space:nowrap}.tracking-preview-table th{background:#f8fafc;color:#475569}.tracking-status{font-weight:800}.tracking-status.apply{color:#047857}.tracking-status.noop{color:#64748b}.tracking-status.blocked{color:#b91c1c}
.oversea-tabs{display:flex;gap:6px;align-items:center;margin:0 0 12px;border-bottom:1px solid #e2e8f0;overflow-x:auto}.oversea-tab{border:0;background:transparent;padding:9px 14px 8px;color:#536174;font-weight:700;white-space:nowrap;border-bottom:2px solid transparent}.oversea-tab span{margin-left:4px;color:#94a3b8;font-weight:600}.oversea-tab.active{color:#0f766e;border-bottom-color:#0f766e}.oversea-tab.active span{color:#0f766e}
.oversea-table-wrap{overflow:auto;border:1px solid #dfe6ea;border-radius:10px;background:white}.oversea-table{width:max-content;min-width:1760px;border-collapse:separate;border-spacing:0;font-size:13px}.oversea-table th{padding:7px 8px;background:#f8fafc;color:#425466;font-weight:700;text-align:left;border-right:1px solid #e5eaee;border-bottom:1px solid #dfe6ea;white-space:nowrap}.oversea-table td{padding:3px 7px;border-right:1px solid #edf1f4;border-bottom:1px solid #e7ecef;vertical-align:middle;white-space:nowrap;background:#fff}.oversea-table tr:last-child td{border-bottom:0}.decision-col{width:118px;min-width:118px}.sticky-decision{position:sticky;left:0;z-index:4;background:#fff!important;box-shadow:6px 0 10px rgba(15,23,42,.06)}th.sticky-decision{z-index:6;background:#f8fafc!important}.line-col{width:44px;text-align:center!important;color:#64748b}.decision-cell{white-space:nowrap}.settings-spacer{width:46px;min-width:46px}.decision{border:1px solid transparent;border-radius:6px;padding:5px 9px;font-weight:700;margin-right:4px}.decision.approve{background:#0ca678;color:white}.decision.reject{background:white;color:#d33;border-color:#efb6b6}.decision-state{font-weight:700;color:#a16207}.decision-state.approved{color:#087f5b}.decision-approved{display:flex;flex-direction:column;align-items:flex-start;gap:2px}.reopen-review{border:1px solid #99cfc7;background:#fff;color:#0f766e;border-radius:6px;padding:2px 7px;font-size:11px;font-weight:700}
.cell-select,.note-input{width:100%;min-height:30px;border:1px solid #d7e0e5;border-radius:6px;background:white;color:#334155;padding:3px 7px}.cell-select:disabled,.note-input:disabled{background:#f8fafc;color:#64748b}.cell-select.warehouse{min-width:135px}.carrier-cell{display:flex;flex-direction:column;gap:3px;min-width:150px}.carrier-cell small{color:#94a3b8;font-size:11px}.cell-select.carrier{min-width:145px}.tracking-cell{min-width:240px}.tracking-entry{display:flex;align-items:center;gap:6px}.tracking-input{width:100%;min-width:220px;min-height:30px;border:1px solid #d7e0e5;border-radius:6px;background:#fff;color:#334155;padding:3px 7px}.tracking-input:disabled{background:#f8fafc;color:#64748b}.manual-ship-button{min-height:30px;white-space:nowrap;border:1px solid #0f766e;border-radius:6px;background:#0f766e;color:#fff;font-weight:700;padding:3px 9px;cursor:pointer}.note-cell{display:flex;flex-direction:column;gap:4px}.note-input{min-width:260px}.note-input.error{border-color:#dc2626}.note-error{color:#dc2626;font-size:11px}.order-link{display:inline-block;min-width:180px;color:#0f766e;font-weight:700;text-decoration:none;white-space:nowrap}.order-link:hover{text-decoration:underline}.items-cell{min-width:180px;color:#475569}.empty-cell{text-align:center!important;color:#94a3b8;padding:30px!important}
.oversea-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;max-width:100%;padding:14px 2px;color:#64748b}.pagination-summary{display:flex;gap:10px;flex-wrap:wrap;font-size:12px}.pagination-toolbar{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;min-width:0;max-width:100%}.page-size,.page-jump{display:flex;align-items:center;gap:5px;white-space:nowrap;font-size:12px}.page-size select,.page-jump input{height:32px;border:1px solid #d7e0e5;border-radius:6px;background:#fff;color:#475569;padding:0 7px}.page-jump input{width:58px}.page-jump button,.pagination button{border:1px solid #d7e0e5;background:white;border-radius:6px;min-width:32px;height:32px;padding:0 8px;color:#475569;white-space:nowrap}.pagination{display:flex;align-items:center;gap:5px;flex-wrap:wrap;min-width:0}.pagination button:disabled,.page-jump button:disabled{opacity:.45;cursor:not-allowed}.pagination button.active{background:#0f766e;border-color:#0f766e;color:white}.page-ellipsis{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:32px;color:#94a3b8}
@media (max-width:900px){.oversea-workbench{grid-template-columns:1fr}.warehouse-rail{position:static;display:flex;gap:6px;overflow-x:auto}.warehouse-rail-title{display:none}.warehouse-link{width:auto;flex:0 0 auto}.oversea-heading{flex-direction:column}.oversea-file-actions{align-self:stretch}.file-button{flex:1}.oversea-page{padding:14px}}
</style>

<style scoped>
.xlwms-header{min-width:270px}.xlwms-cell{min-width:270px!important;max-width:340px;white-space:normal!important}
.xlwms-inline{display:flex;align-items:flex-start;flex-direction:column;gap:4px;padding:5px 2px}
.xlwms-state{font-weight:700;color:#184c3e}.xlwms-number,.xlwms-track,.xlwms-note{font-size:11px;color:#5a6762;overflow-wrap:anywhere}
.xlwms-action{font-size:11px;border:1px solid #adc7be;border-radius:6px;padding:4px 9px;min-height:28px;cursor:pointer;color:#185647;background:#fff;white-space:nowrap}
.xlwms-action.primary{color:#fff;background:#12644d;border-color:#12644d}
.xlwms-action:disabled{opacity:.55;cursor:not-allowed}
.xlwms-error{color:#c02d2d!important;font-size:11px!important;font-weight:600;line-height:1.4;white-space:normal}
.xlwms-confirm{display:flex;flex-direction:column;gap:5px;margin-top:4px;padding:7px;border:1px solid #cfe2da;border-radius:6px;background:#f6faf7;white-space:normal}
.xlwms-confirm small{font-size:11px;color:#496b5b;overflow-wrap:anywhere}
.xlwms-history{width:100%;margin-top:4px;padding-top:6px;border-top:1px solid #dce6e7;font-size:12px;color:#5d7075}.xlwms-history summary{cursor:pointer;color:#42686b}.xlwms-history[open] small{display:block;margin-top:6px}.xlwms-draft-success{color:#167765}
.xlwms-batch-button{min-width:112px}.xlwms-batch-result{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:-4px 0 12px;padding:7px 10px;border:1px solid #cfe2da;border-radius:7px;background:#f6faf7;color:#35564d;font-size:12px}.xlwms-batch-result span{padding-right:8px;border-right:1px solid #dbe7e5}.xlwms-batch-result span:last-child{border-right:0}
.tracking-selection-bar{display:flex;align-items:center;gap:8px;padding:8px 12px;margin:9px 0;background:#eef6ff;border:1px solid #c9dffc;border-radius:8px;font-size:12px}
.tracking-sync-status{display:inline-flex;padding:4px 7px;border-radius:5px;font-size:11px;font-weight:700;color:#92400e;background:#fff1d6}
.tracking-sync-status.completed{color:#166534;background:#dcfce7}
.tracking-sync-status.failed{color:#991b1b;background:#fee2e2}.tracking-sync-status.retry_wait{color:#92400e;background:#fef3c7}.tracking-sync-failure{display:block;max-width:240px;white-space:normal;font-size:10px;color:#b91c1c;margin-top:4px}
.tracking-sync-status.processing{color:#1d4ed8;background:#dbeafe}
</style>

<style scoped>
/* Owner PASS: warehouse switching stays visible, never consumes over five CJK glyphs. */
.oversea-page{padding:8px 9px 20px 5px}
.oversea-workbench{grid-template-columns:60px minmax(0,1fr);gap:8px}
.warehouse-rail{box-sizing:border-box;min-width:0;width:60px;padding:4px 2px;top:10px}
.warehouse-rail-title{margin:3px 2px 5px;font-size:11px;letter-spacing:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.warehouse-link{box-sizing:border-box;display:block;width:100%;min-width:0;max-width:100%;padding:7px 2px;font-size:11.5px;line-height:1.45;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.oversea-heading{margin-bottom:7px;align-items:center;gap:10px}
.oversea-heading h1{font-size:20px}
.oversea-file-actions{gap:6px;align-items:center}
.oversea-file-actions .file-button{height:32px;padding:0 10px;font-size:12px}
.oversea-grid-main{min-width:0;margin:6px 0 9px}
.native-operation-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:12px;color:#475569;font-size:12px}
.oversea-native-workflow{margin-top:8px}
@media(max-width:900px){.oversea-workbench{grid-template-columns:60px minmax(0,1fr)}.warehouse-rail{position:sticky;top:8px;display:block;width:60px;overflow:visible}.warehouse-rail-title{display:block}.warehouse-link{width:100%;flex:none}.oversea-page{padding:8px 5px}}
</style>

<style scoped>
.oversea-compact-help{margin:10px 4px 0;font-size:11px;color:#94a3b8;line-height:1.5}
</style>

<style scoped>
.decision.stockout{border-color:#e3ac78;background:#fff9f1;color:#9a3412}
</style>
