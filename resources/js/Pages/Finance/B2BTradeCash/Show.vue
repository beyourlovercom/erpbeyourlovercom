<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
type Summary={status:string;currency:string;receivable_micros:number|null;gross_confirmed_micros:number|null;refunded_micros:number|null;net_confirmed_micros:number|null;outstanding_micros:number|null;overpaid_micros:number|null}
type Evidence={id:number;source:string;reference:string;status:string;amount_micros:number;currency:string;claimed_paid_on:string|null;reviewed_at:string|null;has_finance_event:boolean}
type Event={id:number;event_type:string;source:string;reference:string;currency:string;amount_micros:number;date:string|null;confirmed_at:string|null;refund_of_id:number|null}
type Trade={id:number;number:string;status:string;company:string|null;currency:string;summary:Summary;evidence:Evidence[];cash_events:Event[]}
const props=defineProps<{trade:Trade;canConfirm:boolean}>()
const receipt=useForm({evidence_id:'',source:'bank_transfer',external_reference:'',amount_decimal:'',value_date:'',attestation_method:'manual_bank_statement',confirmation_note:''})
const refund=useForm({receipt_id:'',source:'bank_transfer',external_reference:'',amount_decimal:'',value_date:'',attestation_method:'manual_bank_statement',confirmation_note:''})
const money=(v:number|null,c:string)=>v===null?'—':new Intl.NumberFormat('zh-CN',{style:'currency',currency:c}).format(v/1000000)
const status=(v:string)=>({pending_trade_approval:'订单待审批',unpaid:'未收',partial:'部分收款',paid:'已核实全额',overpaid:'超收待处理',review_required:'需核对',pending_review:'待业务资料复核',reviewed:'业务资料已复核（非到账）',rejected:'已拒绝'} as Record<string,string>)[v]||v
const selectable=()=>props.trade.evidence.filter(e=>e.status==='reviewed'&&!e.has_finance_event)
const pickEvidence=()=>{const x=selectable().find(e=>e.id===Number(receipt.evidence_id));if(x){receipt.source=x.source;receipt.external_reference='';receipt.amount_decimal=(x.amount_micros/1000000).toFixed(2);receipt.attestation_method=x.source==='bank_transfer'?'manual_bank_statement':'manual_provider_statement'}}
const submitReceipt=()=>receipt.post(`/accounting/b2b-trade-cash/${props.trade.id}/receipts/${receipt.evidence_id}`)
const submitRefund=()=>refund.post(`/accounting/b2b-trade-cash/${props.trade.id}/refunds/${refund.receipt_id}`)
</script>
<template>
  <Head :title="`${trade.number} | B2B 财务核销`"/>
  <AuthenticatedLayout title="B2B 贸易收款核对" :breadcrumbs="['Accounting','B2B 贸易收款',trade.number]" canonical-topbar>
    <main class="cash-page">
      <Link class="back" href="/accounting/b2b-trade-cash">← 返回贸易收款核对</Link>
      <header><small>FINANCE · MANUAL SOURCE ATTESTATION</small><h1>{{ trade.number }}</h1><p>{{ trade.company }} · {{ status(trade.summary.status) }}</p></header>
      <div class="notice">只有有财务权限的人员独立核对外部银行／支付平台账单并填写交易编号后，才会记入本独立 B2B 收款来源。这里**不直接调用银行 API**，也不修改 Woo 收款或费用。</div>
      <div class="metrics">
        <article><small>贸易应收</small><strong>{{ money(trade.summary.receivable_micros,trade.currency) }}</strong></article>
        <article><small>财务已核实净额</small><strong>{{ money(trade.summary.net_confirmed_micros,trade.currency) }}</strong></article>
        <article><small>退款核销</small><strong>{{ money(trade.summary.refunded_micros,trade.currency) }}</strong></article>
        <article><small>未收余额</small><strong>{{ money(trade.summary.outstanding_micros,trade.currency) }}</strong></article>
      </div>
      <section class="panel"><h2>待核实凭证</h2>
        <div v-for="e in trade.evidence" :key="e.id" class="line">
          <strong>{{ e.reference }}</strong><span>{{ money(e.amount_micros,e.currency) }} · {{ status(e.status) }}</span><small>{{ e.has_finance_event?'已由 Finance 记录':'未完成财务核实' }}</small>
        </div><p v-if="!trade.evidence.length">业务部门尚未提交凭证。</p>
      </section>
      <section v-if="canConfirm&&selectable().length" class="panel">
        <h2>财务独立确认付款（人工对账）</h2>
        <p>必须核对外部银行／支付服务商流水。业务员填写的编号只作待核对参考；财务需自行输入外部账单编号。</p>
        <form class="form" @submit.prevent="submitReceipt">
          <label>待核实的内部凭证<select v-model="receipt.evidence_id" required @change="pickEvidence"><option value="">选择已审核凭证</option><option v-for="e in selectable()" :key="e.id" :value="String(e.id)">{{ e.reference }} · {{ money(e.amount_micros,e.currency) }}</option></select></label>
          <label>外部账单交易编号（财务自行填写）<input v-model="receipt.external_reference" required maxlength="191"></label>
          <label>已核实金额（{{ trade.currency }}）<input v-model="receipt.amount_decimal" required inputmode="decimal"></label>
          <label>实际到账日期<input v-model="receipt.value_date" type="date" required></label>
          <label>外部证据来源<select v-model="receipt.source"><option value="bank_transfer">银行转账</option><option value="provider_reference">支付服务商</option></select></label>
          <label>独立核实方式<select v-model="receipt.attestation_method"><option value="manual_bank_statement">人工核对银行账单</option><option value="manual_provider_statement">人工核对服务商账单</option></select></label>
          <label class="wide">核实依据（至少 12 字符）<textarea v-model="receipt.confirmation_note" required rows="2" minlength="12" /></label>
          <button :disabled="receipt.processing||!receipt.evidence_id" type="submit">确认已核实收款</button>
        </form>
        <p v-if="Object.keys(receipt.errors).length" class="error">{{ Object.values(receipt.errors).join(' · ') }}</p>
      </section>
      <section class="panel"><h2>Finance 唯一交易流水</h2>
        <div v-for="event in trade.cash_events" :key="event.id" class="line">
          <strong>{{ event.event_type==='receipt'?'已核实收款':'已核实退款' }} · {{ money(event.amount_micros,event.currency) }}</strong>
          <span>{{ event.reference }}</span><small>{{ event.date }}</small>
        </div><p v-if="!trade.cash_events.length">无已核实外部付款；不从 Won、审批或银行回单文件推断到账。</p>
      </section>
      <section v-if="canConfirm&&trade.cash_events.some(e=>e.event_type==='receipt')" class="panel">
        <h2>退款核销（另一名财务人员）</h2>
        <form class="form" @submit.prevent="submitRefund">
          <label>原收款<select v-model="refund.receipt_id" required><option value="">选择原付款</option><option v-for="e in trade.cash_events.filter(e=>e.event_type==='receipt')" :key="e.id" :value="String(e.id)">{{ e.reference }} · {{ money(e.amount_micros,e.currency) }}</option></select></label>
          <label>退款流水编号<input v-model="refund.external_reference" required></label>
          <label>退款金额（{{ trade.currency }}）<input v-model="refund.amount_decimal" required inputmode="decimal"></label>
          <label>实际退款日期<input v-model="refund.value_date" type="date" required></label>
          <label>退款来源<select v-model="refund.source"><option value="bank_transfer">银行转账</option><option value="provider_reference">支付服务商</option></select></label>
          <label>核实方式<select v-model="refund.attestation_method"><option value="manual_bank_statement">银行账单</option><option value="manual_provider_statement">服务商账单</option></select></label>
          <label class="wide">退款审核依据<textarea v-model="refund.confirmation_note" required minlength="12" rows="2" /></label>
          <button :disabled="refund.processing||!refund.receipt_id" type="submit">核实退款</button>
        </form><p v-if="Object.keys(refund.errors).length" class="error">{{ Object.values(refund.errors).join(' · ') }}</p>
      </section>
    </main>
  </AuthenticatedLayout>
</template>
<style scoped>
.cash-page{padding:24px;min-height:100%;background:#f5f8f6;color:#233a31;font-family:PingFang SC,-apple-system,Arial,sans-serif}.back{color:#1c6b4e;text-decoration:none;font-size:12px}.cash-page header h1{font-size:22px;margin:9px 0}.cash-page header small{letter-spacing:.1em;font-size:10px;color:#648478}.cash-page header p,.panel p{font-size:12px;color:#748479;line-height:1.6}.notice{padding:13px;border:1px solid #e5c984;background:#fff8de;color:#6b582b;border-radius:9px;font-size:12px;line-height:1.7;margin:15px 0}.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.metrics article,.panel{background:white;border:1px solid #e1e9e3;border-radius:13px;padding:16px}.metrics article{min-width:0;display:grid;gap:9px}.metrics small{font-size:11px;color:#7c897d}.metrics strong{font-size:15px;overflow-wrap:anywhere}.panel{margin-top:14px}.panel h2{font-size:16px;margin:0 0 12px}.line{display:flex;align-items:center;justify-content:space-between;gap:12px;border-top:1px solid #e4eae5;padding:13px 0;font-size:12px}.line span,.line small{color:#728479}.form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.form label{display:grid;gap:6px;font-size:12px;min-width:0}.form label.wide{grid-column:1/-1}.form input,.form select,.form textarea{border:1px solid #d4e0d8;padding:10px;border-radius:8px;font:inherit;font-size:12px;min-width:0}.form button{border:0;background:#217252;color:white;border-radius:8px;padding:11px;cursor:pointer;font:inherit;font-size:12px}.error{color:#aa3333;font-size:12px}
@media(max-width:720px){.cash-page{padding:12px}.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.panel{padding:12px}.form{grid-template-columns:1fr}.form label.wide{grid-column:1}.line{flex-direction:column;align-items:flex-start}}
</style>
