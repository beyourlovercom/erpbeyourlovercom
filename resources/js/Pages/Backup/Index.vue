<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Action = { key: string; label: string; available: boolean }
type RestoreState = { available: boolean; status: string }

const props = defineProps<{
  actions: Action[]
  history: unknown[]
  storageUsage: { used: number; capacity: number } | null
  policy: { format: string; includedRoots: string[]; workspace: string; status: string }
  restore: { local: RestoreState; github: RestoreState }
}>()
</script>

<template>
  <Head title="备份与迁移 · BYL ERP" />
  <AuthenticatedLayout title="备份与迁移" :breadcrumbs="['BYL ERP', '设置', '备份与迁移']">
    <section class="mvp-page backup-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">BACKUP &amp; MIGRATION</p><h2>备份与迁移</h2><p>备份引擎尚未接入，当前仅展示已批准的功能边界与策略。</p></div>
      </div>

      <section class="mvp-panel">
        <div class="section-heading"><div><h3>备份操作</h3><p>备份引擎接入后可用，当前不会创建或删除任何记录。</p></div><span class="mvp-status">暂不可用</span></div>
        <div class="action-grid"><button v-for="action in props.actions" :key="action.key" type="button" disabled>{{ action.label }}</button></div>
      </section>

      <div class="two-column">
        <section class="mvp-panel"><h3>备份历史</h3><p v-if="props.history.length === 0" class="mvp-empty">暂无备份记录。系统不会在引擎接入前生成示例记录。</p></section>
        <section class="mvp-panel"><h3>存储使用情况</h3><p v-if="props.storageUsage === null" class="mvp-empty">暂无法读取存储使用情况，等待备份引擎接入。</p><p v-else>{{ props.storageUsage.used }} / {{ props.storageUsage.capacity }}</p></section>
      </div>

      <section class="mvp-panel"><h3>备份策略</h3><dl class="policy-list"><div><dt>格式</dt><dd>{{ props.policy.format }}</dd></div><div><dt>包含目录</dt><dd>{{ props.policy.includedRoots.join('、') }}</dd></div><div><dt>备份工作区</dt><dd>{{ props.policy.workspace }}</dd></div><div><dt>状态</dt><dd>{{ props.policy.status }}</dd></div></dl></section>

      <div class="two-column">
        <section class="mvp-panel"><h3>本地恢复</h3><p>{{ props.restore.local.status }}</p><button type="button" disabled>选择备份文件</button></section>
        <section class="mvp-panel"><h3>GitHub 恢复</h3><p>{{ props.restore.github.status }}</p><button type="button" disabled>从 GitHub 选择备份</button></section>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.backup-page{display:grid;gap:var(--byl-space-20)}.mvp-panel{display:grid;gap:var(--byl-space-12);padding:var(--byl-space-20);border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-lg);background:var(--byl-surface)}.mvp-panel h3,.mvp-panel p{margin:0}.section-heading{display:flex;justify-content:space-between;gap:var(--byl-space-12);align-items:start}.section-heading p,.mvp-empty{color:var(--byl-text-muted)}.action-grid,.two-column{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:var(--byl-space-12)}.two-column{grid-template-columns:repeat(2,minmax(0,1fr))}.action-grid button,.mvp-panel>button{min-height:42px;padding:0 var(--byl-space-12);border:1px solid var(--byl-border-subtle);border-radius:var(--byl-radius-md);background:var(--byl-readonly-bg);color:var(--byl-text-muted);cursor:not-allowed}.policy-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--byl-space-12);margin:0}.policy-list div{display:grid;gap:var(--byl-space-4)}dt{color:var(--byl-text-muted)}dd{margin:0;font-weight:600;overflow-wrap:anywhere}@media(max-width:800px){.action-grid,.two-column,.policy-list{grid-template-columns:1fr}}
</style>
