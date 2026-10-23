<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
type Evidence={id:number;source:string;reference:string;currency:string;amount_micros:number;status:string;recorded_by:number|null;reviewed_at:string|null;review_note:string|null;attachment_href:string|null}
type Order={id:number;number:string;status:string;currency:string;amount_micros:number;organization:string|null;opportunity:string|null;quote:string|null;created_by:number|null;approved_by:number|null;approved_at:string|null;evidence:Evidence[];events:{type:string;occurred_at:string|null}[]}
type FinanceSummary={status:string;currency:string;receivable_micros:number|null;net_confirmed_micros:number|null;outstanding_micros:number|null;refunded_micros:number|null}
const props=defineProps<{tradeOrder:Order;financeSummary:FinanceSummary;canOperate:boolean;canApprove:boolean;canReview:boolean;viewerId:number;canOpenFinance:boolean}>()
const evidence=useForm({source:'bank_transfer',reference:'',currency:props.tradeOrder.currency,claimed_amount_decimal:'',claimed_paid_on:'',attachment:null as File|null})
const review=useForm({decision:'reviewed',reason:''})
const approval=useForm({})
const money=(value:number,currency:string)=>new Intl.NumberFormat('zh-CN',{style:'currency',currency}).format(value/1000000)
const status=(s:string)=>({draft:'待审批草稿',approved:'内部审批完成（未入账）',cancelled:'已取消',pending_review:'待资料复核',reviewed:'资料已复核（不代表到账）',rejected:'资料被拒绝'} as Record<string,string>)[s]||s
const activity=(s:string)=>({draft_created:'生成贸易订单草稿',trade_approved:'第二人批准内部订单',receipt_evidence_submitted:'提交待复核凭证',receipt_evidence_reviewed:'收款资料复核（未入账）',receipt_evidence_rejected:'收款资料审核拒绝'} as Record<string,string>)[s]||s
const dollarsToMicros=(dollars:string):number|null=>{
  if(!/^(0|[1-9][0-9]{0,7})(\.[0-9]{1,2})?$/.test(dollars))return null
  const [whole,partial='']=dollars.split('.')
  return (Number(whole)*100+Number(partial.padEnd(2,'0')))*10000
}
const submitEvidence=()=>{
  const amount=dollarsToMicros(evidence.claimed_amount_decimal)
  if(amount===null || amount<=0){evidence.setError('claimed_amount_decimal','金额必须是正数，最多两位小数');return}
  evidence.clearErrors()
  evidence.transform(v=>({source:v.source,reference:v.reference,currency:v.currency,claimed_amount_micros:amount,claimed_paid_on:v.claimed_paid_on||null,attachment:v.attachment})).post(`/b2b/trade-orders/${props.tradeOrder.id}/evidence`)
}
const onAttachmentChange=(event: Event)=>{evidence.attachment=(event.target as HTMLInputElement).files?.[0]||null}
const approve=()=>approval.post(`/b2b/trade-orders/${props.tradeOrder.id}/approve`)
const submitReview=(id:number)=>review.post(`/b2b/trade-orders/${props.tradeOrder.id}/evidence/${id}/review`)
</script>
<template>
  <Head :title="`${tradeOrder.number} · 贸易订单`"/>
  <AuthenticatedLayout title="B2B 贸易订单" :breadcrumbs="['B2B 业务','贸易订单',tradeOrder.number]" canonical-topbar>
    <section class="trade-detail">
      <Link href="/b2b/trade-orders" class="back">← 订单列表</Link><h1>{{ tradeOrder.number }}</h1>
      <p class="warning">内部贸易订单和凭证审核不代表实际到账。Accounting 尚未收到任何自动入账指令。</p>
      <div class="facts"><article><small>关联企业</small><strong>{{ tradeOrder.organization }}</strong></article><article><small>关联商机 / 报价</small><strong>{{ tradeOrder.opportunity }} / {{ tradeOrder.quote }}</strong></article><article><small>应收金额</small><strong>{{ money(tradeOrder.amount_micros,tradeOrder.currency) }}</strong></article><article><small>订单状态</small><strong>{{ status(tradeOrder.status) }}</strong></article></div>
      <section class="panel">
        <h2>Accounting 核实收款（单一财务事实来源）</h2>
        <p>已核实净收款：{{ financeSummary.net_confirmed_micros===null?'尚未审核':money(financeSummary.net_confirmed_micros,financeSummary.currency) }}</p>
        <p>剩余应收：{{ financeSummary.outstanding_micros===null?'—':money(financeSummary.outstanding_micros,financeSummary.currency) }}</p>
        <p>凭证内部“资料已复核”不等于已收款；这里仅引用 Finance 独立核对结果。</p>
        <Link v-if="canOpenFinance" :href="`/accounting/b2b-trade-cash/${tradeOrder.id}`">打开财务收款核对 →</Link>
      </section>
      <section v-if="tradeOrder.status==='draft'" class="panel"><h2>订单内部审批</h2><p>独立授权、双人复核。订单创建人不得自行批准。</p><button v-if="canApprove && viewerId!==tradeOrder.created_by" :disabled="approval.processing" @click="approve">批准内部贸易订单</button><p v-else>需要另一位具备 B2B 贸易审批权限的管理员审核。</p><p v-if="Object.keys(approval.errors).length" class="error">{{ Object.values(approval.errors).join(' · ') }}</p></section>
      <section v-if="canOperate && tradeOrder.status==='approved'" class="panel"><h2>提交收款证明（仅待复核资料）</h2><form class="form" @submit.prevent="submitEvidence"><label>凭证来源<select v-model="evidence.source"><option value="bank_transfer">银行转账</option><option value="provider_reference">支付平台编号</option></select></label><label>唯一交易编号<input v-model="evidence.reference" required maxlength="191"></label><label>申报金额（{{ tradeOrder.currency }}，两位小数）<input v-model="evidence.claimed_amount_decimal" required inputmode="decimal" placeholder="例如 125.50"></label><label>付款日期（申报）<input v-model="evidence.claimed_paid_on" type="date"></label><label>私有凭证附件（PDF/图片，最大 5 MB）<input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" @change="onAttachmentChange"></label><button type="submit" :disabled="evidence.processing">保存待复核资料</button></form><p v-if="Object.keys(evidence.errors).length" class="error">{{ Object.values(evidence.errors).join(' · ') }}</p></section>
      <section class="panel"><h2>收款凭证 · 非财务账本</h2><div v-for="e in tradeOrder.evidence" :key="e.id" class="evidence"><strong>{{ e.reference }}</strong><p>{{ money(e.amount_micros,e.currency) }} · {{ status(e.status) }}</p><p v-if="e.review_note">审核备注：{{ e.review_note }}</p><p v-if="e.attachment_href"><Link :href="e.attachment_href">私有下载：凭证附件</Link></p><form v-if="canReview && e.status==='pending_review' && viewerId!==e.recorded_by" class="form" @submit.prevent="submitReview(e.id)"><select v-model="review.decision"><option value="reviewed">资料复核通过（未入账）</option><option value="rejected">拒绝资料</option></select><input v-model="review.reason" placeholder="审核依据" required><button :disabled="review.processing" type="submit">记录审核</button></form></div><p v-if="!tradeOrder.evidence.length">暂无凭证。不得将 Won、报价或内部订单审批视为付款。</p></section>
      <section class="panel"><h2>不可覆盖的审批历史</h2><ol><li v-for="(ev,i) in tradeOrder.events" :key="i">{{ activity(ev.type) }} · {{ ev.occurred_at?.slice(0,16).replace('T',' ') }}</li></ol></section>
    </section>
  </AuthenticatedLayout>
</template>
<style scoped>
.trade-detail{padding:22px;background:#f5f7f6;color:#253a32;font-family:PingFang SC,-apple-system,Arial,sans-serif}.trade-detail h1{font-size:24px}.back{color:#176e56;text-decoration:none;font-size:12px}.warning{border:1px solid #e7d49b;background:#fff8e5;color:#735727;border-radius:9px;padding:11px;font-size:12px;line-height:1.5}.facts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.facts article,.panel{background:white;border:1px solid #e0e8e3;border-radius:12px;padding:17px}.facts article{display:grid;gap:8px;min-width:0}.facts small,.panel p{color:#74847c;font-size:12px}.facts strong{overflow-wrap:anywhere;font-size:14px}.panel{margin-top:16px}.panel h2{font-size:17px;margin:0 0 13px}.panel button{border:0;background:#216f55;color:#fff;padding:10px 14px;border-radius:8px;cursor:pointer;font:inherit;font-size:12px}.panel button:disabled{opacity:.5}.form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.form label{display:grid;gap:6px;font-size:12px;min-width:0}.form input,.form select{border:1px solid #d8e3db;border-radius:8px;padding:10px;min-width:0;font:inherit;font-size:12px}.evidence{padding:12px 0;border-top:1px solid #e3ebe6}.error{color:#ad3434;font-size:12px}
@media(max-width:720px){.trade-detail{padding:12px}.facts{grid-template-columns:repeat(2,minmax(0,1fr))}.facts article,.panel{padding:12px}.form{grid-template-columns:1fr}.trade-detail h1{font-size:21px}}
</style>
