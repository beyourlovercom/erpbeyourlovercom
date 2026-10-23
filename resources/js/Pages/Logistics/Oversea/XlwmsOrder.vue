<script setup lang="ts">
import {Head,Link} from '@inertiajs/vue3'
import {computed,ref} from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
type Preview={order_id:number;erp_order_id:string|null;warehouse:string|null;remote_bound:boolean;blockers:string[];ready_for_remote_check:boolean;payload_hash:string;oms_reference:string;attempt:{state:string;oms_order_no:string|null}|null;payload:{whCode:string;logisticsChannel:string;receiver:string;addressOne:string;countryRegionCode:string;postCode:string;productList:Array<{sku:string;quantity:number}>;isSubmit:number}}
const props=defineProps<{preview:Preview;canDispatch:boolean}>()
const confirmation=ref(''),processing=ref(false),feedback=ref('')
const eligible=computed(()=>props.canDispatch&&props.preview.ready_for_remote_check&&!props.preview.attempt&&confirmation.value==='SUBMIT_US_SM_ORDER_'+props.preview.order_id)
const labels:Record<string,string>={wrong_warehouse:'不是世迈仓 US-SM 订单',order_not_pending_shipment:'订单并非待发货',shipment_not_pending:'发货记录已非待发货',warehouse_review_not_approved:'Dede 仓库审核尚未通过',tracking_already_assigned:'已有 Tracking，可能已发货',api_credentials_missing:'仓库未保存 API 凭据',oms_warehouse_not_bound:'未确认 OMS 远端仓库代码',recipient_missing:'缺收件人',address_missing:'缺详细地址',city_missing:'缺城市',postal_code_missing:'缺邮编',country_iso2_missing:'国家代码不完整',province_missing:'缺州省',province_code_not_confirmed:'州省代码需人工核实',native_logistics_channel_missing:'缺 OMS 原生物流渠道代码',logistics_channel_inactive:'渠道已停用',no_valid_order_items:'没有有效 SKU',invalid_item:'SKU 或数量错误',inventory_unverified:'库存未核实',insufficient_warehouse_stock:'当前仓库库存不足',existing_dispatch_attempt:'已经有下发记录，禁止再次创建'}
async function action(type:'send'|'reconcile'){
 processing.value=true;feedback.value=''
 try{
  const token=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  if(!token)throw Error('CSRF')
  const r=await fetch('/logistics/oversea/us-3sm/orders/'+props.preview.order_id+'/xlwms/'+type,{
   method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',
   headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':token},
   body:JSON.stringify(type==='send'?{payload_hash:props.preview.payload_hash,confirmation:confirmation.value}:{})
  })
  if(!r.ok){feedback.value='操作未完成（HTTP '+r.status+'），请刷新并检查审核/权限/配置。不要直接重复下发。';return}
  const x=await r.json() as {state:string;oms_order_no?:string|null}
  feedback.value=x.state+(x.oms_order_no?' · OMS '+x.oms_order_no:'')+'。请刷新核实。'
 }catch{feedback.value='请求结果不明，请先查询 OMS，不能重复创建。'}
 finally{processing.value=false;confirmation.value=''}
}
</script>
<template>
 <Head title="世迈仓 OMS 单笔出库预检"/>
 <AuthenticatedLayout title="物流" :breadcrumbs="['ERP','物流','世迈仓','API 出库预检']">
 <section class="mvp-page single-order">
  <div class="mvp-page-heading"><div><p class="mvp-kicker">XLWMS · SINGLE REAL ORDER</p><h2>世迈仓 · 单笔出库预检</h2><p>ERP 订单 {{preview.erp_order_id||preview.order_id}} · 不提前修改发货状态和 Woo</p></div><Link class="mvp-button" href="/logistics/oversea/us-3sm?view=pending">返回待发货</Link></div>
  <article class="mvp-card"><h3>发货门禁</h3>
   <p v-if="!preview.blockers.length">本地校验通过，但 OMS 已有订单查询必须在发送前完成。</p>
   <ul v-else><li v-for="b in preview.blockers" :key="b">{{labels[b]||labels[b.split(':')[0]]||b}}</li></ul>
   <p>参考号：{{preview.oms_reference}} · 远端仓库绑定：{{preview.remote_bound?'已配置':'未配置'}}</p>
   <p>已有尝试：{{preview.attempt?.state||'无'}} <span v-if="preview.attempt?.oms_order_no"> · {{preview.attempt.oms_order_no}}</span></p>
  </article>
  <article class="mvp-card"><h3>实际提交的字段预览</h3>
   <table><tbody><tr><th>OMS 仓库</th><td>{{preview.payload.whCode||'待绑定'}}</td></tr>
    <tr><th>物流渠道原生代码</th><td>{{preview.payload.logisticsChannel||'未配置'}}</td></tr>
    <tr><th>收件人</th><td>{{preview.payload.receiver}}</td></tr>
    <tr><th>地址</th><td>{{preview.payload.addressOne}} · {{preview.payload.countryRegionCode}} {{preview.payload.postCode}}</td></tr>
    <tr><th>SKU × 数量</th><td><span v-for="item in preview.payload.productList" :key="item.sku">{{item.sku}} × {{item.quantity}}　</span></td></tr>
    <tr><th>提交模式</th><td>isSubmit={{preview.payload.isSubmit}}（直接提交仓库）</td></tr>
   </tbody></table>
  </article>
  <article class="mvp-card"><h3>单笔正式发送</h3>
   <p v-if="!canDispatch">此环境真实发单开关未启用，不能创建出库单。</p>
   <p>必须由 Owner 确定真实订单并批准此单。超时后只读查单，不自动重试。</p>
   <label>请输入确认码 SUBMIT_US_SM_ORDER_{{preview.order_id}}<input v-model="confirmation" autocomplete="off" spellcheck="false" placeholder="手动输入完整确认码"/></label>
   <div class="actions"><button class="mvp-button mvp-button-primary" type="button" :disabled="!eligible||processing" @click="action('send')">真实提交单笔 OMS 出库</button>
    <button v-if="preview.attempt" class="mvp-button" type="button" :disabled="processing" @click="action('reconcile')">只读核对 OMS</button>
   </div><p role="status">{{feedback}}</p>
  </article>
 </section>
 </AuthenticatedLayout>
</template>
<style scoped>
.single-order{max-width:1020px}.actions{display:flex;gap:10px;margin:16px 0}.single-order input{display:block;margin-top:9px;padding:10px;border:1px solid #cbd5cf;border-radius:8px;width:min(100%,440px)}.single-order th{text-align:left;min-width:180px;padding:10px;background:#f8fbf9}.single-order td{padding:10px;word-break:break-word}.single-order tr{border-bottom:1px solid #e8eeea}
</style>
