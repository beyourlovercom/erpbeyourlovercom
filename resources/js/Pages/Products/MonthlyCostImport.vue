<script setup lang="ts">
import { computed, defineAsyncComponent, reactive, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import type { ColDef } from 'ag-grid-community'
import axios from 'axios'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import GlobalGridCell from '../../Components/DataGrid/GlobalGridCell.vue'

const GlobalGrid = defineAsyncComponent(() => import('../../Components/DataGrid/GlobalGrid.vue'))

type Source = {
  source_sheet: string; source_row: number; product_name: string; factory_code: string
  source_variant_key: string; unit_cost: string; raw_unit_cost: string
  cost_value_source: string; extraction_status: string; base_sku: string|null; reason?: string
}
type Product = { id:number; sku:string; name:string }
type Group = {
  group_key: string; effective_from: string; base_sku: string|null
  status: string; blockers: string[]; erp_candidates: Product[]
  product_match: Product|null; source_costs: string[]; sources: Source[]
  selected_source_variant_key: string|null; selected_unit_cost_cny: string|null
  usd_reference_cost?: string|null; manual_cost_applied: boolean; resolution_reason: string
}
type Summary = { groups:number; ready:number; blocked:number; price_conflicts:number; erp_unmatched:number; needs_confirmation:number; manual_costs:number; canonical_create?:number; canonical_noop?:number; canonical_blocked?:number }
type Fx = { available:boolean; usd_to_cny?:string; source?:string; reference_date?:string|null; stale?:boolean; reason?:string; disclosure?:string }
type Decision = { product_id?:number; source_variant_key?:string; manual_cost_cny?:string; reason?:string }

const props = defineProps<{ applyEnabled:boolean }>()
const file = ref<File|null>(null)
const year = ref(new Date().getFullYear())
const csvMonth = ref(10)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const fingerprint = ref('')
const planFingerprint = ref('')
const canApply = ref(false)
const groups = ref<Group[]>([])
const excluded = ref<Source[]>([])
const sourceSummary = ref<Record<string,number>>({})
const summary = ref<Summary|null>(null)
const fx = ref<Fx|null>(null)
const decisions = ref<Record<string,Decision>>({})
const selectedKey = ref('')
const catalogOpen = ref(false)
const excludedOpen = ref(false)
const catalogBusy = ref(false)
const catalogError = ref('')
const catalogQuery = ref('')
const catalogMonth = ref('')
const catalogPage = ref(1)
const catalogLastPage = ref(1)
const catalogTotal = ref(0)
type CostRecord = { id:number; sku:string|null; product_name:string|null; effective_from:string|null; source_unit_cost:string; source_currency:string; usd_reference_cost:string|null; source_key:string; source_reference:string }
const catalogRows = ref<CostRecord[]>([])
const catalogFx = ref<Fx|null>(null)
const filter = ref<'all'|'ready'|'blocked'|'conflict'|'unmatched'|'confirm'>('all')
const search = ref('')
const skuQuery = ref('')
const skuSuggestions = ref<Product[]>([])
const editor = reactive({ product_id:'', source_variant_key:'', manual:false, manual_cost:'', reason:'' })

const selected = computed(() => groups.value.find(g=>g.group_key===selectedKey.value) ?? null)
const sourceOptions = computed(() => selected.value?.sources ?? [])
const visible = computed(() => groups.value.filter(g => {
  if (filter.value==='ready' && g.status!=='ready') return false
  if (filter.value==='blocked' && g.status!=='blocked') return false
  if (filter.value==='conflict' && g.source_costs.length<2) return false
  if (filter.value==='unmatched' && !(g.blockers.includes('erp_product_not_found') || g.blockers.includes('sku_confirmation_required') || !g.base_sku)) return false
  if (filter.value==='confirm' && !(g.blockers.some(x=>x.includes('required')) || g.source_costs.length>1)) return false
  const needle=search.value.trim().toLowerCase()
  return !needle || [g.base_sku,g.product_match?.sku,...g.sources.map(s=>s.product_name)].join(' ').toLowerCase().includes(needle)
}))
const gridRows = computed<Record<string,unknown>[]>(() => visible.value.map(g=>({
  id:g.group_key, month:g.effective_from.slice(0,7), base_sku:g.base_sku ?? '待人工提取',
  erp_sku:g.product_match?.sku ?? '未确认', cny:g.selected_unit_cost_cny ?? '存在多个成本',
  usd:g.usd_reference_cost ?? '—', variants:g.sources.length, status_label:g.status==='ready'?'可导入':'待处理',
  blockers:g.blockers.join('、') || '—',
})))
const catalogGridRows = computed<Record<string,unknown>[]>(() => catalogRows.value.map(r=>({
  id:r.id,sku:r.sku ?? '产品已移除',name:r.product_name ?? '—',
  month:r.effective_from?.slice(0,7) ?? '—',cost:r.source_unit_cost,
  currency:r.source_currency,usd:r.usd_reference_cost ?? '汇率未提供',source:r.source_key,
})))
const catalogColumns:ColDef<Record<string,unknown>>[] = [
  {colId:'sku',field:'sku',headerName:'ERP SKU',minWidth:145,pinned:'left'},
  {colId:'name',field:'name',headerName:'产品名称',minWidth:175},
  {colId:'month',field:'month',headerName:'成本月份',width:115},
  {colId:'cost',field:'cost',headerName:'原始单价',width:145},
  {colId:'currency',field:'currency',headerName:'来源币种',width:100},
  {colId:'usd',field:'usd',headerName:'美元参考成本 $',width:165},
  {colId:'source',field:'source',headerName:'原始来源行',minWidth:205},
]
const columns:ColDef<Record<string,unknown>>[] = [
  {colId:'month',field:'month',headerName:'月份',width:112},
  {colId:'base_sku',field:'base_sku',headerName:'基础 SKU',width:160,pinned:'left'},
  {colId:'erp_sku',field:'erp_sku',headerName:'ERP SKU',width:160},
  {colId:'cny',field:'cny',headerName:'实际成本 ¥',width:158},
  {colId:'usd',field:'usd',headerName:'参考成本 $',width:150},
  {colId:'variants',field:'variants',headerName:'原始记录',width:105},
  {colId:'status_label',field:'status_label',headerName:'预检',width:108,cellRenderer:GlobalGridCell,cellRendererParams:{mode:'badge'}},
  {colId:'blockers',field:'blockers',headerName:'待确认原因',width:260},
  {colId:'actions',field:'id',headerName:'操作',width:130,pinned:'right',cellRenderer:GlobalGridCell,
    cellRendererParams:{mode:'actions',actionsFor:(row:Record<string,unknown>)=>[{label:'快速确认',onClick:()=>openEditor(String(row.id))}]}},
]
const blockerLabels:Record<string,string> = {
  source_price_conflict:'同一个 SKU 当月存在不同成本',
  sku_confirmation_required:'基础 SKU 或版本需要人工确认',
  erp_product_not_found:'ERP 未找到对应 SKU',
  multiple_erp_sku_matches:'ERP 存在多个同名 SKU',
  manual_mapping_reason_required:'人工关联 ERP SKU 必须填写理由',
  manual_cost_reason_required:'人工填写实际成本必须填写理由',
  manual_cost_invalid:'人工成本必须是大于零的人民币金额',
  selected_source_not_in_group:'原始成本记录不存在',
  price_conflict_reason_required:'解决成本冲突必须说明理由',
  manual_product_missing:'所选 ERP 产品不存在',
  invalid_manual_product_id:'ERP 产品 ID 无效',
}

async function loadCatalog(page=1) {
  catalogBusy.value=true;catalogError.value=''
  try {
    const {data}=await axios.get('/products/cost-import/catalog',{params:{
      q:catalogQuery.value||undefined,month:catalogMonth.value||undefined,page,
    }})
    catalogRows.value=data.rows;catalogPage.value=data.pagination.page
    catalogLastPage.value=data.pagination.last_page;catalogTotal.value=data.pagination.total;catalogFx.value=data.fx
  }catch(e){catalogError.value=errorMessage(e)}finally{catalogBusy.value=false}
}
function toggleCatalog() {
  catalogOpen.value=!catalogOpen.value
  if(catalogOpen.value) void loadCatalog()
}
function errorMessage(e:unknown):string {
  const data=(e as {response?:{data?:{message?:string;errors?:Record<string,string[]>}}})?.response?.data
  return Object.values(data?.errors ?? {}).flat()[0] ?? data?.message ?? '操作失败，请检查文件和输入'
}
function onFile(e:Event) { file.value=(e.target as HTMLInputElement).files?.[0] ?? null }
async function preview() {
  if (!file.value) return
  busy.value=true;error.value='';notice.value='';groups.value=[];fingerprint.value='';planFingerprint.value='';decisions.value={};canApply.value=false
  try {
    const form=new FormData()
    form.append('file',file.value);form.append('year',String(year.value))
    if(file.value.name.toLowerCase().endsWith('.csv')) form.append('csv_month',String(csvMonth.value))
    const {data}=await axios.post('/products/cost-import/preview',form)
    groups.value=data.groups;summary.value=data.summary;sourceSummary.value=data.source_summary
    excluded.value=data.excluded;fingerprint.value=data.fingerprint;fx.value=data.fx
    notice.value='解析完成，请先处理冲突和待匹配项，再点击“成本预检”。'
  }catch(e){error.value=errorMessage(e)}finally{busy.value=false}
}
async function plan() {
  if (!fingerprint.value) return
  busy.value=true;error.value='';notice.value='';planFingerprint.value='';canApply.value=false
  try {
    const {data}=await axios.post('/products/cost-import/plan',{fingerprint:fingerprint.value,decisions:decisions.value})
    groups.value=data.groups;summary.value=data.summary;fx.value=data.fx
    planFingerprint.value=data.plan_fingerprint;canApply.value=data.can_apply
    notice.value=data.can_apply?'成本、ERP SKU、重复导入及汇率预检已通过。':'预检完成，仍有待处理项；不会写入成本。'
  }catch(e){error.value=errorMessage(e)}finally{busy.value=false}
}
function openEditor(key:string) {
  const group=groups.value.find(g=>g.group_key===key)
  if(!group)return
  selectedKey.value=key
  const prior=decisions.value[key] ?? {}
  editor.product_id=String(prior.product_id ?? group.product_match?.id ?? '')
  editor.source_variant_key=prior.source_variant_key ?? group.selected_source_variant_key ?? group.sources[0]?.source_variant_key ?? ''
  editor.manual=Boolean(prior.manual_cost_cny)
  editor.manual_cost=prior.manual_cost_cny ?? ''
  editor.reason=prior.reason ?? ''
  skuQuery.value=group.base_sku ?? ''
  skuSuggestions.value=group.erp_candidates
}
async function searchSku() {
  try {
    const {data}=await axios.get('/products/sku-search',{params:{q:skuQuery.value}})
    skuSuggestions.value=(data as Product[]).slice(0,30)
  }catch(e){error.value=errorMessage(e)}
}
function saveEditor() {
  if (!selected.value) return
  const choice:Decision={reason:editor.reason.trim()}
  if(editor.product_id)choice.product_id=Number(editor.product_id)
  if(editor.source_variant_key)choice.source_variant_key=editor.source_variant_key
  if(editor.manual)choice.manual_cost_cny=editor.manual_cost.trim()
  decisions.value={...decisions.value,[selected.value.group_key]:choice}
  selectedKey.value=''
  planFingerprint.value='';canApply.value=false
  void plan()
}
async function confirm() {
  if(!props.applyEnabled||!canApply.value||!planFingerprint.value) return
  if(!window.confirm('仅在隔离 Dev 写入已确认的人民币成本来源。此操作不会改写历史订单。确认继续？'))return
  busy.value=true;error.value=''
  try {
    const {data}=await axios.post('/products/cost-import/confirm',{
      fingerprint:fingerprint.value,plan_fingerprint:planFingerprint.value,decisions:decisions.value,
    })
    notice.value='Dev 成本导入完成：新增 '+data.created+' 条；幂等跳过 '+data.noop+' 条。'
    fingerprint.value='';planFingerprint.value='';canApply.value=false
    if(catalogOpen.value) await loadCatalog(1)
  }catch(e){error.value=errorMessage(e)}finally{busy.value=false}
}
</script>

<template>
  <Head title="月度产品成本导入 · BYL ERP" />
  <AuthenticatedLayout title="月度产品成本导入" :breadcrumbs="[{label:'产品',href:'/products'},{label:'产品列表',href:'/products/list'},'月度产品成本导入']">
    <section class="cost-import-page">
      <div class="page-heading">
        <div><p class="kicker">PRODUCT COST · CNY → USD</p><h2>月度进销存成本导入</h2>
          <p>Excel → 基础 SKU → ERP 匹配 → 冲突确认 → 美元成本 → 订单利润</p></div>
        <div class="page-actions"><a href="/products/list" class="btn subtle">返回产品列表</a>
          <button class="btn primary" :disabled="busy||!fingerprint" @click="plan">重新成本预检</button></div>
      </div>

      <div class="import-card">
        <div class="section-title"><strong>01 · 导入月进销存</strong><span>支持多月份 XLSX/XLS、单月份 CSV；读取“原成本单价”，不采用结算售价</span></div>
        <div class="upload-controls">
          <label>进销存文件<input type="file" accept=".xlsx,.xls,.csv" @change="onFile"></label>
          <label>数据年份<input v-model.number="year" type="number" min="2000" max="2100"></label>
          <label v-if="file?.name.toLowerCase().endsWith('.csv')">CSV 月份<select v-model.number="csvMonth"><option v-for="m in 12" :key="m" :value="m">{{m}} 月</option></select></label>
          <button class="btn primary" :disabled="!file||busy" @click="preview">{{busy?'处理中…':'读取并匹配 SKU'}}</button>
        </div>
        <p v-if="error" class="alert error" role="alert">{{error}}</p>
        <p v-if="notice" class="alert success" role="status">{{notice}}</p>
      </div>

      <template v-if="fingerprint">
        <div class="metrics">
          <div><span>原始成本行</span><strong>{{sourceSummary.cost_rows??0}}</strong></div>
          <div><span>基础 SKU 月组</span><strong>{{summary?.groups??0}}</strong></div>
          <div><span>可导入</span><strong>{{summary?.ready??0}}</strong></div>
          <div><span>需处理</span><strong class="warn">{{summary?.blocked??0}}</strong></div>
          <div><span>成本冲突组</span><strong class="warn">{{summary?.price_conflicts??0}}</strong></div>
          <div><span>原始排除行</span><strong>{{sourceSummary.excluded_rows??0}}</strong></div>
        </div>
        <div class="excluded-evidence" v-if="excluded.length">
          <div class="section-title"><strong>需复核的源文件行 · {{excluded.length}} 条（未进入成本预检）</strong>
            <button class="btn subtle" @click="excludedOpen=!excludedOpen">{{excludedOpen?'收起':'查看排除原因'}}</button></div>
          <div v-if="excludedOpen" class="excluded-table-wrap"><table class="excluded-table">
            <thead><tr><th>月份 / 来源</th><th>原始名称</th><th>原始成本</th><th>排除原因</th></tr></thead>
            <tbody><tr v-for="r in excluded" :key="r.source_sheet+'-'+r.source_row">
              <td>{{r.source_sheet}} #{{r.source_row}}</td><td>{{r.product_name||'—'}}</td>
              <td>{{r.raw_unit_cost||'—'}}</td><td>{{r.reason||'待复核'}}</td></tr></tbody>
          </table></div>
          <small>订单级、调整项或缺失可信成本的记录必须核查原始凭证后再重新上传，不允许直接写入 SKU 成本。</small>
        </div>
        <div class="fx-note">
          <strong>02 · 人民币成本与美元参考</strong>
          <span v-if="fx?.available">参考汇率：1 USD = ¥{{fx.usd_to_cny}} · {{fx.source}} · {{fx.reference_date??'日期未提供'}} {{fx.stale?'· 汇率可能过期':''}}</span>
          <span v-else class="warn">汇率不可用：{{fx?.reason??'请检查会计设置'}}；美元成本及确认将受阻</span>
          <small>人民币为产品成本来源真值；美元仅为当前管理参考，订单利润采用订单生效日及已冻结记账汇率。</small>
        </div>

        <div class="list-heading"><div><strong>03 · SKU 匹配和成本冲突</strong><small>点击右侧“快速确认”，可指定实际 ERP SKU、选择原始单价或手动填写人民币成本</small></div>
          <div class="filter-row"><input v-model="search" type="search" placeholder="查找全部月份 SKU / 名称" aria-label="搜索导入 SKU">
            <button v-for="t in [{key:'all',label:'全部'},{key:'ready',label:'可导入'},{key:'blocked',label:'待处理'},{key:'conflict',label:'成本冲突'},{key:'unmatched',label:'待匹配'},{key:'confirm',label:'待确认'}]"
              :key="t.key" :class="{active:filter===t.key}" @click="filter=t.key as typeof filter">{{t.label}}</button></div></div>

        <GlobalGrid namespace="grid.products.monthly_cost.v1" title="月度成本 · Global Grid VI"
          :rows="gridRows" :columns="columns" :required-column-ids="['base_sku','month']"
          row-identity-key="id" :show-search="false" :default-pinned-right="1" />

        <div class="import-footer">
          <div><strong>04 · 确认前检查</strong><p>预检新增 {{summary?.canonical_create??'—'}} 条；重复 {{summary?.canonical_noop??'—'}} 条；已有成本冲突 {{summary?.canonical_blocked??'—'}} 条。</p>
            <small>剔除或无法识别的原始行保留在本次上传证据中；不会自动发布为产品标准成本。</small></div>
          <div class="footer-actions"><button class="btn subtle" :disabled="busy" @click="plan">Dry-run 重新预检</button>
            <button class="btn primary" :disabled="busy||!props.applyEnabled||!canApply" @click="confirm">确认导入已核实成本</button></div>
        </div>
        <p v-if="!props.applyEnabled" class="locked-note">当前为隔离 Dev 预检模式：真实写入开关未开启；Owner 实际页面验收前不会应用成本。</p>
      </template>

      <section class="import-card catalog-card">
        <div class="section-title"><div><strong>05 · 已确认的美元产品成本表</strong><p>查看全部月份已入账的 SKU 成本；人民币为凭证，美元按当前管理参考汇率动态换算。</p></div>
          <button class="btn subtle" @click="toggleCatalog">{{catalogOpen?'收起成本表':'查看美元成本表'}}</button></div>
        <template v-if="catalogOpen">
          <div class="catalog-controls">
            <input v-model="catalogQuery" placeholder="ERP SKU / 产品名称" aria-label="已确认成本搜索" @keyup.enter="loadCatalog(1)">
            <input v-model="catalogMonth" type="month" aria-label="成本月份">
            <button class="btn primary" :disabled="catalogBusy" @click="loadCatalog(1)">{{catalogBusy?'查询中…':'筛选成本'}}</button>
          </div>
          <p v-if="catalogError" class="alert error">{{catalogError}}</p>
          <p class="catalog-meta">已确认成本记录 {{catalogTotal}} 条 · 第 {{catalogPage}} / {{catalogLastPage}} 页（每页 100 条）
            <span v-if="catalogFx?.available"> · 汇率 1 USD = ¥{{catalogFx.usd_to_cny}} · {{catalogFx.reference_date??'无日期'}}</span>
            <span v-else class="warn"> · 汇率不可用，无法显示人民币成本对应的美元值</span></p>
          <GlobalGrid namespace="grid.products.confirmed_cost_usd.v1" title="产品成本 · USD 管理参考"
            :rows="catalogGridRows" :columns="catalogColumns" :required-column-ids="['sku','month','usd']"
            row-identity-key="id" :show-search="false" />
          <div class="catalog-pagination"><button class="btn subtle" :disabled="catalogBusy||catalogPage<=1" @click="loadCatalog(catalogPage-1)">上一页</button>
            <button class="btn subtle" :disabled="catalogBusy||catalogPage>=catalogLastPage" @click="loadCatalog(catalogPage+1)">下一页</button></div>
          <p class="hint">这里是美元管理参考视图，不会覆盖历史订单。订单利润以订单日期匹配已确认成本，并按当时的记账汇率计算。</p>
        </template>
      </section>

      <div v-if="selected" class="editor-overlay" @click.self="selectedKey=''">
        <aside class="editor-panel">
          <header><div><small>{{selected.effective_from}} · SKU 快速确认</small><h3>{{selected.base_sku??'未提取基础 SKU'}}</h3></div><button class="close" @click="selectedKey=''">✕</button></header>
          <p v-if="selected.blockers.length" class="editor-warning">{{selected.blockers.map(x=>blockerLabels[x]??x).join('；')}}</p>
          <label class="editor-field">ERP SKU 搜索<div class="search-inline"><input v-model="skuQuery" placeholder="输入 SKU 或产品名" @keyup.enter="searchSku"><button @click="searchSku">搜索</button></div></label>
          <label class="editor-field">确认关联的 ERP 产品<select v-model="editor.product_id">
            <option value="">请选择 ERP 产品</option><option v-for="p in skuSuggestions" :key="p.id" :value="String(p.id)">{{p.sku}} · {{p.name}} (#{{p.id}})</option></select></label>
          <div class="editor-field"><strong>原始成本记录（CNY）</strong><p class="hint">选择一条作为来源；不会自动采用最后一行。</p>
            <label v-for="r in sourceOptions" :key="r.source_variant_key" class="source-option">
              <input v-model="editor.source_variant_key" type="radio" :value="r.source_variant_key">
              <span><b>¥{{r.unit_cost}}</b> · {{r.source_sheet}} 第 {{r.source_row}} 行
                <small>{{r.product_name}} · {{r.factory_code||'无长代码'}}</small>
                <small v-if="r.cost_value_source==='xlsx_cached_formula'">Excel 公式缓存值，原公式：{{r.raw_unit_cost}}</small>
              </span></label></div>
          <label class="manual-choice"><input v-model="editor.manual" type="checkbox"> 手动填写财务核实后的实际成本</label>
          <label v-if="editor.manual" class="editor-field">实际成本（人民币 ¥）<input v-model="editor.manual_cost" inputmode="decimal" placeholder="例如 28.00"></label>
          <label class="editor-field">确认理由 / 证据<textarea v-model="editor.reason" rows="3" placeholder="例：工厂 10 月对账单已确认；使用本版本成本（必填于人工处理）"></textarea></label>
          <div class="editor-actions"><button class="btn subtle" @click="selectedKey=''">取消</button><button class="btn primary" :disabled="busy" @click="saveEditor">保存并重新预检</button></div>
        </aside>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.cost-import-page{display:grid;gap:16px;color:#18382d;font-family:PingFang SC,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
.page-heading{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}
.kicker{font-size:11px;letter-spacing:.12em;color:#3e977b;font-weight:800}
.page-heading h2{font-size:25px;font-weight:700;margin:4px 0}
.page-heading p,.section-title span,.list-heading small,.import-footer small{font-size:12px;color:#657c72}
.page-actions,.footer-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end}
.btn{padding:9px 14px;border-radius:8px;border:1px solid #bed5c9;font-size:12px;font-weight:700;cursor:pointer}
.btn.primary{background:#176b4e;color:#fff;border-color:#176b4e}.btn.subtle{background:#fff;color:#25674f}
.btn:disabled{opacity:.5;cursor:not-allowed}
.excluded-evidence{border:1px solid #e5dcc5;background:#fffaf0;border-radius:10px;padding:13px}
.excluded-evidence small{display:block;color:#876f42;margin-top:8px;font-size:11px}
.excluded-table-wrap{max-height:260px;overflow:auto;margin-top:10px}
.excluded-table{width:100%;border-collapse:collapse;font-size:11px;background:#fff}
.excluded-table th,.excluded-table td{padding:8px;border:1px solid #e4dfd2;text-align:left}
.excluded-table th{position:sticky;top:0;background:#f5eedc}
.catalog-card .section-title p{font-size:12px;color:#648477;margin:6px 0 0}
.catalog-controls{display:flex;flex-wrap:wrap;gap:8px;margin-top:15px;align-items:center}
.catalog-controls input{padding:9px;border:1px solid #c7d8ce;border-radius:7px;min-width:155px}
.catalog-meta{font-size:12px;color:#496b5b;margin:12px 0}
.catalog-pagination{display:flex;justify-content:flex-end;gap:8px;margin-top:11px}
.import-card,.fx-note,.import-footer{border:1px solid #d6e6dc;background:#fff;border-radius:12px;padding:16px}
.section-title,.list-heading{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}
.section-title strong,.list-heading strong,.fx-note strong,.import-footer strong{font-size:15px}
.upload-controls{display:flex;align-items:end;gap:12px;flex-wrap:wrap;margin-top:15px}
.upload-controls label,.editor-field{display:grid;gap:6px;color:#496b5b;font-size:12px}
.upload-controls input,.upload-controls select,.editor-field input,.editor-field select,.editor-field textarea{border:1px solid #c7d8ce;border-radius:7px;background:#fff;padding:9px;color:#1e382f}
.upload-controls input[type=file]{max-width:330px}
.alert{padding:10px 12px;border-radius:7px;font-size:12px;margin-top:12px}.alert.error{background:#fff0ef;color:#a9322a}.alert.success{background:#eaf8ef;color:#236c46}
.metrics{display:grid;grid-template-columns:repeat(6,minmax(110px,1fr));gap:9px}
.metrics>div{border:1px solid #d6e6dc;background:#f8fcf9;border-radius:9px;padding:13px;display:grid;gap:5px}
.metrics span,.fx-note small{font-size:11px;color:#658475}.metrics strong{font-size:24px}.warn{color:#b85a27}
.fx-note{display:grid;gap:7px;background:#f5fbf7}.fx-note span{font-size:13px}
.list-heading{margin-top:4px}.list-heading>div:first-child{display:grid;gap:4px}
.filter-row{display:flex;align-items:center;flex-wrap:wrap;gap:5px}
.filter-row input{border:1px solid #cbded2;border-radius:7px;padding:7px 10px;min-width:190px;font-size:12px}
.filter-row button{padding:7px 9px;border:1px solid #cbded2;color:#44755c;background:#fff;border-radius:7px;font-size:12px;cursor:pointer}
.filter-row button.active{background:#176b4e;color:white}
.import-footer{display:flex;justify-content:space-between;align-items:center;gap:20px}.import-footer p{margin:5px 0;font-size:12px;color:#496b5b}
.locked-note{margin:0;font-size:12px;color:#986424;background:#fff9e8;padding:11px 13px;border-radius:7px}
.editor-overlay{position:fixed;inset:0;z-index:1000;background:rgba(18,40,31,.45);display:flex;justify-content:flex-end}
.editor-panel{width:min(510px,100vw);height:100%;overflow:auto;background:#fff;padding:22px;box-shadow:-10px 0 30px rgba(0,0,0,.1);display:grid;gap:16px;align-content:start}
.editor-panel header{display:flex;justify-content:space-between;align-items:start}.editor-panel header h3{font-size:22px;margin:4px 0}.editor-panel header small{font-size:12px;color:#789184}
.close{background:none;border:0;color:#567164;cursor:pointer;font-size:19px}
.editor-warning{margin:0;background:#fff3e9;border:1px solid #f1d4b3;color:#a95d27;padding:9px;border-radius:7px;font-size:12px}
.search-inline{display:flex;gap:7px}.search-inline input{flex:1;min-width:0}.search-inline button{border:1px solid #b8d3c2;border-radius:7px;background:#eaf5ed;padding:8px 12px;cursor:pointer}
.hint{color:#789184;font-size:11px;margin:3px 0 8px}.source-option{display:flex;align-items:start;gap:8px;border:1px solid #e1eae3;border-radius:8px;padding:10px;margin:7px 0;font-size:12px}
.source-option input{margin-top:5px}.source-option span{display:grid;gap:5px}.source-option small{color:#698676;font-size:11px;overflow-wrap:anywhere}
.manual-choice{display:flex;align-items:center;gap:8px;font-size:13px}.editor-actions{display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #e1e9e3;padding-top:15px}
@media(max-width:850px){.metrics{grid-template-columns:repeat(3,minmax(100px,1fr))}.import-footer{align-items:start;flex-direction:column}}
@media(max-width:530px){.metrics{grid-template-columns:repeat(2,minmax(100px,1fr))}.editor-panel{padding:15px}.page-heading h2{font-size:21px}}
</style>
