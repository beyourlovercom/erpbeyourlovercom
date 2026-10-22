<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
const form = useForm({
  display_name: '', country_code: '', website_url: '',
  contact_name: '', contact_email: '', contact_phone: '',
})
const save = () => form.post('/b2b/companies')
</script>
<template>
  <Head title="新增 B2B 企业客户 · BYL ERP" />
  <AuthenticatedLayout title="新增企业客户" :breadcrumbs="['BYL ERP', 'B2B 业务', '企业客户', '新增']">
    <main class="form-main">
      <header class="form-heading"><div><h1>新增企业客户</h1><p>企业商业档案独立于 C 端消费者记录</p></div>
        <div><Link class="cancel" href="/b2b/companies">取消</Link><button class="save" :disabled="form.processing" @click="save">保存企业</button></div>
      </header>
      <form @submit.prevent="save">
        <section class="form-section">
          <h2>基本信息</h2>
          <div class="field-grid">
            <label>企业名称 *<input v-model="form.display_name" required maxlength="255" /><small>{{ form.errors.display_name }}</small></label>
            <label>国家代码<input v-model="form.country_code" placeholder="US / MY / PL" maxlength="2" /><small>{{ form.errors.country_code }}</small></label>
            <label class="wide">公司网站<input v-model="form.website_url" type="url" placeholder="https://example.com" /><small>{{ form.errors.website_url }}</small></label>
          </div>
        </section>
        <section class="form-section">
          <h2>首位联系人（可选）</h2>
          <div class="field-grid">
            <label>姓名<input v-model="form.contact_name" maxlength="255" /><small>{{ form.errors.contact_name }}</small></label>
            <label>邮箱<input v-model="form.contact_email" type="email" maxlength="254" /><small>{{ form.errors.contact_email }}</small></label>
            <label class="wide">联系电话<input v-model="form.contact_phone" maxlength="64" /><small>{{ form.errors.contact_phone }}</small></label>
          </div>
        </section>
      </form>
    </main>
  </AuthenticatedLayout>
</template>
<style scoped>
.form-main{max-width:1080px;margin:0 auto;padding:20px 16px;color:#142126}.form-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px}.form-heading h1{font-size:26px;margin:0}.form-heading p{font-size:13px;color:#70818a}.form-heading>div:last-child{display:flex;align-items:center;gap:12px}.save,.cancel{border:1px solid #d5e2e0;border-radius:10px;padding:10px 15px;cursor:pointer;font-size:14px;text-decoration:none}.save{background:#28786c;color:white;border-color:#28786c}.save:disabled{opacity:.5}.cancel{color:#344b53}.form-section{background:white;border:1px solid #e4eaed;border-radius:16px;padding:22px;margin-bottom:18px}.form-section h2{font-size:17px;margin:0 0 18px}.field-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.field-grid label{display:flex;flex-direction:column;font-size:13px;gap:7px}.field-grid input{border:1px solid #dae5e8;padding:10px 12px;border-radius:10px;height:42px}.field-grid small{color:#be3a3a}.wide{grid-column:span 2}@media(max-width:700px){.field-grid{grid-template-columns:1fr}.wide{grid-column:span 1}.form-section{padding:16px}.form-main{padding:12px}}
</style>
