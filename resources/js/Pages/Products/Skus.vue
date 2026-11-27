<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import axios from 'axios'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import { publishGlobalFeedback } from '../../lib/globalFeedback'

type Material = {id:number;material_code:string;material_name:string;factory_stock_approved?:boolean;factory_physical_qty?:number|null}
type Mapping = {warehouse_id:number|null;sku:string|null;status:string}
type ProductSku = {id:number;sku:string|null;is_active:boolean;material_name:string|null;image_url:string|null;material_versions:Material[];website_skus:string[];mappings:Record<string,Mapping>;alias_count:number}
type Paginated<T> = {data:T[];current_page:number;last_page:number;total:number;links:{url:string|null;label:string;active:boolean}[]}
const props = defineProps<{products:Paginated<ProductSku>;filters:{search?:string;missing?:string};unmappedMaterialCount:number;unmappedMaterials:Material[];productOptions:{id:number;sku:string}[];canOperate:boolean;canApproveStock:boolean}>()
const search = ref(props.filters.search ?? '')
const missing=ref(props.filters.missing??'')
const batchFile=ref<File|null>(null)
const batchOpen=ref(false)
const exportUrl=()=>'/products/skus/export?'+new URLSearchParams({search:search.value,missing:missing.value}).toString()
const warehouses = [{key:'FACTORY',label:'中国工厂'},{key:'DE',label:'德国仓'},{key:'CA',label:'加拿大仓'},{key:'US-LA',label:'美西 MorePlus'},{key:'US-NY',label:'美东'},{key:'US-SM',label:'美世迈仓'}]
const applySearch = () => router.get('/products/skus',{search:search.value,missing:missing.value},{preserveState:true,replace:true})
const materialEdit = ref<{productId:number|null;material_code:string;material_name:string;binding_evidence:string;factory_stock_approved:boolean;same_physical_product_confirmed:boolean}|null>(null)
const warehouseEdit=ref<{productId:number;warehouseId:number;label:string;sku:string;verification_evidence:string;same_physical_product_confirmed:boolean}|null>(null)
const busy=ref(false)
const imagePreview=ref<string|null>(null)
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
</script>

<template>
  <Head title="SKU 映射中心" />
  <AuthenticatedLayout title="SKU 映射中心" :breadcrumbs="[{label:'产品',href:'/products'},'SKU 映射中心']">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div class="flex items-center gap-3 flex-wrap"><h2>SKU 映射中心</h2><span class="text-sm">商品 {{ products.total }} · 未绑定物料 {{ unmappedMaterialCount }}</span></div>
        <div class="mvp-page-actions page-header-actions">
          <form class="flex gap-2" @submit.prevent="applySearch"><input v-model="search" type="search" placeholder="搜索 SKU、库存代码、名称" class="mvp-search-input"/><select v-model="missing" class="erp-select"><option value="">全部映射</option><option value="material">缺少库存代码</option><option v-for="w in warehouses" :key="w.key" :value="w.key">{{w.label}}未核实</option></select><button class="mvp-button">搜索</button></form>
          <a :href="exportUrl()" class="mvp-button mvp-button-secondary">Excel 导出 / 模板</a>
          <button v-if="canOperate" class="mvp-button mvp-button-secondary" @click="batchOpen=true">Excel 批量维护</button>
          <a href="/products/sku-aliases" class="mvp-button mvp-button-secondary">别名与待解析</a>
          <a href="/inventory/sync" class="mvp-button mvp-button-secondary">库存导入</a>
        </div>
      </div>
      <div class="mvp-table-wrapper overflow-x-auto">
        <table class="mvp-table" style="min-width:1450px">
          <thead><tr>
            <th>产品图片</th><th>ERP SKU</th><th>库存表代码</th><th>库存表名称</th><th>网站 SKU</th>
            <th v-for="w in warehouses" :key="w.key">{{w.label}}</th><th>别名数量</th>
          </tr></thead>
          <tbody>
            <tr v-for="p in products.data" :key="p.id">
              <td><button v-if="p.image_url" type="button" @click="imagePreview=p.image_url"><img :src="p.image_url" :alt="p.sku??''" loading="lazy" style="width:44px;height:44px;object-fit:contain" /></button><span v-else>—</span></td>
              <td><a :href="'/products/'+p.id" class="mvp-link">{{p.sku??'—'}}</a><small v-if="!p.is_active" class="block text-gray-500">停用</small></td>
              <td style="min-width:145px">
                <div v-for="m in p.material_versions" :key="m.id"><button v-if="canOperate && p.is_active" class="mvp-link" @click="editMaterial(p.id,m)">{{m.material_code}}</button><span v-else>{{m.material_code}}</span></div>
                <span v-if="!p.material_versions.length" class="text-gray-500">未登记</span>
                <button v-if="canOperate && p.is_active" class="block text-xs mvp-link" @click="editMaterial(p.id)">+ 物料版本</button>
              </td>
              <td style="min-width:240px"><div v-for="m in p.material_versions" :key="m.id">{{m.material_name}}</div><span v-if="!p.material_versions.length">{{p.material_name??'未登记'}}</span></td>
              <td>{{p.website_skus.join(' / ')||'待同步'}}</td>
              <td v-for="w in warehouses" :key="w.key">
                <button v-if="canOperate && p.is_active && p.mappings[w.key]?.warehouse_id" class="mvp-link" @click="editWarehouse(p,w)">{{p.mappings[w.key]?.sku??'待核实'}}</button>
                <span v-else>{{p.mappings[w.key]?.sku??'—'}}</span>
                <small v-if="p.mappings[w.key]?.status==='factory_default'" class="block text-gray-500">默认</small>
              </td>
              <td><a class="mvp-link" :href="'/products/sku-aliases?search='+encodeURIComponent(p.sku??'')">{{p.alias_count}}</a></td>
            </tr>
            <tr v-if="!products.data.length"><td colspan="12" class="mvp-empty">暂无产品</td></tr>
          </tbody>
        </table>
      </div>
      <nav v-if="products.last_page>1" class="mvp-pagination"><template v-for="link in products.links" :key="link.label"><a v-if="link.url" :href="link.url" :class="{'mvp-page-active':link.active}" v-html="link.label"/><span v-else v-html="link.label"/></template></nav>
      <section v-if="unmappedMaterials?.length" class="mt-4">
        <h3 class="font-semibold">待绑定工厂物料（当前搜索前 50 条）</h3>
        <div class="mvp-table-wrapper"><table class="mvp-table"><thead><tr><th>库存代码</th><th>库存名称</th><th>原表工厂数量</th><th>操作</th></tr></thead>
          <tbody><tr v-for="m in unmappedMaterials" :key="m.id"><td>{{m.material_code}}</td><td>{{m.material_name}}</td><td>{{m.factory_physical_qty??'—'}}</td><td><button v-if="canOperate" class="mvp-link" @click="editMaterial(null,m)">核实关联</button></td></tr></tbody></table></div>
      </section>
      <p class="text-xs text-gray-500 mt-3">工厂物理库存不等于可发货库存；网站 SKU 只显示实际同步证据。一个商品可关联多个物料版本。</p>
    </section>
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
