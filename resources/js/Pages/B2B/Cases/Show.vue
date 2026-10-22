<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
const props=defineProps<{
  case:{id:number;case_number:string;title:string;organization_id:number;organization:string|null;contact:string|null;country_code:string|null;source:string;product_interest:string|null;requested_quantity:number|null;inquiry_kind:string;priority:string;stage:string;status:string;handling:string;next_follow_up_at:string|null};
  activities:Array<{id:number;type:string;text:string|null;time:string|null}>;
  canOperate:boolean
}>()
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
            <dt>阶段</dt><dd>{{ props.case.stage }}</dd><dt>处理状态</dt><dd>{{ props.case.handling }}</dd>
            <dt>下次跟进</dt><dd>{{ props.case.next_follow_up_at?props.case.next_follow_up_at.slice(0,16):'未设置' }}</dd></dl>
        </section>
        <section class="panel">
          <h2>跟进时间线</h2>
          <ol><li v-for="activity in activities" :key="activity.id"><span>{{ activity.time?activity.time.slice(0,16):'—' }}</span><strong>{{ activity.type }}</strong><p>{{ activity.text||'—' }}</p></li></ol>
          <p v-if="!activities.length" class="muted">暂无跟进记录</p>
        </section>
      </div>
    </main>
  </AuthenticatedLayout>
</template>
<style scoped>
.case-main{max-width:1120px;margin:0 auto;padding:20px 16px 40px;color:#142126}header{margin-bottom:20px}header a{color:#28786c;font-size:13px;text-decoration:none}h1{font-size:25px;margin:10px 0}header p{font-size:13px;color:#637982}.case-grid{display:grid;grid-template-columns:1fr 1.2fr;gap:16px}.panel{background:white;border:1px solid #e4eaed;border-radius:16px;padding:22px}.panel h2{font-size:17px;margin:0 0 16px}dl{display:grid;grid-template-columns:95px 1fr;gap:12px;font-size:13px}dt{color:#76898f}dd{margin:0;overflow-wrap:anywhere}a{color:#28786c}ol{list-style:none;padding:0;margin:0}li{padding:12px 0;border-bottom:1px solid #edf2f2;font-size:13px}li span{color:#789097;margin-right:14px;font-size:12px}li p{color:#465e67;margin:9px 0 0;white-space:pre-wrap}.muted{font-size:13px;color:#74878e}@media(max-width:720px){.case-grid{grid-template-columns:1fr}.case-main{padding:12px}.panel{padding:16px}}
</style>
