<script setup lang="ts">
import {Head} from '@inertiajs/vue3'
import axios from 'axios'
import {computed, reactive, ref} from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Status = 'ready'|'unchanged'|'duplicate'|'ambiguous_variant'|'conflict_existing'|'ambiguous_identity'|'unmatched'|'invalid'|'resolved'
type ImportRow = {
  sheet:string;source_row:number;material_code:string;material_full_name:string;status:Status
  product_id:number|null;product_sku:string|null;current_name:string|null
}
type ProductHit = {id:number;sku:string;name:string;matched_alias?:string|null}
const rows=ref<ImportRow[]>([])
const fingerprint=ref('')
const file=ref<File|null>(null)
const uploading=ref(false)
const applying=ref(false)
const error=ref('')
const notice=ref('')
const statusFilter=ref('needs')
const search=ref('')
const page=ref(1)
const pageSize=50
const counts=ref<Record<string,number>>({})
const statusText:Record<Status,string>={
  ready:'唯一匹配·待填空',unchanged:'已相同',duplicate:'同名重复',
  ambiguous_variant:'同 SKU 多版本·需确认',conflict_existing:'已有名称·需确认覆盖',
  ambiguous_identity:'SKU 匹配冲突',unmatched:'未匹配产品',invalid:'源数据无效',resolved:'已人工确认',
}
const count=(status:Status)=>counts.value[status]??0
const filtered=computed(()=>rows.value.map((row,index)=>({...row,index})).filter(row=>{
  if(statusFilter.value==='ready'&&row.status!=='ready')return false
  if(statusFilter.value==='needs'&&!['ambiguous_variant','conflict_existing','ambiguous_identity','unmatched','invalid'].includes(row.status))return false
  if(statusFilter.value!=='all'&&statusFilter.value!=='needs'&&statusFilter.value!=='ready'&&row.status!==statusFilter.value)return false
  const term=search.value.trim().toLowerCase()
  return !term||[row.material_code,row.material_full_name,row.product_sku??''].some(value=>value.toLowerCase().includes(term))
}))
const maxPage=computed(()=>Math.max(1,Math.ceil(filtered.value.length/pageSize)))
const visible=computed(()=>filtered.value.slice((page.value-1)*pageSize,page.value*pageSize))
const editIndex=ref<number|null>(null)
const manual=reactive({product_id:0,material_full_name:'',overwrite_confirmed:false,sku_search:''})
const hits=ref<ProductHit[]>([])
const rowBusy=ref(false)
function selectFile(event:Event){file.value=(event.target as HTMLInputElement).files?.[0]??null}
function message(e:unknown):string{
  if(axios.isAxiosError(e)){
    const data=e.response?.data as {message?:string;errors?:Record<string,string[]>}|undefined
    return Object.values(data?.errors??{})[0]?.[0]??data?.message??'请求失败，请重试'
  }
  return e instanceof Error?e.message:'操作失败'
}
async function preview(){
  if(!file.value||uploading.value)return
  error.value='';notice.value='';uploading.value=true
  try{
    const body=new FormData();body.append('file',file.value)
    const {data}=await axios.post('/products/material-names/import/preview',body,{headers:{Accept:'application/json'}})
    rows.value=data.rows;counts.value=data.counts;fingerprint.value=data.fingerprint
    statusFilter.value='needs';page.value=1;editIndex.value=null
    notice.value='预检完成，尚未写入产品资料。请检查冲突后确认。'
  }catch(e){error.value=message(e)}
  finally{uploading.value=false}
}
async function applyReady(){
  if(!fingerprint.value||applying.value)return
  applying.value=true;error.value='';notice.value=''
  try{
    const {data}=await axios.post('/products/material-names/import/apply',{fingerprint:fingerprint.value})
    rows.value=data.rows;counts.value=data.counts
    notice.value='已写入 '+data.updated+' 条唯一匹配且原字段为空的物料全称。冲突仍需逐行确认。'
  }catch(e){error.value=message(e)}
  finally{applying.value=false}
}
function beginResolve(row:ImportRow,index:number){
  editIndex.value=index;hits.value=[];error.value=''
  manual.product_id=row.product_id??0
  manual.material_full_name=row.material_full_name
  manual.overwrite_confirmed=false
  manual.sku_search=row.product_sku??''
}
async function findSku(){
  if(!manual.sku_search.trim())return
  error.value=''
  try{
    const {data}=await axios.get<ProductHit[]>('/products/sku-search',{params:{q:manual.sku_search}})
    hits.value=data
    if(!hits.value.length)error.value='没有找到对应的 ERP 产品，请核对 SKU。'
  }catch(e){error.value=message(e)}
}
async function resolve(){
  if(editIndex.value===null||rowBusy.value||!manual.product_id)return
  rowBusy.value=true;error.value='';notice.value=''
  try{
    const {data}=await axios.post('/products/material-names/import/resolve',{
      fingerprint:fingerprint.value,row_index:editIndex.value,
      product_id:manual.product_id,material_full_name:manual.material_full_name,
      overwrite_confirmed:manual.overwrite_confirmed,
    })
    rows.value[editIndex.value]=data.row;counts.value=data.counts
    editIndex.value=null;hits.value=[]
    notice.value='此行已核对并保存到指定产品。'
  }catch(e){error.value=message(e)}
  finally{rowBusy.value=false}
}
</script>

<template>
  <Head title="导入物料全称 · BYL ERP"/>
  <AuthenticatedLayout title="导入物料全称" :breadcrumbs="[{label:'产品列表',href:'/products/list'},'导入物料全称']">
    <main class="material-import">
      <div class="material-import-head">
        <div><h1>导入物料全称</h1><p>仅写入 ERP 内部物料名称，不修改 Woo 产品名称、订单、库存或物流状态。</p></div>
        <a href="/products/list" class="material-link">返回产品列表</a>
      </div>
      <section class="material-upload">
        <label for="material-file">选择库存 / 物料编码 XLSX</label>
        <input id="material-file" type="file" accept=".xlsx" @change="selectFile"/>
        <button type="button" :disabled="!file||uploading" @click="preview">{{uploading?'解析中…':'上传并预览'}}</button>
        <p>优先识别「物料代码」页的旧系统物料代码、物料名称两列。相同 SKU 多版本必须人工确认。</p>
      </section>
      <p v-if="error" role="alert" class="material-error">{{error}}</p>
      <p v-if="notice" role="status" class="material-notice">{{notice}}</p>
      <template v-if="fingerprint">
        <div class="material-summary">
          <span>总行数 <b>{{rows.length}}</b></span>
          <span>唯一待填空 <b>{{count('ready')}}</b></span>
          <span>多版本 <b>{{count('ambiguous_variant')}}</b></span>
          <span>已有名称冲突 <b>{{count('conflict_existing')}}</b></span>
          <span>未匹配 <b>{{count('unmatched')}}</b></span>
          <span>无效 <b>{{count('invalid')}}</b></span>
          <span>已相同/重复 <b>{{count('unchanged')+count('duplicate')}}</b></span>
        </div>
        <div class="material-actions">
          <button type="button" :disabled="!count('ready')||applying" @click="applyReady">
            {{applying?'写入中…':`确认写入 ${count('ready')} 条唯一匹配`}}
          </button>
          <small>只补空值；有多版本、已有名称、身份冲突的行不会自动写入。</small>
        </div>
        <div class="material-filters">
          <label>查看
            <select v-model="statusFilter" @change="page=1">
              <option value="needs">待人工核对</option><option value="ready">唯一匹配·待写入</option>
              <option value="all">全部</option><option value="ambiguous_variant">SKU 多版本</option>
              <option value="conflict_existing">已有名称冲突</option><option value="unmatched">未匹配</option>
              <option value="invalid">无效</option><option value="resolved">已确认</option>
            </select>
          </label>
          <label>搜索 <input v-model="search" type="search" placeholder="SKU / 物料编码 / 物料全称" @input="page=1"/></label>
          <span>当前 {{filtered.length}} 行</span>
        </div>
        <div class="material-table-wrap">
          <table class="material-table">
            <thead><tr><th>源行</th><th>旧物料代码</th><th>物料全称（原文）</th><th>匹配产品</th><th>ERP 现有值</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
              <template v-for="row in visible" :key="row.index">
                <tr>
                  <td>{{row.source_row}}</td><td class="mono">{{row.material_code||'—'}}</td>
                  <td class="full-name" :title="row.material_full_name">{{row.material_full_name||'—'}}</td>
                  <td>{{row.product_sku??'—'}}<small v-if="row.product_id">#{{row.product_id}}</small></td>
                  <td class="full-name" :title="row.current_name??''">{{row.current_name??'—'}}</td>
                  <td><span class="status" :class="row.status">{{statusText[row.status]}}</span></td>
                  <td><button type="button" class="secondary" @click="beginResolve(row,row.index)">核对 / 匹配</button></td>
                </tr>
                <tr v-if="editIndex===row.index" class="material-review-row"><td colspan="7">
                  <div class="material-review">
                    <label>检索 SKU / 产品名
                      <span class="search-line"><input v-model="manual.sku_search" @keyup.enter="findSku"/><button type="button" @click="findSku">搜索产品</button></span>
                    </label>
                    <div v-if="hits.length" class="material-hits">
                      <button v-for="hit in hits" :key="hit.id" type="button"
                        @click="manual.product_id=hit.id;manual.sku_search=hit.sku">
                        #{{hit.id}} · {{hit.sku}} · {{hit.name}}<small v-if="hit.matched_alias">别名 {{hit.matched_alias}}</small>
                      </button>
                    </div>
                    <label>确认 ERP 产品 ID <input v-model.number="manual.product_id" type="number" min="1"/></label>
                    <label>确认物料全称 <textarea v-model="manual.material_full_name" rows="2" maxlength="10000"/></label>
                    <label class="material-overwrite"><input v-model="manual.overwrite_confirmed" type="checkbox"/> 我已核对，允许覆盖该产品现有物料全称（如有）</label>
                    <div class="material-review-actions"><button type="button" :disabled="rowBusy||!manual.product_id||!manual.material_full_name.trim()" @click="resolve">{{rowBusy?'保存中…':'确认此行'}}</button>
                      <button type="button" class="secondary" @click="editIndex=null">取消</button></div>
                  </div>
                </td></tr>
              </template>
              <tr v-if="!visible.length"><td colspan="7">当前筛选下没有物料行</td></tr>
            </tbody>
          </table>
        </div>
        <div class="material-pager">
          <button type="button" :disabled="page<=1" @click="page--">上一页</button>
          <span>第 {{page}} / {{maxPage}} 页</span>
          <button type="button" :disabled="page>=maxPage" @click="page++">下一页</button>
        </div>
        <p class="material-footer">注意：预览在当前登录会话内保留约一小时。人工选择必须核对 SKU、颜色、版本和完整中英文名称；产品主档每次写入均有审计记录。</p>
      </template>
    </main>
  </AuthenticatedLayout>
</template>

<style scoped>
.material-import{padding:12px 16px;display:flex;flex-direction:column;gap:12px;color:#22313d}
.material-import-head{display:flex;justify-content:space-between;align-items:center;gap:14px}
.material-import-head h1{font-size:23px;font-weight:750;margin:0}
.material-import-head p,.material-footer{font-size:12px;color:#64748b;margin:3px 0}
.material-link{color:#0f766e;font-weight:700}
.material-upload{display:flex;align-items:center;flex-wrap:wrap;gap:10px;padding:12px;border:1px solid #d5e6e1;background:#f8fcfb;border-radius:9px}
.material-upload label{font-size:13px;font-weight:750}
.material-upload p{flex-basis:100%;margin:0;color:#60727c;font-size:12px}
.material-import button{padding:6px 11px;border-radius:6px;border:1px solid #0f766e;background:#0f766e;color:#fff;font-weight:700;font-size:12px;cursor:pointer}
.material-import button:disabled{opacity:.5;cursor:not-allowed}
.material-import button.secondary{background:#fff;color:#0f766e}
.material-error{padding:9px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.material-notice{padding:9px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.material-summary{display:flex;flex-wrap:wrap;gap:8px}
.material-summary span{padding:7px 10px;border:1px solid #d9e4e7;border-radius:7px;background:#fff;font-size:12px}
.material-summary b{font-size:15px;margin-left:4px}
.material-actions{display:flex;align-items:center;gap:10px}.material-actions small{color:#64748b}
 .material-filters{display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:12px}
.material-filters label{display:flex;align-items:center;gap:6px}
.material-filters select,.material-filters input,.material-review input,.material-review textarea{border:1px solid #cbd5e1;border-radius:6px;padding:6px 9px;background:#fff;color:#1e293b}
.material-table-wrap{overflow:auto;max-height:580px;border:1px solid #e2e8f0;border-radius:9px}
.material-table{border-collapse:collapse;width:100%;font-size:12px}
.material-table th,.material-table td{padding:8px;vertical-align:top;border-bottom:1px solid #e2e8f0;text-align:left}
.material-table th{position:sticky;top:0;background:#f1f5f9;color:#475569;z-index:2}
.material-table .mono{font-family:monospace;white-space:nowrap}
.material-table .full-name{max-width:330px;min-width:210px;white-space:normal;overflow-wrap:anywhere}
.material-table td small{display:block;color:#64748b}
.material-table .status{font-size:11px;color:#475569;white-space:nowrap}
.material-table .status.ready,.material-table .status.resolved{color:#047857;font-weight:750}
.material-table .status.ambiguous_variant,.material-table .status.conflict_existing,.material-table .status.ambiguous_identity{color:#92400e;font-weight:750}
.material-review-row{background:#f6fbfa}
.material-review{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:8px}
.material-review label{display:flex;flex-direction:column;gap:4px;font-weight:650}
.material-review label:has(textarea){grid-column:1/-1}
.material-review textarea{width:100%;resize:vertical}
.material-review .search-line{display:flex;gap:6px}
.material-review .search-line input{flex:1;min-width:0}
.material-hits{grid-column:1/-1;display:flex;gap:5px;flex-wrap:wrap}
.material-hits button{max-width:250px;white-space:normal;text-align:left}
.material-review .material-overwrite{grid-column:1/-1;flex-direction:row;align-items:center;color:#92400e}
.material-review-actions{grid-column:1/-1;display:flex;gap:8px}
.material-pager{display:flex;align-items:center;justify-content:flex-end;gap:12px;font-size:12px}
@media(max-width:800px){.material-review{grid-template-columns:1fr}.material-import-head{align-items:flex-start;flex-direction:column}}
</style>
