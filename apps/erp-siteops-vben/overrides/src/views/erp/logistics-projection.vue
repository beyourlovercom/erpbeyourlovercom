<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { CellClickedEvent, ColDef } from 'ag-grid-community';
import GlobalGrid from '#/components/erp-global-grid/GlobalGrid.vue';
import { canonicalDevIdentity } from '#/router/erp-live-navigation';
import { getLogisticsPage, submitOriginalErpReview, submitOriginalErpShipment,
  type LogisticsCarrierOption, type OriginalShippingAction, type DomesticView, type DomesticReviewAction,
  type LogisticsRow, type LogisticsScope, type OverseasView, type OverseasReviewAction } from '#/api/erp-logistics';

defineOptions({ name: 'ERP2LogisticsProjection' });
const props = defineProps<{ scope: LogisticsScope }>();
const pageConfig = {
  allocation:{title:'待分仓',subtitle:'尚未分配发货仓的订单 · 运营分仓前只读确认',path:'待分仓'},
  domestic:{title:'国内发货',subtitle:'中国工厂 · 原 Laravel 九个业务队列',path:'国内发货'},
  overseas:{title:'海外仓发货',subtitle:'海外仓审核、运单履约与 A/B/C 子单',path:'海外仓发货'},
};
const meta = computed(() => pageConfig[props.scope]);
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
const reviewBusy=ref(false),reviewError=ref(''),reviewSuccess=ref(''),reviewReason=ref('');
const shippingBusy=ref(false),shippingError=ref(''),shippingSuccess=ref('');
const trackingDraft=ref(''),outboundReference=ref(''),carrierSelection=ref(''),serviceSelection=ref('');
const carrierOptions=ref<LogisticsCarrierOption[]>([]);
const shippingEnabled=import.meta.env.VITE_ERP_IDENTITY_MODE==='laravel' &&
  import.meta.env.VITE_ERP_M2_ORIGINAL_INERTIA_ENABLED==='1' &&
  import.meta.env.VITE_ERP_M2_SHIPPING_WRITE_ENABLED==='1';
const reviewEnabled=import.meta.env.VITE_ERP_IDENTITY_MODE==='laravel' &&
  import.meta.env.VITE_ERP_M2_ORIGINAL_INERTIA_ENABLED==='1' &&
  import.meta.env.VITE_ERP_M2_REVIEW_WRITE_ENABLED==='1';
const allowed = computed(() => !!canonicalDevIdentity.value?.effectivePermissionKeys.includes('logistics.view'));
const authorizedReview = computed(() => reviewEnabled && allowed.value && capabilities.value.canReview &&
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
  props.scope!=='allocation' && view.value==='pending' && detail.value?.shipment_status==='pending' &&
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
type GridKey = keyof LogisticsRow;
type Column = {key:GridKey;label:string;width?:number};
const common: Column[] = [
 {key:'order_number',label:'前台订单号',width:185},
 {key:'order_date',label:'下单日期',width:130},
 {key:'status',label:'核心状态',width:130},
 {key:'sku',label:'SKU',width:170},
 {key:'actual_sku',label:'实际 SKU',width:170},
 {key:'qty',label:'数量',width:86},
 {key:'warehouse_code',label:'发货仓',width:134},
 {key:'carrier',label:'物流商',width:128},
 {key:'tracking',label:'物流单号',width:170},
];
const overseas: Column[] = [
 {key:'order_number',label:'前台订单号',width:185},
 {key:'fulfillment_suffix',label:'A/B/C',width:90},
 {key:'order_date',label:'下单日期',width:130},
 {key:'status',label:'履约状态',width:125},
 {key:'sku',label:'SKU',width:165},
 {key:'actual_sku',label:'实际 SKU',width:165},
 {key:'qty',label:'数量',width:86},
 {key:'warehouse_code',label:'海外仓',width:130},
 {key:'review_status',label:'审核',width:118},
 {key:'oms_status',label:'OMS 状态',width:110},
 {key:'tracking',label:'物流单号',width:160},
];
const availableCols = computed(() => props.scope==='overseas'?overseas:common);
// Original ERP1 GlobalGrid/OmsGrid implementation is ported as one shared
// component, using the same Laravel account-preferences table on isolated Dev.
const selectedRows = ref<LogisticsRow[]>([]);
const bulkAction = ref<'approve'|'stockout'|'reject'>('approve');
const sorting = ref<{colId:'tracking';direction:'asc'|'desc'}|null>(null);
const filters = ref<Partial<Record<'carrier'|'tracking'|'status',string>>>({});
const preferenceNamespace=computed(()=>`grid.erp2.logistics.${props.scope}.v1`);
const globalRows=computed(()=>rows.value as unknown as Record<string,unknown>[]);
const selectedDomesticExportHref=computed(()=>{
  if(props.scope!=='domestic'||view.value!=='pending'||!selectedRows.value.length)return '';
  const qs=new URLSearchParams();
  for(const id of [...new Set(selectedRows.value.map(row=>row.order_id))])
    qs.append('order_ids[]',String(id));
  return '/logistics/china/pending-batch/export?'+qs.toString();
});
const sortKeys = computed(()=>props.scope==='overseas'?['tracking']:[]);
const filterKeys=computed(()=>props.scope==='overseas'?['carrier','tracking']:view.value==='all'?['carrier','status']:[]);
const gridColumns=computed<ColDef<Record<string,unknown>>[]>(()=>[
  ...availableCols.value.map(c=>({
    field:c.key,headerName:c.label,width:c.width,resizable:true,
    // Laravel's canonical overseas list supports only these server sort fields.
    sortable:sortKeys.value.includes(c.key),
    pinned:c.key==='order_number'?'left' as const:undefined,
    lockPinned:c.key==='order_number',
    valueFormatter:(p:{value:unknown})=>p.value==null||p.value===''?'—':String(p.value),
  })),
  {colId:'action',headerName:'操作',width:108,pinned:'right' as const,
   lockPinned:true,lockVisible:true,sortable:false,resizable:false,
   valueGetter:()=> '查看详情'},
]);
function onGridSelection(input:Record<string,unknown>[]){
  selectedRows.value=input.filter(row=>Number.isSafeInteger(row.order_id))
    .map(row=>row as unknown as LogisticsRow);
}
function onGridSort(input:{colId:string;direction:'asc'|'desc'|null}){
  sorting.value=props.scope==='overseas' && input.colId==='tracking' && input.direction
    ?{colId:'tracking',direction:input.direction}:null;
  void load(1);
}
function onGridColumnFilter(input:{colId:string;value:string}){
  if(!filterKeys.value.includes(input.colId))return;
  filters.value={...filters.value,[input.colId]:input.value};
  void load(1);
}
async function applyBulkReview(){
  if(!authorizedReview.value||view.value!=='review'||reviewBusy.value || props.scope==='allocation')return;
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
    carrierOptions.value=result.carrierOptions||[];
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
  view.value=next;filters.value={};sorting.value=null;reviewSuccess.value='';void load(1);
}
function switchWarehouse(next:string){
  if(props.scope!=='overseas' || !warehouses.some(w=>w.value===next))return;
  warehouse.value=next;reviewSuccess.value='';shippingSuccess.value='';void load(1);
}
function onCellClick(event:CellClickedEvent<Record<string,unknown>>){
  if(event.column.getColId()==='action' && event.data && !loading.value){
    detail.value=event.data as unknown as LogisticsRow;reviewError.value='';reviewReason.value='';
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
    // All-queue must include shipped rows that have left the pending queue.
    const checked=await getLogisticsPage({scope:props.scope,view:'all',
      warehouse:props.scope==='overseas'?warehouse.value:'',
      search:row.order_number,perPage:20});
    const fresh=checked.rows.find(r=>r.order_id===row.order_id);
    if(!fresh)throw Error('ERP2_SHIPPING_READBACK_MISSING_DO_NOT_RETRY');
    const confirmed=action==='carrier-service'
      ?fresh.carrier===carrierSelection.value && fresh.service===serviceSelection.value
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
  clearData();search.value='';warehouse.value='';view.value='review';
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
          {{ shippingEnabled && props.scope!=='allocation'?'隔离 Dev · 审核 / 发货':reviewEnabled && props.scope!=='allocation'?'隔离 Dev · 原 Laravel 审核':'隔离 Dev · 只读' }}</span>
      </div>
    </header>

    <div v-if="!reviewEnabled && props.scope!=='allocation'"
      class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
      原 Laravel 审核按钮尚未在此隔离构建启用；发货与 Tracking 上传仍由原 ERP 流程负责。
    </div>
    <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded border border-slate-200 bg-white">
      <div class="flex min-h-[40px] gap-1 overflow-x-auto border-b border-slate-200 px-3">
        <template v-if="props.scope!=='allocation'">
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
          :key="preferenceNamespace"
          :namespace="preferenceNamespace"
          :title="meta.title + ' · 新表'"
          :columns="gridColumns"
          :rows="globalRows"
          :page="{current_page:pager.page,last_page:pager.lastPage,per_page:pager.perPage,total:pager.total}"
          row-identity-key="order_id"
          :required-column-ids="['order_number','action']"
          :search="search"
          search-placeholder="搜索全库订单号 / SKU / Tracking…"
          :sort="sorting || undefined"
          :filter-columns="filterKeys"
          :filter-values="filters"
          :enable-selection="props.scope!=='allocation'"
          :selected-order-ids="selectedRows.map(row=>row.order_id)"
          :default-pinned-right="1"
          :client-csv-export="false"
          @cell-clicked="onCellClick"
          @selection-change="onGridSelection"
          @search-change="search=$event;filter()"
          @page-change="load($event)"
          @page-size-change="pager.perPage=$event as 20|50|100;filter()"
          @sort-change="onGridSort"
          @column-search-change="onGridColumnFilter"
        >
          <template #footer-left>
            <span v-if="selectedRows.length" class="text-xs text-slate-600">
              已选 {{ selectedRows.length }} 条（当前页）
            </span>
            <template v-if="selectedRows.length && authorizedReview && view==='review'">
              <select v-model="bulkAction" aria-label="批量审核动作"
                class="rounded border border-slate-300 px-2 py-1 text-xs">
                <option value="approve">审核通过</option>
                <option value="stockout">标记缺货</option>
                <option value="reject">驳回</option>
              </select>
              <button type="button" class="rounded bg-indigo-600 px-2 py-1 text-xs text-white"
                :disabled="reviewBusy" @click="applyBulkReview">批量执行</button>
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
