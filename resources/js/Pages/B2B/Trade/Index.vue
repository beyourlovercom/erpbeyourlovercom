<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
type Quote={id:number;number:string;version:number;currency:string;total_micros:number}
type Deal={id:number;title:string;company:string|null;quotes:Quote[]}
type Row={id:number;number:string;status:string;currency:string;amount_micros:number;company:string|null;opportunity:string|null}
const props=defineProps<{
  tradeOrders:{data:Row[];links:{url:string|null;label:string;active:boolean}[]}
  opportunities:Deal[]
  canOperate:boolean
}>()
const form=useForm({opportunity_id:'',quote_id:''})
const selectedQuotes=()=>props.opportunities.find(x=>x.id===Number(form.opportunity_id))?.quotes||[]
const money=(micros:number,currency:string)=>new Intl.NumberFormat('zh-CN',{style:'currency',currency}).format(micros/1000000)
const status=(s:string)=>({draft:'待审批草稿',approved:'内部已审批（未入账）',cancelled:'已取消'} as Record<string,string>)[s]||s
const create=()=>form.transform(x=>({opportunity_id:Number(x.opportunity_id),quote_id:Number(x.quote_id)})).post('/b2b/trade-orders')
</script>
<template>
  <Head title="B2B 贸易订单 · 内部 UAT"/>
  <AuthenticatedLayout title="B2B 贸易订单" :breadcrumbs="['B2B 业务','贸易订单']" canonical-topbar>
    <section class="trade-page">
      <header><div><small>B2B BUSINESS OS · TRADE ORDERS</small><h1>贸易订单</h1><p>独立于 Woo 及 C 端订单。订单审批、收款资料审核 ≠ 财务到账。</p></div><Link href="/b2b/sales">返回销售漏斗</Link></header>
      <section v-if="canOperate" class="panel">
        <h2>由报价创建贸易订单草稿</h2>
        <form @submit.prevent="create" class="trade-form">
          <label>关联商机<select v-model="form.opportunity_id" required @change="form.quote_id=''"><option value="">请选择 B2B 商机</option><option v-for="deal in opportunities" :key="deal.id" :value="String(deal.id)">{{ deal.company }} · {{ deal.title }}</option></select></label>
          <label>报价版本<select v-model="form.quote_id" required><option value="">请选择报价</option><option v-for="q in selectedQuotes()" :key="q.id" :value="String(q.id)">{{ q.number }} · V{{ q.version }} · {{ money(q.total_micros,q.currency) }}</option></select></label>
          <button :disabled="form.processing||!form.quote_id" type="submit">创建草稿（不入账）</button>
        </form>
        <p v-if="Object.keys(form.errors).length" class="error">{{ Object.values(form.errors).join(' · ') }}</p>
      </section>
      <section class="panel">
        <h2>订单列表 <small>共 {{ tradeOrders.data.length }} 条（本页）</small></h2>
        <div class="pc-table"><table><thead><tr><th>贸易订单号</th><th>B2B 企业 / 商机</th><th>应收金额</th><th>内部状态</th></tr></thead><tbody><tr v-for="row in tradeOrders.data" :key="row.id"><td><Link :href="`/b2b/trade-orders/${row.id}`">{{ row.number }}</Link></td><td>{{ row.company }} <small>{{ row.opportunity }}</small></td><td>{{ money(row.amount_micros,row.currency) }}</td><td>{{ status(row.status) }}</td></tr></tbody></table></div>
        <div class="mobile-cards"><article v-for="row in tradeOrders.data" :key="row.id"><Link :href="`/b2b/trade-orders/${row.id}`">{{ row.number }}</Link><strong>{{ row.company }}</strong><p>{{ row.opportunity }}</p><dl><dt>应收</dt><dd>{{ money(row.amount_micros,row.currency) }}</dd><dt>状态</dt><dd>{{ status(row.status) }}</dd></dl></article></div>
        <p v-if="!tradeOrders.data.length" class="empty">暂无贸易订单。请先在销售漏斗建立商机和报价。</p>
        <nav class="pages"><Link v-for="(p,i) in tradeOrders.links" v-show="p.url" :key="i" :href="p.url||'#'">{{ p.label.replace(/&laquo;|&raquo;/g,'') }}</Link></nav>
      </section>
    </section>
  </AuthenticatedLayout>
</template>
<style scoped>
.trade-page{padding:22px;background:#f5f7f6;min-height:100%;color:#243931;font-family:PingFang SC,-apple-system,Arial,sans-serif}.trade-page header{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:20px}.trade-page header h1{font-size:24px;margin:6px 0}.trade-page header p,.trade-page small{font-size:12px;color:#72847c}.trade-page header a,.trade-page td a,.mobile-cards a{color:#176e56;font-weight:650;text-decoration:none}.panel{padding:20px;background:#fff;border:1px solid #e0e9e4;border-radius:14px;margin-bottom:15px}.panel h2{font-size:17px;margin:0 0 14px}.trade-form{display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end}.trade-form label{display:grid;gap:6px;font-size:12px;min-width:0}.trade-form select,.trade-form button{font:inherit;font-size:13px;border:1px solid #d8e1dd;border-radius:8px;padding:10px;min-width:0}.trade-form button{background:#206f55;color:white;cursor:pointer}.trade-form button:disabled{opacity:.5}.pc-table{overflow:auto}.pc-table table{border-collapse:collapse;width:100%;min-width:720px;font-size:13px}.pc-table th,.pc-table td{text-align:left;padding:12px 8px;border-bottom:1px solid #e8eeea}.pc-table small{display:block}.empty{padding:16px;color:#708479}.pages{display:flex;gap:8px;margin-top:12px}.mobile-cards{display:none}.error{color:#a63838;font-size:12px}
@media(max-width:720px){.trade-page{padding:12px}.trade-page header{align-items:flex-start;flex-direction:column}.panel{padding:14px}.trade-form{grid-template-columns:1fr}.pc-table{display:none}.mobile-cards{display:grid;gap:10px}.mobile-cards article{display:grid;gap:6px;border:1px solid #e4ebe5;border-radius:11px;padding:13px}.mobile-cards strong{font-size:14px}.mobile-cards p{font-size:12px;color:#718077}.mobile-cards dl{display:grid;grid-template-columns:auto 1fr;gap:5px;font-size:12px;margin:0}.mobile-cards dd{margin:0;font-weight:600}}
</style>
