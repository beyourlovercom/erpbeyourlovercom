<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { computed as gridComputed } from 'vue'
import type { ColDef } from 'ag-grid-community'
import GlobalGridTemplates from '../../Components/DataGrid/GlobalGridTemplates.vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type OverdueRow = {
  warehouse_id:number; warehouse_code:string; warehouse_name:string; product_id:number
  sku:string; canonical_sku:string; product_name:string; putaway_date:string
  quantity:number; age_days:number; overdue_days:number; lot_id:number|null
  stock_status:string; source:string; quality:string
}
type Summary = {
  overdue_units:number; overdue_products:number; overdue_warehouse_products:number
  overdue_lot_rows:number; overdue_warehouses:number
  unknown_age_units:number; lot_mismatch_skus:number; lot_mismatch_units:number
}
type Page = {
  data:OverdueRow[];current_page:number;last_page:number;total:number
  per_page?:number;from:number|null;to:number|null;prev_page_url:string|null;next_page_url:string|null
}
const props = defineProps<{
  asOf:string;cutoffDate:string;summary:Summary
  warehouses:Array<{code:string;name:string}>
  warehouseUnits:Record<string,number>
  filters:{warehouse:string;search:string}
  filteredUnits:number;filteredRows:number;rows:Page
  canManageExclusions?:boolean
  excludedProducts?:Array<{product_id:number;sku:string;name:string;reason:string;updated_at:string|null}>
  manageSearch?:string
  productMatches?:Array<{product_id:number;sku:string;name:string;is_excluded:boolean}>
}>()
const activeWarehouse=ref(props.filters.warehouse)
const search=ref(props.filters.search)
const manageOpen=ref(Boolean(props.manageSearch))
const manageSearch=ref(props.manageSearch||'')
const excludeReason=ref('')
const pendingProduct=ref<number|null>(null)
const excludeCount=computed(()=>props.excludedProducts?.length||0)
const manageQuery=()=>{
  router.get('/inventory/overdue',{
    warehouse:props.filters.warehouse,search:props.filters.search,
    manage_search:manageSearch.value
  },{preserveState:true,replace:true,preserveScroll:true})
}
const toggleExcluded=(productId:number, excluded:boolean)=>{
  if(pendingProduct.value!==null)return
  router.patch('/inventory/overdue/exclusions/'+productId,
    {excluded,reason:excluded?excludeReason.value:null},
    {preserveScroll:true,preserveState:false,
      onStart:()=>{pendingProduct.value=productId},
      onFinish:()=>{pendingProduct.value=null}})
}
const breadcrumbs=[{label:'BYL ERP',href:'/dashboard'},{label:'库存',href:'/inventory'},'超期库存']
const number=(v:number)=>new Intl.NumberFormat('zh-CN').format(v)
const filter=()=>router.get('/inventory/overdue',{warehouse:activeWarehouse.value,search:search.value},{
  preserveState:true,replace:true,
})
const clear=()=>{
  activeWarehouse.value=''
  search.value=''
  router.get('/inventory/overdue',{}, {preserveState:true,replace:true})
}
const downloadHref=computed(()=>{
  const params = new URLSearchParams()
  if(props.filters.warehouse)params.set('warehouse',props.filters.warehouse)
  if(props.filters.search)params.set('search',props.filters.search)
  return '/inventory/overdue/export'+(params.toString()?'?'+params.toString():'')
})
const origin=(row:OverdueRow)=>row.source==='dated_lot'?(row.lot_id?'批次 #'+row.lot_id:'入库批次'):'汇总入库日期'
type GridRow=Record<string,unknown>
const overdueRows=gridComputed<GridRow[]>(()=>props.rows.data.map((row,index)=>({...row,
  id:row.warehouse_id+'-'+row.product_id+'-'+(row.lot_id??'summary')+'-'+index,
  group_label:'—',owner_label:'—',origin_label:origin(row),quality_label:row.quality==='批次已核对'?'已核对':row.quality,
})))
const overdueColumns=gridComputed<ColDef<GridRow>[]>(()=>[
  {field:'warehouse_name',headerName:'仓别',width:170},{field:'group_label',headerName:'组别',width:85},
  {field:'owner_label',headerName:'责任人',width:100},{field:'putaway_date',headerName:'入库时间',width:125},
  {field:'sku',headerName:'FNSKU',width:175,pinned:'left',lockPinned:true},
  {field:'product_name',headerName:'物料名称',width:260,tooltipField:'product_name'},
  {field:'quantity',headerName:'数量',width:95},{field:'age_days',headerName:'库龄',width:100},
  {field:'overdue_days',headerName:'超期天数',width:110},{field:'origin_label',headerName:'批次 / 来源',width:145},
  {field:'quality_label',headerName:'核对状态',width:215,tooltipField:'quality'},
  ...(props.canManageExclusions?[{colId:'actions',headerName:'超期统计',width:105,pinned:'right' as const,lockPinned:true,lockVisible:true}]:[]),
])
const overduePage=gridComputed(()=>({current_page:props.rows.current_page,last_page:props.rows.last_page,per_page:props.rows.per_page??50,total:props.rows.total}))
const overdueGroups={'批次与商品':['warehouse_name','group_label','owner_label','putaway_date','sku','product_name'],
  '超期信息':['quantity','age_days','overdue_days','origin_label','quality_label'],'操作':['actions']}
const goToPage=(page:number)=>{
  const url=page===props.rows.current_page-1?props.rows.prev_page_url:page===props.rows.current_page+1?props.rows.next_page_url:null
  if(url)router.get(url,{}, {preserveState:true})
}
</script>

<template>
<Head title="超期库存 · BYL ERP"/>
<AuthenticatedLayout title="超期库存" :breadcrumbs="breadcrumbs">
<section class="mvp-page overdue-page">
  <header class="mvp-page-heading">
    <div>
      <p class="mvp-kicker">OVERSEAS WAREHOUSE · AGED STOCK</p>
      <h2>超期库存</h2>
      <p>以 ERP 当前在库数量和真实入库日期为准；入库满 90 个自然日即入选，统计截至 {{asOf}}。</p>
    </div>
    <div class="mvp-actions">
      <Link class="mvp-button" href="/inventory">返回库存概览</Link>
      <button v-if="canManageExclusions" class="mvp-button" type="button" @click="manageOpen=!manageOpen" data-testid="overdue-manage-exclusions">屏蔽设置 ({{excludeCount}})</button>
      <a class="mvp-button mvp-button-primary" :href="downloadHref" data-testid="overdue-export">导出 Excel</a>
    </div>
  </header>

  <div class="overdue-kpis">
    <article class="mvp-card"><small>超期库存</small><strong>{{number(summary.overdue_units)}} <em>件</em></strong><span>入库日期 ≤ {{cutoffDate}}</span></article>
    <article class="mvp-card"><small>不同商品 SKU</small><strong>{{number(summary.overdue_products)}}</strong><span>{{summary.overdue_warehouse_products}} 个仓库商品组合</span></article>
    <article class="mvp-card"><small>超期批次</small><strong>{{number(summary.overdue_lot_rows)}}</strong><span>{{summary.overdue_warehouses}} 个海外仓有超期库存</span></article>
    <article class="mvp-card"><small>待核对</small><strong>{{number(summary.unknown_age_units)}} <em>件</em></strong><span>缺乏可确认日期；另有 {{summary.lot_mismatch_skus}} 个批次账差异</span></article>
  </div>

  <section v-if="canManageExclusions&&manageOpen" class="mvp-card overdue-exclusions" aria-label="超期商品屏蔽设置">
    <div class="overdue-exclusions-heading"><strong>屏蔽商品管理</strong><span>仅影响超期库存统计、网页清单与 Excel，不影响仓库库存和 P9 费用</span></div>
    <form class="overdue-filter" @submit.prevent="manageQuery">
      <input v-model.trim="manageSearch" aria-label="查找需要屏蔽的SKU" placeholder="搜索海外仓 SKU 或商品名称"/>
      <input v-model.trim="excludeReason" aria-label="屏蔽原因" placeholder="原因（可选）：润滑油 / Logo / 礼品盒"/>
      <button type="submit" class="mvp-button mvp-button-primary">查找商品</button>
    </form>
    <div v-if="productMatches?.length" class="overdue-manage-results">
      <strong class="overdue-subheading">匹配商品（最多 30 个）</strong>
      <div v-for="product in productMatches" :key="product.product_id" class="overdue-manage-item">
        <span><strong>{{product.sku}}</strong> · {{product.name}}</span>
        <label class="overdue-switch"><input type="checkbox" :checked="product.is_excluded" :disabled="pendingProduct!==null" :aria-label="'屏蔽 '+product.sku" @change="toggleExcluded(product.product_id,!product.is_excluded)"/><span>{{product.is_excluded?'已屏蔽':'参与统计'}}</span></label>
      </div>
    </div>
    <div class="overdue-manage-results">
      <strong class="overdue-subheading">已屏蔽商品 · {{excludeCount}}</strong>
      <p v-if="!excludedProducts?.length" class="overdue-small">暂无屏蔽商品；可以在下方超期清单中直接关闭某个商品，或在上方搜索 SKU。</p>
      <div v-for="product in excludedProducts" :key="'excluded-'+product.product_id" class="overdue-manage-item">
        <span><strong>{{product.sku}}</strong> · {{product.name}}<small v-if="product.reason">{{product.reason}}</small></span>
        <label class="overdue-switch"><input type="checkbox" checked :disabled="pendingProduct!==null" :aria-label="'恢复 '+product.sku" @change="toggleExcluded(product.product_id,false)"/><span>已屏蔽</span></label>
      </div>
    </div>
  </section>
  <section class="mvp-card overdue-workspace">
    <div class="overdue-toolbar">
      <div class="toolbar-intro"><h3>超期库存明细</h3><span>按实际入库批次逐行显示，重复 SKU 不合并</span></div>
      <form class="overdue-filter" @submit.prevent="filter">
        <select v-model="activeWarehouse" aria-label="筛选海外仓">
          <option value="">全部海外仓</option>
          <option v-for="wh in warehouses" :key="wh.code" :value="wh.code">{{wh.name}}</option>
        </select>
        <input v-model.trim="search" placeholder="搜索 SKU / 商品名称" aria-label="搜索超期库存"/>
        <button class="mvp-button mvp-button-primary" type="submit">查询</button>
        <button class="mvp-button" type="button" @click="clear">重置</button>
      </form>
    </div>
    <div class="overdue-result-meta">
      当前筛选：<strong>{{number(filteredRows)}} 条批次 / {{number(filteredUnits)}} 件</strong>
      <span>导出内容遵循当前筛选条件，不受页面分页限制</span>
    </div>
    <GlobalGridTemplates namespace="grid.inventory.overdue.v2" title="超期库存明细"
      :columns="overdueColumns" :rows="overdueRows" :field-groups="overdueGroups"
      :required-column-ids="canManageExclusions?['sku','actions']:['sku']" :page="overduePage"
      :show-search="false" server-page-size-locked :default-pinned-right="canManageExclusions?1:0" cell-text-selection
      @page-change="goToPage">
      <template #cell-warehouse_name="{row}"><div><strong>{{row.warehouse_name}}</strong><small class="overdue-grid-small">{{row.warehouse_code}}</small></div></template>
      <template #cell-sku="{row}"><div><strong>{{row.sku}}</strong><small v-if="row.canonical_sku!==row.sku" class="overdue-grid-small">ERP: {{row.canonical_sku}}</small></div></template>
      <template #cell-quantity="{row}"><strong>{{number(row.quantity)}}</strong></template>
      <template #cell-age_days="{row}">{{row.age_days}} 天</template>
      <template #cell-overdue_days="{row}">{{row.overdue_days}} 天</template>
      <template #cell-quality_label="{row}"><div><span :class="['quality-pill',row.quality==='批次已核对'?'verified':'review']">{{row.quality==='批次已核对'?'已核对':'待复核'}}</span><small v-if="row.quality!=='批次已核对'" class="overdue-grid-small" :title="row.quality">{{row.quality}}</small></div></template>
      <template #cell-actions="{row}"><label v-if="canManageExclusions" class="overdue-switch"><input type="checkbox" :disabled="pendingProduct!==null" :aria-label="'屏蔽 '+row.sku" @change="toggleExcluded(row.product_id,true)"/><span>参与</span></label></template>
    </GlobalGridTemplates>
    <p v-if="!rows.data.length" class="empty-row">当前筛选没有满 90 天的在库批次。</p>
  </section>
  <p class="overdue-footnote">说明：满 90 天按入库日期计算，等于 90 天当天即入选；此报表仅供库存查询，不自动修改库存，也不直接改变 P9 仓储费用。缺日期的库存不推测入库时间；批次账差异将标记待复核。</p>
</section>
</AuthenticatedLayout>
</template>

<style scoped>
.overdue-grid-small{display:block;font-size:10px;color:#82909b;overflow:hidden;text-overflow:ellipsis;line-height:1.1;max-width:200px}
.overdue-page{display:flex;flex-direction:column;gap:17px}
.overdue-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.overdue-kpis .mvp-card{display:flex;flex-direction:column;gap:6px;padding:18px}
.overdue-kpis small{font-size:12px;color:#64748b}
.overdue-kpis strong{font-size:27px;font-weight:800;line-height:1.2;color:#172c39}
.overdue-kpis em{font-style:normal;font-size:13px;font-weight:500;color:#64748b}
.overdue-kpis span{font-size:11px;color:#64748b}
.overdue-exclusions{padding:14px 18px;display:flex;flex-direction:column;gap:11px}
.overdue-exclusions-heading{display:flex;align-items:baseline;gap:14px;flex-wrap:wrap}
.overdue-exclusions-heading strong{font-size:14px}
.overdue-exclusions-heading span,.overdue-small{font-size:11px;color:#64748b}
.overdue-manage-results{display:flex;flex-direction:column;gap:0}
.overdue-subheading{font-size:12px;padding:5px 0;color:#566674}
.overdue-manage-item{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:7px 0;border-bottom:1px solid #edf0f3;font-size:12px}
.overdue-manage-item>span{min-width:0;overflow-wrap:anywhere}
.overdue-manage-item small{display:block;color:#7a8693;margin-top:3px}
.overdue-switch{display:inline-flex;align-items:center;gap:6px;white-space:nowrap;cursor:pointer;font-size:11px;color:#64748b}
.overdue-switch input{accent-color:#ad7b39;cursor:pointer;width:15px;height:15px}
.overdue-switch input:disabled{opacity:.4;cursor:wait}
.overdue-workspace{padding:0;overflow:hidden}
.overdue-toolbar{display:flex;justify-content:space-between;gap:15px;align-items:center;padding:16px 18px}
.toolbar-intro{display:flex;flex-direction:column;gap:4px}
.toolbar-intro h3{font-size:16px;margin:0;color:#152b38}
.toolbar-intro span{font-size:12px;color:#75818d}
.overdue-filter{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.overdue-filter select,.overdue-filter input{border:1px solid #dce3e8;border-radius:7px;background:#fff;padding:8px 10px;font-size:13px;max-width:240px}
.overdue-result-meta{padding:8px 18px;display:flex;gap:16px;align-items:center;border-top:1px solid #e5eaf0;background:#f8fafc;font-size:12px;color:#64748b}
.overdue-result-meta strong{color:#273b46}
.overdue-result-meta span{margin-left:auto}
.overdue-table-shell{overflow:auto;max-width:100%}
.overdue-table{width:100%;min-width:1100px;border-collapse:collapse;font-size:12px}
.overdue-table th{padding:12px 10px;white-space:nowrap;text-align:left;background:#f7f9fb;color:#5b6874;border-bottom:1px solid #e5eaf0;font-weight:700}
.overdue-table td{padding:11px 10px;border-bottom:1px solid #eef0f3;color:#26343e;vertical-align:middle}
.overdue-table tbody tr:hover{background:#fbfcfd}
.overdue-table td strong{font-weight:700}.overdue-table td small{display:block;margin-top:3px;font-size:10px;color:#82909b}
.number-col{text-align:right!important;white-space:nowrap}
.name-cell{min-width:235px;max-width:390px;overflow-wrap:anywhere}
.empty-cell{color:#b2bbc4!important}
.quality-pill{display:inline-block;font-size:10px;border-radius:20px;padding:3px 7px;font-weight:600;white-space:nowrap}
.quality-pill.verified{background:#eaf6ef;color:#237849}
.quality-pill.review{background:#fff4df;color:#96631a}
.empty-row{text-align:center!important;padding:35px!important;color:#667786!important}
.overdue-pagination{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;font-size:12px;color:#64748b}
.overdue-pagination>div{display:flex;gap:7px}.disabled{opacity:.45;pointer-events:none}
.overdue-footnote{margin:0;font-size:12px;color:#64748b;line-height:1.7}
@media(max-width:1050px){.overdue-kpis{grid-template-columns:repeat(2,1fr)}.overdue-toolbar{align-items:stretch;flex-direction:column}.overdue-result-meta{flex-wrap:wrap}.overdue-result-meta span{margin-left:0}}
@media(max-width:580px){.overdue-kpis{grid-template-columns:1fr 1fr}.overdue-kpis strong{font-size:21px}.overdue-filter input{max-width:160px}.overdue-filter .mvp-button{padding:8px}}
</style>
