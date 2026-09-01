<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type KolRow={id:number;display_name:string;owner_name:string|null;created_at:string|null;approval_updated_at:string|null;reason_code:string|null}
type CollaborationRow={id:number;kol_id:number;kol_name:string;owner_name:string|null;title:string|null;cooperation_type:string|null;amount:string;currency:string;created_at:string|null}
type PayoutRow={id:number;kol_id:number;kol_name:string;requester_name:string;affiliate_id:number;amount:string;currency:string;referral_ids:number[];created_at:string|null}
type HistoryRow={record_key:string;type:'kol'|'collaboration'|'payout';object_id:number;object_label:string;outcome:'approved'|'rejected';actor_name:string;decided_at:string|null;source:string;detail:string|null;reason:string|null;amount:string|null;currency:string|null}
type Workspace={counts:{kols:number;collaborations:number;payouts:number;history:number};kols:KolRow[];collaborations:CollaborationRow[];payouts:PayoutRow[];history:HistoryRow[]}

const props=defineProps<{workspace:Workspace}>()
const tab=ref<'kols'|'collaborations'|'payouts'|'history'>('kols')
const historyType=ref<'all'|'kol'|'collaboration'|'payout'>('all')
const historyOutcome=ref<'all'|'approved'|'rejected'>('all')
const reasons=reactive<Record<string,string>>({})
const total=computed(()=>props.workspace.counts.kols+props.workspace.counts.collaborations+props.workspace.counts.payouts)
const filteredHistory=computed(()=>props.workspace.history.filter(row=>(historyType.value==='all'||row.type===historyType.value)&&(historyOutcome.value==='all'||row.outcome===historyOutcome.value)))
const breadcrumbs=[{label:'BYL ERP',href:'/dashboard'},{label:'网红',href:'/kol'},'审批中心']
function approve(kind:string,id:number){router.post(`/kol/approvals/${kind}/${id}/approve`,{}, {preserveScroll:true})}
function reject(kind:string,id:number){const key=`${kind}:${id}`;const reason=(reasons[key]||'').trim();if(reason)router.post(`/kol/approvals/${kind}/${id}/reject`,{reason},{preserveScroll:true})}
function date(v:string|null){return v?new Date(v).toLocaleString():'—'}
function typeLabel(v:HistoryRow['type']){return v==='kol'?'KOL':v==='collaboration'?'付费合作':'佣金付款'}
function outcomeLabel(v:HistoryRow['outcome']){return v==='approved'?'已批准':'已驳回'}
</script>

<template>
  <Head title="KOL 审批中心 · BYL ERP" />
  <AuthenticatedLayout title="审批中心" :breadcrumbs="breadcrumbs">
    <section class="approval-page">
      <div class="approval-heading">
        <div><p class="mvp-kicker">KOL · Manager</p><h2>审批中心</h2><p>集中处理 Manager 审批，并保留可追溯的审批记录。批准佣金付款不等于 Finance 已完成真实付款。</p></div>
        <div class="approval-total"><strong>{{ total }}</strong><span>待处理</span><small>{{ workspace.counts.history }} 条审批记录</small></div>
      </div>

      <div class="approval-tabs" role="tablist">
        <button :class="{active:tab==='kols'}" @click="tab='kols'">KOL 审批 <b>{{ workspace.counts.kols }}</b></button>
        <button :class="{active:tab==='collaborations'}" @click="tab='collaborations'">付费合作 <b>{{ workspace.counts.collaborations }}</b></button>
        <button :class="{active:tab==='payouts'}" @click="tab='payouts'">佣金付款 <b>{{ workspace.counts.payouts }}</b></button>
        <button :class="{active:tab==='history'}" @click="tab='history'">审批记录 <b>{{ workspace.counts.history }}</b></button>
      </div>

      <div v-if="tab==='kols'" class="mvp-card approval-list">
        <article v-for="row in workspace.kols" :key="row.id" class="approval-row"><div><strong><Link :href="`/kol/library/${row.id}`">{{ row.display_name }}</Link></strong><p>{{ row.owner_name || '未分配负责人' }} · {{ row.reason_code || '等待 Manager 决策' }} · {{ date(row.approval_updated_at||row.created_at) }}</p></div><div class="approval-actions"><button @click="approve('kols',row.id)">批准</button><input v-model="reasons[`kols:${row.id}`]" placeholder="驳回原因"><button :disabled="!(reasons[`kols:${row.id}`]||'').trim()" @click="reject('kols',row.id)">驳回</button></div></article>
        <div v-if="!workspace.kols.length" class="empty">没有待审批 KOL。</div>
      </div>

      <div v-if="tab==='collaborations'" class="mvp-card approval-list">
        <article v-for="row in workspace.collaborations" :key="row.id" class="approval-row"><div><strong>{{ row.kol_name }} · {{ row.title || `合作 #${row.id}` }}</strong><p>{{ row.owner_name || '—' }} · {{ row.cooperation_type }} · {{ row.currency }} {{ row.amount }} · {{ date(row.created_at) }}</p></div><div class="approval-actions"><button @click="approve('collaborations',row.id)">批准</button><input v-model="reasons[`collaborations:${row.id}`]" placeholder="驳回原因"><button :disabled="!(reasons[`collaborations:${row.id}`]||'').trim()" @click="reject('collaborations',row.id)">驳回</button></div></article>
        <div v-if="!workspace.collaborations.length" class="empty">没有待审批付费合作。</div>
      </div>

      <div v-if="tab==='payouts'" class="mvp-card approval-list">
        <article v-for="row in workspace.payouts" :key="row.id" class="approval-row"><div><strong>#{{ row.id }} · {{ row.kol_name }} · {{ row.currency }} {{ row.amount }}</strong><p>{{ row.requester_name }} · Affiliate #{{ row.affiliate_id }} · Referral {{ row.referral_ids.map(id=>`#${id}`).join(', ') }} · {{ date(row.created_at) }}</p></div><div class="approval-actions"><button @click="approve('payouts',row.id)">批准付款</button><input v-model="reasons[`payouts:${row.id}`]" placeholder="驳回原因"><button :disabled="!(reasons[`payouts:${row.id}`]||'').trim()" @click="reject('payouts',row.id)">驳回</button></div></article>
        <div v-if="!workspace.payouts.length" class="empty">没有待审批佣金付款申请。</div>
      </div>

      <div v-if="tab==='history'" class="history-wrap">
        <div class="history-toolbar">
          <label>类型 <select v-model="historyType"><option value="all">全部</option><option value="kol">KOL</option><option value="collaboration">付费合作</option><option value="payout">佣金付款</option></select></label>
          <label>结果 <select v-model="historyOutcome"><option value="all">全部</option><option value="approved">已批准</option><option value="rejected">已驳回</option></select></label>
          <span>按审批时间倒序，显示最近 {{ workspace.counts.history }} 条。</span>
        </div>
        <div class="mvp-card approval-list">
          <article v-for="row in filteredHistory" :key="row.record_key" class="history-row">
            <div class="history-main"><div class="history-tags"><span>{{ typeLabel(row.type) }}</span><b :class="row.outcome">{{ outcomeLabel(row.outcome) }}</b></div><strong>{{ row.object_label }}</strong><p><span v-if="row.detail">{{ row.detail }} · </span><span v-if="row.amount">{{ row.currency }} {{ row.amount }} · </span>{{ row.actor_name }} · {{ date(row.decided_at) }}</p><p v-if="row.reason" class="history-reason">原因：{{ row.reason }}</p></div>
          </article>
          <div v-if="!filteredHistory.length" class="empty">没有符合条件的审批记录。</div>
        </div>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.approval-page{display:grid;gap:16px}.approval-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:20px}.approval-heading h2{margin:4px 0}.approval-heading p{max-width:820px;color:#64748b}.approval-total{text-align:right}.approval-total strong,.approval-total span,.approval-total small{display:block}.approval-total strong{font-size:30px}.approval-total small{margin-top:4px;color:#94a3b8}.approval-tabs{display:flex;gap:8px;border-bottom:1px solid #e2e8f0}.approval-tabs button{border:0;border-bottom:2px solid transparent;background:transparent;padding:10px 14px;color:#64748b;cursor:pointer}.approval-tabs button.active{border-bottom-color:#0f172a;color:#0f172a}.approval-tabs b{margin-left:6px}.approval-list{padding:0;overflow:hidden}.approval-row,.history-row{display:flex;justify-content:space-between;gap:20px;align-items:center;padding:15px 17px;border-bottom:1px solid #e2e8f0}.approval-row p,.history-row p{margin:5px 0 0;color:#64748b;font-size:12px}.approval-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.approval-actions button,.approval-actions input,.history-toolbar select{border:1px solid #cbd5e1;border-radius:8px;background:#fff;padding:8px 10px}.approval-actions input{min-width:170px}.approval-actions button:disabled{color:#94a3b8}.history-wrap{display:grid;gap:10px}.history-toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;color:#64748b;font-size:12px}.history-toolbar label{display:flex;align-items:center;gap:6px}.history-main{min-width:0}.history-tags{display:flex;align-items:center;gap:7px;margin-bottom:5px}.history-tags span,.history-tags b{border-radius:999px;padding:3px 7px;font-size:11px}.history-tags span{background:#f1f5f9;color:#475569}.history-tags b.approved{background:#ecfdf5;color:#047857}.history-tags b.rejected{background:#fff1f2;color:#be123c}.history-reason{color:#475569!important}.empty{padding:44px;text-align:center;color:#64748b}@media(max-width:900px){.approval-heading,.approval-row{flex-direction:column;align-items:flex-start}.approval-total{text-align:left}.approval-tabs{overflow:auto}.approval-actions{width:100%}}
</style>
