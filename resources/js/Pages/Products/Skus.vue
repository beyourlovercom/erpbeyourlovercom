<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, defineAsyncComponent, ref } from 'vue'
import type { ColDef } from 'ag-grid-community'
import GlobalGridCell from '../../Components/DataGrid/GlobalGridCell.vue'
import SkuMappingGridCell from './SkuMappingGridCell.vue'
const GlobalGrid = defineAsyncComponent(() => import('../../Components/DataGrid/GlobalGrid.vue'))
import axios from 'axios'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import { publishGlobalFeedback } from '../../lib/globalFeedback'

type Material = {id:number;material_code:string;material_name:string;factory_stock_approved?:boolean;factory_physical_qty?:number|null}
type Mapping = {warehouse_id:number|null;sku:string|null;status:string}
type ProductSku = {id:number;sku:string|null;is_active:boolean;material_name:string|null;image_url:string|null;material_versions:Material[];website_skus:string[];mappings:Record<string,Mapping>;alias_count:number}
type Paginated<T> = {data:T[];current_page:number;last_page:number;total:number;per_page:number;links:{url:string|null;label:string;active:boolean}[]}
const props = defineProps<{products:Paginated<ProductSku>;filters:{search?:string;missing?:string;per_page?:number|string;sort?:string;direction?:string};unmappedMaterialCount:number;unmappedMaterials:Material[];productOptions:{id:number;sku:string}[];canOperate:boolean;canApproveStock:boolean}>()
const search = ref(props.filters.search ?? '')
const missing=ref(props.filters.missing??'')
const batchFile=ref<File|null>(null)
const batchOpen=ref(false)
const exportUrl=()=>'/products/skus/export?'+new URLSearchParams({search:search.value,missing:missing.value,sort:props.filters.sort??'sku',direction:props.filters.direction??'asc'}).toString()
const warehouses = [{key:'FACTORY',label:'中国工厂'},{key:'DE',label:'德国仓'},{key:'CA',label:'加拿大仓'},{key:'US-LA',label:'美西 MorePlus'},{key:'US-NY',label:'美东'},{key:'US-SM',label:'美世迈仓'}]
const go=(extra:Record<string,string|number>={})=>router.get('/products/skus',{
  search:search.value,missing:missing.value,per_page:props.products.per_page??50,sort:props.filters.sort??'sku',direction:props.filters.direction??'asc',...extra,
},{preserveState:true,replace:true,preserveScroll:true})
const applySearch=()=>go({page:1})
const gridSearch=(value:string)=>{search.value=value;applySearch()}
const gridSort=(sort:{colId:string;direction:'asc'|'desc'|null})=>go({sort:sort.direction?sort.colId:'sku',direction:sort.direction??'asc',page:1})
const gridPager=computed(()=>({current_page:props.products.current_page,last_page:props.products.last_page,total:props.products.total,per_page:props.products.per_page??50}))
const gridSortState=computed(()=>({colId:props.filters.sort==='alias_count'?'alias_count':'sku',direction:props.filters.direction==='desc'?'desc' as const:'asc' as const}))
const activeView=ref<'matrix'|'unmapped'>('matrix')
const materialDetail=ref<ProductSku|null>(null)
const materialEdit = ref<{productId:number|null;material_code:string;material_name:string;binding_evidence:string;factory_stock_approved:boolean;same_physical_product_confirmed:boolean}|null>(null)
const warehouseEdit=ref<{productId:number;warehouseId:number;label:string;sku:string;verification_evidence:string;same_physical_product_confirmed:boolean}|null>(null)
const busy=ref(false)
const imagePreview=ref<string|null>(null)
async function autoMatch() {
  if (busy.value) return
  busy.value=true
  try {
    const {data}=await axios.post('/products/skus/auto-match')
    publishGlobalFeedback({state:'SUCCESS',title:'物料自动匹配完成',message:'新增关联 '+data.matched+' 条；剩余 '+data.remaining+' 条；冲突 '+data.conflicts+' 条。库存数量未改变。'})
    router.reload()
  } catch(e:any) {
    publishGlobalFeedback({state:'BLOCKED',title:'自动匹配失败',message:e?.response?.data?.message??'请检查权限。'})
  } finally {busy.value=false}
}
async function saveBatch() {
  if (!batchFile.value || busy.value) return
  busy.value=true
  try {
    const fd=new FormData();fd.append('file',batchFile.value)
    const {data}=await axios.post('/products/skus/batch',fd)
    publishGlobalFeedback({state:'SUCCESS',title:'批量映射已保存',message:'已维护 '+data.updated+' 条'})
    batchOpen.value=false;batchFile.value=null;router.reload()
  } catch(e:any) {
    publishGlobalFeedback({state:'BLOCKED',title:'批量维护未保存',message:e?.response?.data?.message??'请检查模板及核实证据。'})
  } finally {busy.value=false}
}
function editMaterial(productId:number|null,material?:Material) {
  materialEdit.value={productId,material_code:material?.material_code??'',material_name:material?.material_name??'',binding_evidence:'',factory_stock_approved:!!material?.factory_stock_approved,same_physical_product_confirmed:false}
}
function editWarehouse(p:ProductSku,w:{key:string;label:string}) {
  const mapping=p.mappings[w.key]
  if (!mapping?.warehouse_id) return
  warehouseEdit.value={productId:p.id,warehouseId:mapping.warehouse_id,label:w.label,sku:mapping.sku??'',verification_evidence:'',same_physical_product_confirmed:false}
}
async function saveMaterial() {
  const m=materialEdit.value
  if (!m?.productId || busy.value) return
  busy.value=true
  try {
    await axios.put('/products/'+m.productId+'/material-versions',m)
    publishGlobalFeedback({state:'SUCCESS',title:'物料映射已保存',message:'已核实的库存范围将在下次有效快照同步。'})
    materialEdit.value=null
    router.reload()
  } catch(e:any) {
    publishGlobalFeedback({state:'BLOCKED',title:'物料映射未保存',message:e?.response?.data?.message??'请检查权限和物料身份。'})
  } finally {busy.value=false}
}
function saveWarehouse() {
  const m=warehouseEdit.value
  if(!m || busy.value) return
  busy.value=true
  router.put('/products/'+m.productId+'/warehouse-skus/'+m.warehouseId,
    {sku:m.sku,verification_evidence:m.verification_evidence,same_physical_product_confirmed:m.same_physical_product_confirmed},
    {preserveScroll:true,onSuccess:()=>{warehouseEdit.value=null;publishGlobalFeedback({state:'SUCCESS',title:'仓库 SKU 已核实',message:''})},
     onError:(errors)=>publishGlobalFeedback({state:'BLOCKED',title:'映射未保存',message:Object.values(errors).join('；')}),
     onFinish:()=>{busy.value=false}})
}
type GridRow=Record<string,unknown>
const matrixRows=computed<GridRow[]>(()=>props.products.data.map(p=>({
  ...p, product:p, material_code:p.material_versions.map(m=>m.material_code).join(' / ')||'未登记',
  material_name:p.material_versions.map(m=>m.material_name).join(' / ')||p.material_name||'未登记',
  website_sku:p.website_skus.join(' / ')||'待同步',
  ...Object.fromEntries(warehouses.map(w=>[w.key,p.mappings[w.key]?.sku??'待核实'])),
})))
const showMaterial=(p:ProductSku)=>{
  if(p.material_versions.length===1&&props.canOperate&&p.is_active)editMaterial(p.id,p.material_versions[0])
  else materialDetail.value=p
}
const matrixColumns=computed<ColDef<GridRow>[]>(()=>[
  {colId:'image_url',field:'image_url',headerName:'产品图片',width:86,minWidth:72,sortable:false,filter:false,
    cellRenderer:SkuMappingGridCell,cellRendererParams:{mode:'image',activate:(r:GridRow)=>{imagePreview.value=(r.product as ProductSku).image_url}}},
  {colId:'sku',field:'sku',headerName:'ERP SKU',width:148,sortable:true,filter:false,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'link',hrefFor:(r:GridRow)=>'/products/'+r.id,labelFor:(r:GridRow)=>String(r.sku??'—')+(r.is_active?'':'（停用）')}},
  {colId:'material_code',field:'material_code',headerName:'库存表代码',width:196,sortable:false,filter:false,tooltipField:'material_code',
    cellRenderer:SkuMappingGridCell,cellRendererParams:{mode:'text',activate:(r:GridRow)=>showMaterial(r.product as ProductSku),
      canAdd:(r:GridRow)=>props.canOperate&&!!r.is_active,add:(r:GridRow)=>editMaterial(Number(r.id))}},
  {colId:'material_name',field:'material_name',headerName:'库存表名称',width:320,sortable:false,filter:false,tooltipField:'material_name',
    cellRenderer:SkuMappingGridCell,cellRendererParams:{mode:'text',activate:(r:GridRow)=>showMaterial(r.product as ProductSku)}},
  {colId:'website_sku',field:'website_sku',headerName:'网站 SKU',width:160,sortable:false,filter:false,tooltipField:'website_sku'},
  ...warehouses.map(w=>({colId:w.key,field:w.key,headerName:w.label,width:145,sortable:false,filter:false,tooltipField:w.key,
    cellRenderer:SkuMappingGridCell,cellRendererParams:{mode:'text',
      label:(r:GridRow)=>{const p=r.product as ProductSku;const m=p.mappings[w.key];return (m?.sku??'待核实')+(m?.status==='factory_default'?'（默认）':'')},
      canActivate:(r:GridRow)=>props.canOperate&&!!r.is_active&&!!(r.product as ProductSku).mappings[w.key]?.warehouse_id,
      activate:(r:GridRow)=>editWarehouse(r.product as ProductSku,w)}})),
  {colId:'alias_count',field:'alias_count',headerName:'别名数量',width:105,sortable:true,filter:false,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'link',hrefFor:(r:GridRow)=>'/products/sku-aliases?search='+encodeURIComponent(String(r.sku??''))}},
])
const unboundRows=computed<GridRow[]>(()=>props.unmappedMaterials.map(m=>({...m,material:m})))
const unboundColumns:ColDef<GridRow>[]=[
  {colId:'material_code',field:'material_code',headerName:'库存代码',width:210},
  {colId:'material_name',field:'material_name',headerName:'库存名称',width:420,tooltipField:'material_name'},
  {colId:'factory_physical_qty',field:'factory_physical_qty',headerName:'原表工厂数量',width:150},
  {colId:'actions',headerName:'操作',width:110,sortable:false,filter:false,cellRenderer:GlobalGridCell,
    cellRendererParams:{mode:'actions',actionsFor:(r:GridRow)=>props.canOperate?[{label:'核实关联',onClick:()=>editMaterial(null,r.material as Material)}]:[]}},
]
</script>

<template>
  <Head title="SKU 映射中心" />
  <AuthenticatedLayout title="SKU 映射中心" :breadcrumbs="[{label:'产品',href:'/products'},'SKU 映射中心']">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div class="flex items-center gap-2 flex-wrap">
          <button type="button" class="mvp-button" :class="{'mvp-button-secondary':activeView!=='matrix'}" @click="activeView='matrix'">SKU 映射中心 · {{products.total}}</button>
          <button type="button" class="mvp-button" :class="{'mvp-button-secondary':activeView!=='unmapped'}" @click="activeView='unmapped'">待绑定物料 · {{unmappedMaterialCount}}</button>
        </div>
        <div class="mvp-page-actions page-header-actions">
          <a :href="exportUrl()" class="mvp-button mvp-button-secondary">Excel 导出 / 模板</a>
          <button v-if="canOperate && unmappedMaterialCount" class="mvp-button" :disabled="busy" @click="autoMatch">自动匹配全部待绑定物料</button>
          <button v-if="canOperate" class="mvp-button mvp-button-secondary" @click="batchOpen=true">Excel 批量维护</button>
          <a href="/products/sku-aliases" class="mvp-button mvp-button-secondary">别名与待解析</a>
          <a href="/inventory/sync" class="mvp-button mvp-button-secondary">库存导入</a>
        </div>
      </div>
      <GlobalGrid v-if="activeView==='matrix'" namespace="grid.products.sku-matrix.v1" title="SKU 映射"
        :rows="matrixRows" :columns="matrixColumns" :required-column-ids="['sku']"
        :page="gridPager" :sort="gridSortState" :search="filters.search??''" :default-pinned-right="0"
        search-placeholder="搜索 SKU、库存代码、名称" cell-text-selection
        @search-change="gridSearch" @page-change="page=>go({page})"
        @page-size-change="size=>go({per_page:size==='all'?100:size,page:1})" @sort-change="gridSort">
        <template #actions>
          <select v-model="missing" class="erp-select" aria-label="映射筛选" @change="applySearch">
            <option value="">全部映射</option><option value="material">缺少库存代码</option>
            <option v-for="w in warehouses" :key="w.key" :value="w.key">{{w.label}}未核实</option>
          </select>
        </template>
      </GlobalGrid>
      <GlobalGrid v-else namespace="grid.products.sku-unbound.v1" title="待绑定工厂物料（当前搜索前 50 条）"
        :rows="unboundRows" :columns="unboundColumns" :required-column-ids="['material_code']" :show-search="false" cell-text-selection>
        <template #actions><form class="flex gap-2" @submit.prevent="applySearch"><input v-model="search" type="search" placeholder="搜索库存代码、名称" class="mvp-search-input"/><button class="mvp-button">搜索</button></form></template>
      </GlobalGrid>
      <p class="text-xs text-gray-500 mt-3">工厂物理库存不等于可发货库存；网站 SKU 只显示实际同步证据。一个商品可关联多个物料版本。</p>
    </section>
    <div v-if="materialDetail" role="dialog" aria-modal="true" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center" @click.self="materialDetail=null">
      <div class="bg-white rounded-lg p-5 w-full max-w-xl max-h-[80vh] overflow-y-auto space-y-3 text-gray-900">
        <h3 class="font-semibold">{{materialDetail.sku}} · 物料版本</h3>
        <div v-for="m in materialDetail.material_versions" :key="m.id" class="border-b pb-2">
          <button v-if="canOperate && materialDetail.is_active" class="mvp-link" @click="editMaterial(materialDetail.id,m);materialDetail=null">{{m.material_code}}</button>
          <span v-else>{{m.material_code}}</span><div>{{m.material_name}}</div>
        </div>
        <p v-if="!materialDetail.material_versions.length">尚未登记物料版本</p>
        <div class="flex justify-end gap-2"><button type="button" class="mvp-button mvp-button-secondary" @click="materialDetail=null">关闭</button>
          <button v-if="canOperate && materialDetail.is_active" class="mvp-button" @click="editMaterial(materialDetail.id);materialDetail=null">新增物料版本</button></div>
      </div>
    </div>
    <div v-if="batchOpen" role="dialog" aria-modal="true" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center">
      <form class="bg-white rounded-lg p-5 w-full max-w-lg space-y-3" @submit.prevent="saveBatch">
        <h3 class="font-semibold">Excel 批量维护</h3>
        <p class="text-sm">下载最新模板，在「批量维护」工作表逐行填写物料或仓库映射及真实核实依据。确认同一商品填「是」；物料库存范围填「是」或「否」。空白行不覆盖。任一行错误则整批回滚。</p>
        <a :href="exportUrl()" class="mvp-link">下载模板</a>
        <input type="file" accept=".xlsx" @change="batchFile=($event.target as HTMLInputElement).files?.[0]??null"/>
        <div class="flex justify-end gap-2"><button type="button" class="mvp-button mvp-button-secondary" :disabled="busy" @click="batchOpen=false;batchFile=null">取消</button><button class="mvp-button" :disabled="busy||!batchFile">核实并批量保存</button></div>
      </form>
    </div>
    <div v-if="materialEdit" role="dialog" aria-modal="true" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center" @click.self="!busy&&(materialEdit=null)">
      <form class="bg-white rounded-lg p-5 w-full max-w-lg space-y-3 text-gray-900" @submit.prevent="saveMaterial">
        <h3 class="font-semibold">物料版本核实</h3>
        <label class="block">ERP SKU<select v-model="materialEdit.productId" required class="erp-select w-full"><option :value="null">选择商品</option><option v-for="p in productOptions" :key="p.id" :value="p.id">{{p.sku}}</option></select></label>
        <label class="block">库存表代码<input v-model="materialEdit.material_code" required maxlength="128" class="erp-input w-full"/></label>
        <label class="block">库存表名称<textarea v-model="materialEdit.material_name" required class="erp-textarea w-full" rows="2"/></label>
        <label class="block">核实依据（至少20字）<textarea v-model="materialEdit.binding_evidence" required minlength="20" class="erp-textarea w-full" rows="2"/></label>
        <label class="flex gap-2"><input type="checkbox" v-model="materialEdit.same_physical_product_confirmed" required/>确认属于同一实际商品</label>
        <label v-if="canApproveStock" class="flex gap-2"><input type="checkbox" v-model="materialEdit.factory_stock_approved"/>确认原表成品库存汇总属于本商品可用工厂库存范围</label>
        <div class="flex justify-end gap-2"><button type="button" class="mvp-button mvp-button-secondary" :disabled="busy" @click="materialEdit=null">取消</button><button class="mvp-button" :disabled="busy">保存</button></div>
      </form>
    </div>
    <div v-if="warehouseEdit" role="dialog" aria-modal="true" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center" @click.self="!busy&&(warehouseEdit=null)">
      <form class="bg-white rounded-lg p-5 w-full max-w-lg space-y-3 text-gray-900" @submit.prevent="saveWarehouse">
        <h3 class="font-semibold">{{warehouseEdit.label}} SKU 核实</h3>
        <label class="block">仓库真实 SKU<input v-model="warehouseEdit.sku" required maxlength="128" class="erp-input w-full"/></label>
        <label class="block">仓库核实依据（至少20字）<textarea v-model="warehouseEdit.verification_evidence" minlength="20" required rows="3" class="erp-textarea w-full"/></label>
        <label class="flex gap-2"><input type="checkbox" v-model="warehouseEdit.same_physical_product_confirmed" required/>确认同一物理商品</label>
        <div class="flex justify-end gap-2"><button type="button" class="mvp-button mvp-button-secondary" :disabled="busy" @click="warehouseEdit=null">取消</button><button class="mvp-button" :disabled="busy">保存</button></div>
      </form>
    </div>
    <div v-if="imagePreview" role="dialog" class="fixed inset-0 z-50 bg-black/70 flex items-center justify-center" @click="imagePreview=null"><img :src="imagePreview" class="max-h-[80vh] max-w-[90vw]" alt="产品图片"/></div>
  </AuthenticatedLayout>
</template>
