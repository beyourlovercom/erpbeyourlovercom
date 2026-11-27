<script setup lang="ts">
import { computed, defineAsyncComponent, reactive, ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import type { ColDef } from 'ag-grid-community'
import axios from 'axios'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import GlobalGridCell from '../../../Components/DataGrid/GlobalGridCell.vue'

const GlobalGrid = defineAsyncComponent(() => import('../../../Components/DataGrid/GlobalGrid.vue'))
type Row = Record<string, unknown>
type Version = {id:number; book_key?:string; display_name:string; supplier_name:string; version_number:number; status:string; effective_from:string|null; effective_until:string|null; original_filename:string; sha256:string; created_at:string; notes?:{sheets?:string[];parsed_sheets?:string[];warnings?:string[]}|null}
type Pager = {data:Row[];current_page:number;last_page:number;per_page:number;total:number}
type Preview = {book_key:string;sha256:string;previous_version_id:number|null;sheets:string[];parsed_sheets:string[];warnings:string[];component_count:number;band_count:number;zone_count:number;counts:Record<string,number>;changes:{id:string;kind:string;old:Row|null;new:Row|null}[];change_sample_limit:number;approval_allowed:boolean}
type Quote = {status:string;reason:string|null;native_totals:Record<string,number>|null;total_cny:number|null;reference_native_totals?:Record<string,number>|null;reference_total_cny?:number|null;components:Row[]}
const props = defineProps<{versions:Version[];selectedVersion:Version|null;view:'books'|'bands'|'zones';search:string;rows:Pager|null;canImport:boolean}>()
const route = '/logistics/rates'
const file = ref<File|null>(null)
const preview = ref<Preview|null>(null)
const effective = ref('')
const importOpen = ref(false)
const trialOpen = ref(false)
const reviewOpen = ref(false)
const review = reactive({evidence:'',confirm_contract:false,confirm_rules:false,confirm_restrictions:false})
const reviewAvailable = computed(()=>props.canImport&&props.selectedVersion?.status==='draft'&&props.selectedVersion.book_key==='tianyun_de'&&!!props.selectedVersion.effective_from&&props.versions.find(v=>v.book_key==='tianyun_de')?.id===props.selectedVersion.id)

const busy = ref(false)
const error = ref('')
const notice = ref('')
const trial = ref<Quote|null>(null)
const form = reactive({warehouse_code:'DE',carrier:'DHL',service:'domestic',country:'DE',postal_code:'',as_of:new Date().toISOString().slice(0,10),handling_policy:'internal',weight_kg:1,weight_basis:'measured_packaged',length:20,width:15,height:10,item_count:1,eligibility:false})
const statusLabels:Record<string,string> = {draft:'待审核',approved:'已批准',needs_review:'待审核',verified:'已核验',retired:'已停用'}
const reasonLabels:Record<string,string> = {rate_version_not_approved:'报价尚未批准',rate_version_outside_effective_dates:'不在生效日期范围',rate_scope_missing:'仓库、渠道或目的国缺少对应报价',rate_rule_unreviewed:'计费规则未审核',rate_coverage_unverified:'费用覆盖未核验',eligibility_unconfirmed:'商品/目的地适用性尚未确认',missing_dimensions:'缺少完整尺寸',missing_weight:'缺少重量',postal_zone_missing_or_ambiguous:'邮编分区缺失或冲突',postal_code_invalid:'邮编格式错误',postal_rate_excluded:'该邮编不适用协议报价',weight_band_missing_or_ambiguous:'重量档位缺失或冲突',fx_unavailable:'汇率不可用，原币费用可查看'}
const select = (view:'books'|'bands'|'zones', version = props.selectedVersion?.id, page=1, search='', perPage=props.rows?.per_page??50) => {
  router.get(route,{view,version_id:version||undefined,page,search,per_page:perPage},{preserveScroll:true,preserveState:true,replace:true})
}
const download = (id:number) => { window.location.assign(route+'/'+id+'/original') }
const bookRows = computed<Row[]>(()=>props.versions.filter(v=>!props.search||[v.display_name,v.supplier_name,v.original_filename].join(' ').toLowerCase().includes(props.search.toLowerCase())).map(v=>({...v,status_label:statusLabels[v.status]??v.status})))
const columns = (spec:[string,string,number][]):ColDef<Row>[]=>spec.map(([field,headerName,width])=>({colId:field,field,headerName,width,sortable:false}))
const bookColumns:ColDef<Row>[]=[...columns([['display_name','报价名称',165],['supplier_name','物流商',115],['version_number','版本',88],['status_label','状态',105],['effective_from','生效日期',120],['original_filename','源文件',300],['sha256','文件指纹',270]]),
  {colId:'actions',field:'id',headerName:'操作',width:170,pinned:'right',sortable:false,cellRenderer:GlobalGridCell,cellRendererParams:{mode:'actions',actionsFor:(row:Row)=>[{label:'费率明细',onClick:()=>select('bands',Number(row.id))},{label:'原表',onClick:()=>download(Number(row.id))}]}}]
const bandColumns=columns([['warehouse_code','报价发货仓',125],['carrier','物流商',110],['service','渠道',165],['destination_country','国家',90],['destination_zone','分区',85],['component_type','费用类型',115],['currency','币种',90],['weight_unit','重量单位',95],['min_weight','起始重量',110],['max_weight','截止重量',110],['fixed_amount','固定费',115],['per_weight_amount','每单位重量',125],['per_extra_item_amount','额外件费',115],['billing_basis','计重方式',145],['dim_divisor','体积系数',100],['rounding','进位',110],['review_status','审核状态',115],['source_note','原始说明',260],['source_sheet','来源工作表',180],['source_cells','单元格',120]])
const zoneColumns=columns([['warehouse_code','报价发货仓',125],['carrier','物流商',110],['service','渠道',160],['destination_country','国家',100],['postal_start','起始邮编',125],['postal_end','截止邮编',125],['zone_label','原始分区',110],['review_status','审核状态',115],['source_sheet','来源工作表',175],['source_cells','单元格',120]])
const detailRows = computed(()=> (props.rows?.data??[]).map(row=>({...row,review_status:statusLabels[String(row.review_status)]??row.review_status})))
const activeColumns = computed(()=>props.view==='books'?bookColumns:props.view==='bands'?bandColumns:zoneColumns)
const gridRows = computed(()=>props.view==='books'?bookRows.value:detailRows.value)
const previewRows = computed<Row[]>(()=> (preview.value?.changes??[]).map(row=>({id:row.id,kind:({added:'新增',changed:'变动',removed:'删除'} as Record<string,string>)[row.kind]??row.kind,old:row.old?JSON.stringify(row.old):'—',new:row.new?JSON.stringify(row.new):'—'})))
const previewColumns=columns([['kind','变化',90],['id','记录',235],['old','原版本',430],['new','新版本',430]])
const componentColumns=columns([['component_type','费用',145],['component_key','费用项',210],['weight_unit','单位',85],['actual_weight','实重',110],['dimensional_weight','体积重',110],['billable_weight','计费重',110],['amount','金额',110],['currency','币种',85],['source_sheet','来源',160],['source_cells','单元格',115]])
const quoteRows=computed<Row[]>(()=> (trial.value?.components??[]).map((row,index)=>({...row,id:index+1})))
function pickFile(event:Event){file.value=(event.target as HTMLInputElement).files?.[0]??null;preview.value=null;error.value='';notice.value=''}
function err(e:unknown){if(axios.isAxiosError(e)){const d=e.response?.data;return Object.values(d?.errors??{}).flat().join('；')||d?.message||'操作失败，请重试。'}return '操作失败，请重试。'}
async function inspect(){
  if(!file.value||busy.value)return;busy.value=true;preview.value=null;error.value='';notice.value=''
  const body=new FormData();body.append('file',file.value)
  try{preview.value=(await axios.post<Preview>(route+'/preview',body)).data}catch(e){error.value=err(e)}finally{busy.value=false}
}
async function save(){
  if(!file.value||!preview.value||busy.value)return;busy.value=true;error.value='';notice.value=''
  const body=new FormData();body.append('file',file.value);body.append('sha256',preview.value.sha256)
  if(preview.value.previous_version_id!==null)body.append('previous_version_id',String(preview.value.previous_version_id))
  if(effective.value)body.append('effective_from',effective.value)
  try{const {data}=await axios.post(route+'/draft',body);notice.value=data.duplicate?'原文件已存在，未重复创建版本。':'已保存新报价草稿，未启用。';preview.value=null;file.value=null;importOpen.value=false;select('bands',data.version_id)}
  catch(e){error.value=err(e)}finally{busy.value=false}
}
async function quote(){
  if(!props.selectedVersion||busy.value)return;busy.value=true;trial.value=null;error.value=''
  try{trial.value=(await axios.post<Quote>(route+'/quote',{version_id:props.selectedVersion.id,mode:props.selectedVersion.status==='draft'?'draft_transport':'approved',warehouse_code:form.warehouse_code,carrier:form.carrier,service:form.service,country:form.country.toUpperCase(),postal_code:form.postal_code,as_of:form.as_of,handling_policy:form.handling_policy,packages:[{weight_kg:form.weight_kg,weight_basis:form.weight_basis,dimensions_cm:[form.length,form.width,form.height],item_count:form.item_count,eligibility:form.eligibility?'confirmed':'unknown'}]})).data}
  catch(e){error.value=err(e)}finally{busy.value=false}
}
async function approveReview(){
  if(!reviewAvailable.value||!props.selectedVersion||busy.value||!review.confirm_contract||!review.confirm_rules||!review.confirm_restrictions||review.evidence.trim().length<12)return
  busy.value=true;error.value='';notice.value=''
  try{
    const v=props.selectedVersion
    await axios.post(route+'/'+v.id+'/approve-germany-domestic',{sha256:v.sha256,...review})
    notice.value='德国本地 DHL 核验范围已批准；其他渠道仍未审核。'
    reviewOpen.value=false
    select('bands',v.id)
  }catch(e){error.value=err(e)}finally{busy.value=false}
}
watch(()=>props.selectedVersion?.id,()=>{trial.value=null;reviewOpen.value=false;Object.assign(review,{evidence:'',confirm_contract:false,confirm_rules:false,confirm_restrictions:false})})
</script>

<template>
  <Head title="物流报价 · BYL ERP" />
  <AuthenticatedLayout title="物流报价" :breadcrumbs="['BYL ERP','物流','物流报价']">
    <section class="mvp-page rate-center">
      <div class="rate-heading">
        <div class="rate-tabs">
          <h2>物流报价</h2>
          <button v-for="v in (['books','bands','zones'] as const)" :key="v" type="button" class="mvp-button" :class="{'mvp-button-primary':view===v}" @click="select(v)">{{v==='books'?'报价版本':v==='bands'?'费率明细':'邮编分区'}}</button>
        </div>
        <div class="rate-tabs">
          <select v-if="versions.length" aria-label="报价版本" :value="selectedVersion?.id" @change="select(view,Number(($event.target as HTMLSelectElement).value))">
            <option v-for="v in versions" :key="v.id" :value="v.id">{{v.display_name}} V{{v.version_number}} · {{statusLabels[v.status]??v.status}}</option>
          </select>
          <button class="mvp-button" type="button" :disabled="!selectedVersion" @click="trialOpen=!trialOpen">运费试算</button><button v-if="reviewAvailable" class="mvp-button" type="button" @click="reviewOpen=!reviewOpen">审核德国本地报价</button>
          <button v-if="canImport" class="mvp-button mvp-button-primary" type="button" @click="importOpen=!importOpen">＋ 导入报价</button>
        </div>
      </div>
      <p v-if="notice" role="status" class="rate-notice">{{notice}}</p>
      <p v-if="error" role="alert" class="mvp-error">{{error}}</p>
      <form v-if="canImport&&importOpen" class="mvp-card rate-form" @submit.prevent="inspect">
        <label>原始报价 Excel<input type="file" accept=".xlsx,.xls" :disabled="busy" @change="pickFile"></label>
        <label>报价生效日期（按原表确认）<input v-model="effective" type="date" :disabled="busy"></label>
        <button class="mvp-button mvp-button-primary" :disabled="busy||!file">预览变动</button>
      </form>
      <section v-if="preview" class="mvp-card">
        <div class="rate-heading"><strong>导入预览 · 新增 {{preview.counts.added}} / 变动 {{preview.counts.changed}} / 删除 {{preview.counts.removed}} / 不变 {{preview.counts.unchanged}}</strong><button class="mvp-button mvp-button-primary" :disabled="busy" type="button" @click="save">确认保存草稿</button></div>
        <p>解析 {{preview.parsed_sheets.length}} / {{preview.sheets.length}} 张工作表 · {{preview.band_count}} 条费率 · {{preview.zone_count}} 条分区</p>
        <GlobalGrid v-if="previewRows.length" namespace="grid.logistics.rate_preview.v1" title="报价变动" :columns="previewColumns" :rows="previewRows" :required-column-ids="['kind','id']" :show-search="false" />
        <p>最多展示前 {{preview.change_sample_limit}} 条差异；完整草稿可在保存后查看。仅保存草稿，不启用正式报价。</p>
        <p v-for="w in preview.warnings" :key="w" class="rate-muted">{{w}}</p>
      </section>
      <section v-if="reviewOpen&&reviewAvailable" class="mvp-card">
        <form class="rate-form" @submit.prevent="approveReview">
          <strong>仅批准德国仓 → 德国境内 DHL · V{{selectedVersion?.version_number}} · {{selectedVersion?.effective_from}}</strong>
          <label class="rate-check"><input v-model="review.confirm_contract" type="checkbox" required> 已核实供应商协议适用于实际德国仓及 DHL 本地服务</label>
          <label class="rate-check"><input v-model="review.confirm_rules" type="checkbox" required> 已核对原表的运输、出库、计重进位、燃油及旺季收费</label>
          <label class="rate-check"><input v-model="review.confirm_restrictions" type="checkbox" required> 已核对商品、地址及常规包装限制；异常附加费/包装材料等不作为本次基础估算</label>
          <label>核验依据（协议/报价日期/供应商确认记录）<textarea v-model="review.evidence" required minlength="12" maxlength="2000" rows="3" /></label>
          <button class="mvp-button mvp-button-primary" type="submit" :disabled="busy||!review.confirm_contract||!review.confirm_rules||!review.confirm_restrictions||review.evidence.trim().length<12">确认批准此范围</button>
          <button class="mvp-button" type="button" @click="reviewOpen=false">取消</button>
        </form>
        <p class="rate-muted">不会批准德国国际、美国仓或国内小包；保留历史版本及审核证据。</p>
      </section>
      <section v-if="trialOpen&&selectedVersion" class="mvp-card">
        <form class="rate-form rate-trial" @submit.prevent="quote">
          <label>仓库代码<input v-model="form.warehouse_code" required></label><label>物流商<input v-model="form.carrier" required></label><label>渠道<input v-model="form.service" required></label>
          <label>国家代码<input v-model="form.country" maxlength="2" required></label><label>邮编<input v-model="form.postal_code"></label><label>试算日期<input v-model="form.as_of" type="date" required></label>
          <label>重量 kg<input v-model.number="form.weight_kg" type="number" min=".001" step=".001" required></label>
          <label>重量来源<select v-model="form.weight_basis"><option value="measured_packaged">包装实重</option><option value="estimated_packaged">预计包装重</option><option value="bare">商品裸重（+20%）</option></select></label>
          <label>长 cm<input v-model.number="form.length" type="number" min=".01" step=".01" required></label><label>宽 cm<input v-model.number="form.width" type="number" min=".01" step=".01" required></label><label>高 cm<input v-model.number="form.height" type="number" min=".01" step=".01" required></label>
          <label>件数<input v-model.number="form.item_count" type="number" min="1" step="1" required></label>
          <label>操作费<select v-model="form.handling_policy"><option value="internal">内部保守标准</option><option value="supplier">供应商报价</option></select></label>
          <label class="rate-check"><input v-model="form.eligibility" type="checkbox"> 已核实商品及目的地适用性（仅试算）</label>
          <button class="mvp-button mvp-button-primary" :disabled="busy||!['approved','draft'].includes(selectedVersion.status)">试算</button>
        </form>
        <p v-if="selectedVersion.status!=='approved'" class="rate-muted">当前为未审核版本，仅可查看基础运输费参考，不含额外操作费、未知附加费及未核实协议，不参与正式分仓比价。</p>
        <div v-if="trial"><strong>{{trial.status==='draft_estimate'?'未审核基础运费参考（不可排名）':trial.status==='ready'?'预计总费用：¥'+Number(trial.total_cny).toFixed(2):(reasonLabels[trial.reason??'']??trial.reason??'暂不可计算')}}</strong><p v-if="trial.native_totals||trial.reference_native_totals">{{Object.entries(trial.native_totals??trial.reference_native_totals??{}).map(([k,v])=>k+' '+v.toFixed(4)).join(' / ')}}</p>
          <GlobalGrid v-if="quoteRows.length" namespace="grid.logistics.rate_quote.v1" title="计算明细" :columns="componentColumns" :rows="quoteRows" :required-column-ids="['component_type']" :show-search="false"/>
        </div>
      </section>
      <GlobalGrid :key="view" :namespace="'grid.logistics.rates.'+view+'.v1'" :title="view==='books'?'报价版本':view==='bands'?'费率明细':'邮编分区'" :columns="activeColumns" :rows="gridRows" :required-column-ids="[view==='books'?'display_name':'warehouse_code']" :page="rows" :search="search" :default-pinned-right="view==='books'?1:0" @page-change="p=>select(view,selectedVersion?.id,p,search)" @page-size-change="s=>select(view,selectedVersion?.id,1,search,typeof s==='number'?s:50)" @search-change="q=>select(view,selectedVersion?.id,1,q)" />
      <div v-if="selectedVersion&&view!=='books'" class="rate-source"><strong>原表：{{selectedVersion.original_filename}}</strong> · {{statusLabels[selectedVersion.status]??selectedVersion.status}} · <button class="mvp-button" type="button" @click="download(selectedVersion.id)">下载原表</button>
        <p v-for="w in selectedVersion.notes?.warnings??[]" :key="w" class="rate-muted">{{w}}</p>
      </div>
      <p class="rate-muted">原始报价及历史版本保留。运费试算不产生发货、财务账单或包装标准写入。未审核、缺少必要数据或规则冲突时不使用估算价格。</p>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.rate-heading,.rate-tabs{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.rate-heading{justify-content:space-between;margin-bottom:8px}
.rate-tabs h2{font-size:20px;margin:0 8px 0 0}
.rate-tabs select,.rate-form input,.rate-form select{min-height:34px;border:1px solid #d7dee8;border-radius:5px;background:var(--erp-card-bg,#fff);padding:4px 8px;max-width:100%;color:inherit}
.rate-form{display:flex;align-items:end;flex-wrap:wrap;gap:12px;margin-bottom:8px}
.rate-form label{display:grid;gap:4px;font-size:13px;min-width:110px}
.rate-trial input,.rate-trial select{width:145px}
.rate-check{display:flex!important;align-items:center}.rate-check input{width:16px}
.rate-muted{font-size:12px;color:#68758a;margin:6px 0}
.rate-source{margin-top:8px;font-size:13px}.rate-notice{color:#176b48}
</style>
