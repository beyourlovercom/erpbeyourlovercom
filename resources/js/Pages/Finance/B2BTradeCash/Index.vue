<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
type Totals={
 status:string;currency:string;receivable_micros:number|null;
 net_confirmed_micros:number|null;outstanding_micros:number|null;refunded_micros:number|null;
}
type Order={id:number;number:string;company:string|null;summary:Totals}
const props=defineProps<{orders:{data:Order[];links:{url:string|null;label:string;active:boolean}[]}}>()
const money=(v:number|null,c:string)=>v===null?'—':new Intl.NumberFormat('zh-CN',{style:'currency',currency:c}).format(v/1000000)
const status=(v:string)=>({
 pending_trade_approval:'内部订单未审批',unpaid:'未收',partial:'部分收款',paid:'已核实全额',overpaid:'需处理超收',review_required:'需人工核对'
} as Record<string,string>)[v]||v
</script>
<template>
  <Head title="B2B 贸易财务核销 | Accounting"/>
  <AuthenticatedLayout title="B2B 贸易收款核对" :breadcrumbs="['Accounting','B2B 贸易收款']" canonical-topbar>
    <main class="cash-page">
      <header class="hero"><small>ACCOUNTING · INDEPENDENT B2B TRADE</small><h1>B2B 贸易收款核对</h1>
        <p>独立于 Woo 订单及 C 端财务。仅汇总由财务人员独立核实的银行／支付平台流水；业务员凭证审核不等于到账。</p>
      </header>
      <section class="panel">
        <h2>贸易订单应收与核实金额</h2>
        <div class="table"><table><thead><tr><th>贸易订单</th><th>企业</th><th>应收</th><th>已核实净收款</th><th>未收</th><th>核对状态</th></tr></thead>
          <tbody><tr v-for="row in orders.data" :key="row.id">
            <td><Link :href="`/accounting/b2b-trade-cash/${row.id}`">{{ row.number }}</Link></td>
            <td>{{ row.company }}</td>
            <td>{{ money(row.summary.receivable_micros,row.summary.currency) }}</td>
            <td>{{ money(row.summary.net_confirmed_micros,row.summary.currency) }}</td>
            <td>{{ money(row.summary.outstanding_micros,row.summary.currency) }}</td>
            <td>{{ status(row.summary.status) }}</td></tr></tbody>
        </table></div>
        <div class="mobile-list">
          <Link v-for="row in orders.data" :key="row.id" class="mobile-item" :href="`/accounting/b2b-trade-cash/${row.id}`">
            <strong>{{ row.number }}</strong><span>{{ row.company }}</span>
            <dl><dt>应收</dt><dd>{{ money(row.summary.receivable_micros,row.summary.currency) }}</dd><dt>已核实净收</dt><dd>{{ money(row.summary.net_confirmed_micros,row.summary.currency) }}</dd><dt>状态</dt><dd>{{ status(row.summary.status) }}</dd></dl>
          </Link>
        </div>
        <p v-if="!orders.data.length" class="empty">暂无独立 B2B 贸易订单。</p>
        <nav class="pages"><Link v-for="(p,i) in orders.links" v-show="p.url" :key="i" :href="p.url||'#'">{{ p.label.replace(/&laquo;|&raquo;/g,'') }}</Link></nav>
      </section>
    </main>
  </AuthenticatedLayout>
</template>
<style scoped>
.cash-page{padding:24px;min-height:100%;background:#f5f8f6;color:#23382f;font-family:PingFang SC,-apple-system,Arial,sans-serif}.hero{padding:21px;background:#e9f3ed;border:1px solid #d7e8df;border-radius:14px;margin-bottom:15px}.hero small{letter-spacing:.12em;color:#397660;font-size:10px}.hero h1{margin:7px 0;font-size:23px}.hero p{font-size:12px;color:#547569;line-height:1.7}.panel{background:#fff;border:1px solid #e1eae5;border-radius:14px;padding:18px}.panel h2{font-size:16px;margin:0 0 14px}.table{overflow:auto}.table table{border-collapse:collapse;width:100%;min-width:870px;font-size:12px}.table th,.table td{border-bottom:1px solid #e5ece7;text-align:left;padding:13px 8px}.table a,.pages a{color:#146d4e;text-decoration:none;font-weight:600}.mobile-list{display:none}.pages{display:flex;gap:10px;margin-top:12px}.empty{font-size:13px;color:#74857d}
@media(max-width:720px){.cash-page{padding:12px}.hero{padding:15px}.hero h1{font-size:20px}.panel{padding:13px}.table{display:none}.mobile-list{display:grid;gap:10px}.mobile-item{text-decoration:none;color:inherit;border:1px solid #e2eae5;border-radius:10px;display:grid;padding:13px;gap:6px}.mobile-item strong{color:#176f54;font-size:13px}.mobile-item span{font-size:12px;color:#75867b}.mobile-item dl{margin:4px 0 0;display:grid;grid-template-columns:auto 1fr;gap:6px;font-size:12px}.mobile-item dd{margin:0;text-align:right}}
</style>
