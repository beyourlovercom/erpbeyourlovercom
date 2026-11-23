<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { AllCommunityModule, ModuleRegistry, themeQuartz, type CellClickedEvent, type ColDef } from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { canonicalDevIdentity } from '#/router/erp-live-navigation';
import { getLogisticsPage, submitOriginalErpReview, type DomesticView, type DomesticReviewAction,
  type LogisticsRow, type LogisticsScope, type OverseasView, type OverseasReviewAction } from '#/api/erp-logistics';

defineOptions({ name: 'ERP2LogisticsProjection' });
const props = defineProps<{ scope: LogisticsScope }>();
ModuleRegistry.registerModules([AllCommunityModule]);
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
  {value:'us-sm',label:'美国 3SM'}, {value:'us-moreplus',label:'美国 MorePlus'},
  {value:'de-tianyun',label:'德国天运'}, {value:'ca',label:'加拿大'},
];
const search = ref(''),warehouse = ref(''),view = ref<DomesticView|OverseasView>('review');
const loading = ref(false),error = ref(''),rows = ref<LogisticsRow[]>([]);
const pager = ref({page:1,perPage:20 as 20|50|100,total:0,lastPage:1});
const detail = ref<LogisticsRow|null>(null);
const capabilities = ref({canReview:false,canOperate:false});
const reviewBusy=ref(false),reviewError=ref(''),reviewSuccess=ref(''),reviewReason=ref('');
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
const reviewActions=computed<DomesticReviewAction[]>(()=>{
  if(!authorizedReview.value || !detail.value)return [];
  if(view.value==='review' && detail.value.review_status==='pending')
    return ['approve','stockout','reject'];
  if(view.value==='stockout' && detail.value.review_status==='stockout')
    return props.scope==='domestic'?['restock','return_allocation']:['restock'];
  return [];
});
let sequence = 0;
const theme = themeQuartz.withParams({fontSize:12,spacing:4,headerBackgroundColor:'#f8fafc',
  borderColor:'#e2e8f0',accentColor:'#5a50dd',backgroundColor:'#fff'});

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
const visibility = ref<Record<string,boolean>>({});
const columnsOpen = ref(false);
const preferenceKey = computed(() => 'erp2.grid.logistics.v1.'+canonicalDevIdentity.value?.user.id+'.'+props.scope);
function readPreference(){
  visibility.value={};
  if(!canonicalDevIdentity.value)return;
  try{
    const stored=JSON.parse(localStorage.getItem(preferenceKey.value)||'{}');
    if(stored && typeof stored==='object' && !Array.isArray(stored))
      visibility.value=Object.fromEntries(Object.entries(stored).filter(([,v])=>typeof v==='boolean')) as Record<string,boolean>;
  }catch{visibility.value={};}
}
function toggleColumn(key:string,checked:boolean){
  visibility.value={...visibility.value,[key]:checked};
  if(canonicalDevIdentity.value)localStorage.setItem(preferenceKey.value,JSON.stringify(visibility.value));
}
const gridColumns = computed<ColDef<LogisticsRow>[]>(() => [
 ...availableCols.value.filter(c=>visibility.value[c.key]!==false).map(c=>({
  field:c.key,headerName:c.label,width:c.width,resizable:true,sortable:false,
  pinned:c.key==='order_number'?'left' as const:undefined,
  valueFormatter:(p:{value:unknown})=>p.value==null||p.value===''?'—':String(p.value),
 })),
 {colId:'action',headerName:'操作',width:100,pinned:'right' as const,
  sortable:false,resizable:false,valueGetter:()=> '查看详情'},
]);

function clearData(){
  ++sequence; rows.value=[];detail.value=null;
  capabilities.value={canReview:false,canOperate:false};reviewError.value='';
  pager.value={page:1,perPage:pager.value.perPage,total:0,lastPage:1};
}
async function load(page=1){
  const ticket=++sequence;error.value='';rows.value=[];detail.value=null;
  if(import.meta.env.VITE_ERP_IDENTITY_MODE!=='laravel' || !canonicalDevIdentity.value){
    error.value='ERP2 物流只允许通过 Laravel 原始 Dev Session 读取。';return;
  }
  if(!allowed.value){error.value='当前账号无 logistics.view 权限，已阻止访问。';return;}
  loading.value=true;
  try{
    const result=await getLogisticsPage({scope:props.scope,page,perPage:pager.value.perPage,
      search:search.value,view:view.value,warehouse:warehouse.value});
    if(ticket!==sequence || !allowed.value)return;
    rows.value=result.rows;
    capabilities.value={canReview:result.canReview===true,canOperate:result.canOperate===true};
    pager.value={page:result.page,perPage:result.perPage as 20|50|100,
      total:result.total,lastPage:result.lastPage};
  }catch(reason){
    if(ticket!==sequence)return;
    rows.value=[];capabilities.value={canReview:false,canOperate:false};
    pager.value={...pager.value,page:1,total:0,lastPage:1};
    error.value=reason instanceof Error?reason.message:'隔离 Dev 物流读取失败';
  }finally{if(ticket===sequence)loading.value=false;}
}
function filter(){void load(1);}
function switchView(next:DomesticView|OverseasView){
  view.value=next;reviewSuccess.value='';void load(1);
}
function onCellClick(event:CellClickedEvent<LogisticsRow>){
  if(event.column.getColId()==='action' && event.data && !loading.value){
    detail.value=event.data;reviewError.value='';reviewReason.value='';
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
watch(() => [props.scope,canonicalDevIdentity.value?.user.id,
  canonicalDevIdentity.value?.effectivePermissionKeys.join('|')],()=>{
  clearData();search.value='';warehouse.value='';view.value='review';
  columnsOpen.value=false;readPreference();void load(1);
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
          {{ reviewEnabled && props.scope!=='allocation'?'隔离 Dev · 原 Laravel 审核':'隔离 Dev · 只读' }}</span>
      </div>
    </header>

    <div v-if="!reviewEnabled && props.scope!=='allocation'"
      class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
      原 Laravel 审核按钮尚未在此隔离构建启用；发货与 Tracking 上传仍由原 ERP 流程负责。
    </div>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
      <div class="rounded border border-slate-200 bg-white px-4 py-3">
        <p class="text-xs text-slate-500">当前队列订单 / 物流单</p>
        <strong class="mt-1 block text-2xl">{{ pager.total }}</strong>
      </div>
      <div class="rounded border border-slate-200 bg-white px-4 py-3">
        <p class="text-xs text-slate-500">总页数</p><strong class="mt-1 block text-2xl">{{ pager.lastPage }}</strong>
      </div>
      <div class="rounded border border-slate-200 bg-white px-4 py-3">
        <p class="text-xs text-slate-500">数据链路</p>
        <strong class="mt-2 block text-sm text-emerald-700">Laravel Dev · 已鉴权</strong>
      </div>
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
      <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-3">
        <input v-model="search" class="min-w-[200px] max-w-full rounded border border-slate-300 px-3 py-2 text-xs"
          aria-label="物流搜索" placeholder="搜索订单号 / SKU" :disabled="loading"
          @keydown.enter="filter" />
        <select v-if="props.scope==='overseas'" v-model="warehouse"
          aria-label="海外仓筛选" class="rounded border border-slate-300 px-2 py-2 text-xs"
          :disabled="loading" @change="filter">
          <option v-for="w in warehouses" :key="w.value" :value="w.value">{{ w.label }}</option>
        </select>
        <button type="button" class="rounded border border-indigo-500 bg-indigo-600 px-3 py-2 text-xs text-white"
          :disabled="loading || !allowed" @click="filter">查询</button>
        <div class="flex-1"></div>
        <div class="relative">
          <button type="button" class="rounded border border-slate-300 px-3 py-2 text-xs"
            aria-label="列设置" @click="columnsOpen=!columnsOpen">☷ 列设置</button>

          <div v-if="columnsOpen" class="absolute right-0 top-full z-20 mt-1 w-48 rounded border border-slate-200 bg-white p-3 shadow-xl">
            <p class="mb-2 text-xs font-semibold">显示列 · 按账号独立保存</p>
            <label v-for="col in availableCols" :key="col.key" class="mb-2 flex items-center gap-2 text-xs">
              <input type="checkbox" :checked="visibility[col.key]!==false"
                @change="toggleColumn(col.key,($event.target as HTMLInputElement).checked)" />
              {{ col.label }}
            </label>
          </div>
        </div>
      </div>
      <p v-if="reviewSuccess" role="status"
        class="mx-3 mt-2 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700">
        {{ reviewSuccess }}</p>
      <p v-if="error" role="alert" class="m-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
        {{ error }}</p>
      <div class="relative min-h-[350px] flex-1 overflow-hidden" :aria-busy="loading">
        <div v-if="loading" role="status"
          class="absolute inset-0 z-10 flex items-center justify-center bg-white/75 text-sm text-slate-500">
          正在读取隔离 Dev API…</div>
        <AgGridVue class="h-full w-full" :theme="theme" :row-data="rows" :column-defs="gridColumns"
          :row-height="36" :header-height="38"
          :default-col-def="{sortable:false,resizable:true}"
          :suppress-cell-focus="true" @cell-clicked="onCellClick" />
      </div>
      <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-3 py-3 text-xs text-slate-500">
        <span>共 {{ pager.total }} 条 · 当前 {{ rows.length }} 条 · 第 {{ pager.page }} / {{ pager.lastPage }} 页</span>
        <div class="flex items-center gap-3">
          <select :value="pager.perPage" aria-label="每页条数"
            class="rounded border border-slate-300 px-2 py-1"
            @change="pager.perPage=Number(($event.target as HTMLSelectElement).value) as 20|50|100;filter()">
            <option :value="20">20 / 页</option><option :value="50">50 / 页</option><option :value="100">100 / 页</option>
          </select>
          <button type="button" :disabled="pager.page<=1||loading" @click="load(pager.page-1)">上一页</button>
          <button type="button" :disabled="pager.page>=pager.lastPage||loading" @click="load(pager.page+1)">下一页</button>
        </div>
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
        <p v-if="view==='pending' && capabilities.canOperate" class="mt-3 rounded bg-amber-50 p-3 text-xs text-amber-800">
          发货信息已读取。确认发货需真实物理出库、有效物流商/服务与 Tracking；
          ERP2 发货写入将在独立隔离验证后开放。
        </p>
        <p class="mt-4 text-xs text-slate-500">受 Laravel 会话权限及当前订单状态约束；不能操作的状态不显示审核按钮。</p>
      </aside>
    </div>
  </div>
</template>
