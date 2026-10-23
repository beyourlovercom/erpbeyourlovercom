<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
const props = defineProps<{ warehouseCode: string; enabled: boolean }>()
type Result = { status: string; http_status: number|null; code: string|null; warehouse_count: number|null }
const appKey=ref(''),appSecret=ref(''),showSecret=ref(false),testing=ref(false),result=ref<Result|null>(null),error=ref('')
const explain=(status:string):string => ({
verified:'API 只读认证已通过；还未保存密钥或绑定 OMS 实际仓库。',
business_rejected:'OMS 业务校验拒绝。请检查 API 密钥。',
rejected:'认证被服务器拒绝，请检查密钥和权限。',
network_error:'无法连接 OMS，请检查网络。',
http_error:'OMS HTTP 请求未成功。',
unconfirmed:'OMS 返回内容尚不足以确认认证成功。',
unexpected_response:'OMS 返回了不支持的数据格式。',
disabled:'当前环境尚未允许只读连接测试。'
})[status] ?? '请检查接口结果'
async function verify(){
  if(testing.value||!props.enabled||!appKey.value||!appSecret.value)return
  testing.value=true;result.value=null;error.value=''
  try{
    const token=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
    if(!token)throw new Error('csrf token missing')
    const r=await fetch('/settings/warehouses/xlwms/verify',{
      method:'POST',credentials:'same-origin',redirect:'error',cache:'no-store',
      referrerPolicy:'no-referrer',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},
      body:JSON.stringify({app_key:appKey.value.trim(),app_secret:appSecret.value.trim()})
    })
    if(!r.ok){error.value=r.status===429?'连接测试过于频繁，请稍后重试。':r.status===422?'请输入完整的密钥。':'连接测试未完成，请检查登录权限。';return}
    result.value=await r.json() as Result
  }catch{error.value='验证请求未完成，请检查网络或刷新。'}
  finally{appSecret.value='';showSecret.value=false;testing.value=false}
}
onBeforeUnmount(()=>{appKey.value='';appSecret.value='';result.value=null})
</script>
<template>
  <div class="warehouse-openapi">
    <p class="openapi-help">服务商：领星 OMS / XLWMS。正在配置的 ERP 仓库：<b>{{warehouseCode}}</b>。尚未完成实际 OMS 仓库绑定。</p>
    <p class="openapi-help">API 端点：api.xlwms.com/openapi/v1 · SKU 相同默认直连 · 物流渠道无需映射 · 自动发货关闭。</p>
    <div class="openapi-state">{{enabled?'可进行只读连接测试':'当前环境未启用 API 连接测试'}}</div>
    <form class="openapi-form" autocomplete="off" @submit.prevent="verify">
      <label>App Key<input v-model="appKey" autocomplete="off" maxlength="256" spellcheck="false" required :disabled="testing" placeholder="输入仓库提供的完整 App Key"></label>
      <label>App Secret
        <span class="secret-line"><input v-model="appSecret" :type="showSecret?'text':'password'" autocomplete="new-password" maxlength="256" spellcheck="false" required :disabled="testing" placeholder="输入完整 App Secret"><button class="button ghost" type="button" @click="showSecret=!showSecret">{{showSecret?'隐藏':'显示'}}</button></span>
      </label>
      <div class="openapi-buttons"><button class="button primary" type="submit" :disabled="!enabled||testing||!appKey||!appSecret">{{testing?'正在测试…':'测试连接（仅读取仓库）'}}</button></div>
    </form>
    <p v-if="error" class="openapi-error" role="alert">{{error}}</p>
    <div v-if="result" class="openapi-result" role="status"><b>{{explain(result.status)}}</b><p>状态：{{result.status}} · HTTP {{result.http_status??'—'}} · OMS 代码 {{result.code??'—'}}</p><p v-if="result.warehouse_count!==null">返回仓库数：{{result.warehouse_count}}（尚未绑定当前仓库）</p></div>
    <p class="openapi-help">安全说明：本阶段仅测试，密钥不会保存至数据库、浏览器长期存储或 GitHub。正式加密保存将在仓库绑定校验后独立启用。</p>
  </div>
</template>
<style scoped>
.warehouse-openapi{display:grid;gap:12px;padding:2px 0}.openapi-help{font-size:12px;color:#627268;margin:0;line-height:1.7}.openapi-state{background:#f1f5f2;padding:10px 13px;border-radius:8px;color:#345641;font-size:13px;font-weight:700}.openapi-form{display:grid;gap:12px;max-width:720px}.openapi-form label{display:grid;gap:6px;font-size:13px;font-weight:700}.openapi-form input{width:100%;max-width:650px;box-sizing:border-box;border:1px solid #d4dfd8;border-radius:8px;padding:10px;font-weight:400;color:#19271f;background:#fff}.secret-line{display:flex;gap:9px;align-items:center}.secret-line input{flex:1}.openapi-buttons{margin-top:5px}.openapi-result{border:1px solid #cad9cf;border-radius:9px;padding:12px;background:#fafdfb;font-size:13px}.openapi-result p{margin:6px 0}.openapi-error{color:#a92c2c}
</style>
