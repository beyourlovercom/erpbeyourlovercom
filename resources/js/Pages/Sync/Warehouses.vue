<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { onMounted, onUnmounted, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Counts = { provider:number; matched:number; unmatched:number; blocked:number }
type Warehouse = {
  id:number; code:string; name:string; country_code:string|null
  is_active:boolean; connector_status:string; can_sync:boolean
  last_sync_at:string|null; last_applied_at:string|null
  last_status_at:string|null; last_sync_status:string; last_error_code:string|null
  active_run_id:number|null; sync_counts:Counts
}
type SyncResult = {
  run_id:number; status:string; mode:'read_only'|'apply_if_verified'
  erp_stock_updated:boolean; provider_sku_count:number; mapped_sku_count:number
  unmatched_sku_count:number; blocked_sku_count:number; error_code:string|null
  finished_at:string|null
}
type ResolutionFilter = 'unmatched'|'matched'|'blocked'|'catalog_only'|'all'
type InventoryRow = {
  provider_sku:string; erp_sku:string|null; resolution_status:string
  oms_total:number|null; oms_available:number|null; oms_locked:number|null
  oms_in_transit:number|null; mismatch_reason:string|null
}
type ManualRun = {
  id:number; status:string; mode:'read_only'|'apply_if_verified'
  snapshot_id:number|null; provider_sku_count:number; mapped_sku_count:number
  unmatched_sku_count:number; blocked_sku_count:number; error_code:string|null
  created_at:string; finished_at:string|null
}
type Details = {
  snapshot: null | { id:number; status:string; fetched_at:string
    applied_at:string|null; erp_stock_updated:boolean; provider_sku_count:number
    mapped_sku_count:number; unmatched_sku_count:number; blocked_sku_count:number }
  counts:Record<'matched'|'unmatched'|'blocked'|'catalog_only',number>
  rows:InventoryRow[]; pagination:{page:number; per_page:number; total:number}
  runs:ManualRun[]
}
const props = defineProps<{ warehouses:Warehouse[] }>()
const resultById = reactive<Record<number,SyncResult>>({})
const errors = reactive<Record<number,string>>({})
const busy = reactive<Record<number,boolean>>({})
const timers = new Map<number,ReturnType<typeof setInterval>>()
const expandedId = ref<number|null>(null)
const details = ref<Details|null>(null)
const detailLoading = ref(false)
const detailError = ref('')
const detailFilter = ref<ResolutionFilter>('unmatched')
const detailSearch = ref('')
const detailPage = ref(1)
let detailRequestSerial = 0


const labels:Record<string,string> = {
  never:'尚未同步', queued:'排队中', running:'同步中',
  read_only:'读取成功 · 未更新 ERP', applied:'ERP 库存已同步',
  partial_applied:'部分库存已同步', blocked:'OMS 已读取 · 待处理 SKU 差异',
  not_ready:'库存数据未就绪', failed:'同步失败',
}
function statusOf(w:Warehouse) { return resultById[w.id]?.status || w.last_sync_status }
function statusLabel(w:Warehouse) { return labels[statusOf(w)] || statusOf(w) }
function countsOf(w:Warehouse):Counts {
  const result=resultById[w.id]
  return result && !['queued','running'].includes(result.status)
    ? {provider:result.provider_sku_count,matched:result.mapped_sku_count,
        unmatched:result.unmatched_sku_count,blocked:result.blocked_sku_count}
    : w.sync_counts
}
function timeOf(w:Warehouse) { return resultById[w.id]?.finished_at || w.last_status_at }
function dateLabel(value:string|null) {
  if (!value)return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('zh-CN',{
    year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hour12:false,
  }).format(date)
}
function token() { return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '' }
function errorLabel(code:string) {
  const map:Record<string,string>={
    XLWMS_DE_CONNECTOR_NOT_CONFIGURED:'德国仓 Connector 尚未配置完成',
    XLWMS_DE_SYNC_LOCKED:'其他操作员正在创建同步任务',
    XLWMS_DE_SYNC_ALREADY_RUNNING:'已有同步任务执行中，请等待结果',
    XLWMS_DE_SYNC_QUEUE_UNAVAILABLE:'后台队列不可用，任务已标记失败',
    XLWMS_DE_SYNC_TIMEOUT:'后台同步超时，可检查队列后重新读取',
    XLWMS_DE_SYNC_PROVIDER_FAILED:'OMS 读取失败，请检查 API 连通性后重试',
    XLWMS_DE_SYNC_WORKER_BUSY:'后台已有库存同步任务，稍后重试',
    XLWMS_DE_SYNC_WORKER_INTERRUPTED:'同步任务中断，检查队列后重试',
    XLWMS_DE_SYNC_NOT_AUTHORIZED:'执行用户权限不足，请联系管理员',
    XLWMS_DE_SYNC_APPLY_GATES_CHANGED:'库存写入保护条件已改变，本次未执行写入',
  }
  return map[code] || ('同步未完成：'+code)
}
async function requestJson(url:string, method:'GET'|'POST'='GET') {
  const response=await fetch(url,{method,credentials:'same-origin',
    headers:{Accept:'application/json',...(method==='POST'?{'X-CSRF-TOKEN':token()}:{} )}})
  const data=await response.json()
  if(!response.ok)throw new Error(errorLabel(data.error_code||String(response.status)))
  return data
}
function resolutionLabel(code:string) {
  const labels:Record<string,string>={
    matched:'已匹配 ERP SKU', unmatched:'未匹配', blocked:'库存保护阻断',
    catalog_only:'无本仓库存台账',
  }
  return labels[code] || code
}
function reasonLabel(code:string|null) {
  if(!code)return '—'
  const labels:Record<string,string>={
    OMS_SKU_NOT_VERIFIED_ERP_IDENTITY:'ERP 尚无经过验证的对应 SKU',
    ABSENT_WAREHOUSE_STOCK_LEDGER:'OMS 产品目录存在，但本仓没有库存记录',
    ERP_LOCAL_RESERVATIONS_EXCEED_OMS_PHYSICAL_QOH:'ERP 本地预留库存超过 OMS 实物库存',
    LOT_RECONCILIATION_REQUIRED:'需要先完成批次库存核对',
    ABSENT_LEDGER_NOT_READY:'本仓库存台账未就绪',
  }
  return labels[code] || code
}
function stockLabel(qty:number|null) { return qty===null?'—':String(qty) }
async function loadDetails(w:Warehouse,page=1) {
  const serial=++detailRequestSerial
  detailPage.value=page
  detailLoading.value=true
  detailError.value=''
  const query=new URLSearchParams({status:detailFilter.value,page:String(page)})
  if(detailSearch.value.trim())query.set('search',detailSearch.value.trim())
  try {
    const data=await requestJson('/sync/warehouses/'+w.id+'/sync-details?'+query.toString()) as Details
    if(serial===detailRequestSerial && expandedId.value===w.id)details.value=data
  } catch(e) {
    if(serial===detailRequestSerial)detailError.value=e instanceof Error?e.message:'无法读取同步明细'
  } finally {
    if(serial===detailRequestSerial)detailLoading.value=false
  }
}
function toggleDetails(w:Warehouse) {
  if(w.code!=='DE')return
  if(expandedId.value===w.id) {
    expandedId.value=null
    ++detailRequestSerial
    return
  }
  expandedId.value=w.id
  detailFilter.value='unmatched'
  detailSearch.value=''
  details.value=null
  void loadDetails(w)
}
function stopPolling(id:number) {
  const timer=timers.get(id)
  if(timer)clearInterval(timer)
  timers.delete(id)
}
async function poll(w:Warehouse, runId:number) {
  try {
    const result=await requestJson('/sync/warehouses/'+w.id+'/sync/'+runId) as SyncResult
    resultById[w.id]=result
    if(!['queued','running'].includes(result.status)) {
      stopPolling(w.id)
      if(expandedId.value===w.id)void loadDetails(w,detailPage.value)
    }
  } catch(e) {
    errors[w.id]=e instanceof Error?e.message:'无法读取同步状态'
    stopPolling(w.id)
  }
}
function watchRun(w:Warehouse,runId:number) {
  stopPolling(w.id)
  timers.set(w.id,setInterval(()=>void poll(w,runId),1500))
  void poll(w,runId)
}
async function startSync(w:Warehouse) {
  if(!w.can_sync || busy[w.id] || ['queued','running'].includes(statusOf(w)))return
  busy[w.id]=true;errors[w.id]=''
  try {
    const data=await requestJson('/sync/warehouses/'+w.id+'/sync','POST') as {
      run_id:number;status:string;mode:'read_only'|'apply_if_verified'
    }
    resultById[w.id]={run_id:data.run_id,status:data.status,mode:data.mode,
      erp_stock_updated:false,provider_sku_count:0,mapped_sku_count:0,
      unmatched_sku_count:0,blocked_sku_count:0,error_code:null,finished_at:null}
    watchRun(w,data.run_id)
  } catch(e) {
    errors[w.id]=e instanceof Error?e.message:'同步操作失败'
  } finally {busy[w.id]=false}
}
onMounted(()=>{
  props.warehouses.forEach(w=>{
    if(w.active_run_id && w.can_sync)watchRun(w,w.active_run_id)
  })
  if(new URLSearchParams(window.location.search).get('details')==='DE') {
    const de=props.warehouses.find(w=>w.code==='DE')
    if(de)toggleDetails(de)
  }
})
onUnmounted(()=>{++detailRequestSerial;for(const id of timers.keys())stopPolling(id)})
</script>

<template>
  <Head title="同步仓库 · BYL ERP" />
  <AuthenticatedLayout title="同步仓库" :breadcrumbs="['BYL ERP', '同步', '同步仓库']">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">WAREHOUSE SYNC</p>
          <h2>同步仓库</h2>
          <p>查看真实 Connector 与库存同步结果；点击仓库最右侧按钮可手动刷新。</p>
        </div>
        <a class="mvp-button" href="/sync">返回同步总览</a>
      </div>
      <div class="mvp-table-wrap"><table class="mvp-table">
        <thead><tr>
          <th>仓库</th><th>代码</th><th>国家/地区</th><th>状态</th>
          <th>Connector</th><th>最后同步</th><th>同步操作</th>
        </tr></thead>
        <tbody>
          <template v-for="warehouse in warehouses" :key="warehouse.id">
          <tr>
            <td>{{warehouse.name}}</td><td>{{warehouse.code}}</td>
            <td>{{warehouse.country_code ?? '—'}}</td>
            <td><span class="mvp-status">{{warehouse.is_active?'active':'inactive'}}</span></td>
            <td><span class="mvp-status">{{warehouse.connector_status==='not_configured'?'未配置':'已配置'}}</span></td>
            <td>
              <span :data-testid="'warehouse-sync-status-'+warehouse.code">{{statusLabel(warehouse)}}</span>
              <small v-if="timeOf(warehouse)" style="display:block">{{dateLabel(timeOf(warehouse))}}</small>
              <small v-if="statusOf(warehouse)!=='never' && !['queued','running'].includes(statusOf(warehouse))" style="display:block">
                OMS 产品 {{countsOf(warehouse).provider}} · 匹配 {{countsOf(warehouse).matched}}
                · 未匹配 {{countsOf(warehouse).unmatched}} · 阻断 {{countsOf(warehouse).blocked}}
              </small>
              <small v-if="resultById[warehouse.id]?.error_code || warehouse.last_error_code" style="display:block;color:#b42318">
                {{resultById[warehouse.id]?.error_code || warehouse.last_error_code}}
              </small>
            </td>
            <td>
              <div class="de-actions">
                <button v-if="warehouse.code==='DE'" type="button" class="mvp-button" :data-testid="'warehouse-sync-details-'+warehouse.code"
                  :aria-expanded="expandedId===warehouse.id" @click="toggleDetails(warehouse)">
                  {{expandedId===warehouse.id?'收起明细':'查看同步明细'}}
                </button>
              <button type="button" class="mvp-button" :data-testid="'warehouse-sync-button-'+warehouse.code"
                :disabled="!warehouse.can_sync || !!busy[warehouse.id] || ['queued','running'].includes(statusOf(warehouse))"
                @click="startSync(warehouse)">
                {{['queued','running'].includes(statusOf(warehouse))?'同步中…':'立即同步'}}
              </button>
              </div>
              <small v-if="!warehouse.can_sync" style="display:block">暂未开放</small>
              <small v-if="errors[warehouse.id]" role="alert" style="display:block;color:#b42318">{{errors[warehouse.id]}}</small>
            </td>
          </tr>
          <tr v-if="expandedId===warehouse.id" class="de-details-row">
            <td colspan="7" class="de-detail-cell">
              <section class="de-detail-panel" aria-label="德国天运库存同步明细" data-testid="de-sync-details-panel">
                <div class="de-detail-head">
                  <div>
                    <h3>德国天运 · 同步明细</h3>
                    <p>读取真实 OMS 库存快照，逐条核对 ERP SKU；此处不写入 ERP 库存。</p>
                  </div>
                  <button type="button" class="mvp-button" :disabled="detailLoading" @click="loadDetails(warehouse,detailPage)">刷新明细</button>
                </div>
                <p v-if="detailLoading" role="status" class="de-loading">正在读取本地同步记录…</p>
                <p v-if="detailError" role="alert" class="de-error">{{detailError}}</p>
                <template v-if="details">
                  <div v-if="details.snapshot" class="de-metrics" data-testid="de-sync-summary">
                    <div><strong>{{details.snapshot.provider_sku_count}}</strong><span>OMS 产品目录 SKU</span></div>
                    <div><strong>{{details.counts.matched+details.counts.unmatched+details.counts.blocked}}</strong><span>有本仓库存记录</span></div>
                    <div><strong>{{details.counts.matched}}</strong><span>ERP SKU 已匹配</span></div>
                    <div class="de-metric-warning"><strong>{{details.counts.unmatched}}</strong><span>SKU 未匹配</span></div>
                    <div><strong>{{details.snapshot.erp_stock_updated?'已执行':'未执行'}}</strong><span>ERP 库存写入</span></div>
                  </div>
                  <p v-if="details.snapshot" class="de-snapshot-line">
                    快照 #{{details.snapshot.id}} · {{dateLabel(details.snapshot.fetched_at)}} ·
                    {{details.snapshot.erp_stock_updated?'已通过库存写入流程':'OMS 已读取，尚未更新 ERP'}}
                  </p>
                  <p v-else class="de-note">还没有库存快照；可先点击上方「立即同步」创建只读读取记录。</p>
                  <div class="de-detail-toolbar">
                    <label for="de-status-filter">SKU 匹配状态</label>
                    <select id="de-status-filter" v-model="detailFilter" data-testid="de-status-filter"
                      @change="loadDetails(warehouse,1)">
                      <option value="unmatched">未匹配（{{details.counts.unmatched}}）</option>
                      <option value="matched">已匹配（{{details.counts.matched}}）</option>
                      <option value="blocked">被阻断（{{details.counts.blocked}}）</option>
                      <option value="catalog_only">目录中但无本仓台账（{{details.counts.catalog_only}}）</option>
                      <option value="all">全部 SKU</option>
                    </select>
                    <form class="de-search-form" @submit.prevent="loadDetails(warehouse,1)">
                      <input v-model="detailSearch" type="search" aria-label="搜索 OMS SKU" data-testid="de-sku-search"
                        placeholder="搜索 OMS SKU" maxlength="128" />
                      <button class="mvp-button" type="submit">搜索</button>
                    </form>
                  </div>
                  <div class="de-table-scroll">
                    <table class="de-table" aria-label="SKU 匹配明细">
                      <thead><tr>
                        <th>OMS SKU</th><th>ERP SKU</th><th>OMS 总库存</th><th>可用</th><th>锁定</th><th>在途</th>
                        <th>匹配状态</th><th>错误 / 差异原因</th>
                      </tr></thead>
                      <tbody>
                        <tr v-for="row in details.rows" :key="row.provider_sku" data-testid="de-sku-row">
                          <td class="de-sku">{{row.provider_sku}}</td>
                          <td>{{row.erp_sku||'—'}}</td>
                          <td>{{stockLabel(row.oms_total)}}</td>
                          <td>{{stockLabel(row.oms_available)}}</td>
                          <td>{{stockLabel(row.oms_locked)}}</td>
                          <td>{{stockLabel(row.oms_in_transit)}}</td>
                          <td><span :class="['de-resolution', 'de-resolution-'+row.resolution_status]">{{resolutionLabel(row.resolution_status)}}</span></td>
                          <td>{{reasonLabel(row.mismatch_reason)}}</td>
                        </tr>
                        <tr v-if="details.rows.length===0"><td colspan="8" class="de-empty">当前筛选条件没有 SKU</td></tr>
                      </tbody>
                    </table>
                  </div>
                  <div class="de-pagination">
                    <span>共 {{details.pagination.total}} 条 · 第 {{details.pagination.page}} 页</span>
                    <button type="button" class="mvp-button" data-testid="de-page-prev"
                      :disabled="detailLoading||detailPage<=1" @click="loadDetails(warehouse,detailPage-1)">上一页</button>
                    <button type="button" class="mvp-button" data-testid="de-page-next"
                      :disabled="detailLoading||detailPage*details.pagination.per_page>=details.pagination.total"
                      @click="loadDetails(warehouse,detailPage+1)">下一页</button>
                  </div>
                  <h4 class="de-history-title">执行 / 重试记录（最近 12 次）</h4>
                  <div class="de-table-scroll">
                    <table class="de-table" aria-label="同步重试记录">
                      <thead><tr>
                        <th>运行编号</th><th>执行时间</th><th>结果</th><th>模式</th><th>已匹配 / 未匹配</th><th>失败原因</th>
                      </tr></thead>
                      <tbody>
                        <tr v-for="run in details.runs" :key="run.id" data-testid="de-run-row">
                          <td>#{{run.id}}</td>
                          <td>{{dateLabel(run.created_at)}}</td>
                          <td>{{labels[run.status]||run.status}}</td>
                          <td>{{run.mode==='read_only'?'只读':'验证后写入'}}</td>
                          <td>{{run.mapped_sku_count}} / {{run.unmatched_sku_count}}</td>
                          <td>{{run.error_code?errorLabel(run.error_code):'—'}}</td>
                        </tr>
                        <tr v-if="details.runs.length===0"><td colspan="6" class="de-empty">暂无执行记录</td></tr>
                      </tbody>
                    </table>
                  </div>
                  <p class="de-note">未匹配时，请核对 ERP 产品主数据中的现有 SKU；重复读取不会自动创建商品或修复映射。只有“ERP 库存已同步”才表示完成库存写入。</p>
                </template>
              </section>
            </td>
          </tr>
          </template>
          <tr v-if="warehouses.length===0"><td colspan="7" class="mvp-empty">暂无仓库</td></tr>
        </tbody>
      </table></div>
      <p class="mvp-muted">“读取成功”只表示 OMS 快照已保存；只有“ERP 库存已同步”才表示库存写入。当前仅德国天运仓在隔离 Dev 开放主动同步；其他仓库按钮保持禁用。</p>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.de-actions{display:flex;justify-content:flex-end;align-items:center;gap:8px;flex-wrap:wrap}
.de-detail-cell{padding:0!important;background:#f3f7fa}
.de-detail-panel{padding:14px 16px 16px;color:#274156}
.de-detail-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px}
.de-detail-head h3{font-size:17px;font-weight:700;margin:0 0 3px;color:#163146}
.de-detail-head p{font-size:12px;color:#61798b;margin:0}
.de-metrics{display:grid;grid-template-columns:repeat(5,minmax(110px,1fr));gap:8px;margin:10px 0}
.de-metrics>div{background:white;border:1px solid #deebf0;border-radius:8px;padding:9px 11px;display:flex;flex-direction:column;gap:3px}
.de-metrics strong{font-size:20px;line-height:1.15;font-weight:700;color:#147b75}
.de-metrics span{font-size:11px;color:#5b7182}
.de-metrics .de-metric-warning strong{color:#b55327}
.de-snapshot-line{font-size:12px;color:#526d7d;margin:4px 0 12px}
.de-detail-toolbar{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:12px 0 8px;font-size:12px}
.de-detail-toolbar label{font-weight:600}
.de-detail-toolbar select,.de-search-form input{height:34px;min-width:155px;border:1px solid #cbdce6;border-radius:6px;padding:4px 9px;background:#fff;color:#234054}
.de-search-form{display:flex;align-items:center;gap:6px;margin-left:auto}
.de-search-form input{min-width:215px}
.de-table-scroll{overflow-x:auto;border:1px solid #dbe5eb;border-radius:8px;background:white}
.de-table{width:100%;border-collapse:collapse;min-width:780px;font-size:12px;text-align:left}
.de-table th{color:#506b7b;background:#f8fafc;font-weight:600;padding:8px 10px;border-bottom:1px solid #dbe5eb;white-space:nowrap}
.de-table td{padding:7px 10px;border-bottom:1px solid #edf1f4;vertical-align:middle}
.de-table tbody tr:last-child td{border-bottom:none}
.de-table th:first-child,.de-table td:first-child{position:sticky;left:0;background:inherit;z-index:1}
.de-table .de-sku{font-weight:650;white-space:nowrap;color:#096f6a}
.de-resolution{display:inline-block;border-radius:5px;padding:3px 6px;white-space:nowrap;background:#e7f4f2;color:#13685d}
.de-resolution-unmatched,.de-resolution-blocked{background:#fff0e2;color:#9b4b23}
.de-resolution-catalog_only{background:#e9eff5;color:#4e6678}
.de-pagination{display:flex;justify-content:flex-end;align-items:center;gap:8px;font-size:12px;margin:8px 0 14px;color:#60788a}
.de-history-title{font-size:14px;font-weight:700;margin:10px 0 7px}
.de-note{font-size:12px;color:#60798b;margin:9px 0 0}
.de-loading{font-size:12px;color:#27586f;margin:5px 0}
.de-error{padding:8px 10px;border-radius:6px;background:#fff0ef;color:#a62525;font-size:12px}
.de-empty{text-align:center;color:#7e909d;padding:14px!important}
@media(max-width:900px){
  .de-metrics{grid-template-columns:repeat(2,minmax(120px,1fr))}
  .de-search-form{margin-left:0}
  .de-detail-panel{padding:12px}
  .de-detail-head{align-items:flex-start;flex-wrap:wrap}
}
</style>
