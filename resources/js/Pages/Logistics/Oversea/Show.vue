<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import ColumnSettingsHeader from '../../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../../Composables/useAccountGridPreferences'
import { publishGlobalFeedback } from '../../../lib/globalFeedback'

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
type OmsPreflight = {
  order_id:number; blockers:string[]; ready:boolean; payload_hash:string
  oms_warehouse:string; items:Item[]; already_attempted:boolean
}
type ReshipPreview = {
  order_id:number;erp_order_id:string;original_oms_order_no:string|null
  original_tracking_present:boolean;warehouse:string;channel:string;draft_only:boolean
  items:Item[];recipient:string|null
  address:{country:string|null;province:string|null;city:string|null;postal_code:string|null;address_1:string|null}
  reason_default:string;required_oms_memo:string;existing_reshipment_count:number
  blockers:string[];ready_for_review:boolean;can_create_oms:boolean;memo_contract_verified:boolean
  existing_reissue?:{request_id:string;status:string;oms_order_no:string|null;oms_status_code:string|null;last_error_code:string|null;can_create_oms:boolean}|null
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
  line:number; order_id:number; erp_order_id:string|null; order_url:string|null; recipient_name:string|null; country:string|null
  warehouse_id:number; warehouse_name:string|null; carrier:string|null; service:string|null; carrier_selection_source:string|null; tracking_number:string|null
  progress:string; note:string|null; xlwms?:OmsSnapshot|null; warehouse_dispatch?:DispatchProjection; review_status:'approved'|'pending'; shipment_status:string; source_type?:string; tracking_sync_status?:string|null; tracking_sync_reason?:string|null; shipped_at?:string|null; items:Item[]
}
type PageRows = { data:Row[]; current_page:number; last_page:number; per_page:number; total:number; from:number|null; to:number|null; links?:{url:string|null;label:string;active:boolean}[] }
type ColumnKey = 'line'|'order'|'warehouse'|'carrier'|'service'|'tracking'|'note'|'recipient'|'country'|'items'
const columnDefinitions: GridColumnDefinition<ColumnKey>[] = [
  { key:'line', label:'#', defaultVisible:true },
  { key:'order', label:'订单号', defaultVisible:true },
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
  rows:PageRows; viewCounts:Record<string,number>; canReview:boolean; canOperate:boolean; canTrackingSync?:boolean; shipoutSandboxReadEnabled?:boolean; shipoutSandboxDispatchEnabled?:boolean; shipoutSandboxTrackingApplyEnabled?:boolean; shipoutSandboxAuthorizedOrderNo?:string; shipoutSandboxSpecifiedChannel?:{id:string;label:string}|null; shipoutSandboxOwnLabelEnabled?:boolean; carrierOptions:CarrierOption[]; trackingImportPreview:TrackingImportPreview|null; xlwmsAuthorizedOrderId?:number; xlwmsBatchEnabled?:boolean

}>()

const grid = reactive(useAccountGridPreferences<ColumnKey>({
  namespace:'grid.logistics.oversea.workbench.v1',
  definitions:columnDefinitions,
}))
const canEditRow = computed(() => props.canOperate || (props.canReview && props.view === 'review'))
const trackingSelection = ref<number[]>([])
const trackingBusy = ref(false)
const eligibleForTracking = (row:Row) => row.source_type === 'woocommerce'
  && row.shipment_status === 'shipped' && !!row.tracking_number && !!row.carrier && !!row.shipped_at
const chooseTracking = (id:number, checked:boolean) => {
  trackingSelection.value=checked?[...new Set([...trackingSelection.value,id])]:trackingSelection.value.filter(v=>v!==id)
}
const pushTracking = (ids:number[]) => {
  if(!props.canTrackingSync || !ids.length || trackingBusy.value)return
  trackingBusy.value=true
  router.post('/logistics/tracking-outbound/selected',{order_ids:ids},{
    preserveScroll:true,
    onSuccess:()=>{trackingSelection.value=[]},
    onFinish:()=>{trackingBusy.value=false},
  })
}
const trackingLabel=(status:string|null|undefined)=>status==='completed'?'已同步':status==='failed'?'失败':status==='retry_wait'?'待重试':status==='processing'?'同步中':'待同步'
const drafts = reactive<Record<number,{ note:string; carrier:string; service:string; tracking_number:string }>>({})
const trackingFileInput = ref<HTMLInputElement|null>(null)
const trackingPreviewRows = computed(() => props.trackingImportPreview?.rows.slice(0, 20) ?? [])
const canUploadTrackingFile = computed(() => props.warehouseKey !== 'us-3sm')
const trackingFileHint = computed(() => props.warehouseKey === 'us-3sm'
  ? '世迈订单行直接审核、API 发货并查看 OMS 状态；手工 Tracking 确认仅用于经核实的备用流程。'
  : '上传当前仓库真实回传 XLSX；兼容旧 CSV。上传只生成预览，确认后才会标记已发货。')
const rowErrors = reactive<Record<number,string>>({})
// MorePlus API is a separate sandbox-only provider, NEVER the 世迈 XLWMS route.
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
const reshipPreview=reactive<Record<number,ReshipPreview>>({})
const reshipBusy=reactive<Record<number,boolean>>({})
const reshipError=reactive<Record<number,string>>({})
const reshipReason=reactive<Record<number,string>>({})
const reshipIntent=reactive<Record<number,{request_id:string;status:string;can_create_oms:boolean}>>({})
const reshipApproved=reactive<Record<number,boolean>>({})
const reshipCanCreate=reactive<Record<number,boolean>>({})
const reshipOmsNo=reactive<Record<number,string>>({})
const reshipProviderStatus=reactive<Record<number,string>>({})
const reshipPost=async (row:Row, action:'reship-intent'|'reship-approve', body:Record<string,unknown>) => {
  if(!props.canOperate||props.warehouseKey!=='us-3sm'||row.shipment_status!=='shipped'||reshipBusy[row.order_id])return
  reshipBusy[row.order_id]=true;reshipError[row.order_id]=''
  try {
    const token=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
    const response=await fetch(omsEndpoint(row,action),{
      method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',
      headers:{Accept:'application/json','Content-Type':'application/json',...(token?{'X-CSRF-TOKEN':token}:{})},
      body:JSON.stringify(body),
    })
    if(!response.ok)throw Error('补发申请/审批失败（HTTP '+response.status+'）')
    const result=await response.json() as {request_id:string;status:string;can_create_oms:boolean}
    if(!result.request_id)throw Error('补发审核结果未通过安全校验')
    if(action==='reship-intent'&&result.status==='requested'&&result.can_create_oms===false)reshipIntent[row.order_id]=result
    else if(action==='reship-approve'&&result.status==='approved_for_reissue'
      &&result.request_id===reshipIntent[row.order_id]?.request_id){
        reshipApproved[row.order_id]=true
        reshipCanCreate[row.order_id]=result.can_create_oms===true
      }
    else throw Error('补发审核状态不一致')
    notifyOms('SUCCESS', '仓库重新下发申请已更新', result.status)
  }catch(e){
    reshipError[row.order_id]=e instanceof Error?e.message:'补发操作失败'
    notifyOms('ERROR','补发申请/审批失败',reshipError[row.order_id])
  }finally{reshipBusy[row.order_id]=false}
}
const requestReship=async(row:Row)=>{
  const reason=(reshipReason[row.order_id]??'').trim()
  if(reason.length<5){reshipError[row.order_id]='请先填写不少于 5 个字的补发原因';return}
  await reshipPost(row,'reship-intent',{reason,confirm_original_shipped:true})
}
const approveReship=async(row:Row)=>{
  const intent=reshipIntent[row.order_id]
  if(!intent||intent.status!=='requested')return
  await reshipPost(row,'reship-approve',{
    request_id:intent.request_id,confirmation:'APPROVE_RESHIP_'+intent.request_id,
  })
}

const createReshipDraft=async(row:Row)=>{
  const intent=reshipIntent[row.order_id]
  if(!intent||!reshipApproved[row.order_id]||!reshipCanCreate[row.order_id]
    ||reshipBusy[row.order_id]||reshipOmsNo[row.order_id])return
  reshipBusy[row.order_id]=true;reshipCanCreate[row.order_id]=false;reshipError[row.order_id]=''
  try{
    const token=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
    const r=await fetch(omsEndpoint(row,'reship-create'),{
      method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',
      headers:{Accept:'application/json','Content-Type':'application/json',
        ...(token?{'X-CSRF-TOKEN':token}:{})},
      body:JSON.stringify({request_id:intent.request_id,
        confirmation:'CREATE_REISSUE_'+intent.request_id}),
    })
    if(!r.ok)throw Error('独立 OMS 草稿创建被拒绝（HTTP '+r.status+'）')
    const result=await r.json() as {result:string;request_id:string;oms_order_no?:string;code?:string}
    if(result.request_id!==intent.request_id)throw Error('重新下发身份不一致，禁止再次 CREATE')
    if((result.result==='created'||result.result==='existing')&&result.oms_order_no){
      reshipOmsNo[row.order_id]=result.oms_order_no
      notifyOms('SUCCESS','独立 OMS 草稿已登记','OMS '+result.oms_order_no+' · 原订单未改变')
    }else{
      reshipError[row.order_id]='创建未确认：'+(result.code??result.result)+'。禁止重复下发，先查询仓库'
      notifyOms('ERROR','OMS 结果需核实',reshipError[row.order_id])
    }
  }catch(e){
    reshipError[row.order_id]=(e instanceof Error?e.message:'请求结果未知')+'；禁止直接重发，请核实 OMS'
  }finally{reshipBusy[row.order_id]=false}
}
const refreshReshipStatus=async(row:Row)=>{
  const intent=reshipIntent[row.order_id]
  if(!intent||!reshipOmsNo[row.order_id]||reshipBusy[row.order_id])return
  reshipBusy[row.order_id]=true;reshipError[row.order_id]=''
  try{
    const r=await fetch(omsEndpoint(row,'reship-status')+'/'+encodeURIComponent(intent.request_id),{
      method:'GET',credentials:'same-origin',cache:'no-store',redirect:'error',
      headers:{Accept:'application/json'},
    })
    if(!r.ok)throw Error('OMS 状态查询失败（HTTP '+r.status+'）')
    const v=await r.json() as {request_id:string;oms_order_no:string;oms_status_code:string;
      original_erp_unchanged:boolean}
    if(v.request_id!==intent.request_id||v.oms_order_no!==reshipOmsNo[row.order_id]
      ||v.original_erp_unchanged!==true)throw Error('OMS 状态身份核对失败')
    reshipProviderStatus[row.order_id]=v.oms_status_code
  }catch(e){reshipError[row.order_id]=e instanceof Error?e.message:'状态查询异常'}
  finally{reshipBusy[row.order_id]=false}
}
const readReshipPreview=async (row:Row) => {
  if(!props.canOperate||props.warehouseKey!=='us-3sm'||row.shipment_status!=='shipped'||reshipBusy[row.order_id])return
  reshipBusy[row.order_id]=true;reshipError[row.order_id]=''
  try {
    const token=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
    const res=await fetch(omsEndpoint(row,'reship-preflight'),{
      method:'GET',credentials:'same-origin',cache:'no-store',redirect:'error',
      headers:{Accept:'application/json',...(token?{'X-CSRF-TOKEN':token}:{})},
    })
    if(!res.ok)throw Error('补发预检不可用（HTTP '+res.status+'）')
    const result=await res.json() as ReshipPreview
    if(result.order_id!==row.order_id||result.channel!=='No_Shipping_Service'
      ||result.draft_only!==true||result.can_create_oms!==false)throw Error('补发预检安全校验失败')
    reshipPreview[row.order_id]=result
    if(result.existing_reissue){
      const old=result.existing_reissue
      reshipIntent[row.order_id]={request_id:old.request_id,status:old.status,can_create_oms:old.can_create_oms}
      reshipApproved[row.order_id]=!['requested','rejected','canceled'].includes(old.status)
      reshipCanCreate[row.order_id]=old.can_create_oms
      if(old.oms_order_no)reshipOmsNo[row.order_id]=old.oms_order_no
      if(old.oms_status_code)reshipProviderStatus[row.order_id]=old.oms_status_code
    }
  }catch(e){
    reshipError[row.order_id]=e instanceof Error?e.message:'补发预检失败'
    notifyOms('ERROR','API 重发货预检失败',reshipError[row.order_id])
  }finally{reshipBusy[row.order_id]=false}
}
const omsRow = (row:Row): OmsSnapshot|null => omsSnapshots[row.order_id] ?? row.xlwms ?? null
const omsLabel = (row:Row) => {
  const record=omsRow(row)
  if(row.shipment_status==='shipped')return '已发货'
  if(!record?.oms_order_no)return row.warehouse_dispatch?.label ?? '待下发'
  if(record.oms_status_code==='0')return 'OMS 草稿 · 未提交仓库'
  if(record.oms_status_code==='5')return 'OMS 异常 · 待处理'
  if(record.oms_status_code==='3')return '已出库 · 待回写'
  if(record.oms_status_code==='4')return '仓库已取消 · 待处理'
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
const draft = (row:Row) => drafts[row.order_id] ??= { note:row.note ?? '', carrier:row.carrier ?? '', service:row.service ?? defaultServiceFor(row.carrier ?? '', row.country), tracking_number:row.tracking_number ?? '' }
const viewTabs = [
  ['review','待审核'], ['pending','待发货'], ['pending_dispatch','待下发'], ['dispatched','OMS 已建单'], ['shipped','已发货'], ['exceptions','异常订单'], ['all','全部订单'],
] as const


const pageTitle = computed(() => props.warehouse ? `海外仓发货 · ${props.warehouse.label}` : '海外仓发货 · 全部海外仓')
const basePath = computed(() => props.warehouse ? props.warehouse.url : '/logistics/oversea')
const switchView = (view:string) => router.visit(`${basePath.value}?view=${encodeURIComponent(view)}&page=1&per_page=${props.rows.per_page || 50}`, { preserveScroll:true, preserveState:true, only:['rows','view','viewCounts','warehouse','warehouseKey','warehouseOptions','canReview','canOperate','carrierOptions'] })
const switchWarehouse = (url:string) => router.visit(`${url}?view=${encodeURIComponent(props.view)}&page=1&per_page=${props.rows.per_page || 50}`, { preserveScroll:true, preserveState:true })
const saveDraft = (row:Row, payload:Record<string,string|number|null>) => router.patch(`/logistics/oversea/orders/${row.order_id}/draft`, payload, { preserveScroll:true, preserveState:true })
const changeWarehouse = (row:Row, warehouseId:number) => saveDraft(row, { warehouse_id:Number(warehouseId) })
const changeWarehouseFromEvent = (row:Row, event:Event) => changeWarehouse(row, Number((event.target as HTMLSelectElement).value))
const saveNote = (row:Row) => saveDraft(row, { note:draft(row).note.trim() || null })
const saveCarrier = (row:Row) => { const d=draft(row); d.service=defaultServiceFor(d.carrier, row.country); saveDraft(row, { carrier:d.carrier, service:d.service || null }) }
const saveService = (row:Row) => saveDraft(row, { carrier:draft(row).carrier, service:draft(row).service || null })
const saveTracking = (row:Row) => saveDraft(row, { tracking_number:draft(row).tracking_number.trim() || null })
const manualShipUsSm = (row:Row) => {
  const oms=omsRow(row)
  if (oms && (oms.oms_status_code!=='3' || !oms.oms_tracking_no
    || oms.oms_tracking_no!==draft(row).tracking_number.trim())) {
    rowErrors[row.order_id]='OMS 已有出库记录：必须先查到“已出库”且 Tracking 完全匹配，才能手动确认'
    return
  }
  const tracking = draft(row).tracking_number.trim()
  if (!tracking) { rowErrors[row.order_id] = '请先填写真实 Tracking'; return }
  rowErrors[row.order_id] = ''
  router.post(`/logistics/oversea/us-3sm/orders/${row.order_id}/manual-ship`, { tracking_number:tracking }, { preserveScroll:true })
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
const reopenReview = (row:Row) => router.post(`/logistics/oversea/orders/${row.order_id}/reopen-review`, {}, { preserveScroll:true })
const reject = (row:Row) => {
  const note = draft(row).note.trim()
  if (!note) { rowErrors[row.order_id] = '请先填写驳回原因'; return }
  rowErrors[row.order_id] = ''
  router.post(`/logistics/oversea/orders/${row.order_id}/reject`, { note }, { preserveScroll:true, preserveState:true })
}
const itemText = (row:Row) => row.items.map(item => `${item.sku ?? '无SKU'} × ${item.quantity}`).join(' / ') || '—'
const pageUrl = (page:number, perPage:number = props.rows.per_page || 50) => `${basePath.value}?view=${encodeURIComponent(props.view)}&page=${page}&per_page=${perPage}`
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
        <aside class="warehouse-rail" aria-label="海外仓切换">
          <p class="warehouse-rail-title">海外仓</p>
          <button type="button" class="warehouse-link" :class="{ active: warehouseKey === 'all' }" @click="switchWarehouse('/logistics/oversea')">全部海外仓</button>
          <button v-for="option in warehouseOptions" :key="option.key" type="button" class="warehouse-link" :class="{ active: warehouseKey === option.key }" @click="switchWarehouse(option.url)">{{ option.label }}</button>
        </aside>
        <main class="oversea-main">
      <header class="oversea-heading">
        <div><h1>{{ pageTitle }}</h1><p>审核 Vita 分仓结果；通过后进入真实下单，驳回则退回运营重新分配。</p><p v-if="warehouseKey === 'us-3sm'" class="xlwms-channel-hint">世迈仓统一使用无需物流服务（No_Shipping_Service），不需上传面单；API 仅创建 OMS 草稿，仓库接单和真实发货状态需要后续核对。</p><p v-if="warehouseKey === 'us-moreplus'" class="xlwms-channel-hint">ShipOut 自动渠道和指定渠道需核对仓库最终承运商，不能假定 USPS Priority。</p></div>
        <div class="oversea-file-actions">
          <button v-if="canTrackingSync" type="button" class="file-button primary"
            :disabled="!trackingSelection.length || trackingBusy" @click="pushTracking(trackingSelection)">
            {{ trackingBusy?'同步中…':'同步官网物流' }}</button>
          <template v-if="warehouse && view === 'pending'">
          <button v-if="warehouseKey === 'us-3sm' && canOperate" type="button" class="file-button" :disabled="refreshBusy" @click="refreshOmsStatuses">{{refreshBusy?'查询中…':'更新 OMS 状态'}}</button>
          <button v-if="warehouseKey === 'us-3sm' && canOperate" type="button" class="file-button primary xlwms-batch-button" :disabled="batchBusy||!xlwmsBatchEnabled" :title="!xlwmsBatchEnabled?'真实下发权限关闭，请先获得受控单笔授权':'只处理当前授权订单'" @click="syncWarehouse">{{ batchBusy ? '同步中…' : '同步待发货订单' }}</button>
          <a class="file-button file-link" :href="`${basePath}/export`">⇩ 下载仓库下单表</a>
          <input ref="trackingFileInput" class="hidden-file" type="file" accept=".xlsx,.csv,.txt" @change="previewTrackingFile" />
          <button v-if="canOperate && canUploadTrackingFile" type="button" class="file-button primary" @click="chooseTrackingFile">⇧ 上传物流回传表</button>
          </template>
        </div>
      </header>
      <div v-if="warehouseKey === 'us-3sm' && (batchResult || batchError)" class="xlwms-batch-result" role="status">
        <strong v-if="batchResult">本次处理 {{ batchResult.count }} 单 · 新建 {{batchResult.summary?.created??0}} · 已存在 {{batchResult.summary?.existing??0}} · 阻断 {{batchResult.summary?.blocked??0}} · 待核实 {{batchResult.summary?.uncertain??0}}</strong>
        <small v-if="batchResult?.blocked_reason">正式建单权限关闭，本次没有发出任何新订单</small>
        <span v-if="batchResult" v-for="item in batchResult.results" :key="item.order_id">订单 {{ item.order_id }} · {{ batchCodeText[item.code] || item.code }}<small v-if="item.reason">（{{ item.reason }}）</small></span>
        <span v-if="batchError" class="xlwms-error">{{ batchError }}</span>
      </div>
      <div v-if="warehouse && view === 'pending'" class="oversea-file-flow">
        <template v-if="warehouseKey==='us-3sm'"><span>① Dede 审核</span><b>→</b><span>② 订单行 API 发货</span><b>→</b><span>③ OMS 状态查询</span><b>→</b><span>④ Tracking 核实与回写</span></template>
        <template v-else><span>① 审核通过</span><b>→</b><span>② 下载仓库下单表</span><b>→</b><span>③ 上传仓库 / WMS</span><b>→</b><span>④ 仓库回传 Tracking</span><b>→</b><span>⑤ 上传 ERP 并确认发货</span></template>
        <small>{{ trackingFileHint }}</small>
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
          {{ label }} <span>{{ viewCounts[key] ?? 0 }}</span>
        </button>
      </nav>

      <div v-if="canTrackingSync && trackingSelection.length" class="tracking-selection-bar">
        已选 {{ trackingSelection.length }} 项
        <button type="button" class="file-button primary" :disabled="trackingBusy" @click="pushTracking(trackingSelection)">批量同步官网物流</button>
        <button type="button" class="file-button" @click="trackingSelection=[]">清除选择</button>
      </div>
      <div class="oversea-table-wrap">
        <table class="oversea-table">
          <thead><tr>
            <th v-if="canTrackingSync">选择</th>
            <th class="decision-col sticky-decision">审核</th>
            <th v-if="warehouseKey === 'us-3sm'" class="xlwms-header">世迈 OMS · API 发货 / 状态</th>
            <th v-if="warehouseKey === 'us-moreplus'" class="xlwms-header">MorePlus ShipOut · API / 物流</th>
            <th v-for="column in grid.visibleColumns" :key="column.key" :class="{ 'line-col':column.key === 'line' }">{{ column.label }}</th>
            <th>官网同步</th><th v-if="canTrackingSync">操作</th>
            <ColumnSettingsHeader :columns="grid.columns" :sync-state="grid.syncState" @toggle="grid.toggle" @start-drag="grid.startDrag" @drop="grid.drop" @reset="grid.reset" />
          </tr></thead>
          <tbody>
            <tr v-for="row in rows.data" :key="row.order_id">
              <td v-if="canTrackingSync"><input v-if="eligibleForTracking(row)" type="checkbox"
                :aria-label="'选择同步 '+(row.erp_order_id??row.order_id)" :checked="trackingSelection.includes(row.order_id)"
                @change="chooseTracking(row.order_id, ($event.target as HTMLInputElement).checked)" /></td>
              <td class="decision-cell sticky-decision">
                <template v-if="canReview && view === 'review'">
                  <button type="button" class="decision approve" @mousedown.prevent @click="approve(row)">通过</button>
                  <button type="button" class="decision reject" title="如需驳回，请先填写备注原因" @mousedown.prevent @click="reject(row)">驳回</button>
                </template>
                <div v-else-if="row.review_status === 'approved'" class="decision-approved"><span class="decision-state approved">已通过</span><button v-if="canOperate" type="button" class="reopen-review" @click="reopenReview(row)">重新审核</button></div>
                <span v-else class="decision-state">待审核</span>
              </td>
              <td v-if="warehouseKey === 'us-3sm'" class="xlwms-cell">
                <div class="xlwms-inline" :data-oms-order-id="row.order_id">
                  <template v-if="omsRow(row)?.oms_order_no">
                    <div class="xlwms-state" :class="'dispatch-'+(row.warehouse_dispatch?.status??'pending_dispatch')">{{ omsLabel(row) }}</div>
                    <small v-if="omsDetail(row)" class="xlwms-note">{{ omsDetail(row) }}</small>
                    <small v-if="row.warehouse_dispatch?.last_checked_at" class="xlwms-note">核对 {{row.warehouse_dispatch.last_checked_at}}</small>
                    <small v-if="omsRow(row)?.oms_order_no" class="xlwms-number">OMS {{ omsRow(row)?.oms_order_no }}</small>
                    <small v-if="omsRow(row)?.oms_tracking_no" class="xlwms-track">Tracking {{ omsRow(row)?.oms_tracking_no }}（未同步）</small>
                    <button v-if="omsRow(row)?.oms_order_no" type="button" class="xlwms-action" :disabled="omsBusy[row.order_id]" @click="omsRefresh(row)">{{omsBusy[row.order_id]?'查询中…':'更新 OMS 状态'}}</button>
                    <button v-else-if="canOperate" type="button" class="xlwms-action" :disabled="omsBusy[row.order_id]" @click="omsReconcile(row)">核对 OMS（不重发）</button>
                    <small v-if="omsRow(row)?.oms_status_failure_code" class="xlwms-error" role="alert">{{ omsFailureText(omsRow(row)?.oms_status_failure_code) }}</small>
                  </template>
                  <template v-else-if="row.review_status === 'approved' && row.shipment_status === 'pending'">
                    <span class="xlwms-state">待下发</span>
                    <small v-if="row.warehouse_dispatch?.detail" class="xlwms-note">{{row.warehouse_dispatch.detail}}</small>
                    <button v-if="canOperate && !omsPreflight[row.order_id]" type="button" class="xlwms-action" :disabled="omsBusy[row.order_id] || omsFinishedThisPage[row.order_id]" @click="omsPreflightStart(row)">{{ omsBusy[row.order_id]?'预检中…':'API 发货' }}</button>
                    <div v-if="omsPreflight[row.order_id]" class="xlwms-confirm">
                      <small>校验：{{omsPreflight[row.order_id].oms_warehouse}} · {{omsPreflight[row.order_id].items.map(i=>i.sku+' × '+i.quantity).join(' / ')}}</small>
                      <button v-if="omsReadyToSend(row)" type="button" class="xlwms-action primary" :disabled="omsBusy[row.order_id]" @click="omsSend(row)">确认创建 OMS 草稿</button>
                      <small v-else>尚未获得此订单的实际发货授权</small>
                    </div>
                  </template>
                  <span v-else class="xlwms-note">{{ row.review_status !== 'approved' ? '待 Dede 审核' : '不允许重复发货' }}</span>
                  <div v-if="canOperate && row.shipment_status==='shipped'" class="xlwms-reship-readonly">
                    <button type="button" class="xlwms-action" :disabled="reshipBusy[row.order_id]"
                      @click="readReshipPreview(row)">{{reshipBusy[row.order_id]?'预检中…':'重新下发仓库'}}</button>
                    <div v-if="reshipPreview[row.order_id]" class="xlwms-confirm">
                      <strong>重新下发仓库 · 独立出库草稿</strong>
                      <small>原订单 {{reshipPreview[row.order_id].erp_order_id}} · 原 OMS {{reshipPreview[row.order_id].original_oms_order_no??'未关联'}}</small>
                      <small>{{reshipPreview[row.order_id].items.map(i=>i.sku+' × '+i.quantity).join(' / ')}}</small>
                      <small>{{reshipPreview[row.order_id].recipient}} · {{reshipPreview[row.order_id].address.address_1}} · {{reshipPreview[row.order_id].address.city}}</small>
                      <small>渠道：无需物流服务 · 不自动提交仓库 · ERP 保留操作原因</small>
                      <small v-if="reshipPreview[row.order_id].blockers.length && !reshipIntent[row.order_id]" class="xlwms-error">{{reshipPreview[row.order_id].blockers.join('；')}}</small>
                      <template v-if="reshipPreview[row.order_id].ready_for_review || !!reshipIntent[row.order_id]">
                        <template v-if="!reshipIntent[row.order_id]">
                          <input v-model="reshipReason[row.order_id]" type="text" maxlength="180"
                            class="xlwms-reship-reason" placeholder="填写补发原因（至少 5 个字）" />
                          <button type="button" class="xlwms-action"
                            :disabled="reshipBusy[row.order_id] || (reshipReason[row.order_id]??'').trim().length<5"
                            @click="requestReship(row)">确认原订单已发货 · 提交补发申请</button>
                        </template>
                        <template v-else-if="!reshipApproved[row.order_id]">
                          <small>申请已保存：{{reshipIntent[row.order_id].request_id}}（待二次确认）</small>
                          <button type="button" class="xlwms-action"
                            :disabled="reshipBusy[row.order_id]" @click="approveReship(row)">二次确认补发申请（不创建 OMS）</button>
                        </template>
                        <small v-else>申请审核已记录；核对旧 OMS 状态后才允许创建新草稿</small>
                      </template>
                      <button v-if="reshipApproved[row.order_id] && reshipCanCreate[row.order_id] && !reshipOmsNo[row.order_id]"
                        type="button" class="xlwms-action primary" :disabled="reshipBusy[row.order_id]"
                        @click="createReshipDraft(row)">创建独立 OMS 草稿（单笔授权）</button>
                      <small v-if="reshipOmsNo[row.order_id]">新 OMS 出库单：{{reshipOmsNo[row.order_id]}}（原单保留）</small>
                      <button v-if="reshipOmsNo[row.order_id]" type="button" class="xlwms-action"
                        :disabled="reshipBusy[row.order_id]" @click="refreshReshipStatus(row)">查询新 OMS 状态</button>
                      <small v-if="reshipProviderStatus[row.order_id]">新 OMS 原始状态码：{{reshipProviderStatus[row.order_id]}}（未自动同步官网）</small>
                      <small>真实 CREATE 需要单笔订单及申请双重白名单；不会覆盖原发货记录</small>
                    </div>
                    <small v-if="reshipError[row.order_id]" role="alert" class="xlwms-error">{{reshipError[row.order_id]}}</small>
                  </div>
                  <small v-if="omsErrors[row.order_id]" role="alert" class="xlwms-error">{{omsErrors[row.order_id]}}</small>
                </div>
              </td>
              <td v-if="warehouseKey === 'us-moreplus'" class="xlwms-cell">
                <div class="xlwms-inline" :data-shipout-order-id="row.order_id">
                  <template v-if="!shipoutSandboxReadEnabled && !shipoutSandboxDispatchEnabled">
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
                <template v-else-if="column.key === 'warehouse'"><select :value="row.warehouse_id" :disabled="!canReview || view !== 'review'" class="cell-select warehouse" @change="changeWarehouseFromEvent(row, $event)"><option v-for="option in warehouseOptions" :key="option.id" :value="option.id">{{ option.label }}</option></select></template>
                <template v-else-if="column.key === 'carrier'"><select v-model="draft(row).carrier" :disabled="!canOperate || row.shipment_status === 'shipped'" class="cell-select carrier" @change="saveCarrier(row)"><option v-for="carrier in carrierOptions" :key="carrier.value" :value="carrier.value">{{ carrier.label }}</option></select></template>
                <template v-else-if="column.key === 'service'"><select v-model="draft(row).service" :disabled="!canOperate || row.shipment_status === 'shipped' || !draft(row).carrier" class="cell-select service" @change="saveService(row)"><option value="">请选择服务类型</option><option v-for="service in serviceOptionsFor(draft(row).carrier)" :key="service.id" :value="service.value">{{ service.label }}</option></select></template>
                <template v-else-if="column.key === 'tracking'"><div class="tracking-entry"><input v-model="draft(row).tracking_number" :disabled="!canOperate || row.shipment_status === 'shipped'" class="tracking-input" placeholder="填写发货单号" @blur="saveTracking(row)" /><button v-if="canOperate && warehouseKey === 'us-3sm' && view === 'pending' && row.review_status === 'approved' && row.shipment_status === 'pending'" type="button" class="manual-ship-button" @mousedown.prevent @click="manualShipUsSm(row)">确认发货</button></div></template>
                <template v-else-if="column.key === 'note'"><div class="note-cell"><input v-model="draft(row).note" :disabled="!canEditRow" class="note-input" :class="{ error: !!rowErrors[row.order_id] }" placeholder="等海运 / 等空运 / 缺货 / 太贵 / 送不到…" @input="rowErrors[row.order_id] = ''" @blur="view === 'review' ? undefined : saveNote(row)" /><small v-if="rowErrors[row.order_id]" class="note-error">{{ rowErrors[row.order_id] }}</small></div></template>
                <template v-else-if="column.key === 'recipient'">{{ row.recipient_name ?? '—' }}</template>
                <template v-else-if="column.key === 'country'">{{ row.country ?? '—' }}</template>
                <template v-else>{{ itemText(row) }}</template>
              </td>
              <td><span v-if="row.source_type==='woocommerce'" class="tracking-sync-status" :title="row.tracking_sync_reason??''" :class="row.tracking_sync_status??'pending'">{{ trackingLabel(row.tracking_sync_status) }}</span><span v-else>—</span><small v-if="row.tracking_sync_reason" class="tracking-sync-failure">{{ row.tracking_sync_reason }}</small></td>
              <td v-if="canTrackingSync"><button v-if="eligibleForTracking(row)" class="file-button"
                type="button" :disabled="trackingBusy" @click="pushTracking([row.order_id])">同步官网物流</button><span v-else>—</span></td>
              <td class="settings-spacer" aria-hidden="true"></td>
            </tr>
            <tr v-if="!rows.data.length"><td :colspan="grid.visibleColumns.length + (['us-3sm','us-moreplus'].includes(warehouseKey) ? 3 : 2) + 1 + (canTrackingSync?2:0)" class="empty-cell">当前视图暂无订单</td></tr>

          </tbody>
        </table>
      </div>

      <footer class="oversea-footer">
        <div class="pagination-summary">
          <span>共 {{ rows.total }} 条</span>
          <span>第 {{ rows.current_page }} / {{ rows.last_page }} 页</span>
          <span v-if="rows.from !== null && rows.to !== null">{{ rows.from }}–{{ rows.to }} / {{ rows.total }}</span>
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
        </main>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.oversea-page{padding:18px 22px 28px 8px;min-width:0}.oversea-workbench{display:grid;grid-template-columns:124px minmax(0,1fr);gap:10px;align-items:start}.oversea-main{min-width:0}.warehouse-rail{position:sticky;top:12px;border:1px solid #dfe6ea;border-radius:8px;background:#fff;padding:6px 5px}.warehouse-rail-title{margin:2px 6px 5px;color:#64748b;font-size:11px;font-weight:800;letter-spacing:.06em}.warehouse-link{width:100%;border:0;background:transparent;border-radius:6px;padding:7px 6px;text-align:left;color:#475569;font-size:12px;font-weight:700;white-space:nowrap}.warehouse-link:hover{background:#f1f5f9}.warehouse-link.active{background:#e7f6f3;color:#0f766e}.oversea-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:12px}.oversea-heading h1{margin:0;color:#132238;font-size:28px;line-height:1.2}.oversea-heading p{margin:6px 0 0;color:#64748b;font-size:14px}
.xlwms-channel-hint{font-size:12px!important;color:#8b5a16!important;line-height:1.4!important;max-width:660px}.oversea-file-actions{display:flex;gap:10px}.hidden-file{display:none}.file-button{height:40px;padding:0 16px;border:1px solid #0f8f82;border-radius:8px;background:white;color:#0d796f;font-weight:700}.file-button.primary{background:#0f8f82;color:white}.file-link{display:inline-flex;align-items:center;text-decoration:none}.file-button:disabled{opacity:.45;cursor:not-allowed}.oversea-file-flow{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:-2px 0 12px;padding:9px 11px;border:1px solid #dbe7e5;border-radius:8px;background:#f5fbfa;color:#395d59;font-size:12px}.oversea-file-flow b{color:#94a3b8}.oversea-file-flow small{flex-basis:100%;color:#64748b}
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
.xlwms-batch-button{min-width:112px}.xlwms-batch-result{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:-4px 0 12px;padding:7px 10px;border:1px solid #cfe2da;border-radius:7px;background:#f6faf7;color:#35564d;font-size:12px}.xlwms-batch-result span{padding-right:8px;border-right:1px solid #dbe7e5}.xlwms-batch-result span:last-child{border-right:0}
.tracking-selection-bar{display:flex;align-items:center;gap:8px;padding:8px 12px;margin:9px 0;background:#eef6ff;border:1px solid #c9dffc;border-radius:8px;font-size:12px}
.tracking-sync-status{display:inline-flex;padding:4px 7px;border-radius:5px;font-size:11px;font-weight:700;color:#92400e;background:#fff1d6}
.tracking-sync-status.completed{color:#166534;background:#dcfce7}
.tracking-sync-status.failed{color:#991b1b;background:#fee2e2}.tracking-sync-status.retry_wait{color:#92400e;background:#fef3c7}.tracking-sync-failure{display:block;max-width:240px;white-space:normal;font-size:10px;color:#b91c1c;margin-top:4px}
.tracking-sync-status.processing{color:#1d4ed8;background:#dbeafe}
</style>
