<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
type CaseRow = {id:number;case_number:string|null;title:string;organization:string|null;contact:string|null;country_code:string|null;source:string;product_interest:string|null;requested_quantity:number|null;stage:string;status:string;handling:string;next_follow_up_at:string|null;last_inbound_at:string|null}
type Page = {data:CaseRow[];links:Array<{url:string|null;label:string;active:boolean}>;total:number}
const props=defineProps<{followupsOnly:boolean;overdueOnly:boolean;cases:Page;metrics:{total:number;open:number;waiting:number;overdue:number};filters:{search:string;source:string;status:string;stage:string};canOperate:boolean}>()
const state=reactive({...props.filters})
const run=()=>router.get(props.followupsOnly ? '/b2b/followups' : '/b2b/inquiries',state,{preserveState:true,replace:true})
const stageName=(s:string)=>({new:'新询盘',contacted:'已联系',qualified:'已验证',quoted:'已报价',negotiating:'谈判中'} as Record<string,string>)[s]||s
const statusName=(s:string)=>({open:'处理中',won:'已成交',lost:'已流失',spam:'无效'} as Record<string,string>)[s]||s
const sourceName=(s:string)=>({manual:'人工录入',website_form:'网站表单',email:'邮件',whatsapp_business:'WhatsApp Business',trade_show:'展会',referral:'推荐',other:'其他'} as Record<string,string>)[s]||s
</script>
<template>
  <Head :title="followupsOnly ? 'B2B 跟进任务 · BYL ERP' : 'B2B 询盘 · BYL ERP'" />
  <AuthenticatedLayout title="B2B 询盘" :breadcrumbs="['BYL ERP','B2B 业务','询盘管理']">
    <main class="b2b-page">
      <header><div><h1>{{ followupsOnly ? '跟进任务' : '询盘管理' }}</h1><p>{{ followupsOnly ? '所有已安排下一次跟进的开放询盘' : '统一记录企业采购意向与销售跟进' }}</p></div><Link v-if="canOperate" class="action" href="/b2b/inquiries/create">新增询盘</Link></header>
      <div class="metrics">
        <article><span>全部询盘</span><strong>{{ metrics.total }}</strong></article>
        <article><span>处理中</span><strong>{{ metrics.open }}</strong></article>
        <article><span>待处理</span><strong>{{ metrics.waiting }}</strong></article>
        <article><span>跟进逾期</span><strong>{{ metrics.overdue }}</strong></article>
      </div>
      <form class="filters" @submit.prevent="run">
        <input v-model="state.search" type="search" placeholder="Case 编号 / 询盘主题" aria-label="查询询盘" />
        <select v-model="state.source" aria-label="来源"><option value="">全部来源</option><option value="manual">人工录入</option><option value="website_form">网站</option><option value="email">邮件</option><option value="whatsapp_business">WhatsApp Business</option><option value="trade_show">展会</option><option value="referral">推荐</option></select>
        <select v-model="state.status" aria-label="状态"><option value="">全部状态</option><option value="open">处理中</option><option value="won">成交</option><option value="lost">流失</option><option value="spam">无效</option></select>
        <select v-model="state.stage" aria-label="阶段"><option value="">全部阶段</option><option value="new">新询盘</option><option value="contacted">已联系</option><option value="qualified">已验证</option><option value="quoted">已报价</option><option value="negotiating">谈判中</option></select>
        <button type="submit">查询</button>
      </form>
      <div class="table-scroll">
        <table><thead><tr><th>询盘编号</th><th>客户 / 主题</th><th>国家</th><th>来源</th><th>产品 / 数量</th><th>阶段</th><th>状态</th><th>最近询盘</th></tr></thead>
          <tbody>
            <tr v-for="item in cases.data" :key="item.id">
              <td><Link :href="`/b2b/cases/${item.id}`">{{ item.case_number||'—' }}</Link></td>
              <td><strong>{{ item.organization||item.contact||'未分配客户' }}</strong><small>{{ item.title }}</small></td>
              <td>{{ item.country_code||'—' }}</td><td>{{ sourceName(item.source) }}</td>
              <td>{{ item.product_interest||'—' }}<small v-if="item.requested_quantity">数量：{{ item.requested_quantity }}</small></td>
              <td>{{ stageName(item.stage) }}</td><td>{{ statusName(item.status) }}</td>
              <td>{{ item.last_inbound_at?item.last_inbound_at.slice(0,10):'—' }}</td>
            </tr>
            <tr v-if="!cases.data.length"><td colspan="8" class="empty">暂无询盘记录；仅实际存储成功的 Case 会在此显示</td></tr>
          </tbody>
        </table>
      </div>
      <section class="mobile-cases" aria-label="移动端询盘卡片">
        <article v-for="item in cases.data" :key="item.id" class="mobile-case-card">
          <div class="mobile-case-top">
            <Link :href="`/b2b/cases/${item.id}`" class="mobile-case-number">{{ item.case_number || '查看询盘' }}</Link>
            <span class="mobile-case-status">{{ statusName(item.status) }}</span>
          </div>
          <Link :href="`/b2b/cases/${item.id}`" class="mobile-case-company">{{ item.organization || item.contact || '未分配客户' }}</Link>
          <p class="mobile-case-title">{{ item.title }}</p>
          <dl class="mobile-case-facts">
            <div><dt>国家</dt><dd>{{ item.country_code || '—' }}</dd></div>
            <div><dt>阶段</dt><dd>{{ stageName(item.stage) }}</dd></div>
            <div><dt>产品</dt><dd>{{ item.product_interest || '—' }}</dd></div>
            <div><dt>数量</dt><dd>{{ item.requested_quantity ?? '待确认' }}</dd></div>
            <div><dt>来源</dt><dd>{{ sourceName(item.source) }}</dd></div>
            <div><dt>下次跟进</dt><dd>{{ item.next_follow_up_at ? item.next_follow_up_at.slice(0,10) : '未安排' }}</dd></div>
          </dl>
        </article>
        <p v-if="!cases.data.length" class="mobile-cases-empty">暂无询盘记录；仅实际存储成功的 Case 会在此显示</p>
      </section>
      <nav class="links" aria-label="分页"><template v-for="(link,index) in cases.links" :key="index"><Link v-if="link.url" :href="link.url" :class="{active:link.active}">{{ link.label.replace('&laquo;','‹').replace('&raquo;','›') }}</Link></template></nav>
    </main>
  </AuthenticatedLayout>
</template>
<style scoped>
.b2b-page{max-width:1300px;margin:0 auto;padding:20px 16px 40px;color:#142126}header{display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:15px;margin-bottom:20px}h1{font-size:26px;margin:0}header p{font-size:13px;color:#71848b}a{color:#236f6a;text-decoration:none}.action{background:#28786c;color:white;padding:10px 16px;border-radius:10px;font-size:14px}.pending-action{font-size:12px;color:#57786e;border:1px solid #d7e3e0;border-radius:8px;padding:8px 12px}.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}.metrics article{background:#fff;border:1px solid #e5ecec;border-radius:12px;padding:14px}.metrics span{display:block;color:#647982;font-size:12px}.metrics strong{display:block;font-size:23px;margin-top:5px}.filters{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:16px}.filters input,.filters select{border:1px solid #d8e3e4;border-radius:8px;padding:9px;font-size:13px;background:#fff}.filters input{flex:1;min-width:175px}.filters button{background:#28786c;border:0;border-radius:9px;color:white;padding:9px 17px}.table-scroll{background:white;border:1px solid #e4eaed;overflow-x:auto;border-radius:12px}table{border-collapse:collapse;min-width:920px;width:100%;font-size:13px}th,td{padding:12px;text-align:left;border-bottom:1px solid #eef1f2}th{color:#698087;font-weight:600}td small{display:block;margin-top:5px;font-size:11px;color:#72868c}.empty{text-align:center;color:#72868c;padding:30px}.mobile-cases{display:none}.mobile-case-card{min-width:0;background:#fff;border:1px solid #e2eaeb;border-radius:14px;padding:16px;box-shadow:0 1px 2px rgba(20,33,38,.03)}.mobile-case-top{display:flex;justify-content:space-between;align-items:center;gap:8px}.mobile-case-number{font-size:12px;font-weight:650;overflow-wrap:anywhere}.mobile-case-status{flex:none;border-radius:99px;background:#e7f2ef;color:#20695e;padding:4px 9px;font-size:11px}.mobile-case-company{display:block;margin-top:12px;font-size:16px;font-weight:650;overflow-wrap:anywhere}.mobile-case-title{color:#5a6f77;font-size:12px;margin:6px 0 12px;line-height:1.5;overflow-wrap:anywhere}.mobile-case-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px 16px;margin:0}.mobile-case-facts>div{min-width:0}.mobile-case-facts dt{color:#789096;font-size:11px;margin-bottom:3px}.mobile-case-facts dd{margin:0;font-size:13px;font-weight:500;color:#243a40;overflow-wrap:anywhere}.mobile-cases-empty{padding:22px;background:#fff;border:1px solid #e2eaeb;border-radius:12px;text-align:center;color:#7a8d93;font-size:13px}.links{display:flex;gap:6px;margin-top:16px;flex-wrap:wrap}.links a{padding:6px 9px;border:1px solid #dbe5e5;border-radius:7px}.links .active{font-weight:bold;background:#eff6f4}@media(max-width:680px){.table-scroll{display:none}.mobile-cases{display:grid;grid-template-columns:minmax(0,1fr);gap:12px}.b2b-page{padding:14px 12px}.metrics{grid-template-columns:repeat(2,1fr)}.filters input{width:100%;flex:auto}}
</style>
