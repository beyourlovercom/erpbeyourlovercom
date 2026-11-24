<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { CellClickedEvent } from 'ag-grid-community';
import { logisticsOrderColumns, logisticsPageNamespace, logisticsQueryCapabilities } from '#/components/erp-global-grid/logisticsOrderGrid';
import type {LogisticsFilterKey,LogisticsSortKey} from '#/api/logistics-query-contract';
import GlobalGrid from '#/components/erp-global-grid/GlobalGrid.vue';
import DomesticAllocationPanel from './domestic-allocation-panel.vue';
import OverseaReturnPanel from './oversea-return-panel.vue';
import OverseaOmsBatchPanel from './oversea-oms-batch-panel.vue';
import {original3smManualExport,preflight3sm,type OmsCapabilities,type OmsBatchWarehouse} from '#/api/erp-logistics';
import {originalOverseaExportHref,submitOriginalOverseaReturn} from '#/api/erp-logistics';
import {readDomesticBarcode69,type AllocationWarehouse} from '#/api/erp-logistics';
import { canonicalDevIdentity } from '#/router/erp-live-navigation';
import { getLogisticsPage, submitOriginalErpReview, submitOriginalErpShipment, submitOriginalDomesticFlow, domesticShipmentReceipt, type DomesticFlowAction,
  previewOriginalDomesticTrackingReturn, readOriginalDomesticTrackingConfirmation, applyOriginalDomesticTrackingReturn, type DomesticTrackingConfirmation, type DomesticTrackingPreview,
  type LogisticsCarrierOption, type OriginalShippingAction, type DomesticView, type DomesticReviewAction,
  type LogisticsRow, type LogisticsScope, type OverseasView, type OverseasReviewAction } from '#/api/erp-logistics';

defineOptions({ name: 'ERP2LogisticsProjection' });
const props = defineProps<{ scope: LogisticsScope }>();
const pageConfig = {
  allocation:{title:'待分仓',subtitle:'尚未分配发货仓的订单 · 运营分仓前只读确认',path:'待分仓'},
  domestic:{title:'国内发货',subtitle:'中国工厂 · 原 Laravel 九个业务队列',path:'国内发货'},
  overseas:{title:'海外仓发货',subtitle:'海外仓审核、运单履约与 A/B/C 子单',path:'海外仓发货'},
  awaiting:{title:'已发待单号',subtitle:'原 Laravel 已发货待 Tracking 队列',path:'已发待单号'},
  transit:{title:'运输中',subtitle:'原 Laravel 运输中队列',path:'运输中'},
  exceptions:{title:'异常订单',subtitle:'原 Laravel 异常物流队列',path:'异常订单'},

};
const meta = computed(() => pageConfig[props.scope]);
const isWorkScope=computed(()=>props.scope==='domestic'||props.scope==='overseas');
const domesticViews: {key:DomesticView;label:string}[] = [
  {key:'review',label:'待审核'},{key:'stockout',label:'缺货单'},
  {key:'outsource',label:'外采单'},{key:'pending',label:'待发货'},
  {key:'today',label:'今日订单'},{key:'today-4px',label:'今日速达'},
  {key:'today-weship',label:'今日纬狮'},{key:'exceptions',label:'异常订单'},
  {key:'all',label:'全部国内订单'},
];
const overseasViews: {key:OverseasView;label:string}[] = [
  {key:'review',label:'待审核'},{key:'stockout',label:'缺货单'},
  {key:'pending',label:'待发货'},{key:'issued',label:'已下发'},
  {key:'shipped',label:'已发货'},{key:'exceptions',label:'异常订单'},
  {key:'all',label:'全部订单'},
];
const views = computed(() => props.scope==='domestic'?domesticViews:overseasViews);
const warehouses = [
  {value:'',label:'全部海外仓'}, {value:'us-ny',label:'美国 NY'},
  {value:'us-moreplus',label:'美国 MorePlus'}, {value:'us-sm',label:'美国 3SM'},
  {value:'de-tianyun',label:'德国天运'}, {value:'ca',label:'加拿大'},
];
const search = ref(''),warehouse = ref(''),view = ref<DomesticView|OverseasView>('review');
const loading = ref(false),error = ref(''),rows = ref<LogisticsRow[]>([]);
const pager = ref({page:1,perPage:20 as 20|50|100,total:0,lastPage:1});
const detail = ref<LogisticsRow|null>(null);
const capabilities = ref({canReview:false,canOperate:false});
const omsCapabilities=ref<OmsCapabilities|null>(null);
const omsEnabled=import.meta.env.VITE_ERP_P0_OMS_ENABLED==='1';
const omsWarehouse=computed<OmsBatchWarehouse|null>(()=>warehouse.value==='us-moreplus'||warehouse.value==='de-tianyun'?warehouse.value:null);
const omsPreflight=ref(''),omsPreflightBusy=ref(false);
const is3smDetail=computed(()=>props.scope==='overseas'&&['US-SM','US-3SM'].includes(detail.value?.warehouse_code.toUpperCase()||''));
async function check3sm(){const row=detail.value;if(!row||!is3smDetail.value||omsPreflightBusy.value)return;omsPreflightBusy.value=true;omsPreflight.value='';try{const p=await preflight3sm(row.order_id);if(detail.value===row)omsPreflight.value=(p.ready?'本地预检通过（未下发）':p.blockers.join('；')||'需核对')+(p.already_attempted?'；已有 OMS 尝试，勿重复导入':'');}catch(e){if(detail.value===row)omsPreflight.value=e instanceof Error?e.message:'预检失败';}finally{omsPreflightBusy.value=false;}}
const reviewBusy=ref(false),reviewError=ref(''),reviewSuccess=ref(''),reviewReason=ref('');
const shippingBusy=ref(false),shippingError=ref(''),shippingSuccess=ref('');
const trackingDraft=ref(''),outboundReference=ref(''),carrierSelection=ref(''),serviceSelection=ref('');
const carrierOptions=ref<LogisticsCarrierOption[]>([]);
const allocationWarehouses=ref<AllocationWarehouse[]>([]);
const barcodeRows=ref<{sku:string;quantity:number;barcode:string}[]>([]),barcodeError=ref(''),barcodeBusy=ref(false);
async function loadBarcode(){const row=detail.value,actor=canonicalDevIdentity.value?.user.id;if(!row||barcodeBusy.value)return;barcodeBusy.value=true;barcodeRows.value=[];barcodeError.value='';try{const items=await readDomesticBarcode69(row.order_id);if(detail.value===row&&actor===canonicalDevIdentity.value?.user.id)barcodeRows.value=items;}catch(e){if(detail.value===row)barcodeError.value=e instanceof Error?e.message:'条码读取失败';}finally{barcodeBusy.value=false;}}
function allocationCompleted(){domesticFlowResult.value='分仓已通过原 ERP 回读确认';detail.value=null;void load(1);}
const returnFile=ref<File|null>(null);
const returnPreview=ref<DomesticTrackingPreview|null>(null);
const returnConfirmation=ref<DomesticTrackingConfirmation|null>(null);
const returnApplyResult=ref('');
const returnApplyBusy=ref(false);
const returnApplyEnabled=import.meta.env.VITE_ERP_P0_DOMESTIC_APPLY_ENABLED==='1';
const returnPreviewBusy=ref(false),returnPreviewError=ref('');
const canPreviewDomesticReturn=computed(()=>props.scope==='domestic' && capabilities.value.canOperate &&
  !!canonicalDevIdentity.value?.effectivePermissionKeys.includes('logistics.operate'));
let returnContext=0;
function clearReturnPreview(){returnContext++;returnFile.value=null;returnPreview.value=null;returnConfirmation.value=null;returnApplyResult.value='';returnPreviewError.value='';}
function chooseDomesticReturnFile(event:Event){
  const input=event.target as HTMLInputElement;
  returnFile.value=input.files?.[0]??null;
  returnPreview.value=null;returnConfirmation.value=null;returnApplyResult.value='';
  returnPreviewError.value='';
}
async function previewDomesticReturn(){
  if(!canPreviewDomesticReturn.value||returnPreviewBusy.value||!returnFile.value)return;
  returnPreviewBusy.value=true;returnPreviewError.value='';returnPreview.value=null;
  returnConfirmation.value=null;returnApplyResult.value='';
  const context=returnContext,actor=canonicalDevIdentity.value?.user.id,file=returnFile.value;
  try{const summary=await previewOriginalDomesticTrackingReturn(file);if(context!==returnContext||actor!==canonicalDevIdentity.value?.user.id)return;returnPreview.value=summary;if(returnApplyEnabled){const confirmation=await readOriginalDomesticTrackingConfirmation(summary);if(context===returnContext&&actor===canonicalDevIdentity.value?.user.id)returnConfirmation.value=confirmation;}}
  catch(e){returnPreviewError.value=e instanceof Error?e.message:'ERP2 回传表预览失败';}
  finally{returnPreviewBusy.value=false;}
}
async function applyDomesticReturn(){
  const preview=returnConfirmation.value;if(!returnApplyEnabled||!canPreviewDomesticReturn.value||!preview||preview.apply_count<1||returnApplyBusy.value||returnPreviewBusy.value)return;
  if(!window.confirm('确认批量登记 '+preview.apply_count+' 单？'))return;
  const actor=canonicalDevIdentity.value?.user.id,context=returnContext;
  returnApplyBusy.value=true;returnPreviewError.value='';returnApplyResult.value='';returnConfirmation.value=null;returnPreview.value=null;
  try{const r=await applyOriginalDomesticTrackingReturn(preview);if(actor!==canonicalDevIdentity.value?.user.id||context!==returnContext)return;returnApplyResult.value='发货登记 '+r.applied_count+' 单；重复 '+r.noop_count+'；阻断 '+r.blocked_count+'；失败 '+r.failed_count+'；官网待同步 '+r.sync_pending_count+'；同步登记失败 '+r.sync_handoff_failed_count+'。';await load(1);}
  catch(e){returnPreviewError.value=(e instanceof Error?e.message:'Apply failed')+'；请先刷新核对发货状态，不要重复提交。';}
  finally{returnApplyBusy.value=false;}
}
const shippingEnabled=import.meta.env.VITE_ERP_IDENTITY_MODE==='laravel' &&
  import.meta.env.VITE_ERP_M2_ORIGINAL_INERTIA_ENABLED==='1' &&
  import.meta.env.VITE_ERP_M2_SHIPPING_WRITE_ENABLED==='1';
const reviewEnabled=import.meta.env.VITE_ERP_IDENTITY_MODE==='laravel' &&
  import.meta.env.VITE_ERP_M2_ORIGINAL_INERTIA_ENABLED==='1' &&
  import.meta.env.VITE_ERP_M2_REVIEW_WRITE_ENABLED==='1';
const allowed = computed(() => !!canonicalDevIdentity.value?.effectivePermissionKeys.includes('logistics.view'));
const authorizedReview = computed(() => reviewEnabled && isWorkScope.value && allowed.value && capabilities.value.canReview &&
  (props.scope==='domestic'?canonicalDevIdentity.value?.effectivePermissionKeys.includes('domestic.review'):
  canonicalDevIdentity.value?.effectivePermissionKeys.includes('logistics.operate')));
const reviewActionLabels:Record<DomesticReviewAction,string>={
  approve:'审核通过',stockout:'标记缺货',reject:'驳回',restock:'确认到货',return_allocation:'退回待分仓',
};
const selectedCarrier=computed(()=>carrierOptions.value.find(c=>c.value===carrierSelection.value));
const availableServices=computed(()=>selectedCarrier.value?.services||[]);
const shippingSupported=computed(()=>{
  if(props.scope!=='overseas')return true;
  return ['US-NY','DE','CA','US-SM','US-3SM','US-LA'].includes(detail.value?.warehouse_code?.toUpperCase()||'');
});
const authorizedShipping=computed(()=>shippingEnabled && allowed.value && capabilities.value.canOperate &&
  !!canonicalDevIdentity.value?.effectivePermissionKeys.includes('logistics.operate') &&
  isWorkScope.value && view.value==='pending' && detail.value?.shipment_status==='pending' &&
  shippingSupported.value &&
  (props.scope==='domestic'||detail.value?.review_status==='approved'));
const reviewActions=computed<DomesticReviewAction[]>(()=>{
  if(!authorizedReview.value || !detail.value)return [];
  if(view.value==='review' && detail.value.review_status==='pending')
    return ['approve','stockout','reject'];
  if(view.value==='stockout' && detail.value.review_status==='stockout')
    return props.scope==='domestic'?['restock','return_allocation']:['restock'];
  return [];
});
let sequence = 0;
const selectedRows = ref<LogisticsRow[]>([]);
const bulkAction = ref<'approve'|'stockout'|'reject'>('approve');

const domesticFlowEnabled=import.meta.env.VITE_ERP_P0_DOMESTIC_FLOW_ENABLED==='1';
const domesticFlowBusy=ref(false),domesticFlowError=ref(''),domesticFlowResult=ref('');
const canAllocateFactory=computed(()=>domesticFlowEnabled&&props.scope==='allocation'&&allowed.value&&capabilities.value.canOperate);
const overseaFlowEnabled=import.meta.env.VITE_ERP_P0_OVERSEA_FLOW_ENABLED==='1';
const canForceReturnOversea=computed(()=>overseaFlowEnabled&&props.scope==='overseas'&&authorizedReview.value&&['review','stockout','pending'].includes(view.value));
const canExportOversea=computed(()=>props.scope==='overseas'&&allowed.value&&!!warehouse.value&&view.value==='pending');
const selectedOverseaExportHref=computed(()=>canExportOversea.value&&selectedRows.value.length?originalOverseaExportHref(warehouse.value,[...new Set(selectedRows.value.map(r=>r.order_id))]):'');
async function runOverseaReturn(){if(!canForceReturnOversea.value||domesticFlowBusy.value||reviewBusy.value||loading.value)return;const ids=[...new Set(selectedRows.value.map(r=>r.order_id))];if(!ids.length||ids.length>50||!window.confirm('确认 '+ids.length+' 单退回待分仓？'))return;const actor=canonicalDevIdentity.value?.user.id,context=gridInstanceKey.value;domesticFlowBusy.value=true;domesticFlowError.value='';domesticFlowResult.value='';try{const r=await submitOriginalOverseaReturn(ids);if(actor!==canonicalDevIdentity.value?.user.id||context!==gridInstanceKey.value)return;domesticFlowResult.value='批量到待分仓：成功 '+r.success_count+'，失败 '+r.failed_count+'。';if(r.failed_count)domesticFlowError.value=r.results.filter(x=>!x.ok).map(x=>x.message).join('；');await load(1);}catch(e){if(context===gridInstanceKey.value)domesticFlowError.value=(e instanceof Error?e.message:'操作失败')+'；请刷新核对。';}finally{domesticFlowBusy.value=false;}}
const canForceReturnDomestic=computed(()=>domesticFlowEnabled&&props.scope==='domestic'&&allowed.value&&capabilities.value.canReview&&!!canonicalDevIdentity.value?.effectivePermissionKeys.includes('domestic.review')&&['review','stockout','pending'].includes(view.value));
async function runDomesticFlow(action:DomesticFlowAction){
 if(domesticFlowBusy.value||reviewBusy.value||loading.value||!(action==='allocate_factory'?canAllocateFactory.value:canForceReturnDomestic.value))return;
 const ids=[...new Set(selectedRows.value.map(r=>r.order_id))];
 if(!ids.length||ids.length>(action==='allocate_factory'?100:50))return;
 const label=action==='allocate_factory'?'批量分到中国工厂':'批量到待分仓（清空原仓库和当前发货信息）';
 if(!window.confirm('确认对已选 '+ids.length+' 单执行「'+label+'」？'))return;
 domesticFlowBusy.value=true;domesticFlowError.value='';domesticFlowResult.value='';
 try{const result=await submitOriginalDomesticFlow({action,orderIds:ids});domesticFlowResult.value=label+'：成功 '+result.success_count+' 单，失败 '+result.failed_count+' 单。';
 if(result.failed_count)domesticFlowError.value=result.results.filter(r=>!r.ok).map(r=>r.order_id+': '+r.message).join('；');
 selectedRows.value=[];await load(1);
 }catch(e){domesticFlowError.value=(e instanceof Error?e.message:'操作失败')+'；请先刷新核对，不要重复提交。';}
 finally{domesticFlowBusy.value=false;}
}

const sorting = ref<{colId:LogisticsSortKey;direction:'asc'|'desc'}|null>(null);
const filters = ref<Partial<Record<LogisticsFilterKey,string>>>({});
const preferenceNamespace=computed(()=>logisticsPageNamespace(props.scope,view.value,warehouse.value));
const gridInstanceKey=computed(()=>String(canonicalDevIdentity.value?.user.id??0)+':'+preferenceNamespace.value);
const globalRows=computed(()=>rows.value as unknown as Record<string,unknown>[]);
const selectedDomesticExportHref=computed(()=>{
  if(props.scope!=='domestic'||view.value!=='pending'||!selectedRows.value.length)return '';
  const qs=new URLSearchParams();
  for(const id of [...new Set(selectedRows.value.map(row=>row.order_id))])
    qs.append('order_ids[]',String(id));
  return '/logistics/china/pending-batch/export?'+qs.toString();
});
const queryCapabilities=computed(()=>logisticsQueryCapabilities(props.scope,view.value));
const filterKeys=computed(()=>queryCapabilities.value.filterKeys);
const gridColumns=computed(()=>logisticsOrderColumns(props.scope,view.value));
function onGridSelection(input:Record<string,unknown>[]){
  selectedRows.value=input.filter(row=>Number.isSafeInteger(row.order_id))
    .map(row=>row as unknown as LogisticsRow);
}
function onGridSort(input:{colId:string;direction:'asc'|'desc'|null}){
  if(!input.direction && !sorting.value)return;
  sorting.value=queryCapabilities.value.sortKeys.includes(input.colId) && input.direction
    ?{colId:input.colId as LogisticsSortKey,direction:input.direction}:null;
  void load(1);
}
function clearGridFilters(){if(Object.values(filters.value).some(Boolean)){filters.value={};void load(1);}}
function onGridColumnFilter(input:{colId:string;value:string}){
  if(!filterKeys.value.includes(input.colId))return;
  filters.value={...filters.value,[input.colId as LogisticsFilterKey]:input.value};
  void load(1);
}
async function applyBulkReview(){
  if(!authorizedReview.value||view.value!=='review'||reviewBusy.value || !isWorkScope.value)return;
  const ids=[...new Set(selectedRows.value.filter(row=>row.review_status==='pending')
    .map(row=>row.order_id))];
  if(ids.length!==selectedRows.value.length || !ids.length || ids.length>50){
    reviewError.value='仅允许对当前待审核队列中的 1–50 条订单操作；请重新选择。';return;
  }
  const label=bulkAction.value==='approve'?'审核通过':bulkAction.value==='stockout'?'标记缺货':'驳回';
  let reason: string|undefined;
  if(bulkAction.value==='reject'){
    const typed=window.prompt('请填写选中订单统一驳回原因（3–500字）');
    if(typed===null)return;
    reason=typed.trim();
    if(reason.length<3||reason.length>500){reviewError.value='驳回原因需填写 3–500 字。';return;}
  }
  if(!window.confirm('确认对 '+ids.length+' 条选中的 Dev 订单执行「'+label+'」？'))return;
  reviewBusy.value=true;reviewError.value='';reviewSuccess.value='';
  try {
    const result=props.scope==='domestic'
      ?await submitOriginalErpReview({scope:'domestic',orderIds:ids,action:bulkAction.value,reason})
      :await submitOriginalErpReview({scope:'overseas',orderIds:ids,action:bulkAction.value,reason});
    reviewSuccess.value='原 Laravel 批量审核成功 '+result.success_count+' 条；失败 '+result.failed_count+' 条';
    if(result.failed_count)reviewError.value=result.results.filter(r=>!r.ok).map(r=>r.message).slice(0,3).join('；');
    selectedRows.value=[];await load(1);
  }catch(e){reviewError.value=e instanceof Error?e.message:'批量审核失败';}
  finally{reviewBusy.value=false;}
}
function clearData(){
  ++sequence; rows.value=[];detail.value=null;selectedRows.value=[];
  capabilities.value={canReview:false,canOperate:false};reviewError.value='';
  shippingError.value='';carrierOptions.value=[];
  pager.value={page:1,perPage:pager.value.perPage,total:0,lastPage:1};
}
async function load(page=1){
  const ticket=++sequence;error.value='';rows.value=[];detail.value=null;selectedRows.value=[];
  if(import.meta.env.VITE_ERP_IDENTITY_MODE!=='laravel' || !canonicalDevIdentity.value){
    error.value='ERP2 物流只允许通过 Laravel 原始 Dev Session 读取。';return;
  }
  if(!allowed.value){error.value='当前账号无 logistics.view 权限，已阻止访问。';return;}
  loading.value=true;
  try{
    const result=await getLogisticsPage({scope:props.scope,page,perPage:pager.value.perPage,
      search:search.value,view:view.value,warehouse:warehouse.value,
      sortCol:sorting.value?.colId,sortDir:sorting.value?.direction,columnFilters:filters.value});
    if(ticket!==sequence || !allowed.value)return;
    rows.value=result.rows;
    capabilities.value={canReview:result.canReview===true,canOperate:result.canOperate===true};
    carrierOptions.value=result.carrierOptions||[];allocationWarehouses.value=result.allocationWarehouses||[];omsCapabilities.value=result.omsCapabilities||null;
    pager.value={page:result.page,perPage:result.perPage as 20|50|100,
      total:result.total,lastPage:result.lastPage};
  }catch(reason){
    if(ticket!==sequence)return;
    rows.value=[];capabilities.value={canReview:false,canOperate:false};carrierOptions.value=[];
    pager.value={...pager.value,page:1,total:0,lastPage:1};
    const message=reason instanceof Error?reason.message:'';
    error.value=/Failed to fetch|NetworkError|fetch failed/i.test(message)
      ?'隔离 Dev 网关连接失败，请检查本机预览服务后点击「刷新数据」。订单数据未被修改。'
      :message || '隔离 Dev 物流读取失败';
  }finally{if(ticket===sequence)loading.value=false;}
}
function filter(){void load(1);}
function switchView(next:DomesticView|OverseasView){
  clearReturnPreview();view.value=next;pager.value.perPage=20;search.value='';filters.value={};sorting.value=null;reviewSuccess.value='';void load(1);
}
function switchWarehouse(next:string){
  if(props.scope!=='overseas' || !warehouses.some(w=>w.value===next))return;
  omsCapabilities.value=null;warehouse.value=next;pager.value.perPage=20;search.value='';filters.value={};sorting.value=null;reviewSuccess.value='';shippingSuccess.value='';void load(1);
}
function onCellClick(event:CellClickedEvent<Record<string,unknown>>){
  if(event.column.getColId()==='action' && event.data && !loading.value){
    omsPreflight.value='';barcodeRows.value=[];barcodeError.value='';detail.value=event.data as unknown as LogisticsRow;reviewError.value='';reviewReason.value='';
    shippingError.value='';trackingDraft.value=String(event.data.tracking||'');
    carrierSelection.value=String(event.data.carrier||'');serviceSelection.value=String(event.data.service||'');
    outboundReference.value='';
  }
}
async function decideReview(action:DomesticReviewAction){
  const row=detail.value;
  if(!row || !reviewActions.value.includes(action) || reviewBusy.value)return;
  const reason=action==='reject'?reviewReason.value.trim():undefined;
  if(action==='reject' && (!reason || reason.length<3 || reason.length>500)){
    reviewError.value='驳回原因需填写 3–500 字。';return;
  }
  if(!window.confirm('确认对 '+row.order_number+' 执行「'+reviewActionLabels[action]+'」？'))return;
  reviewBusy.value=true;reviewError.value='';reviewSuccess.value='';
  try{
    const result=props.scope==='domestic'
      ?await submitOriginalErpReview({scope:'domestic',orderIds:[row.order_id],action,reason})
      :await submitOriginalErpReview({scope:'overseas',orderIds:[row.order_id],
          action:action as OverseasReviewAction,reason});
    const outcome=result.results[0];
    if(result.success_count!==1 || result.failed_count!==0 || !outcome?.ok){
      reviewError.value=outcome?.message || '原 Laravel 已阻止本次审核；无成功写入。';
      return;
    }
    reviewSuccess.value=row.order_number+' · '+reviewActionLabels[action]+'成功（原 Laravel）';
    detail.value=null;
    await load(1);
  }catch(cause){
    reviewError.value=cause instanceof Error?cause.message:'原 Laravel 审核失败';
  }finally{reviewBusy.value=false;}
}
function onCarrierChange(){
  const selected=selectedCarrier.value;
  serviceSelection.value=selected?.default_service_value || selected?.services[0]?.value || '';
}
/** One single POST, then a fresh original Laravel GET as proof. No automatic POST retry. */
async function confirmShipping(action:'carrier-service'|'tracking-draft'|'quick-ship'|'manual-ship'){
  const row=detail.value;
  if(!row||!authorizedShipping.value||shippingBusy.value)return;
  const actor=canonicalDevIdentity.value?.user.id,scope=props.scope,warehouseAtSubmit=warehouse.value;
  const carrierAtSubmit=carrierSelection.value,serviceAtSubmit=serviceSelection.value;
  const tracking=trackingDraft.value.trim();
  const outbound=outboundReference.value.trim();
  if(action==='carrier-service' &&
    (!selectedCarrier.value||!availableServices.value.some(s=>s.value===serviceSelection.value))){
    shippingError.value='请在原 ERP 已启用的物流商及服务类型中选择。';return;
  }
  if(action==='manual-ship' && row.warehouse_code.toUpperCase()==='US-LA' && !outbound){
    shippingError.value='MorePlus 人工发货必须填写真实出库凭证号。';return;
  }
  if((action==='quick-ship'||action==='manual-ship') &&
    !window.confirm('确认 '+row.order_number+' 已实际出库，Tracking 为 '+tracking+'？这将写入原 ERP 发货记录。'))return;
  let input:OriginalShippingAction;
  if(props.scope==='domestic'){
    input=action==='carrier-service'
      ?{scope:'domestic',action,orderId:row.order_id,carrier:carrierSelection.value,service:serviceSelection.value}
      :{scope:'domestic',action:action as 'tracking-draft'|'quick-ship',orderId:row.order_id,tracking};
  }else{
    input={scope:'overseas',action:'manual-ship',orderId:row.order_id,
      warehouse:row.warehouse_code,tracking,outboundReference:outbound};
  }
  shippingBusy.value=true;shippingError.value='';shippingSuccess.value='';
  try{
    await submitOriginalErpShipment(input);
    if(actor!==canonicalDevIdentity.value?.user.id||scope!==props.scope||warehouseAtSubmit!==warehouse.value)return;
    // All-queue must include shipped rows that have left the pending queue.
    const checked=await getLogisticsPage({scope:props.scope,view:'all',
      warehouse:props.scope==='overseas'?warehouse.value:'',
      search:row.order_number,perPage:20});
    const fresh=checked.rows.find(r=>r.order_id===row.order_id);
    if(!fresh)throw Error('ERP2_SHIPPING_READBACK_MISSING_DO_NOT_RETRY');
    if(scope==='domestic'&&action!=='manual-ship'){
      shippingSuccess.value=row.order_number+' · '+domesticShipmentReceipt(action,fresh,{carrier:carrierAtSubmit,service:serviceAtSubmit,tracking});detail.value=null;await load(1);return;
    }
    const confirmed=action==='carrier-service'
      ?fresh.carrier===carrierAtSubmit && fresh.service===serviceAtSubmit
      :action==='tracking-draft'
        ?fresh.tracking===tracking
        :fresh.tracking===tracking && fresh.shipment_status==='shipped';
    if(!confirmed)throw Error('ERP2_SHIPPING_READBACK_UNCONFIRMED_DO_NOT_RETRY');
    shippingSuccess.value=row.order_number+' · '+(
      action==='carrier-service'?'物流商/服务已保存（未发货）':
      action==='tracking-draft'?'Tracking 草稿已保存（未发货）':
      'ERP 发货确认已落库；官网同步请以原同步模块回执为准');
    detail.value=null;
    await load(1);
  }catch(e){
    shippingError.value=e instanceof Error?e.message:'ERP2_SHIPPING_UNKNOWN';
  }finally{shippingBusy.value=false;}
}
watch(() => [props.scope,canonicalDevIdentity.value?.user.id,
  canonicalDevIdentity.value?.effectivePermissionKeys.join('|')],()=>{
  clearReturnPreview();clearData();search.value='';warehouse.value='';view.value='review';
  filters.value={};sorting.value=null;selectedRows.value=[];void load(1);
},{immediate:true});
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-3 p-3">
    <header class="flex flex-wrap items-start justify-between gap-2">
      <div><h1 class="text-xl font-semibold text-slate-800">{{ meta.title }}</h1>
        <p class="mt-1 text-xs text-slate-500">{{ meta.subtitle }}</p></div>
      <div class="flex items-center gap-2">
        <button type="button" class="rounded border border-slate-200 bg-white px-3 py-2 text-xs"
          :disabled="loading || !allowed" @click="load(pager.page)">↻ 刷新数据</button>
        <span class="rounded bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">
          {{ shippingEnabled && isWorkScope?'隔离 Dev · 审核 / 发货':reviewEnabled && isWorkScope?'隔离 Dev · 原 Laravel 审核':'隔离 Dev · 只读' }}</span>
      </div>
    </header>

    <div v-if="!reviewEnabled && isWorkScope"
      class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
      原 Laravel 审核按钮尚未在此隔离构建启用；发货与 Tracking 上传仍由原 ERP 流程负责。
    </div>
    <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded border border-slate-200 bg-white">
      <div class="flex min-h-[40px] gap-1 overflow-x-auto border-b border-slate-200 px-3">
        <template v-if="isWorkScope">
          <button v-for="v in views" :key="v.key" type="button"
            class="shrink-0 border-b-2 px-4 py-2 text-xs font-semibold"
            :class="view===v.key?'border-indigo-500 text-indigo-600':'border-transparent text-slate-500'"
            :disabled="loading" @click="switchView(v.key)">{{ v.label }}</button>
        </template>
        <span v-else class="border-b-2 border-indigo-500 px-4 py-2 text-xs font-semibold text-indigo-600">
          待分配</span>
      </div>
      <nav v-if="props.scope==='overseas'" aria-label="海外仓切换"
        class="flex flex-wrap items-center gap-1 border-b border-slate-200 px-3 py-2">
        <span class="mr-2 text-xs font-medium text-slate-500">发货仓：</span>
        <button v-for="w in warehouses" :key="w.value" type="button"
          class="rounded-md border px-3 py-1.5 text-xs font-semibold transition-colors"
          :class="warehouse===w.value?'border-indigo-600 bg-indigo-50 text-indigo-700':'border-slate-200 bg-white text-slate-600 hover:border-indigo-300'"
          :disabled="loading" :aria-pressed="warehouse===w.value"
          @click="switchWarehouse(w.value)">{{ w.label }}</button>
      </nav>
      <div v-if="props.scope==='domestic'" class="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-slate-50 px-3 py-2">
        <span class="text-xs font-semibold text-slate-600">原 ERP Excel 导出：</span>
        <template v-if="view==='pending'">
          <a data-testid="p0-export-domestic-pending" href="/logistics/china/pending-batch/export"
            class="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs text-indigo-700">下载待发货批量表</a>
          <a data-testid="p0-export-domestic-sku-merged" href="/logistics/china/pending-batch/export-sku-merged"
            class="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs text-indigo-700">下载 SKU 合并版</a>
          <a v-if="selectedDomesticExportHref" data-testid="p0-export-domestic-selected" :href="selectedDomesticExportHref"
            class="rounded border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700">
            下载已选 {{ selectedRows.length }} 单</a>
        </template>
        <a v-else-if="view==='today'" data-testid="p0-export-domestic-today"
          href="/logistics/china/today/export" class="rounded border bg-white px-3 py-1.5 text-xs text-indigo-700">下载今日订单表</a>
        <a v-else-if="view==='today-4px'" data-testid="p0-export-domestic-4px"
          href="/logistics/china/export/4px" class="rounded border bg-white px-3 py-1.5 text-xs text-indigo-700">下载今日速达</a>
        <a v-else-if="view==='today-weship'" data-testid="p0-export-domestic-weship"
          href="/logistics/china/export/weship" class="rounded border bg-white px-3 py-1.5 text-xs text-indigo-700">下载今日纬狮</a>
        <span v-else class="text-xs text-slate-500">切换至待发货或今日订单队列下载原有业务模板</span>
      </div>
      <div v-if="canPreviewDomesticReturn && view==='pending'"
        class="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-3 py-2">
        <label class="text-xs font-semibold text-slate-600" for="erp2-domestic-return-file">物流回传表 XLS / XLSX：</label>
        <input id="erp2-domestic-return-file" data-testid="p0-domestic-tracking-return-file"
          type="file" accept=".xls,.xlsx" class="max-w-[245px] text-xs"
          :disabled="returnPreviewBusy||returnApplyBusy" @change="chooseDomesticReturnFile" />
        <button type="button" data-testid="p0-domestic-tracking-preview"
          class="rounded border border-indigo-500 px-3 py-1.5 text-xs font-semibold text-indigo-700"
          :disabled="!returnFile||returnPreviewBusy||returnApplyBusy" @click="previewDomesticReturn">
          {{ returnPreviewBusy?'正在由原 ERP 解析…':'上传并预览（不写订单）' }}</button>
        <span v-if="returnPreview" role="status" class="text-xs text-slate-700">
          {{ returnPreview.provider }} · 可应用 {{ returnPreview.apply_count }}，
          重复 {{ returnPreview.noop_count }}，阻断 {{ returnPreview.blocked_count }}。
          {{ returnApplyEnabled?'请核对回传行后确认登记。':'当前仅预览；Apply 尚未开放。' }}</span>
        <div v-if="returnConfirmation" class="w-full max-h-56 overflow-auto">
          <table class="w-full text-xs"><thead><tr><th>订单号</th><th>Tracking</th><th>状态</th><th>说明</th></tr></thead>
            <tbody><tr v-for="(row,i) in returnConfirmation.rows" :key="i"><td>{{ row.order_number }}</td><td>{{ row.tracking_number }}</td><td>{{ row.status }}</td><td>{{ row.message }}</td></tr></tbody></table>
        </div>
        <button v-if="returnApplyEnabled && returnConfirmation" type="button" data-testid="p0-domestic-tracking-apply"
          class="rounded border border-indigo-500 px-3 py-1.5 text-xs font-semibold text-indigo-700"
          :disabled="returnApplyBusy||returnPreviewBusy||returnConfirmation.apply_count===0" @click="applyDomesticReturn">确认批量登记</button>
        <p v-if="returnApplyResult" role="status" class="w-full text-xs">{{ returnApplyResult }}</p>
        <p v-if="returnPreviewError" role="alert" class="w-full text-xs text-red-700">{{ returnPreviewError }}</p>
      </div>
      <div v-if="canExportOversea" class="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-3 py-2"><a :href="originalOverseaExportHref(warehouse)" class="rounded border px-3 py-1.5 text-xs">下载本仓待发货</a><a v-if="selectedOverseaExportHref" :href="selectedOverseaExportHref" class="rounded border px-3 py-1.5 text-xs">下载已选 {{selectedRows.length}} 单</a></div>
      <OverseaReturnPanel v-if="overseaFlowEnabled && canExportOversea && warehouse!=='us-sm' && capabilities.canOperate" :key="gridInstanceKey" :warehouse="warehouse" @completed="load(1)"/>
      <OverseaOmsBatchPanel v-if="omsEnabled && props.scope==='overseas' && view==='pending' && omsWarehouse && omsCapabilities?.batch && omsCapabilities.simulation" :key="gridInstanceKey" :warehouse="omsWarehouse" :ids="selectedRows.map(r=>r.order_id)" :capabilities="omsCapabilities" :can-operate="capabilities.canOperate"/>
      <p v-if="domesticFlowResult" role="status" class="mx-3 text-xs text-emerald-700">{{ domesticFlowResult }}</p>
      <p v-if="domesticFlowError" role="alert" class="mx-3 text-xs text-red-700">{{ domesticFlowError }}</p>
      <p v-if="shippingSuccess" role="status"
        class="mx-3 mt-2 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">{{ shippingSuccess }}</p>
      <p v-if="reviewSuccess" role="status"
        class="mx-3 mt-2 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
        {{ reviewSuccess }}</p>
      <p v-if="error" role="alert" class="m-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
        {{ error }}</p>
      <div class="grid-host min-h-0 flex-1" :aria-busy="loading">
        <p v-if="loading" role="status" class="px-3 pt-2 text-xs text-slate-500">正在读取原 Laravel 全库数据…</p>
        <GlobalGrid
          :key="gridInstanceKey"
          :namespace="preferenceNamespace"
          :title="meta.title + ' · 新表'"
          :columns="gridColumns"
          :rows="globalRows"
          :page="{current_page:pager.page,last_page:pager.lastPage,per_page:pager.perPage,total:pager.total}"
          row-identity-key="order_id"
          :required-column-ids="['order_number','action']"
          :search="search"
          :search-placeholder="props.scope==='domestic'||props.scope==='allocation'?'搜索全库订单号 / 收件人…':props.scope==='overseas'?'搜索全库订单号 / SKU / Tracking…':'搜索订单号 / Tracking / 物流商…'"
          :sort="sorting || undefined"
          :filter-columns="filterKeys"
          :filter-values="filters"
          :enable-selection="queryCapabilities.selection||canAllocateFactory"
          :selected-order-ids="selectedRows.map(row=>row.order_id)"
          :default-pinned-right="1"
          :client-csv-export="false"
          :cell-text-selection="true"
          @cell-clicked="onCellClick"
          @selection-change="onGridSelection"
          @search-change="search=$event;filter()"
          @page-change="load($event)"
          @page-size-change="pager.perPage=$event as 20|50|100;filter()"
          @sort-change="onGridSort"
          @column-search-change="onGridColumnFilter"
          @clear-column-filters="clearGridFilters"
        >
          <template #footer-left>
            <span v-if="selectedRows.length" class="text-xs text-slate-600">
              已选 {{ selectedRows.length }} 条（当前页）
            </span>
            <button v-if="selectedRows.length && canAllocateFactory" data-testid="p0-batch-factory" type="button" class="rounded bg-indigo-600 px-2 py-1 text-xs text-white" :disabled="domesticFlowBusy||loading" @click="runDomesticFlow('allocate_factory')">批量分到中国工厂</button>
            <button v-if="selectedRows.length && canForceReturnDomestic" data-testid="p0-batch-return-allocation" type="button" class="rounded border px-2 py-1 text-xs" :disabled="domesticFlowBusy||loading||reviewBusy" @click="runDomesticFlow('return_allocation')">批量到待分仓</button>
            <button v-if="selectedRows.length && canForceReturnOversea" data-testid="p0-oversea-batch-return" type="button" class="rounded border px-2 py-1 text-xs" :disabled="domesticFlowBusy||loading||reviewBusy" @click="runOverseaReturn">批量到待分仓</button>
            <template v-if="selectedRows.length && authorizedReview && view==='review'">
              <select v-model="bulkAction" aria-label="批量审核动作"
                class="rounded border border-slate-300 px-2 py-1 text-xs">
                <option value="approve">审核通过</option>
                <option value="stockout">标记缺货</option>
                <option value="reject">驳回</option>
              </select>
              <button type="button" class="rounded bg-indigo-600 px-2 py-1 text-xs text-white"
                :disabled="reviewBusy||domesticFlowBusy||loading" @click="applyBulkReview">批量执行</button>
            </template>
          </template>
          <template #actions>
            <button type="button" class="rounded border px-2 py-1 text-xs"
              :disabled="loading||!allowed" @click="load(pager.page)">↻ 刷新</button>
          </template>
        </GlobalGrid>
      </div>
    </div>
    <p class="text-xs text-slate-400">帮助 · 审核结果由原 Laravel 记录；确认发货需真实 Tracking 和独立履约验证 · Workspace Tabs 保持不变。</p>

    <div v-if="detail" class="fixed inset-0 flex justify-end bg-slate-900/40" style="z-index: 20000" @click.self="detail=null">
      <aside class="h-full w-[440px] max-w-[95vw] overflow-y-auto bg-white p-6 shadow-2xl">
        <div class="flex items-start justify-between">
          <div><p class="text-xs text-slate-500">订单 / 物流详情 · 原 Laravel</p>
            <h2 class="mt-1 text-lg font-semibold">{{ detail.order_number }}</h2></div>
          <button type="button" class="rounded border px-3 py-1 text-xs" @click="detail=null">关闭</button>
        </div>
        <dl class="mt-5 space-y-3 text-sm">
          <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">子物流单</dt><dd>{{ detail.fulfillment_suffix||'—' }}</dd></div>
          <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">发货仓</dt><dd>{{ detail.warehouse_code||'—' }}</dd></div>
          <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">状态</dt><dd>{{ detail.status||'—' }}</dd></div>
          <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">物流商</dt><dd>{{ detail.carrier||'—' }}</dd></div>
          <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">单号</dt><dd>{{ detail.tracking||'—' }}</dd></div>
        </dl>
        <h3 class="mt-6 text-sm font-semibold">SKU 明细</h3>
        <div v-for="(item,i) in detail.items" :key="i" class="mt-2 rounded border border-slate-200 p-3 text-xs">
          <div>SKU: {{ item.sku||'—' }}</div>
          <div class="mt-1">实际 SKU: {{ item.final_sku||item.resolved_sku||item.sku||'—' }}</div>
          <div class="mt-1">数量: {{ item.quantity }}</div>
        </div>
        <DomesticAllocationPanel v-if="canAllocateFactory && detail.allocation_plan" :key="detail.order_id" :order-id="detail.order_id" :plan="detail.allocation_plan" :warehouses="allocationWarehouses" @completed="allocationCompleted"/>
        <section v-if="props.scope==='domestic'" class="mt-4 space-y-2 rounded border p-3 text-xs"><button type="button" class="rounded border px-2 py-1" :disabled="barcodeBusy" @click="loadBarcode">查看欧洲包裹69条码</button><p v-if="barcodeError" role="alert">{{barcodeError}}</p><p v-for="(b,i) in barcodeRows" :key="i">{{b.sku}} × {{b.quantity}} · {{b.barcode}}</p></section>
        <section v-if="omsEnabled && is3smDetail" class="mt-4 rounded border p-3 text-xs"><strong>世迈 OMS · 人工导入</strong><p>现有 OMS 单号：{{detail.oms_order_number||'—'}} · 状态：{{detail.oms_status||'—'}}</p><button type="button" class="rounded border px-2 py-1" :disabled="omsPreflightBusy" @click="check3sm">本地预检</button><a v-if="capabilities.canOperate && detail.review_status==='approved' && detail.shipment_status==='pending'" :href="original3smManualExport(detail.order_id)" class="ml-2 rounded border px-2 py-1">下载单笔 OMS 文件</a><p v-if="omsPreflight">{{omsPreflight}}</p><p>先核对 OMS 当前草稿箱，再人工导入；Dev 不执行真实 OMS 查询或自动建单。</p></section>
        <section v-if="reviewActions.length" class="mt-6 rounded border border-indigo-200 p-3">
          <h3 class="text-sm font-semibold">审核操作 · 当前订单</h3>
          <p class="mt-1 text-xs text-slate-500">将调用原 Laravel 审核服务；每次只提交这一个订单。</p>
          <label v-if="reviewActions.includes('reject')" class="mt-3 block text-xs">
            驳回原因（选择驳回时必填）
            <textarea v-model="reviewReason" rows="2" maxlength="500"
              class="mt-1 w-full rounded border border-slate-300 p-2"
              placeholder="输入 3–500 字驳回原因" :disabled="reviewBusy"></textarea>
          </label>
          <p v-if="reviewError" role="alert" class="mt-2 rounded bg-red-50 p-2 text-xs text-red-700">
            {{ reviewError }}</p>
          <div class="mt-3 flex flex-wrap gap-2">
            <button v-for="action in reviewActions" :key="action" type="button"
              class="rounded border border-indigo-500 px-3 py-2 text-xs font-semibold text-indigo-700 disabled:opacity-50"
              :disabled="reviewBusy" @click="decideReview(action)">{{ reviewActionLabels[action] }}</button>
          </div>
        </section>
        <p v-else-if="reviewError" role="alert" class="mt-3 rounded bg-red-50 p-3 text-xs text-red-700">
          {{ reviewError }}</p>
        <section v-if="authorizedShipping" class="mt-6 space-y-3 rounded border border-indigo-200 p-3">
          <h3 class="text-sm font-semibold">原 ERP 物流履约 · 当前订单</h3>
          <p class="text-xs text-slate-500">仅对隔离 Dev 订单操作；每次仅提交一次，提交后从 Laravel 重新读取验证。</p>
          <template v-if="props.scope==='domestic'">
            <label class="block text-xs">物流商
              <select v-model="carrierSelection" aria-label="国内物流商" class="mt-1 w-full rounded border p-2" :disabled="shippingBusy" @change="onCarrierChange">
                <option value="">选择原 ERP 启用的物流商</option>
                <option v-for="c in carrierOptions" :key="c.value" :value="c.value">{{ c.label }}</option>
              </select>
            </label>
            <label class="block text-xs">运输 / 服务类型
              <select v-model="serviceSelection" aria-label="国内服务类型" class="mt-1 w-full rounded border p-2" :disabled="shippingBusy">
                <option value="">选择服务类型</option>
                <option v-for="s in availableServices" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
            </label>
            <button type="button" class="rounded border border-indigo-500 px-3 py-2 text-xs text-indigo-700" :disabled="shippingBusy||!selectedCarrier||!serviceSelection" @click="confirmShipping('carrier-service')">保存物流商 / 服务（未发货）</button>
          </template>
          <p v-else class="rounded bg-slate-50 p-2 text-xs">当前物流商：{{ detail.carrier||'未配置' }} · {{ detail.service||'未配置服务' }}。海外仓使用原仓库默认渠道。</p>
          <label class="block text-xs">Tracking / 物流单号
            <input v-model="trackingDraft" aria-label="发货 Tracking" maxlength="128" class="mt-1 w-full rounded border p-2" placeholder="填写真实 Tracking" :disabled="shippingBusy" />
          </label>
          <label v-if="props.scope==='overseas' && detail.warehouse_code.toUpperCase()==='US-LA'" class="block text-xs">MorePlus 真实出库参考号
            <input v-model="outboundReference" aria-label="MorePlus 出库号" maxlength="90" class="mt-1 w-full rounded border p-2" :disabled="shippingBusy" />
          </label>
          <div class="flex flex-wrap gap-2">
            <button v-if="props.scope==='domestic'" type="button" class="rounded border border-indigo-500 px-3 py-2 text-xs text-indigo-700" :disabled="shippingBusy" @click="confirmShipping('tracking-draft')">仅保存 Tracking 草稿</button>
            <button type="button" class="rounded bg-indigo-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50" :disabled="shippingBusy||!trackingDraft.trim()" @click="confirmShipping(props.scope==='domestic'?'quick-ship':'manual-ship')">确认实际发货…</button>
          </div>
          <p v-if="shippingError" role="alert" class="rounded bg-red-50 p-2 text-xs text-red-700">{{ shippingError }}。请核对原 ERP 状态，勿重复提交发货。</p>
        </section>
        <p v-else-if="view==='pending' && capabilities.canOperate" class="mt-3 rounded bg-amber-50 p-3 text-xs text-amber-800">
          当前订单未满足此仓库的人工履约条件，或隔离 Dev 发货写入开关尚未开放。发货须沿用原 ERP 专属流程。
        </p>
        <p class="mt-4 text-xs text-slate-500">受 Laravel 会话权限及当前订单状态约束；不能操作的状态不显示审核按钮。</p>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.grid-host :deep(.oms-grid){height:clamp(420px,62vh,840px);min-height:400px;border:0;border-radius:0}
.grid-host :deep(.oms-grid.is-maximized){height:100dvh;min-height:0;border:0}
</style>