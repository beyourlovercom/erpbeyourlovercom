<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
type Organization = { id:number;name:string;country_code:string|null;contacts:Array<{id:number;name:string}> }
const props=defineProps<{organizations:Organization[];initialOrganization:number}>()
const form=useForm({
  organization_id: props.organizations.some(o=>o.id===props.initialOrganization)?String(props.initialOrganization):'',
  primary_contact_id:'',title:'',product_interest:'',requested_quantity:'',
  inquiry_kind:'wholesale',priority:'normal',next_follow_up_at:'',initial_note:'',
})
const contacts=computed(()=>props.organizations.find(o=>String(o.id)===form.organization_id)?.contacts||[])
const save=()=>form.post('/b2b/inquiries')
</script>
<template>
  <Head title="新建询盘 · BYL ERP" />
  <AuthenticatedLayout title="新建 B2B 询盘" :breadcrumbs="['BYL ERP','B2B 业务','询盘管理','新建']">
    <main class="case-form">
      <header><div><h1>新建询盘</h1><p>人工记录采购咨询；不会发邮件或创建订单</p></div>
        <div class="actions"><Link href="/b2b/inquiries">取消</Link><button type="button" :disabled="form.processing" @click="save">保存询盘</button></div>
      </header>
      <form @submit.prevent="save">
        <section class="panel"><h2>客户与询盘</h2>
          <div class="fields">
            <label>企业客户 *<select v-model="form.organization_id" required @change="form.primary_contact_id=''">
              <option value="">选择企业</option><option v-for="organization in organizations" :key="organization.id" :value="String(organization.id)">{{ organization.name }}</option>
            </select><small>{{ form.errors.organization_id }}</small></label>
            <label>主要联系人<select v-model="form.primary_contact_id" :disabled="!contacts.length"><option value="">待确认</option><option v-for="contact in contacts" :key="contact.id" :value="String(contact.id)">{{ contact.name }}</option></select><small>{{ form.errors.primary_contact_id }}</small></label>
            <label class="wide">询盘主题 *<input v-model="form.title" required maxlength="255" placeholder="Naughty Clock Wholesale Inquiry" /><small>{{ form.errors.title }}</small></label>
            <label>关注产品<input v-model="form.product_interest" maxlength="255" placeholder="Naughty Clock" /><small>{{ form.errors.product_interest }}</small></label>
            <label>预计数量（件）<input v-model="form.requested_quantity" type="number" min="1" step="1" /><small>{{ form.errors.requested_quantity }}</small></label>
          </div>
        </section>
        <section class="panel"><h2>商机与跟进</h2>
          <div class="fields">
            <label>客户意向<select v-model="form.inquiry_kind"><option value="retail">Retail</option><option value="wholesale">Wholesale</option><option value="authorized_distributor_interest">Authorized Distributor（意向）</option><option value="exclusive_distributor_interest">Exclusive Distributor（意向）</option><option value="other">其他</option></select></label>
            <label>优先级<select v-model="form.priority"><option value="low">低</option><option value="normal">正常</option><option value="high">高</option></select></label>
            <label>下一次跟进<input v-model="form.next_follow_up_at" type="datetime-local" /><small>{{ form.errors.next_follow_up_at }}</small></label>
            <label class="wide">首次沟通记录<textarea v-model="form.initial_note" rows="4" maxlength="5000" placeholder="记录客户需求与沟通情况"></textarea><small>{{ form.errors.initial_note }}</small></label>
          </div>
        </section>
        <p v-if="!organizations.length" class="notice">尚未有可管理的企业客户，请先到企业客户页面建立档案。</p>
      </form>
    </main>
  </AuthenticatedLayout>
</template>
<style scoped>
.case-form{max-width:1080px;margin:0 auto;padding:20px 16px 40px;color:#142126}header{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-bottom:20px}h1{font-size:26px;margin:0}header p{color:#6b7d84;font-size:13px}.actions{display:flex;gap:10px;align-items:center}.actions a,.actions button{font-size:14px;padding:10px 15px;border-radius:9px;text-decoration:none}.actions a{border:1px solid #d8e3e3;color:#415b64}.actions button{background:#28786c;color:#fff;border:0}.actions button:disabled{opacity:.5}.panel{background:white;border:1px solid #e4eaed;border-radius:16px;padding:22px;margin-bottom:18px}.panel h2{font-size:18px;margin:0 0 20px}.fields{display:grid;grid-template-columns:1fr 1fr;gap:16px}.fields label{font-size:13px;display:flex;flex-direction:column;gap:6px}.fields input,.fields select,.fields textarea{padding:10px;border:1px solid #dce5e6;border-radius:9px;max-width:100%;font:inherit}.fields input,.fields select{min-height:42px}.fields small{color:#b93e3e}.wide{grid-column:span 2}.notice{color:#a76418;font-size:13px}@media(max-width:700px){.case-form{padding:12px}.fields{grid-template-columns:1fr}.wide{grid-column:span 1}.panel{padding:16px}}
</style>
