<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { onMounted, onUnmounted, reactive } from 'vue'
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
const props = defineProps<{ warehouses:Warehouse[] }>()
const resultById = reactive<Record<number,SyncResult>>({})
const errors = reactive<Record<number,string>>({})
const busy = reactive<Record<number,boolean>>({})
const timers = new Map<number,ReturnType<typeof setInterval>>()

const labels:Record<string,string> = {
  never:'尚未同步', queued:'排队中', running:'同步中',
  read_only:'读取成功 · 未更新 ERP', applied:'ERP 库存已同步',
  partial_applied:'部分库存已同步', blocked:'已读取 · 存在差异',
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
function stopPolling(id:number) {
  const timer=timers.get(id)
  if(timer)clearInterval(timer)
  timers.delete(id)
}
async function poll(w:Warehouse, runId:number) {
  try {
    const result=await requestJson('/sync/warehouses/'+w.id+'/sync/'+runId) as SyncResult
    resultById[w.id]=result
    if(!['queued','running'].includes(result.status))stopPolling(w.id)
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
onMounted(()=>props.warehouses.forEach(w=>{
  if(w.active_run_id && w.can_sync)watchRun(w,w.active_run_id)
}))
onUnmounted(()=>{for(const id of timers.keys())stopPolling(id)})
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
      </div>
      <div class="mvp-table-wrap"><table class="mvp-table">
        <thead><tr>
          <th>仓库</th><th>代码</th><th>国家/地区</th><th>状态</th>
          <th>Connector</th><th>最后同步</th><th>同步操作</th>
        </tr></thead>
        <tbody>
          <tr v-for="warehouse in warehouses" :key="warehouse.id">
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
              <button type="button" class="mvp-button" :data-testid="'warehouse-sync-button-'+warehouse.code"
                :disabled="!warehouse.can_sync || !!busy[warehouse.id] || ['queued','running'].includes(statusOf(warehouse))"
                @click="startSync(warehouse)">
                {{['queued','running'].includes(statusOf(warehouse))?'同步中…':'立即同步'}}
              </button>
              <small v-if="!warehouse.can_sync" style="display:block">暂未开放</small>
              <small v-if="errors[warehouse.id]" role="alert" style="display:block;color:#b42318">{{errors[warehouse.id]}}</small>
            </td>
          </tr>
          <tr v-if="warehouses.length===0"><td colspan="7" class="mvp-empty">暂无仓库</td></tr>
        </tbody>
      </table></div>
      <p class="mvp-muted">“读取成功”只表示 OMS 快照已保存；只有“ERP 库存已同步”才表示库存写入。当前仅德国天运仓在隔离 Dev 开放主动同步；其他仓库按钮保持禁用。</p>
      <a class="mvp-button" href="/sync">返回同步总览</a>
    </section>
  </AuthenticatedLayout>
</template>
