<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, nextTick, ref, watch } from 'vue'
import { computed as gridComputed } from 'vue'
import type { ColDef } from 'ag-grid-community'
import GlobalGridTemplates from '../../../Components/DataGrid/GlobalGridTemplates.vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
type RecordRow={id:number;status:string;product:{sku:string|null;name:string};warehouse:string|null;operator:string|null;measured_at:string|null;dimensions:string;packaged_weight_kg:string|null;media_count:number}
type Input={weight_kg:number|null;length_cm:number|null;width_cm:number|null;height_cm:number|null;status:string;source:string}
type Row={product_id:number;sku:string;name:string;material_name:string|null;input:Input;needs_measurement:boolean;awaiting_review:boolean;coverage_status:string;measurement_counts:Record<string,number>}
type Page<T>={data:T[];links:{url:string|null;label:string;active:boolean}[];total?:number;current_page?:number;last_page?:number;per_page?:number}
const props=defineProps<{records:Page<RecordRow>;filters:Record<string,any>;canReview:boolean;canSubmit:boolean;canQuote?:boolean;tab?:string;optimization?:{rows:Page<Row>;stats:{total:number;complete:number;missing:number;review:number}};selected?:any}>()
const labels:Record<string,string>={draft:'草稿',submitted:'待审核',approved:'已批准',rejected:'已驳回'}
const coverageLabels:Record<string,string>={missing_weight:'缺少重量',missing_dimensions:'缺少尺寸',complete_adopted_standard:'完整标准',ready:'待采用'}
const tab=computed(()=>props.tab||'records')
const search=ref(props.filters?.search??''); const status=ref(props.filters?.status??'')
const coverage=ref(props.filters?.coverage??'');const pageSize=ref(Number(props.filters?.per_page??20))
const country=ref(props.filters?.country??'US');const postal=ref(props.filters?.postal??'');const routeId=ref(Number(props.filters?.route_id??0))
const divisor=ref(Number(props.filters?.divisor??6000));const handling=ref(props.filters?.handling??'supplier');const confirmed=ref(!!props.filters?.confirmed)
watch(()=>props.filters,f=>{search.value=f.search??'';status.value=f.status??'';coverage.value=f.coverage??'';pageSize.value=Number(f.per_page??20);country.value=f.country??'US';postal.value=f.postal??'';routeId.value=Number(f.route_id??0);divisor.value=Number(f.divisor??6000);handling.value=f.handling??'supplier';confirmed.value=!!f.confirmed})
function listFilters(target=tab.value){return {tab:target,search:search.value||undefined,status:target==='records'?status.value||undefined:undefined,coverage:target==='records'?undefined:coverage.value||undefined,per_page:pageSize.value}}
function navigate(target=tab.value){router.get('/inventory/packaging',listFilters(target),{preserveScroll:true,replace:true})}
function detail(id:number){router.get('/inventory/packaging',{...listFilters(),product_id:id,divisor:divisor.value,country:country.value},{preserveScroll:true,onSuccess:()=>{nextTick(()=>document.querySelector('.details')?.scrollIntoView({block:'start'}))}})}
function calculate(reset=false){if(!props.selected)return; if(reset){routeId.value=0;confirmed.value=false}router.get('/inventory/packaging',{...listFilters(),product_id:props.selected.product.product_id,country:country.value,postal:postal.value||undefined,route_id:routeId.value||undefined,divisor:divisor.value,handling:handling.value,confirmed:confirmed.value?1:0},{preserveScroll:true,replace:true})}
function dims(i?:Input){return i?.length_cm&&i.width_cm&&i.height_cm?[i.length_cm,i.width_cm,i.height_cm].join(' × '):'待测量'}
function num(v:any,d=2){return v==null||!Number.isFinite(Number(v))?'—':Number(v).toFixed(d)}
function money(q:any){if(!q)return '待报价';const v=q.status==='draft_estimate'?q.reference_total_cny:q.total_cny;return v==null?'不可用':(q.status==='draft_estimate'?'参考 ':'')+'¥'+num(v)}
function startUpgrade(method:string){if(!props.selected?.standard_record_id)return;router.post(`/inventory/packaging/product/${props.selected.product.product_id}/upgrade`,{baseline_id:props.selected.standard_record_id,method,divisor:divisor.value})}
function pageLabel(s:string){return s.includes('Previous')||s.includes('laquo')?'上一页':s.includes('Next')||s.includes('raquo')?'下一页':s}
const baseline=computed(()=>props.selected?.geometry?.baseline)
const rows=computed(()=>tab.value==='records'?props.records:props.optimization?.rows)
type GridRow=Record<string,unknown>
const packagingRows=gridComputed<GridRow[]>(()=>tab.value==='records'
  ?props.records.data.map(r=>({...r,product_label:r.product.name+' · '+(r.product.sku||'无 SKU'),warehouse_label:r.warehouse||'未选择',
      operator_label:r.operator||'—',weight_label:r.packaged_weight_kg||'—',status_label:labels[r.status]??r.status,
      measured_label:r.measured_at?new Date(r.measured_at).toLocaleString('zh-CN',{timeZone:'Asia/Shanghai'}):'—'}))
  :(props.optimization?.rows.data??[]).map(r=>({...r,id:r.product_id,product_label:(r.sku||'无 SKU')+' · '+r.name,
      dimensions:dims(r.input),weight_label:num(r.input.weight_kg,3),status_label:coverageLabels[r.coverage_status]||'待测量',
      goal:r.needs_measurement?'补齐测量':'按路线测算',saving:'待核算'})))
const packagingColumns=gridComputed<ColDef<GridRow>[]>(()=>tab.value==='records'?[
  {field:'product_label',headerName:'产品 / SKU',width:245},{field:'warehouse_label',headerName:'地点',width:140},
  {field:'operator_label',headerName:'操作员',width:130},{field:'dimensions',headerName:'尺寸',width:155},
  {field:'weight_label',headerName:'重量',width:110},{field:'measured_label',headerName:'测量时间',width:175},
  {field:'status_label',headerName:'状态',width:110},{field:'media_count',headerName:'证据',width:90},
  {colId:'actions',headerName:'操作',width:90,pinned:'right',lockPinned:true,lockVisible:true},
]:[
  {field:'product_label',headerName:'SKU / 商品',width:245},{field:'dimensions',headerName:'现有包装 cm',width:165},
  {field:'weight_label',headerName:'重量 kg',width:110},{field:'status_label',headerName:'状态',width:155},
  {field:'goal',headerName:'优化目标',width:145},{field:'saving',headerName:'月节省',width:100},
  {colId:'actions',headerName:'操作',width:125,pinned:'right',lockPinned:true,lockVisible:true},
])
const packagingPager=gridComputed(()=>({current_page:rows.value?.current_page??1,last_page:rows.value?.last_page??1,per_page:rows.value?.per_page??pageSize.value,total:rows.value?.total??rows.value?.data.length??0}))
const packagingGroups={'商品信息':['product_label'],'包装参数':['warehouse_label','operator_label','dimensions','weight_label','measured_label','status_label','media_count'],
  '优化分析':['goal','saving'],'操作':['actions']}
function goGridPage(page:number){
  const exact=rows.value?.links.find(l=>l.url&&l.label===String(page))?.url
  if(exact){router.get(exact,{}, {preserveScroll:true});return}
  router.get('/inventory/packaging',{...listFilters(),page},{preserveScroll:true,replace:true})
}
function gridPageSize(size:number|'all'){if(typeof size==='number'&&[20,50,100].includes(size)){pageSize.value=size;navigate()}}
</script>
<template>
<Head title="包装与测量 · BYL ERP"/>
<AuthenticatedLayout title="包装与测量" :breadcrumbs="[{label:'库存',href:'/inventory'},'包装与测量']">
<section class="mvp-page packaging-workspace">
<div class="heading"><h2>包装与测量</h2><nav aria-label="包装视图"><button v-if="canReview" :class="{active:tab==='optimization'}" @click="navigate('optimization')">包装优化</button><button :class="{active:tab==='records'}" @click="navigate('records')">测量记录</button><button v-if="canReview" :class="{active:tab==='standards'}" @click="navigate('standards')">包装标准</button></nav><div class="mvp-actions"><Link v-if="canSubmit" class="mvp-button mvp-button-primary" href="/inventory/packaging/create">＋ 新建测量</Link></div></div>
<div v-if="optimization" class="stats"><span>商品 <b>{{optimization.stats.total}}</b></span><span>待补录 <b>{{optimization.stats.missing}}</b></span><span>完整标准 <b>{{optimization.stats.complete}}</b></span><span>待审核 <b>{{optimization.stats.review}}</b></span><span>预计月节省 <b>待核算</b></span></div>
<div class="mvp-card filters"><input v-model="search" placeholder="搜索 SKU / 产品名称" @keyup.enter="navigate()"/><select v-if="tab==='records'" v-model="status" @change="navigate()"><option value="">全部状态</option><option v-for="(label,key) in labels" :key="key" :value="key">{{label}}</option></select><select v-else v-model="coverage" @change="navigate()"><option value="">全部状态</option><option value="missing">待补录</option><option value="complete">完整标准</option><option value="review">待审核</option></select><button class="mvp-button" @click="navigate()">筛选</button></div>
<GlobalGridTemplates v-if="tab==='records'||optimization" :key="tab" :namespace="'grid.inventory.packaging.'+tab+'.v2'"
 :title="tab==='records'?'测量记录':tab==='optimization'?'包装优化':'包装标准'" :columns="packagingColumns" :rows="packagingRows"
 :field-groups="packagingGroups" :required-column-ids="['product_label','actions']" :page="packagingPager"
 :show-search="false" cell-text-selection @page-change="goGridPage" @page-size-change="gridPageSize">
 <template #cell-product_label="{row}"><div v-if="tab==='records'"><strong>{{row.product.name}}</strong><small class="packaging-grid-small">{{row.product.sku||'无 SKU'}}</small></div><div v-else><strong>{{row.sku||'无 SKU'}}</strong><small class="packaging-grid-small">{{row.name}}</small></div></template>
 <template #cell-weight_label="{row}">{{row.weight_label}}{{tab==='records'?' kg':''}}</template>
 <template #cell-status_label="{row}"><div><span class="status">{{row.status_label}}</span><small v-if="tab!=='records'&&row.awaiting_review" class="packaging-grid-small">有记录待审核</small></div></template>
 <template #cell-media_count="{row}">{{row.media_count}} 张</template>
 <template #cell-actions="{row}"><Link v-if="tab==='records'" :href="'/inventory/packaging/'+row.id">查看</Link><button v-else class="text-link" type="button" @click="detail(row.product_id)">查看方案</button></template>
 <template #footer-left><div class="pagination" v-if="rows?.links?.length"><Link v-for="l in rows.links" :key="l.label" :href="l.url||'#'" :class="{active:l.active,disabled:!l.url}" preserve-scroll>{{pageLabel(l.label)}}</Link></div></template>
</GlobalGridTemplates>
<section v-if="selected" class="mvp-card details" style="scroll-margin-top:72px">
<div class="detail-heading"><h3>{{selected.product.sku}} · 包装优化</h3><Link v-if="selected.standard_record_id" :href="`/inventory/packaging/${selected.standard_record_id}`">查看现有标准</Link><button class="mvp-button" @click="navigate()">关闭</button></div>
<div class="route-controls"><label>目的国 <select v-model="country" @change="calculate(true)"><option v-for="c in ['US','DE','CA','GB','AU','FR','IT','ES','JP']" :key="c">{{c}}</option></select></label><label>邮编 <input v-model="postal" placeholder="实际试算邮编" @input="confirmed=false"/></label><label v-if="canQuote">计费路线 <select v-model="routeId" @change="confirmed=false"><option :value="0">未选择路线（理论参考）</option><option v-for="r in selected.routes" :key="r.id" :value="r.id">{{r.warehouse}} / {{r.carrier}} / {{r.service}} · {{r.status==='draft'?'草稿':'已批准'}} v{{r.version_id}}</option></select></label><label v-if="!routeId">参考体积除数 <select v-model="divisor"><option :value="5000">5000</option><option :value="6000">6000</option><option :value="8000">8000</option></select></label><label v-if="canQuote&&routeId">操作费口径 <select v-model="handling" @change="confirmed=false"><option value="supplier">供应商</option><option value="internal">内部</option></select></label><label v-if="canQuote&&routeId"><input type="checkbox" v-model="confirmed"/> 确认本次假设路线适用</label><button class="mvp-button mvp-button-primary" @click="calculate()">重新测算</button></div>
<div class="compare-cards"><div><small>现有包装</small><strong>{{dims(selected.product.input)}} cm</strong><span>{{selected.product.input.source==='adopted_packaging_measurement'?'实重':'参考重量'}} {{num(selected.product.input.weight_kg,3)}} kg · {{money(selected.quote?.baseline)}}</span><span>参考计费重 {{num(baseline?.reference_chargeable_weight_kg,3)}} kg</span></div><div><small>理论优化目标（长宽不变）</small><strong>{{baseline?.candidate_height_cm!=null?'高度 ≤ '+num(baseline.candidate_height_cm,1)+' cm':'待确认'}}</strong><span>体积减少 {{baseline?.volume_reduction_fraction==null?'—':num(baseline.volume_reduction_fraction*100,1)+'%'}} · 未验证保护性</span><span>{{selected.geometry_reference==='hypothetical'?'假设体积规则，不代表实际运费':'所选路线规则；未支持规则不推断'}}</span></div></div>
<div class="table-wrap"><table><thead><tr><th>四种包装方案</th><th>外包装 cm</th><th>实重 kg</th><th>参考计费重 kg</th><th>报价</th><th>单件节省</th><th>证据</th><th v-if="canSubmit&&selected.standard_record_id">采用方案</th></tr></thead><tbody><tr v-for="(label,key) in selected.methods" :key="String(key)"><td>{{label}}</td><td>{{dims(selected.candidates[key])}}</td><td>{{num(selected.candidates[key]?.weight_kg,3)}}</td><td>{{num(selected.geometry.candidates[key]?.geometry?.reference_chargeable_weight_kg,3)}}</td><td>{{money(selected.quote?.candidates[key]?.quote)}}</td><td>{{selected.quote?.candidates[key]?.logistics_saving_cny!=null?'¥'+num(selected.quote.candidates[key].logistics_saving_cny):selected.quote?.candidates[key]?.reference_transport_saving_cny!=null?'参考 ¥'+num(selected.quote.candidates[key].reference_transport_saving_cny):'—'}}</td><td><Link v-if="selected.candidates[key]?.record_id" :href="`/inventory/packaging/${selected.candidates[key].record_id}`">测量记录</Link><span v-else>待测量</span></td><td v-if="canSubmit&&selected.standard_record_id"><button class="text-link" @click="startUpgrade(String(key))">确认采用</button></td></tr></tbody></table></div>
<div class="history"><b>近90天实际发货</b><span>{{selected.history.observed}} 条</span><span>初筛 {{selected.history.candidate}} 条</span><span>排除 {{selected.history.excluded}} 条</span><span>月节省：待核实包裹、线路与保护证据</span></div>
<p class="footnote">本页试算不会修改包装标准、订单或库存。草稿仅参考运费，不参与排名；图片常规选填，复核时必传。实际月节省须具备完整的历史与正式报价证据。</p>
</section>
</section>
</AuthenticatedLayout>
</template>
<style scoped>
.packaging-grid-small{display:block;line-height:1.1;font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;max-width:220px}
.packaging-workspace{gap:10px}.heading,.detail-heading,.stats,.history,.pagination,.route-controls,.filters{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.heading h2,.detail-heading h3{margin:0;font-size:18px}.heading nav{display:flex;gap:4px;flex-wrap:wrap}.heading nav button,.text-link{border:0;background:transparent;padding:7px 10px;cursor:pointer;color:#475569}.heading nav button.active{color:#2563eb;border-bottom:2px solid #2563eb;font-weight:600}.heading .mvp-actions{margin-left:auto}.stats{gap:24px;padding:8px 0;color:#64748b;font-size:13px}.stats b{color:#1e293b;margin-left:4px}.filters{padding:8px!important}.filters input{min-width:180px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:9px 10px;border-bottom:1px solid #e5e7eb;text-align:left;white-space:nowrap;font-size:13px}th{color:#64748b;background:#f8fafc}td small{display:block;color:#64748b;max-width:240px;overflow:hidden;text-overflow:ellipsis}.status{padding:4px 8px;border-radius:5px;background:#ecfeff;color:#155e75;font-size:12px}.text-link{color:#2563eb;padding:0}.selected{background:#eff6ff}.pagination{justify-content:flex-end;font-size:13px}.pagination a{padding:4px 7px;border:1px solid #e2e8f0;border-radius:4px}.pagination a.active{background:#eff6ff;color:#2563eb}.pagination a.disabled{pointer-events:none;opacity:.45}.details{padding:14px;display:grid;gap:12px}.detail-heading button{margin-left:auto}.route-controls label{display:flex;align-items:center;gap:5px;font-size:12px}.route-controls input:not([type=checkbox]){width:115px}.route-controls select{max-width:280px}.compare-cards{display:grid;grid-template-columns:1fr 1fr;gap:10px}.compare-cards>div{display:grid;gap:6px;padding:12px;border:1px solid #e2e8f0;border-radius:6px}.compare-cards strong{font-size:20px}.compare-cards small,.compare-cards span,.history,.footnote{font-size:12px;color:#64748b}.history{border-top:1px solid #e2e8f0;padding-top:10px}.footnote{margin:0}@media(max-width:680px){.compare-cards{grid-template-columns:1fr}.heading .mvp-actions{margin-left:0}.stats{gap:12px}}
</style>
