<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
const props = defineProps<{ warehouseCode: string; warehouseSlug: string; enabled: boolean; connection?: { configured:boolean;key_hint:string|null;last_verified_at:string|null;remote_warehouse_bound:boolean;oms_warehouse_code?:string|null }|null }>()
type Result = { status: string; http_status: number|null; code: string|null; warehouse_count: number|null }
const warehouseOptions=ref<Array<{code:string;name:string;country:string}>>([]),selectedWhCode=ref(''),binding=ref(false),bindingStatus=ref(''),currentWhCode=ref(props.connection?.oms_warehouse_code||'')
const appKey=ref(''),appSecret=ref(''),showSecret=ref(false),testing=ref(false),result=ref<Result|null>(null),error=ref(''),saved=ref(props.connection?.configured??false)
const explain=(status:string):string => ({
verified:'API 只读认证已通过；本次仅测试，密钥尚未保存。',
saved_verified:'已通过 API 验证并加密保存。仍需确认 OMS 实际仓库绑定，自动发货保持关闭。',
business_rejected:'OMS 业务校验拒绝。请检查 API 密钥。',
rejected:'认证被服务器拒绝，请检查密钥和权限。',
network_error:'无法连接 OMS，请检查网络。',
http_error:'OMS HTTP 请求未成功。',
unconfirmed:'OMS 返回内容尚不足以确认认证成功。',
unexpected_response:'OMS 返回了不支持的数据格式。',
disabled:'当前环境尚未允许只读连接测试。'
})[status] ?? '请检查接口结果'
async function verify(save:boolean){
  if(testing.value||!props.enabled||!appKey.value||!appSecret.value)return
  testing.value=true;result.value=null;error.value=''
  try{
    const token=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
    if(!token)throw new Error('csrf token missing')
    const url=save?'/settings/warehouses/'+encodeURIComponent(props.warehouseSlug)+'/openapi/xlwms':'/settings/warehouses/xlwms/verify'
    const r=await fetch(url,{
      method:'POST',credentials:'same-origin',redirect:'error',cache:'no-store',
      referrerPolicy:'no-referrer',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},
      body:JSON.stringify({app_key:appKey.value.trim(),app_secret:appSecret.value.trim()})
    })
    if(!r.ok){const failed=await r.json().catch(()=>null);error.value=r.status===429?'连接测试过于频繁，请稍后重试。':r.status===422?(failed?.status==='invalid_credentials_format'?'请输入完整的密钥。':'OMS 验证未通过，配置没有保存。'):'连接测试未完成，请检查登录权限。';return}
    result.value=await r.json() as Result
    if(result.value?.status==='saved_verified')saved.value=true
  }catch{error.value='验证请求未完成，请检查网络或刷新。'}
  finally{appSecret.value='';showSecret.value=false;testing.value=false}
}

async function loadWarehouses(){
  if(!saved.value || binding.value)return
  binding.value=true;bindingStatus.value=''
  try{
    const response=await fetch('/settings/warehouses/'+encodeURIComponent(props.warehouseSlug)+'/openapi/xlwms/warehouses',{credentials:'same-origin',cache:'no-store'})
    if(!response.ok)throw Error('HTTP '+response.status)
    const data=await response.json() as {warehouses:Array<{code:string;name:string;country:string}>}
    warehouseOptions.value=data.warehouses
    bindingStatus.value='已获取 OMS 授权仓库，请核对名称及所在国家后确认绑定。'
  }catch{bindingStatus.value='读取 OMS 授权仓库失败，请检查保存的 API 凭据。'}
  finally{binding.value=false}
}
async function bindWarehouse(){
 if(!selectedWhCode.value || binding.value)return
 binding.value=true;bindingStatus.value=''
 try{
   const token=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
   if(!token)throw Error('no csrf')
   const response=await fetch('/settings/warehouses/'+encodeURIComponent(props.warehouseSlug)+'/openapi/xlwms/bind',{
     method:'POST',credentials:'same-origin',cache:'no-store',
     headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':token},
     body:JSON.stringify({wh_code:selectedWhCode.value})
   })
   if(!response.ok)throw Error('HTTP '+response.status)
   currentWhCode.value=selectedWhCode.value
   bindingStatus.value='已绑定 OMS 仓库 '+selectedWhCode.value+'。这不等于已经授权自动发货。'
 }catch{bindingStatus.value='OMS 仓库绑定失败：所选代码必须来自当前账户可用仓库。'}
 finally{binding.value=false}
}

onBeforeUnmount(()=>{appKey.value='';appSecret.value='';result.value=null})
</script>
<template>
  <div class="warehouse-openapi">
    <p class="openapi-help">服务商：领星 OMS / XLWMS。正在配置的 ERP 仓库：<b>{{warehouseCode}}</b>。尚未完成实际 OMS 仓库绑定。</p>
    <p class="openapi-help">API 端点：api.xlwms.com/openapi/v1 · SKU 相同默认直连 · 物流渠道无需映射 · 自动发货关闭。</p>
    <div class="openapi-state">{{saved?'密钥已加密保存（OMS 仓库待绑定）':enabled?'可进行只读连接测试':'当前环境未启用 API 连接测试'}}<span v-if="connection?.key_hint"> · Key {{connection.key_hint}}</span></div>
    <form class="openapi-form" autocomplete="off" @submit.prevent="verify(false)">
      <label>App Key<input v-model="appKey" autocomplete="off" maxlength="256" spellcheck="false" required :disabled="testing" placeholder="输入仓库提供的完整 App Key"></label>
      <label>App Secret
        <span class="secret-line"><input v-model="appSecret" :type="showSecret?'text':'password'" autocomplete="new-password" maxlength="256" spellcheck="false" required :disabled="testing" placeholder="输入完整 App Secret"><button class="button ghost" type="button" @click="showSecret=!showSecret">{{showSecret?'隐藏':'显示'}}</button></span>
      </label>
      <div class="openapi-buttons"><button class="button ghost" type="submit" :disabled="!enabled||testing||!appKey||!appSecret">{{testing?'处理中…':'仅测试连接'}}</button><button class="button primary" type="button" :disabled="!enabled||testing||!appKey||!appSecret" @click="verify(true)">验证并加密保存</button></div>
    </form>
    <p v-if="error" class="openapi-error" role="alert">{{error}}</p>
    <div v-if="result" class="openapi-result" role="status"><b>{{explain(result.status)}}</b><p>状态：{{result.status}} · HTTP {{result.http_status??'—'}} · OMS 代码 {{result.code??'—'}}</p><p v-if="result.warehouse_count!==null">返回仓库数：{{result.warehouse_count}}（尚未绑定当前仓库）</p></div>
    <section class="openapi-binding">
      <b>远端 OMS 仓库绑定</b>
      <p class="openapi-help">已绑定代码：{{currentWhCode||'尚未绑定'}}。必须核实仓库实际名称及国家，不能自动按 ERP 名称猜测。</p>
      <button class="button ghost" type="button" :disabled="!saved||binding" @click="loadWarehouses">读取 OMS 授权仓库列表</button>
      <label v-if="warehouseOptions.length">选择真实对应的仓库
        <select v-model="selectedWhCode"><option value="">请手动选择</option>
          <option v-for="w in warehouseOptions" :key="w.code" :value="w.code">{{w.name}} · {{w.code}} · {{w.country}}</option>
        </select>
      </label>
      <button v-if="warehouseOptions.length" type="button" class="button primary" :disabled="!selectedWhCode||binding" @click="bindWarehouse">确认绑定本仓库</button>
      <p class="openapi-help" role="status">{{bindingStatus}}</p>
    </section>
    <p class="openapi-help">安全说明：点击「仅测试」不会保存密钥；「验证并加密保存」仅在 OMS 返回明确成功后加密写入当前 ERP 仓库。Secret 不回显，也不存入 GitHub。未绑定远端仓库前不允许自动发货。</p>
  </div>
</template>
<style scoped>
.warehouse-openapi{display:grid;gap:12px;padding:2px 0}.openapi-help{font-size:12px;color:#627268;margin:0;line-height:1.7}.openapi-state{background:#f1f5f2;padding:10px 13px;border-radius:8px;color:#345641;font-size:13px;font-weight:700}.openapi-form{display:grid;gap:12px;max-width:720px}.openapi-form label{display:grid;gap:6px;font-size:13px;font-weight:700}.openapi-form input{width:100%;max-width:650px;box-sizing:border-box;border:1px solid #d4dfd8;border-radius:8px;padding:10px;font-weight:400;color:#19271f;background:#fff}.secret-line{display:flex;gap:9px;align-items:center}.secret-line input{flex:1}.openapi-buttons{margin-top:5px;display:flex;align-items:center;gap:10px}.openapi-result{border:1px solid #cad9cf;border-radius:9px;padding:12px;background:#fafdfb;font-size:13px}.openapi-result p{margin:6px 0}.openapi-error{color:#a92c2c}
</style>
\n<style scoped>.openapi-binding{border-top:1px solid #dfe8e1;padding-top:14px;display:grid;gap:9px;max-width:720px}.openapi-binding label{display:grid;gap:6px}.openapi-binding select{padding:9px;border:1px solid #c9d4cb;border-radius:7px;background:#fff}</style>\n