<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, defineAsyncComponent, ref, watch } from 'vue'
import type { ColDef } from 'ag-grid-community'
import GlobalGridCell from '../../Components/DataGrid/GlobalGridCell.vue'
const GlobalGrid = defineAsyncComponent(() => import('../../Components/DataGrid/GlobalGrid.vue'))
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Row = {
  id:number
  warehouse:{slug:string;public_slug:string;name:string}
  product:{id:number;sku:string;name:string}
  inbound_at:string|null
  stay_days:number|null
  location:string|null
  quantity_on_hand:number
  quantity_reserved:number
  quantity_available:number
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
}>()

const search = ref(props.filters.search || '')
// Keep the legacy filter model coherent with in-grid server-side search.
watch(() => props.filters.search, value => { search.value = value || '' })
const globalGridSearch = (value:string) => {
  search.value = value
  router.get('/inventory/products', {
    search:value, warehouse:warehouse.value, stock_status:stockStatus.value,
    global_grid:'1', per_page:String(props.rows.per_page??100), page:1,
  }, {preserveState:true,replace:true})
}
const warehouse = ref(props.filters.warehouse || '')
const stockStatus = ref(props.filters.stock_status || '')
const apply = () => router.get('/inventory/products', {
  search: search.value,
  warehouse: warehouse.value,
  stock_status: stockStatus.value,
  ...(props.globalGridPilotEnabled ? {global_grid:'1', per_page: String(props.rows.per_page??100)} : {}),
}, { preserveState:true, replace:true })

const currentFilters = () => ({
  search: search.value, warehouse: warehouse.value, stock_status: stockStatus.value,
})
const toggleGlobalPilot = () => router.get('/inventory/products', {
  ...currentFilters(), global_grid: props.globalGridPilotEnabled ? '0' : '1',
}, { preserveState: true, replace: true })
const gridPage = (page:number) => router.get('/inventory/products', {
  ...currentFilters(), global_grid:'1', per_page:String(props.rows.per_page??100), page,
}, { preserveState:true,replace:true })
const gridPageSize = (size:number|'all') => {
  if(size==='all')return
  router.get('/inventory/products',{
    ...currentFilters(),global_grid:'1',per_page:String(size),page:1,
  },{preserveState:true,replace:true})
}
type GridRow = Record<string,unknown>
const globalRows = computed<GridRow[]>(() => props.rows.data.map(r => ({
  id:r.id,
  warehouse_name:r.warehouse.name,
  warehouse_slug:r.warehouse.public_slug || r.warehouse.slug,
  sku:r.product.sku || '—',
  product_name:r.product.name,
  product_id:r.product.id,
  inbound_at:r.inbound_at || '—',
  stay_days:r.stay_days===null?'—':r.stay_days+' 天',
  location:r.location || '—',
  quantity_on_hand:r.quantity_on_hand,
  quantity_reserved:r.quantity_reserved,
  quantity_available:r.quantity_available,
  stock_status_label:r.stock_status_label,
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
  {colId:'inbound_at',field:'inbound_at',headerName:'上架时间',width:134},
  {colId:'stay_days',field:'stay_days',headerName:'停留时间',width:110},
  {colId:'location',field:'location',headerName:'Location',width:130},
  {colId:'quantity_on_hand',field:'quantity_on_hand',headerName:'当前库存',width:106},
  {colId:'quantity_reserved',field:'quantity_reserved',headerName:'预留',width:90},
  {colId:'quantity_available',field:'quantity_available',headerName:'可用库存',width:104},
  {colId:'stock_status',field:'stock_status_label',headerName:'状态',width:115,
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
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">产品 × 仓库</p><h2>产品对应仓库清单</h2><p>统一统计产品在各仓库的库存、上架时间与停留时间。</p></div>
        <div class="inventory-actions">
          <button v-if="globalGridPilotAvailable" type="button" class="mvp-button" data-testid="inventory-global-toggle" @click="toggleGlobalPilot">{{globalGridPilotEnabled?'返回原列表':'Dev · Global Grid'}}</button>
          <Link class="mvp-button" href="/inventory/list">仓库清单</Link>
        </div>
      </div>
      <div class="mvp-card filters">
        <input v-if="!globalGridPilotEnabled" v-model="search" placeholder="搜索 SKU / 产品名称" @keyup.enter="apply">
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
      <GlobalGrid v-if="globalGridPilotEnabled" namespace="grid.inventory.products.global.v1"
        title="产品库存 · Global Grid V1" :rows="globalRows" :columns="inventoryColumns"
        :search="filters.search ?? ''" search-placeholder="搜索全部 SKU / 产品名称..."
        :required-column-ids="['warehouse','sku']" :page="inventoryPager"
        @page-change="gridPage" @page-size-change="gridPageSize" @search-change="globalGridSearch" />
      <div v-else class="mvp-card table-wrap">
        <table>
          <thead><tr><th>所属仓库</th><th>SKU</th><th>产品</th><th>上架时间</th><th>停留时间</th><th>Location</th><th>当前库存</th><th>预留</th><th>可用库存</th><th>状态</th><th>90天发货</th><th>月均 / 日均</th><th>可覆盖</th><th>安全库存</th><th>补货点</th><th>建议补货</th><th>Standard QoH</th><th>预计到货</th></tr></thead>
          <tbody>
            <tr v-for="r in rows.data" :key="r.id">
              <td><Link :href="`/inventory/${r.warehouse.public_slug||r.warehouse.slug}`">{{r.warehouse.name}}</Link></td>
              <td><strong>{{r.product.sku||'—'}}</strong></td><td>{{r.product.name}}</td><td>{{r.inbound_at||'—'}}</td>
              <td>{{r.stay_days===null?'—':`${r.stay_days} 天`}}</td><td>{{r.location||'—'}}</td>
              <td>{{r.quantity_on_hand}}</td><td>{{r.quantity_reserved}}</td><td><strong>{{r.quantity_available}}</strong></td>
              <td><span class="stock-badge" :class="`stock-${r.stock_status}`">{{r.stock_status_label}}</span></td>
              <td>{{r.replenishment.shipped_90d_qty}}</td><td>月 {{r.replenishment.monthly_average}} / 日 {{r.replenishment.daily_average}}</td>
              <td>{{r.replenishment.days_of_cover===null?'—':`${r.replenishment.days_of_cover} 天`}}</td><td>{{r.replenishment.safety_stock_qty??'—'}}</td>
              <td>{{r.replenishment.reorder_point_qty??'—'}}</td>
              <td><span class="replenishment-badge" :class="`replenishment-${r.replenishment.policy_status}`">{{r.replenishment.recommended_restock_qty===null?r.replenishment.policy_label:`${r.replenishment.policy_label} · ${r.replenishment.recommended_restock_qty}`}}</span></td>
              <td>{{r.standard_qoh??'—'}}</td><td>{{r.expected_restock_qty}}</td>
            </tr>
            <tr v-if="!rows.data.length"><td colspan="18">暂无库存数据</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.inventory-actions{display:flex;gap:8px;align-items:center}.filters{display:flex;gap:10px;flex-wrap:wrap}.filters input,.filters select{padding:9px;border:1px solid #dbe2e8;border-radius:8px}.filters input{min-width:280px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;white-space:nowrap}th{font-size:12px;color:#64748b}.stock-badge{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:700}.stock-out_of_stock{background:#fee2e2;color:#991b1b}.stock-low_stock{background:#ffedd5;color:#9a3412}.stock-in_stock{background:#dcfce7;color:#166534}.replenishment-badge{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:700}.replenishment-reorder{background:#fee2e2;color:#991b1b}.replenishment-healthy{background:#dcfce7;color:#166534}.replenishment-no_demand,.replenishment-unconfigured{background:#f1f5f9;color:#475569}@media(max-width:700px){.filters>*{min-width:0!important;flex:1 1 100%}}
</style>
