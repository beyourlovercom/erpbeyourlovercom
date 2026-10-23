<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Node={key:string;label:string;visited:boolean;current:boolean;entered_at:string|null}
type StageEvent={id:number;from_stage:string|null;to_stage:string;entered_at:string|null;source:string;reason:string|null;actor:string|null}
type Row={
 id:number;opportunity_number:string;title:string;status:string;pipeline_stage:string;
 currency:string;expected_value_micros:number|null;probability_bps:number;
 company:{id:number;name:string;country_code:string|null}|null;
 owner:{id:number;name:string}|null;contact:{id:number;name:string}|null;
 case:{id:number;case_number:string}|null;stage_events:StageEvent[];
}
type FollowUp={id:number;stage_key:string;channel:string;summary:string;outcome:string|null;next_plan:string|null;happened_at:string|null;actor:string|null;attachments:Array<{id:number;name:string;href:string}>}
type Quote={id:number;quote_number:string;version:number;status:string;currency:string;subtotal_micros:number;discount_micros:number;tax_micros:number;total_micros:number;notes:string|null}
const props=defineProps<{opportunity:Row;roadmap:Node[];canOperate:boolean;canQuote:boolean;followUps:FollowUp[];quotes:Quote[]}>()
const update=useForm({pipeline_stage:props.opportunity.pipeline_stage})
const label=(s:string)=>({'discovery':'初步接洽','qualified':'已确认需求','solution':'方案阶段','quoted':'已报价','negotiating':'谈判中','won':'成交','lost':'丢单'} as Record<string,string>)[s]||s
const money=(v:number|null,currency:string)=>v===null?'—':new Intl.NumberFormat('zh-CN',{style:'currency',currency}).format(v/1000000)
const followForm=useForm({stage_key:props.opportunity.status==='open'?props.opportunity.pipeline_stage:props.opportunity.status,channel:'note',summary:'',outcome:'',next_plan:'',next_follow_up_at:'',attachments:[] as File[]})
const quoteForm=useForm({currency:props.opportunity.currency,subtotal:'',discount:'0',tax:'0',notes:''})
const lostForm=useForm({reason_code:'',reason_note:''})
const wonForm=useForm({})
const reopenForm=useForm({stage:'discovery',reason:''})
const filesChanged=(event:Event)=>{const target=event.target as HTMLInputElement;followForm.attachments=Array.from(target.files||[])}
const saveFollow=()=>followForm.post(`/b2b/sales/opportunities/${props.opportunity.id}/followups`,{forceFormData:true,onSuccess:()=>followForm.reset('summary','outcome','next_plan','next_follow_up_at','attachments')})
const micros=(amount:string):number=>Math.round(Number(amount)*1000000)
const saveQuote=()=>quoteForm.transform(d=>({currency:d.currency,subtotal_micros:micros(d.subtotal),discount_micros:micros(d.discount),tax_micros:micros(d.tax),notes:d.notes})).post(`/b2b/sales/opportunities/${props.opportunity.id}/quotes`)
const closeWon=()=>wonForm.post(`/b2b/sales/opportunities/${props.opportunity.id}/won`)
const closeLost=()=>lostForm.post(`/b2b/sales/opportunities/${props.opportunity.id}/lost`)
const reopen=()=>reopenForm.post(`/b2b/sales/opportunities/${props.opportunity.id}/reopen`)
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
            <option v-for="node in roadmap.filter(n=>!(['final','won','lost'].includes(n.key)))" :key="node.key" :value="node.key">{{ node.label }}</option>
          </select>
          <button type="submit" :disabled="update.processing">保存阶段</button>
        </form>
        <p v-if="update.errors.pipeline_stage" class="error">{{ update.errors.pipeline_stage }}</p>
      </article>
      <article class="sales-history">
        <h2>阶段历史（不可覆盖）</h2>
        <ol><li v-for="ev in opportunity.stage_events" :key="ev.id">
          <strong>{{ ev.from_stage ? label(ev.from_stage) : '商机创建' }} → {{ label(ev.to_stage) }}</strong>
          <span>{{ ev.entered_at?.slice(0,16).replace('T',' ') }} · {{ ev.actor || '系统' }} <template v-if="ev.reason">· {{ ev.reason }}</template></span>
        </li></ol>
        <p v-if="!opportunity.stage_events.length">尚无阶段记录</p>
      </article>
      <div class="s2-grid">
        <article class="sales-history">
          <h2>阶段跟进记录</h2>
          <form v-if="canOperate" @submit.prevent="saveFollow" class="s2-form">
            <select v-model="followForm.stage_key" required>
              <option v-for="node in roadmap" :key="node.key" v-show="node.key!=='final'" :value="node.key">{{ node.label }}</option>
            </select>
            <select v-model="followForm.channel"><option value="note">备注</option><option value="email">邮件记录</option><option value="whatsapp">WhatsApp 记录</option><option value="call">电话</option><option value="meeting">会议</option></select>
            <textarea v-model="followForm.summary" required placeholder="实际跟进内容；不会自动发送任何邮件"></textarea>
            <input v-model="followForm.next_plan" placeholder="下一步计划（可选）">
            <input v-model="followForm.next_follow_up_at" type="datetime-local">
            <label class="small-label">跟进附件（PDF/图片/DOCX，最多 3 个，单个 5MB）<input type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.docx" @change="filesChanged"></label>
            <button type="submit" :disabled="followForm.processing">保存跟进</button>
            <p v-if="Object.keys(followForm.errors).length" class="error">{{ Object.values(followForm.errors).join('；') }}</p>
          </form>
          <ol><li v-for="fu in followUps" :key="fu.id">
            <strong>{{ label(fu.stage_key) }} · {{ fu.summary }}</strong>
            <span>{{ fu.happened_at?.slice(0,16).replace('T',' ') }} · {{ fu.actor||'系统' }} · {{ fu.channel }}</span>
            <small v-if="fu.next_plan">下一步：{{ fu.next_plan }}</small>
            <p v-for="attachment in fu.attachments" :key="attachment.id"><a :href="attachment.href">{{ attachment.name }} <span>下载</span></a></p>
          </li></ol>
          <p v-if="!followUps.length">尚无商机跟进</p>
        </article>
        <article class="sales-history">
          <h2>报价版本（内部草稿）</h2>
          <form v-if="canOperate && canQuote && opportunity.status==='open'" class="s2-form" @submit.prevent="saveQuote">
            <input v-model="quoteForm.currency" maxlength="3" required placeholder="USD">
            <input v-model="quoteForm.subtotal" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,6})?" required placeholder="小计金额，例如 250.00">
            <input v-model="quoteForm.discount" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,6})?" placeholder="折扣">
            <input v-model="quoteForm.tax" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,6})?" placeholder="税费">
            <textarea v-model="quoteForm.notes" placeholder="报价备注，仅 ERP 内部草稿，不会发送邮件"></textarea>
            <button :disabled="quoteForm.processing">保存下一报价版本</button>
            <p v-if="Object.keys(quoteForm.errors).length" class="error">{{ Object.values(quoteForm.errors).join('；') }}</p>
          </form>
          <ol><li v-for="q in quotes" :key="q.id">
            <strong>报价 v{{ q.version }} · {{ money(q.total_micros,q.currency) }}</strong>
            <span>{{ q.quote_number }} · 草稿 · 小计 {{ money(q.subtotal_micros,q.currency) }}</span>
            <small v-if="q.notes">{{ q.notes }}</small>
          </li></ol>
          <p v-if="!quotes.length">尚无报价版本</p>
        </article>
      </div>
      <article class="sales-history">
        <h2>成交或丢单（不等于财务入账）</h2>
        <div v-if="canOperate && opportunity.status==='open'" class="s2-grid">
          <form @submit.prevent="closeWon" class="s2-form"><p>成交只改变销售商机状态，不确认实际收款。</p><button :disabled="wonForm.processing">标记成交 Won</button></form>
          <form @submit.prevent="closeLost" class="s2-form">
            <input v-model="lostForm.reason_code" required maxlength="64" placeholder="丢单原因，如 price、timing">
            <textarea v-model="lostForm.reason_note" placeholder="原因说明（可选）"></textarea>
            <button :disabled="lostForm.processing">标记丢单 Lost</button>
            <p v-if="Object.keys(lostForm.errors).length" class="error">{{ Object.values(lostForm.errors).join('；') }}</p>
          </form>
        </div>
        <form v-if="canOperate && opportunity.status==='lost'" class="s2-form" @submit.prevent="reopen">
          <select v-model="reopenForm.stage"><option v-for="node in roadmap.filter(n=>!['final','won','lost'].includes(n.key))" :key="node.key" :value="node.key">{{ node.label }}</option></select>
          <textarea v-model="reopenForm.reason" required placeholder="重新开启原因（写入不可覆盖的历史）"></textarea>
          <button :disabled="reopenForm.processing">重新开启商机</button>
        </form>
        <p v-if="opportunity.status==='won'">商机已成交；确认回款必须进入后续财务 S3。</p>
      </article>
      <p class="roadmap-caveat">S2 仅保存私有附件、跟进记录和报价草稿。报价没有被发送；Won 不自动入账。实际应收／收款仍待 S3 与 BYL Accounting 独立验收。</p>
    </section>
  </AuthenticatedLayout>
</template>
<style scoped>
.sales-show-page{padding:24px;background:#f5f7f7;color:#193a30;min-height:100%;font-family:PingFang SC,-apple-system,BlinkMacSystemFont,Arial,sans-serif}
.sales-show-header{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:18px}.sales-show-header h1{font-size:25px;color:#172e27;margin:4px 0}.sales-show-header p{color:#778983;margin:5px 0;font-size:12px}.kicker{letter-spacing:.09em;font-weight:700}.go-back{color:#166b51;text-decoration:none;border:1px solid #d3dfd7;padding:9px 12px;border-radius:8px;font-size:12px}.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:15px 0}.metrics article,.sales-roadmap,.sales-manage,.sales-history{background:white;border:1px solid #dde7df;border-radius:14px;padding:18px}.metrics article{display:flex;flex-direction:column;gap:9px;min-width:0}.metrics strong{font-size:16px;overflow-wrap:anywhere}.metrics small{color:#76857c;font-size:11px}.metrics a{color:#196c50}.sales-roadmap,.sales-manage,.sales-history{margin-top:15px}.sales-roadmap h2,.sales-manage h2,.sales-history h2{font-size:17px;margin:0 0 7px}.sales-roadmap p{font-size:12px;color:#708379}.stages{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-top:22px}.stage{min-width:0;border:1px solid #e2e9e5;border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:7px;background:#f7f9f7}.stage.visited{background:#e8f2e8;border-color:#b8d7c8}.stage.current{background:#d0eade;border-color:#177951}.stage .step{background:#e1e7e3;border-radius:99px;width:26px;height:26px;display:grid;place-items:center;font-size:11px}.stage strong{font-size:12px;overflow-wrap:anywhere}.stage small{font-size:11px;color:#72847a}.sales-manage form{display:flex;gap:10px;align-items:center}.sales-manage select,.sales-manage button{border:1px solid #d8e5db;border-radius:7px;padding:10px;font:inherit;font-size:13px}.sales-manage button{background:#196c50;color:white;cursor:pointer}.sales-history ol{padding-left:19px;margin:0}.sales-history li{margin:10px 0}.sales-history li strong{display:block;font-size:13px}.sales-history li span{color:#75837b;font-size:11px}.roadmap-caveat{font-size:12px;color:#887953;margin:18px 0}
@media(max-width:720px){.sales-show-page{padding:12px}.sales-show-header{align-items:flex-start;flex-direction:column}.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.stages{grid-template-columns:repeat(2,minmax(0,1fr))}.stage:last-child{grid-column:1/-1}.sales-manage form{flex-direction:column;align-items:stretch}.sales-show-header h1{font-size:21px}}

.s2-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}
.s2-grid .sales-history{margin-top:15px}
.s2-form{display:grid;gap:10px;margin:15px 0}
.s2-form input,.s2-form select,.s2-form textarea,.s2-form button{min-width:0;width:100%;box-sizing:border-box;border:1px solid #d8e5db;border-radius:8px;padding:10px;font:inherit;font-size:13px;color:#253d31}
.s2-form textarea{min-height:70px;resize:vertical}
.s2-form button{background:#196c50;color:white;cursor:pointer}
.s2-form button:disabled{opacity:.5}
.s2-form .small-label{font-size:11px;color:#71887b}
.s2-form .small-label input{margin-top:7px}
.sales-history li small{display:block;color:#647e70;margin-top:5px}
.sales-history li p{margin:4px 0;font-size:12px}
.sales-history li a{color:#176c51}
.error{color:#b04335;font-size:12px}
@media(max-width:720px){.s2-grid{grid-template-columns:minmax(0,1fr)}}
</style>
