<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
type Contact = {id: number; name: string; job_title: string | null; email_normalized: string | null; phone: string | null; is_primary: boolean}
const props = defineProps<{
  organization: { id: number; name: string; country_code: string | null; website_url: string | null; stage: string; contacts: Contact[] }
  canOperate: boolean
}>()
const contact = useForm({ name: '', job_title: '', email: '', phone: '' })
const add = () => contact.post(`/b2b/companies/${props.organization.id}/contacts`, { onSuccess: () => contact.reset() })
</script>
<template>
  <Head :title="`${organization.name} · BYL ERP`" />
  <AuthenticatedLayout title="B2B 企业客户" :breadcrumbs="['BYL ERP', 'B2B 业务', '企业客户', organization.name]">
    <main class="b2b-page">
      <header><div><Link href="/b2b/companies">← 企业客户</Link><h1>{{ organization.name }}</h1><p>{{ organization.country_code || '国家待确认' }} · {{ organization.stage === 'prospect' ? '潜在客户' : organization.stage }}</p></div></header>
      <section class="panel">
        <h2>企业信息</h2>
        <p>公司网站：<a v-if="organization.website_url" :href="organization.website_url" target="_blank" rel="noopener noreferrer">{{ organization.website_url }}</a><span v-else>未填写</span></p>
      </section>
      <section class="panel">
        <h2>采购联系人</h2>
        <ul><li v-for="person in organization.contacts" :key="person.id"><strong>{{ person.name }}</strong><span>{{ person.job_title || '—' }}</span><span>{{ person.email_normalized || person.phone || '未填写联系方式' }}</span></li></ul>
        <p v-if="!organization.contacts.length" class="muted">尚无联系人</p>
        <form v-if="canOperate" class="new-contact" @submit.prevent="add">
          <h3>新增联系人</h3>
          <div class="fields"><label>姓名 *<input v-model="contact.name" required /></label><label>职位<input v-model="contact.job_title" /></label><label>邮箱<input v-model="contact.email" type="email" /></label><label>电话<input v-model="contact.phone" /></label></div>
          <p class="error">{{ Object.values(contact.errors).join(' · ') }}</p>
          <button :disabled="contact.processing" type="submit">添加联系人</button>
        </form>
      </section>
    </main>
  </AuthenticatedLayout>
</template>
<style scoped>
.b2b-page{max-width:1080px;padding:20px 16px;margin:0 auto;color:#142126}header{margin-bottom:20px}header a{font-size:13px;color:#28786c;text-decoration:none}h1{font-size:26px;margin:14px 0 8px}header p,.muted{color:#71848b;font-size:13px}.panel{padding:22px;border:1px solid #e4eaed;border-radius:16px;background:#fff;margin-bottom:16px}.panel h2{font-size:18px;margin:0 0 18px}.panel p{font-size:14px}a{color:#28786c}ul{list-style:none;margin:0;padding:0}li{display:flex;gap:20px;padding:13px 0;border-bottom:1px solid #edf1f2;flex-wrap:wrap;font-size:13px}li span{color:#556c75}.new-contact{padding-top:22px}.new-contact h3{font-size:15px}.fields{display:grid;grid-template-columns:1fr 1fr;gap:14px}.fields label{display:flex;flex-direction:column;font-size:13px;gap:6px}.fields input{border:1px solid #dae5e8;border-radius:9px;padding:10px}.new-contact button{border:0;border-radius:10px;background:#28786c;color:white;padding:10px 18px;margin-top:15px}.error{color:#b83838}@media(max-width:680px){.b2b-page{padding:12px}.panel{padding:16px}.fields{grid-template-columns:1fr}}
</style>
