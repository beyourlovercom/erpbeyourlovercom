<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

defineProps<{
  workspaces: Array<{ key: string; title: string; description: string; available: boolean }>
}>()
</script>

<template>
  <Head title="B2B 业务 · BYL ERP" />
  <AuthenticatedLayout title="B2B 业务" :breadcrumbs="['BYL ERP', 'B2B 业务']">
    <main class="b2b-workspace">
      <header class="b2b-heading">
        <div>
          <h1>B2B 业务</h1>
          <p>企业客户、询盘和经销商的独立业务工作区</p>
        </div>
      </header>
      <div class="b2b-banner" role="status">
        <strong>模块基础框架已建立</strong>
        <p>目前仅提供独立导航与权限隔离；客户、询盘和报价数据尚未接入。不会显示模拟的业务指标。</p>
      </div>
      <section class="b2b-grid" aria-label="B2B 功能区域">
        <article v-for="workspace in workspaces" :key="workspace.key" class="b2b-card">
          <div class="b2b-title"><h2>{{ workspace.title }}</h2><span>{{ workspace.available ? '已启用' : '待接入' }}</span></div>
          <p>{{ workspace.description }}</p>
        </article>
      </section>
    </main>
  </AuthenticatedLayout>
</template>

<style scoped>
.b2b-workspace { max-width: 1160px; margin: 0 auto; padding: 24px 18px 40px; }
.b2b-heading { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 22px; }
.b2b-heading h1 { margin: 0; color: #142126; font-size: 26px; font-weight: 650; }
.b2b-heading p { color: #607680; font-size: 14px; margin: 8px 0 0; }
.b2b-banner { border: 1px solid #d8e7e3; background: #f1f8f6; border-radius: 12px; padding: 15px 18px; margin-bottom: 22px; color: #183d37; }
.b2b-banner strong { font-size: 14px; }
.b2b-banner p { margin: 5px 0 0; font-size: 13px; line-height: 1.6; }
.b2b-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.b2b-card { background: #fff; border: 1px solid #e4eaed; border-radius: 16px; padding: 22px; min-height: 112px; }
.b2b-title { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.b2b-title h2 { margin: 0; font-size: 17px; font-weight: 650; color: #142126; }
.b2b-title span { white-space: nowrap; border-radius: 20px; padding: 3px 8px; color: #56706a; background: #eef3f2; font-size: 11px; }
.b2b-card p { margin: 12px 0 0; font-size: 13px; line-height: 1.6; color: #607680; }
@media (max-width: 680px) { .b2b-workspace { padding: 16px 12px 28px; } .b2b-grid { grid-template-columns: 1fr; gap: 10px; } .b2b-heading h1 { font-size: 23px; } .b2b-card { padding: 16px; min-height: 90px; } }
</style>
