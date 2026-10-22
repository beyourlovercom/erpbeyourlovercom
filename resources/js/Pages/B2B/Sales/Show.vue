<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Node={key:string;label:string;visited:boolean;current:boolean;entered_at:string|null}
type Event={id:number;from_stage:string|null;to_stage:string;entered_at:string|null;source:string;actor:string|null}
type Row={
 id:number;opportunity_number:string;title:string;status:string;pipeline_stage:string;
 currency:string;expected_value_micros:number|null;probability_bps:number;
 company:{id:number;name:string;country_code:string|null}|null;
 owner:{id:number;name:string}|null;contact:{id:number;name:string}|null;
 case:{id:number;case_number:string}|null;stage_events:Event[];
}
const props=defineProps<{opportunity:Row;roadmap:Node[];canOperate:boolean}>()
const update=useForm({pipeline_stage:props.opportunity.pipeline_stage})
const label=(s:string)=>({'discovery':'初步接洽','qualified':'已确认需求','solution':'方案阶段','quoted':'已报价','negotiating':'谈判中','won':'成交','lost':'丢单'} as Record<string,string>)[s]||s
const money=(v:number|null,currency:string)=>v===null?'—':new Intl.NumberFormat('zh-CN',{style:'currency',currency}).format(v/1000000)
const save=()=>update.patch(`/b2b/sales/opportunities/${props.opportunity.id}/stage`)
</script>
<template>
  <Head :title="`${opportunity.opportunity_number} · B2B 销售路线图`" />
  <AuthenticatedLayout title="销售路线图" :breadcrumbs="['BYL ERP','B2B 业务','销售漏斗',opportunity.opportunity_number]" canonical-topbar>
    <section class="sales-show-page">
      <div class="sales-show-header">
        <div><p class="kicker">YBY SALES OS · BYL B2B ADAPTER</p><h1>{{ opportunity.title }}</h1><p>{{ opportunity.opportunity_number }} · {{ opportunity.company?.name }} · {{ opportunity.owner?.name||'待分配' }}</p></div>
        <Link href="/b2b/sales" class="go-back">返回销售漏斗</Link>
      </div>
      <div class="metrics">
        <article><small>商机阶段</small><strong>{{ label(opportunity.status==='open'?opportunity.pipeline_stage:opportunity.status) }}</strong></article>
        <article><small>预计金额 · 非实际收入</small><strong>{{ money(opportunity.expected_value_micros,opportunity.currency) }}</strong></article>
        <article><small>成交概率</small><strong>{{ (opportunity.probability_bps/100).toFixed(0) }}%</strong></article>
        <article><small>关联询盘</small><Link v-if="opportunity.case" :href="`/b2b/cases/${opportunity.case.id}`">{{ opportunity.case.case_number }}</Link><strong v-else>手动商机</strong></article>
      </div>
      <article class="sales-roadmap">
        <h2>销售进度路线图</h2><p>保留 YBY 五阶段和访问过的历史阶段。仅进入过的阶段标记为已到达。</p>
        <div class="stages">
          <div v-for="(node,i) in roadmap" :key="node.key" class="stage" :class="{visited:node.visited,current:node.current}">
            <span class="step">{{ i+1 }}</span><strong>{{ node.label }}</strong><small>{{ node.entered_at?.slice(0,10) || '尚未进入' }}</small>
          </div>
        </div>
      </article>
      <article v-if="canOperate && opportunity.status==='open'" class="sales-manage">
        <h2>调整销售阶段</h2>
        <form @submit.prevent="save">
          <select v-model="update.pipeline_stage" required>
            <option v-for="node in roadmap" :key="node.key" :value="node.key">{{ node.label }}</option>
          </select>
          <button type="submit" :disabled="update.processing">保存阶段</button>
        </form>
        <p v-if="update.errors.pipeline_stage" class="error">{{ update.errors.pipeline_stage }}</p>
      </article>
      <article class="sales-history">
        <h2>阶段历史（不可覆盖）</h2>
        <ol><li v-for="ev in opportunity.stage_events" :key="ev.id">
          <strong>{{ label(ev.from_stage || 'new') }} → {{ label(ev.to_stage) }}</strong>
          <span>{{ ev.entered_at?.slice(0,16).replace('T',' ') }} · {{ ev.actor || '系统' }}</span>
        </li></ol>
        <p v-if="!opportunity.stage_events.length">尚无阶段记录</p>
      </article>
      <p class="roadmap-caveat">报价版本、阶段附件、成交／丢单与收款真相属于后续 S2/S3 移植范围；当前 S1 不发送报价、不创建真实收款。</p>
    </section>
  </AuthenticatedLayout>
</template>
<style scoped>
.sales-show-page{padding:24px;background:#f5f7f7;color:#193a30;min-height:100%;font-family:PingFang SC,-apple-system,BlinkMacSystemFont,Arial,sans-serif}
.sales-show-header{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:18px}.sales-show-header h1{font-size:25px;color:#172e27;margin:4px 0}.sales-show-header p{color:#778983;margin:5px 0;font-size:12px}.kicker{letter-spacing:.09em;font-weight:700}.go-back{color:#166b51;text-decoration:none;border:1px solid #d3dfd7;padding:9px 12px;border-radius:8px;font-size:12px}.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:15px 0}.metrics article,.sales-roadmap,.sales-manage,.sales-history{background:white;border:1px solid #dde7df;border-radius:14px;padding:18px}.metrics article{display:flex;flex-direction:column;gap:9px;min-width:0}.metrics strong{font-size:16px;overflow-wrap:anywhere}.metrics small{color:#76857c;font-size:11px}.metrics a{color:#196c50}.sales-roadmap,.sales-manage,.sales-history{margin-top:15px}.sales-roadmap h2,.sales-manage h2,.sales-history h2{font-size:17px;margin:0 0 7px}.sales-roadmap p{font-size:12px;color:#708379}.stages{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-top:22px}.stage{min-width:0;border:1px solid #e2e9e5;border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:7px;background:#f7f9f7}.stage.visited{background:#e8f2e8;border-color:#b8d7c8}.stage.current{background:#d0eade;border-color:#177951}.stage .step{background:#e1e7e3;border-radius:99px;width:26px;height:26px;display:grid;place-items:center;font-size:11px}.stage strong{font-size:12px;overflow-wrap:anywhere}.stage small{font-size:11px;color:#72847a}.sales-manage form{display:flex;gap:10px;align-items:center}.sales-manage select,.sales-manage button{border:1px solid #d8e5db;border-radius:7px;padding:10px;font:inherit;font-size:13px}.sales-manage button{background:#196c50;color:white;cursor:pointer}.sales-history ol{padding-left:19px;margin:0}.sales-history li{margin:10px 0}.sales-history li strong{display:block;font-size:13px}.sales-history li span{color:#75837b;font-size:11px}.roadmap-caveat{font-size:12px;color:#887953;margin:18px 0}
@media(max-width:720px){.sales-show-page{padding:12px}.sales-show-header{align-items:flex-start;flex-direction:column}.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.stages{grid-template-columns:repeat(2,minmax(0,1fr))}.stage:last-child{grid-column:1/-1}.sales-manage form{flex-direction:column;align-items:stretch}.sales-show-header h1{font-size:21px}}
</style>
