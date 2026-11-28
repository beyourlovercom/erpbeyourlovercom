<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue'
import type { ColDef } from 'ag-grid-community'
import GlobalGridCell from '../../Components/DataGrid/GlobalGridCell.vue'
const GlobalGrid = defineAsyncComponent(() => import('../../Components/DataGrid/GlobalGrid.vue'))
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
const InventoryMatrix = defineAsyncComponent(() => import('./InventoryMatrix.vue'))

type Row = {
  id:number
  warehouse:{slug:string;public_slug:string;name:string}
  product:{id:number;sku:string;name:string;material_full_name?:string|null}
  inbound_at:string|null
  stay_days:number|null
  location:string|null
  quantity_on_hand:number
  quantity_reserved:number
  quantity_available:number
  provider_stock_synced_at?:string|null
  provider_sync_status?:string|null
  stock_status:'out_of_stock'|'low_stock'|'in_stock'
  stock_status_label:string
  standard_qoh:number|null
  expected_restock_qty:number
  replenishment:{
    policy_status:'unconfigured'|'no_demand'|'reorder'|'healthy'
    policy_label:string
    shipped_90d_qty:number
    monthly_average:number
    daily_average:number
    days_of_cover:number|null
    safety_stock_qty:number|null
    reorder_point_qty:number|null
    recommended_restock_qty:number|null
  }
}
type W = {slug:string;public_slug:string;name:string}
const props = defineProps<{
  rows:{data:Row[];links:any[];current_page:number;last_page:number;per_page?:number;total?:number}
  warehouses:W[]
  filters:{search?:string;warehouse?:string;stock_status?:string}
  globalGridPilotAvailable?:boolean
  globalGridPilotEnabled?:boolean
  warehouseGridEnabled?:boolean
  inventoryView?:'product'|'warehouse'|null
  matrixRows:{id:number;sku:string;material_name:string;warehouses:Record<string,{on_hand:number|null;transit:number;pending:number}>}[]
}>()

const actor = Number((usePage().props as {auth?:{user?:{id?:number}}}).auth?.user?.id)
const preferenceKey = actor>0 ? 'inventory-products:view:v1:user:'+actor : null
let saved: {view?:'product'|'warehouse';warehouseFilters?:{search?:string;warehouse?:string;stock_status?:string}} = {}
try { if(preferenceKey) saved=JSON.parse(localStorage.getItem(preferenceKey)??'{}') ?? {} } catch { /* optional cache */ }
const view = ref<'product'|'warehouse'>(props.inventoryView ?? (saved.view==='warehouse'?'warehouse':'product'))
const warehouseGrid = computed(()=>props.warehouseGridEnabled ?? props.globalGridPilotEnabled ?? true)
const initialFilters = props.inventoryView==='warehouse' ? props.filters : (saved.warehouseFilters??props.filters)
const search = ref(initialFilters.search || '')
// Keep the legacy filter model coherent with in-grid server-side search.
watch(() => props.filters, value => {
  if(props.inventoryView==='warehouse') {
    search.value=value.search||''; warehouse.value=value.warehouse||''; stockStatus.value=value.stock_status||''
  }
})
const globalGridSearch = (value:string) => {
  search.value = value
  router.get('/inventory/products', {
    search:value, warehouse:warehouse.value, stock_status:stockStatus.value,
    view:'warehouse', global_grid:'1', per_page:String(props.rows.per_page??100), page:1,
  }, {preserveState:true,replace:true})
}
const warehouse = ref(initialFilters.warehouse || '')
const stockStatus = ref(initialFilters.stock_status || '')
const remember = () => {
  saved={view:view.value,warehouseFilters:{search:search.value,warehouse:warehouse.value,stock_status:stockStatus.value}}
  try { if(preferenceKey) localStorage.setItem(preferenceKey,JSON.stringify(saved)) } catch { /* optional cache */ }
}
const switchView = (target:'product'|'warehouse') => {
  if (target===view.value) return
  view.value=target
  remember()
  // Inertia visit retains the canonical path without a full-page reload.
  router.get('/inventory/products', target==='warehouse'
    ? {...currentFilters(),view:target,global_grid:warehouseGrid.value?'1':'0'} : {view:target},
    {preserveState:true,replace:true,preserveScroll:true})
}
watch(()=>props.inventoryView,next=>{ if(next) { view.value=next; remember() } })
watch([search,warehouse,stockStatus],()=>{if(view.value==='warehouse')remember()})
// Restore prior warehouse filters on a default entry; do not overwrite explicit links.
onMounted(()=>{
  if(!props.inventoryView && view.value==='warehouse' && (search.value || warehouse.value || stockStatus.value)) {
    router.get('/inventory/products',{...currentFilters(),view:'warehouse',global_grid:'1'}, {preserveState:true,replace:true})
  }
  remember()
})
const apply = () => router.get('/inventory/products', {
  search: search.value,
  warehouse: warehouse.value,
  stock_status: stockStatus.value,
  view:'warehouse', global_grid:warehouseGrid.value?'1':'0', per_page:String(props.rows.per_page??100),
}, { preserveState:true, replace:true })

const currentFilters = () => ({
  search: search.value, warehouse: warehouse.value, stock_status: stockStatus.value,
})
const toggleGlobalPilot = () => router.get('/inventory/products', {
  ...currentFilters(), view:'warehouse', global_grid: warehouseGrid.value ? '0' : '1',
}, { preserveState: true, replace: true })
const gridPage = (page:number) => router.get('/inventory/products', {
  ...currentFilters(), view:'warehouse', global_grid:'1', per_page:String(props.rows.per_page??100), page,
}, { preserveState:true,replace:true })
const gridPageSize = (size:number|'all') => {
  if(size==='all')return
  router.get('/inventory/products',{
    ...currentFilters(),view:'warehouse',global_grid:'1',per_page:String(size),page:1,
  },{preserveState:true,replace:true})
}
const syncIssueLabel = (status?:string|null):string => {
  if (status === 'read_failed_last_known') return 'API读取失败 · 历史值'
  if (status === 'read_success_apply_failed') return 'ERP同步失败 · 历史值'
  if (status === 'read_success') return 'API已读取 · 待同步'
  if (status === 'sku_unverified') return 'SKU待核验'
  if (status === 'not_read') return '尚无API核验记录'
  return ''
}
type GridRow = Record<string,unknown>
const globalRows = computed<GridRow[]>(() => props.rows.data.map(r => ({
  id:r.id,
  warehouse_name:r.warehouse.name,
  warehouse_slug:r.warehouse.public_slug || r.warehouse.slug,
  sku:r.product.sku || '—',
  product_name:r.product.name,
  material_full_name:r.product.material_full_name || '—',
  product_id:r.product.id,
  inbound_at:r.inbound_at || '—',
  stay_days:r.stay_days===null?'—':r.stay_days+' 天',
  location:r.location || '—',
  quantity_on_hand:r.quantity_on_hand,
  quantity_reserved:r.quantity_reserved,
  quantity_available:r.quantity_available,
  provider_stock_synced_at:r.provider_stock_synced_at ? new Date(r.provider_stock_synced_at).toLocaleString("zh-CN",{timeZone:"Asia/Shanghai",hour12:false}) : "—",
  stock_status_label:[r.stock_status_label,syncIssueLabel(r.provider_sync_status)].filter(Boolean).join(" · "),
  shipped_90d_qty:r.replenishment.shipped_90d_qty,
  velocity:'月 '+r.replenishment.monthly_average+' / 日 '+r.replenishment.daily_average,
  days_of_cover:r.replenishment.days_of_cover===null?'—':r.replenishment.days_of_cover+' 天',
  safety_stock_qty:r.replenishment.safety_stock_qty??'—',
  reorder_point_qty:r.replenishment.reorder_point_qty??'—',
  replenishment_label:r.replenishment.recommended_restock_qty===null?r.replenishment.policy_label
    :r.replenishment.policy_label+' · '+r.replenishment.recommended_restock_qty,
  standard_qoh:r.standard_qoh??'—',
  expected_restock_qty:r.expected_restock_qty,
})))
const inventoryColumns:ColDef<GridRow>[] = [
  {colId:'warehouse',field:'warehouse_name',headerName:'所属仓库',width:150,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'link',hrefFor:(r:GridRow)=>'/inventory/'+r.warehouse_slug}},
  {colId:'sku',field:'sku',headerName:'SKU',width:160,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'link',hrefFor:(r:GridRow)=>'/products/'+r.product_id}},
  {colId:'name',field:'product_name',headerName:'产品名称',width:230},
  {colId:'material_full_name',field:'material_full_name',headerName:'物料全称',width:340,hide:true,tooltipField:'material_full_name'},
  {colId:'inbound_at',field:'inbound_at',headerName:'上架时间',width:134},
  {colId:'stay_days',field:'stay_days',headerName:'停留时间',width:110},
  {colId:'location',field:'location',headerName:'Location',width:130},
  {colId:'quantity_on_hand',field:'quantity_on_hand',headerName:'实物库存',width:106},
  {colId:'quantity_reserved',field:'quantity_reserved',headerName:'预留',width:90},
  {colId:'quantity_available',field:'quantity_available',headerName:'可用库存',width:104},
  {colId:'provider_stock_synced_at',field:'provider_stock_synced_at',headerName:'API核验时间（北京时间）',width:190,hide:true},
  {colId:'stock_status',field:'stock_status_label',headerName:'状态',width:210,tooltipField:'stock_status_label',
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'badge'}},
  {colId:'shipped_90d_qty',field:'shipped_90d_qty',headerName:'90天发货',width:104},
  {colId:'velocity',field:'velocity',headerName:'月均 / 日均',width:165,hide:true},
  {colId:'days_of_cover',field:'days_of_cover',headerName:'可覆盖',width:106},
  {colId:'safety_stock_qty',field:'safety_stock_qty',headerName:'安全库存',width:105,hide:true},
  {colId:'reorder_point_qty',field:'reorder_point_qty',headerName:'补货点',width:105,hide:true},
  {colId:'replenishment',field:'replenishment_label',headerName:'建议补货',width:170},
  {colId:'standard_qoh',field:'standard_qoh',headerName:'Standard QoH',width:126,hide:true},
  {colId:'expected_restock_qty',field:'expected_restock_qty',headerName:'预计到货',width:105,hide:true},
]
const inventoryPager = computed(() => ({
  current_page:props.rows.current_page,last_page:props.rows.last_page,
  per_page:props.rows.per_page??100,total:props.rows.total??props.rows.data.length,
}))

const breadcrumbs = [{label:'BYL ERP',href:'/dashboard'},{label:'库存',href:'/inventory'},'产品库存']
</script>

<template>
  <Head title="产品库存 · BYL ERP"/>
  <AuthenticatedLayout title="产品库存" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="inventory-view-toolbar">
        <div class="inventory-view-switch" role="group" aria-label="库存视图">
          <button type="button" :aria-pressed="view==='product'" :class="{active:view==='product'}" @click="switchView('product')" data-testid="inventory-view-product">按产品查看</button>
          <button type="button" :aria-pressed="view==='warehouse'" :class="{active:view==='warehouse'}" @click="switchView('warehouse')" data-testid="inventory-view-warehouse">按仓库查看</button>
        </div>
        <div class="inventory-actions">
          <button v-if="view==='warehouse' && globalGridPilotAvailable" type="button" class="mvp-button" data-testid="inventory-global-toggle" @click="toggleGlobalPilot">{{warehouseGrid?'返回原列表':'新版仓库表'}}</button>
          <Link class="mvp-button" href="/inventory/list">仓库清单</Link>
        </div>
      </div>
      <InventoryMatrix v-if="view==='product'" :rows="matrixRows??[]" />
      <template v-else>
      <div class="mvp-card filters">
        <input v-if="!warehouseGrid" v-model="search" placeholder="搜索 SKU / 产品名称" @keyup.enter="apply">
        <select v-model="warehouse" @change="apply">
          <option value="">全部仓库</option>
          <option v-for="w in warehouses" :key="w.public_slug||w.slug" :value="w.public_slug||w.slug">{{w.name}}</option>
        </select>
        <select v-model="stockStatus" aria-label="库存状态" @change="apply">
          <option value="">全部库存状态</option>
          <option value="alert">库存预警（没货 + 紧张）</option>
          <option value="out_of_stock">没货</option>
          <option value="low_stock">库存紧张</option>
          <option value="in_stock">有货</option>
        </select>
        <button class="mvp-button" @click="apply">筛选</button>
      </div>
      <GlobalGrid v-if="warehouseGrid" :column-settings-version="2" :field-groups="{'商品与仓库':['warehouse','sku','name','material_full_name','location','inbound_at','stay_days'],'库存信息':['quantity_on_hand','quantity_reserved','quantity_available','stock_status','provider_stock_synced_at','standard_qoh'],'销售与补货':['shipped_90d_qty','velocity','days_of_cover','safety_stock_qty','reorder_point_qty','replenishment','expected_restock_qty']}" namespace="grid.inventory.products.global.v1"
        title="产品库存" :rows="globalRows" :columns="inventoryColumns"
        :search="filters.search ?? ''" search-placeholder="搜索全部 SKU / 产品名称..."
        :required-column-ids="['warehouse','sku']" :page="inventoryPager"
        @page-change="gridPage" @page-size-change="gridPageSize" @search-change="globalGridSearch" />
      <div v-else class="mvp-card table-wrap">
        <table>
          <thead><tr><th>所属仓库</th><th>SKU</th><th>产品</th><th>上架时间</th><th>停留时间</th><th>Location</th><th>实物库存</th><th>预留</th><th>可用库存</th><th>状态</th><th>90天发货</th><th>月均 / 日均</th><th>可覆盖</th><th>安全库存</th><th>补货点</th><th>建议补货</th><th>Standard QoH</th><th>预计到货</th><th>API核验时间（北京时间）</th></tr></thead>
          <tbody>
            <tr v-for="r in rows.data" :key="r.id">
              <td><Link :href="`/inventory/${r.warehouse.public_slug||r.warehouse.slug}`">{{r.warehouse.name}}</Link></td>
              <td><strong>{{r.product.sku||'—'}}</strong></td><td>{{r.product.name}}</td><td>{{r.inbound_at||'—'}}</td>
              <td>{{r.stay_days===null?'—':`${r.stay_days} 天`}}</td><td>{{r.location||'—'}}</td>
              <td>{{r.quantity_on_hand}}</td><td>{{r.quantity_reserved}}</td><td><strong>{{r.quantity_available}}</strong></td>
              <td><span class="stock-badge" :class="`stock-${r.stock_status}`">{{r.stock_status_label}}</span><small v-if="syncIssueLabel(r.provider_sync_status)" class="block text-amber-700">{{syncIssueLabel(r.provider_sync_status)}}</small></td>
              <td>{{r.replenishment.shipped_90d_qty}}</td><td>月 {{r.replenishment.monthly_average}} / 日 {{r.replenishment.daily_average}}</td>
              <td>{{r.replenishment.days_of_cover===null?'—':`${r.replenishment.days_of_cover} 天`}}</td><td>{{r.replenishment.safety_stock_qty??'—'}}</td>
              <td>{{r.replenishment.reorder_point_qty??'—'}}</td>
              <td><span class="replenishment-badge" :class="`replenishment-${r.replenishment.policy_status}`">{{r.replenishment.recommended_restock_qty===null?r.replenishment.policy_label:`${r.replenishment.policy_label} · ${r.replenishment.recommended_restock_qty}`}}</span></td>
              <td>{{r.standard_qoh??'—'}}</td><td>{{r.expected_restock_qty}}</td><td>{{r.provider_stock_synced_at ? new Date(r.provider_stock_synced_at).toLocaleString('zh-CN',{timeZone:'Asia/Shanghai',hour12:false}) : '—'}}</td>
            </tr>
            <tr v-if="!rows.data.length"><td colspan="19">暂无库存数据</td></tr>
          </tbody>
        </table>
      </div>
      <p class="text-xs text-slate-500">实物库存包含仓库锁定货品；可用库存与分仓口径一致。API 核验时间可通过列设置查看。</p>
      </template>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.inventory-view-toolbar{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}.inventory-view-switch{display:inline-flex;align-self:flex-start;gap:2px;background:#e8eef0;border:1px solid #d6e0e3;border-radius:7px;padding:3px;margin-bottom:0}.inventory-view-switch button{background:transparent;color:#536775;border:0;border-radius:5px;padding:8px 18px;cursor:pointer;font-weight:600}.inventory-view-switch button.active{background:#fff;color:#116b57;box-shadow:0 1px 3px #2037421a}
.inventory-actions{display:flex;gap:8px;align-items:center}.filters{display:flex;gap:10px;flex-wrap:wrap}.filters input,.filters select{padding:9px;border:1px solid #dbe2e8;border-radius:8px}.filters input{min-width:280px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;white-space:nowrap}th{font-size:12px;color:#64748b}.stock-badge{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:700}.stock-out_of_stock{background:#fee2e2;color:#991b1b}.stock-low_stock{background:#ffedd5;color:#9a3412}.stock-in_stock{background:#dcfce7;color:#166534}.replenishment-badge{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:700}.replenishment-reorder{background:#fee2e2;color:#991b1b}.replenishment-healthy{background:#dcfce7;color:#166534}.replenishment-no_demand,.replenishment-unconfigured{background:#f1f5f9;color:#475569}@media(max-width:700px){.filters>*{min-width:0!important;flex:1 1 100%}}
</style>
