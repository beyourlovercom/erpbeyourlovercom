<script setup lang="ts">
import {computed,ref} from 'vue'
import type {ColDef} from 'ag-grid-community'
import GlobalGrid from '../../Components/DataGrid/GlobalGrid.vue'
import GlobalGridCell from '../../Components/DataGrid/GlobalGridCell.vue'
import InventoryProductRateDialog from './InventoryProductRateDialog.vue'
import SkuMappingGridCell from '../Products/SkuMappingGridCell.vue'
type Row=Record<string,unknown>
type Scope={version_id:number;warehouse_code:string;carrier:string;service:string;destination_country:string;effective_from:string|null;effective_until:string|null}
const props=defineProps<{view:'logistics'|'sources';rows:Row[];canPreviewRates?:boolean;rateScopes?:Scope[]}>()
const quoteRow=ref<Row|null>(null)
const quotes=ref<Record<string,Row>>({})
const displayRows=computed(()=>props.rows.map(r=>{const q=quotes.value[String(r.id)];return q&&q.measurement_record_id===r.shipping_measurement_id?{...r,...q}:r}))
function acceptQuote(q:Row){
 if(!quoteRow.value)return
 const sc=q.quote_scope as Scope&{country:string}|undefined
 quotes.value={...quotes.value,[String(quoteRow.value.id)]:{measurement_record_id:q.measurement_record_id,volume_weight_kg:q.volume_weight_kg??null,billable_weight_kg:q.billable_weight_kg??null,billing_basis:q.billing_basis??'报价不可用',quote_scope:sc?sc.warehouse_code+'/'+sc.carrier+'/'+sc.service+'/'+sc.country+' #'+sc.version_id+' '+String((sc as unknown as Row).as_of??'')+' '+String((sc as unknown as Row).postal_code??''):null}}
}
const image=ref<string|null>(null)
const col=(field:string,headerName:string,width=135,hide=false):ColDef<Row>=>({colId:field,field,headerName,width,hide,filter:true,sortable:true,tooltipField:field,valueFormatter:p=>p.value==null?'—':String(p.value)})
const sku=col('sku','SKU',150)
const name=col('material_name','物料名称',220)
const logistics:ColDef<Row>[]=[
 {colId:'image_url',field:'image_url',headerName:'图片',width:80,sortable:false,filter:false,cellRenderer:SkuMappingGridCell,cellRendererParams:{mode:'image',activate:(r:Row)=>{if(typeof r.image_url==='string')image.value=r.image_url}}},
 {...sku,cellRenderer:GlobalGridCell,cellRendererParams:{mode:'link',hrefFor:(r:Row)=>'/products/'+r.id}},
 name,{colId:'rate_preview',headerName:'运费试算',width:115,hide:!props.canPreviewRates,cellRenderer:GlobalGridCell,cellRendererParams:{mode:'actions',actionsFor:(r:Row)=>props.canPreviewRates&&r.shipping_status==='已采纳'?[{label:'试算',onClick:()=>{quoteRow.value=r}}]:[]}},col('retail_dimensions','彩盒尺寸 cm',150),col('retail_weight_kg','彩盒毛重 kg'),col('retail_volume_cm3','彩盒体积 cm³',145,true),col('retail_weight_source','彩盒重量来源',210,true),
 col('shipping_dimensions','物流尺寸 cm',150),col('shipping_weight_kg','物流实重 kg'),col('shipping_volume_cm3','物流体积 cm³',145),
 col('packaging_method','包装方案',140),col('shipping_status','物流参数状态'),
 col('volume_weight_kg','体积重 kg'),col('billable_weight_kg','计费重 kg'),col('billing_basis','计费规则/状态',210),col('quote_scope','最近试算渠道',230),
 col('unclassified_package_dimensions','单件包装参考（未分类） cm',200,true),col('reference_weight_kg','运输参考重量 kg',160,true),
 col('carton_dimensions','整箱尺寸 cm',160,true),col('carton_weight_kg','整箱毛重 kg',140,true),col('units_per_carton','装箱数量',110,true),col('carton_cbm','整箱 CBM',120,true),
 col('estimated_carton_dimensions','外箱预估尺寸 cm',180,true),col('estimated_carton_cbm','外箱预估 CBM',150,true),
 col('shipping_measured_at','物流测量时间（北京时间）',180,true),
 {colId:'evidence',headerName:'物流测量证据',width:130,cellRenderer:GlobalGridCell,cellRendererParams:{mode:'actions',actionsFor:(r:Row)=>r.shipping_measurement_id?[{label:'查看',href:'/inventory/packaging/'+r.shipping_measurement_id}]:[]}},
]
const sources:ColDef<Row>[]=[
 sku,name,col('source','数据来源',180),col('kind','测量类型',180),col('weight_kg','原始重量 kg'),col('dimensions','原始尺寸 cm',150),
 col('method','包装方案',150),{...col('status','审核状态'),valueFormatter:p=>({draft:'草稿',submitted:'待审核',pending:'待审核',approved:'已批准',adopted:'已采纳',rejected:'已驳回'} as Record<string,string>)[String(p.value)]??String(p.value??'—')},{...col('current','当前采用'),cellDataType:false,valueFormatter:p=>p.value?'是':'否'},
 col('observed_at','测量时间（北京时间）',185),col('reviewed_at','审核时间（北京时间）',185,true),col('media_count','证据数量'),col('reference','来源编号',220),
 {colId:'evidence',headerName:'操作',width:120,cellRenderer:GlobalGridCell,cellRendererParams:{mode:'actions',actionsFor:(r:Row)=>r.href?[{label:'查看 / 审核',href:r.href}]:[]}},
]
const columns=computed(()=>props.view==='logistics'?logistics:sources)
</script>
<template>
 <GlobalGrid :column-settings-version="2" :field-groups="view==='logistics'?{'商品信息':['image_url','sku','material_name'],'彩盒参数':['retail_dimensions','retail_weight_kg','retail_volume_cm3','retail_weight_source'],'正式物流参数':['shipping_dimensions','shipping_weight_kg','shipping_volume_cm3','packaging_method','shipping_status','shipping_measured_at'],'运费试算':['rate_preview','volume_weight_kg','billable_weight_kg','billing_basis','quote_scope'],'运输与整箱参考':['unclassified_package_dimensions','reference_weight_kg','carton_dimensions','carton_weight_kg','units_per_carton','carton_cbm','estimated_carton_dimensions','estimated_carton_cbm'],'证据':['evidence']}:{'商品信息':['sku','material_name'],'来源与测量':['source','kind','weight_kg','dimensions','method','observed_at','reference'],'审核与证据':['status','current','reviewed_at','media_count','evidence']}" :namespace="'grid.inventory.'+view+'.v2'" :title="view==='logistics'?'产品物流参数总表':'物流参数数据来源'" :rows="displayRows" :columns="columns" :required-column-ids="['sku']" :default-pinned-left="1" :default-pinned-right="0" remember-client-filters>
  <template #actions><a class="mvp-button" :href="'/inventory/products/export-logistics?view='+view">Excel 导出全部</a></template>
 </GlobalGrid>
 <p class="text-xs text-slate-500" v-if="view==='logistics'">彩盒和单件包装参考不参与正式计费；物流实重及尺寸仅来自已采纳的包装测量。体积重及计费重来自已批准报价的运输费用组件；操作费可能采用另一规则，见试算明细。表内试算仅本次浏览会话，Excel 导出基础参数，不含临时试算。缺失数据不以零代替。</p>
 <p class="text-xs text-slate-500" v-else>保留历史及待审核记录，查看或审核跳转原有权限保护页面。运输参考重量不等于正式物流实重。</p>
 <InventoryProductRateDialog v-if="quoteRow" :row="quoteRow" :scopes="rateScopes??[]" @close="quoteRow=null" @result="acceptQuote"/>
 <div v-if="image" role="dialog" class="fixed inset-0 z-50 bg-black/70 flex items-center justify-center" @click="image=null"><img :src="image" class="max-h-[80vh] max-w-[90vw]" alt="产品图片"/></div>
</template>
