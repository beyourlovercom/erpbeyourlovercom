<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Organization = { id: number; name: string; country_code: string | null; stage: string; contacts_count: number }
type Page = { data: Organization[]; links: Array<{ url: string | null; label: string; active: boolean }>; total: number }
const props = defineProps<{ organizations: Page; search: string; canOperate: boolean }>()
const search = ref(props.search)
const apply = () => router.get('/b2b/companies', { search: search.value }, { preserveState: true, replace: true })
</script>

<template>
  <Head title="企业客户 · BYL ERP" />
  <AuthenticatedLayout title="B2B 企业客户" :breadcrumbs="['BYL ERP', 'B2B 业务', '企业客户']">
    <main class="b2b-content">
      <header class="b2b-actions">
        <div><h1>企业客户</h1><p>独立管理 B2B 企业商业档案和采购联系人</p></div>
        <Link v-if="canOperate" class="b2b-primary" href="/b2b/companies/create">新增企业客户</Link>
      </header>
      <form class="b2b-filters" @submit.prevent="apply">
        <label for="company-search">搜索企业</label>
        <input id="company-search" v-model="search" type="search" placeholder="公司名称" />
        <button type="submit">搜索</button>
      </form>
      <div class="b2b-table-wrap">
        <table><thead><tr><th>企业</th><th>国家</th><th>联系人</th><th>阶段</th><th></th></tr></thead>
          <tbody>
            <tr v-for="row in organizations.data" :key="row.id">
              <td><Link :href="`/b2b/companies/${row.id}`">{{ row.name }}</Link></td>
              <td>{{ row.country_code || '—' }}</td><td>{{ row.contacts_count }}</td>
              <td>{{ row.stage === 'prospect' ? '潜在客户' : row.stage }}</td>
              <td><Link :href="`/b2b/companies/${row.id}`">查看</Link></td>
            </tr>
            <tr v-if="organizations.data.length === 0"><td colspan="5" class="empty">暂无符合条件的企业客户</td></tr>
          </tbody>
        </table>
      </div>
      <nav class="page-links" aria-label="分页">
        <template v-for="(link, index) in organizations.links" :key="index">
          <Link v-if="link.url" :href="link.url" :class="{ active: link.active }">{{ link.label.replace('&laquo;', '‹').replace('&raquo;', '›') }}</Link>
        </template>
      </nav>
      <p class="hint">当前共 {{ organizations.total }} 个企业档案。该页面不会读取 B2C 购买或订阅数据。</p>
    </main>
  </AuthenticatedLayout>
</template>
<style scoped>
.b2b-content{max-width:1120px;margin:0 auto;padding:20px 16px 32px;color:#142126}.b2b-actions{display:flex;justify-content:space-between;align-items:start;gap:16px;margin-bottom:20px;flex-wrap:wrap}.b2b-actions h1{margin:0;font-size:25px}.b2b-actions p,.hint{color:#667d84;font-size:13px}.b2b-primary{background:#28786c;color:#fff;padding:10px 16px;border-radius:10px;text-decoration:none;font-size:14px}.b2b-filters{display:flex;align-items:center;gap:12px;margin-bottom:16px}.b2b-filters label{font-size:13px}.b2b-filters input{flex:1;min-width:0;border:1px solid #dce5e7;border-radius:9px;padding:9px}.b2b-filters button{background:#fff;border:1px solid #cbdad8;border-radius:9px;padding:9px 16px}.b2b-table-wrap{overflow-x:auto;border:1px solid #e4eaed;border-radius:13px;background:#fff}table{width:100%;border-collapse:collapse;text-align:left;font-size:13px;min-width:470px}th,td{padding:13px;border-bottom:1px solid #edf1f2}th{color:#667d84}a{color:#236f6a;text-decoration:none}.empty{text-align:center;padding:32px;color:#667d84}.page-links{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.page-links a{font-size:13px;padding:7px 10px;border:1px solid #dae6e3;border-radius:7px}.page-links .active{font-weight:700;background:#e8f3f1}@media(max-width:640px){.b2b-content{padding:14px 12px}.b2b-filters{flex-wrap:wrap}.b2b-filters label{width:100%}}
</style>
