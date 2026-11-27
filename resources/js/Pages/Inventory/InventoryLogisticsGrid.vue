<script setup lang="ts">
import {computed,ref} from 'vue'
import type {ColDef} from 'ag-grid-community'
import GlobalGrid from '../../Components/DataGrid/GlobalGrid.vue'
import GlobalGridCell from '../../Components/DataGrid/GlobalGridCell.vue'
import SkuMappingGridCell from '../Products/SkuMappingGridCell.vue'
type Row=Record<string,unknown>
const props=defineProps<{view:'logistics'|'sources';rows:Row[]}>()
const image=ref<string|null>(null)
const col=(field:string,headerName:string,width=135,hide=false):ColDef<Row>=>({colId:field,field,headerName,width,hide,filter:true,sortable:true,tooltipField:field,valueFormatter:p=>p.value==null?'—':String(p.value)})
const sku=col('sku','SKU',150)
const name=col('material_name','物料名称',220)
const logistics:ColDef<Row>[]=[
 {colId:'image_url',field:'image_url',headerName:'图片',width:80,sortable:false,filter:false,cellRenderer:SkuMappingGridCell,cellRendererParams:{mode:'image',activate:(r:Row)=>{if(typeof r.image_url==='string')image.value=r.image_url}}},
 {...sku,cellRenderer:GlobalGridCell,cellRendererParams:{mode:'link',hrefFor:(r:Row)=>'/products/'+r.id}},
 name,col('retail_dimensions','彩盒尺寸 cm',150),col('retail_weight_kg','彩盒毛重 kg'),col('retail_volume_cm3','彩盒体积 cm³',145,true),col('retail_weight_source','彩盒重量来源',210,true),
 col('shipping_dimensions','物流尺寸 cm',150),col('shipping_weight_kg','物流实重 kg'),col('shipping_volume_cm3','物流体积 cm³',145),
 col('packaging_method','包装方案',140),col('shipping_status','物流参数状态'),
 col('volume_weight_kg','体积重 kg'),col('billable_weight_kg','计费重 kg'),col('billing_basis','计费状态',210),
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
 <GlobalGrid :namespace="'grid.inventory.'+view+'.v2'" :title="view==='logistics'?'产品物流参数总表':'物流参数数据来源'" :rows="rows" :columns="columns" :required-column-ids="['sku']" :default-pinned-left="1" :default-pinned-right="0" remember-client-filters>
  <template #actions><a class="mvp-button" :href="'/inventory/products/export-logistics?view='+view">Excel 导出全部</a></template>
 </GlobalGrid>
 <p class="text-xs text-slate-500" v-if="view==='logistics'">彩盒和单件包装参考不参与正式计费；物流实重及尺寸仅来自已采纳的包装测量。体积重及计费重待统一计费引擎接入，不使用固定系数。缺失数据不以零代替。</p>
 <p class="text-xs text-slate-500" v-else>保留历史及待审核记录，查看或审核跳转原有权限保护页面。运输参考重量不等于正式物流实重。</p>
 <div v-if="image" role="dialog" class="fixed inset-0 z-50 bg-black/70 flex items-center justify-center" @click="image=null"><img :src="image" class="max-h-[80vh] max-w-[90vw]" alt="产品图片"/></div>
</template>
