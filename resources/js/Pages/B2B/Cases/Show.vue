<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
const props=defineProps<{
  case:{id:number;case_number:string;title:string;organization_id:number;owner_id:number|null;organization:string|null;contact:string|null;country_code:string|null;source:string;product_interest:string|null;requested_quantity:number|null;inquiry_kind:string;priority:string;stage:string;status:string;handling:string;next_follow_up_at:string|null};
  activities:Array<{id:number;type:string;text:string|null;time:string|null}>;
  canOperate:boolean; canAssign:boolean; owners:Array<{id:number;name:string}>;
}>()
const state=useForm({
  case_stage:props.case.stage,case_status:props.case.status,
  handling_state:props.case.handling==='none'?'in_progress':props.case.handling,
  priority:props.case.priority,
  next_follow_up_at:props.case.next_follow_up_at?.slice(0,16)||'',
  loss_reason_code:'',
})
const note=useForm({body:''})
const owner=useForm({owner_id:props.case.owner_id?String(props.case.owner_id):''})
const save=()=>state.patch(`/b2b/cases/${props.case.id}`,{preserveScroll:true})
const addNote=()=>note.post(`/b2b/cases/${props.case.id}/notes`,{preserveScroll:true,onSuccess:()=>note.reset()})
const assign=()=>owner.post(`/b2b/cases/${props.case.id}/assignment`,{preserveScroll:true})
</script>
<template>
  <Head :title="`${props.case.case_number||'询盘'} · BYL ERP`" />
  <AuthenticatedLayout title="B2B 询盘" :breadcrumbs="['BYL ERP','B2B 业务','询盘管理',props.case.case_number||'详情']">
    <main class="case-main">
      <header><Link href="/b2b/inquiries">← 询盘列表</Link><h1>{{ props.case.title }}</h1><p>{{ props.case.case_number }} · {{ props.case.source }}</p></header>
      <div class="case-grid">
        <section class="panel">
          <h2>询盘详情</h2>
          <dl><dt>公司</dt><dd><Link :href="`/b2b/companies/${props.case.organization_id}`">{{ props.case.organization||'—' }}</Link></dd>
            <dt>联系人</dt><dd>{{ props.case.contact||'—' }}</dd>
            <dt>国家</dt><dd>{{ props.case.country_code||'—' }}</dd>
            <dt>产品 / 数量</dt><dd>{{ props.case.product_interest||'未填写' }} / {{ props.case.requested_quantity||'待确认' }}</dd>
            <dt>商业意向</dt><dd>{{ props.case.inquiry_kind }}</dd>
            <dt>优先级</dt><dd>{{ props.case.priority }}</dd>
            <dt>阶段</dt><dd>{{ props.case.stage }}</dd><dt>处理状态</dt><dd>{{ props.case.handling }}</dd>
            <dt>下次跟进</dt><dd>{{ props.case.next_follow_up_at?props.case.next_follow_up_at.slice(0,16):'未设置' }}</dd></dl>
          <form v-if="canOperate && props.case.status==='open'" class="edit-form" @submit.prevent="save">
            <h3>更新询盘进度</h3>
            <div class="fields">
              <label>阶段<select v-model="state.case_stage"><option value="new">新询盘</option><option value="contacted">已联系</option><option value="qualified">已验证</option><option value="quoted">已报价</option><option value="negotiating">谈判中</option></select></label>
              <label>状态<select v-model="state.case_status"><option value="open">处理中</option><option value="won">成交</option><option value="lost">流失</option><option value="spam">无效</option></select></label>
              <label v-if="state.case_status==='lost'">流失原因<input v-model="state.loss_reason_code" maxlength="64" required /></label>
              <label>处理状态<select v-model="state.handling_state"><option value="waiting">待处理</option><option value="in_progress">跟进中</option><option value="waiting_customer">等待客户回复</option></select></label>
              <label>优先级<select v-model="state.priority"><option value="low">低</option><option value="normal">正常</option><option value="high">高</option></select></label>
              <label>下次跟进<input v-model="state.next_follow_up_at" type="datetime-local" /></label>
            </div>
            <p v-if="Object.keys(state.errors).length" class="error">{{ Object.values(state.errors).join(' · ') }}</p>
            <button :disabled="state.processing" type="submit">保存进度</button>
          </form>
          <form v-if="canAssign" class="edit-form" @submit.prevent="assign">
            <h3>分配业务负责人</h3><label>负责人<select v-model="owner.owner_id" required><option value="">选择员工</option><option v-for="u in owners" :key="u.id" :value="String(u.id)">{{ u.name }}</option></select></label>
            <p class="error">{{ Object.values(owner.errors).join(' · ') }}</p><button :disabled="owner.processing" type="submit">更新负责人</button>
          </form>
        </section>
        <section class="panel">
          <h2>跟进时间线</h2>
          <form v-if="canOperate" class="note-form" @submit.prevent="addNote">
            <label for="case-note">跟进备注</label><textarea id="case-note" v-model="note.body" rows="4" maxlength="5000" placeholder="记录客户问题、跟进内容和下一步计划"></textarea>
            <p class="error">{{ Object.values(note.errors).join(' · ') }}</p><button :disabled="note.processing || !note.body.trim()" type="submit">保存备注</button>
          </form>
          <ol><li v-for="activity in activities" :key="activity.id"><span>{{ activity.time?activity.time.slice(0,16):'—' }}</span><strong>{{ activity.type }}</strong><p v-if="activity.text">{{ activity.text }}</p></li></ol>
          <p v-if="!activities.length" class="muted">暂无跟进记录</p>
        </section>
      </div>
    </main>
  </AuthenticatedLayout>
</template>
<style scoped>
.case-main{max-width:1120px;margin:0 auto;padding:20px 16px 40px;color:#142126}header{margin-bottom:20px}header a{color:#28786c;font-size:13px;text-decoration:none}h1{font-size:25px;margin:10px 0}header p{font-size:13px;color:#637982}.case-grid{display:grid;grid-template-columns:1fr 1.2fr;gap:16px}.panel{background:#fff;border:1px solid #e4eaed;border-radius:16px;padding:22px}.panel h2{font-size:17px;margin:0 0 16px}dl{display:grid;grid-template-columns:95px 1fr;gap:12px;font-size:13px}dt{color:#76898f}dd{margin:0;overflow-wrap:anywhere}a{color:#28786c}ol{list-style:none;padding:0;margin:16px 0 0}li{padding:12px 0;border-bottom:1px solid #edf2f2;font-size:13px}li span{color:#789097;margin-right:14px;font-size:12px}li p{color:#465e67;margin:9px 0 0;white-space:pre-wrap}.muted{font-size:13px;color:#74878e}.edit-form,.note-form{border-top:1px solid #e9efee;padding-top:17px;margin-top:18px}.edit-form h3{font-size:15px}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}label{display:flex;flex-direction:column;gap:6px;font-size:13px}input,select,textarea{border:1px solid #d8e3e3;border-radius:9px;padding:10px;font:inherit;min-width:0;max-width:100%}.note-form textarea{width:100%;display:block;margin-top:8px}.edit-form button,.note-form button{background:#28786c;color:#fff;border:0;padding:10px 15px;border-radius:9px;margin-top:12px}.edit-form button:disabled,.note-form button:disabled{opacity:.5}.error{font-size:12px;color:#b83a3a}@media(max-width:720px){.case-grid{grid-template-columns:1fr}.case-main{padding:12px}.panel{padding:16px}.fields{grid-template-columns:1fr}}
</style>
