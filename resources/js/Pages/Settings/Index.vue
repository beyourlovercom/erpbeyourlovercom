<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type ReleaseNote = { id:number; version:string; title:string; summary:string|null; released_on:string|null }
defineProps<{ currentRelease:ReleaseNote|null; recentReleases:ReleaseNote[]; canManage:boolean }>()
</script>

<template>
  <Head title="设置 · BYL ERP" />
  <AuthenticatedLayout title="设置" :breadcrumbs="['BYL ERP', '设置']">
    <section class="mvp-page settings-page">
      <div class="mvp-page-heading"><div><p class="mvp-kicker">ERP SETTINGS</p><h2>系统设置</h2><p>集中管理 ERP 配置，并查看当前版本与近期发布摘要。</p></div></div>
      <div class="settings-grid">
        <article class="mvp-card release-card"><p class="mvp-kicker">CURRENT RELEASE</p><h3>{{ currentRelease?.version || '尚无已发布版本' }}</h3><p>{{ currentRelease?.title || '发布版本记录后，这里会显示当前版本。' }}</p><p v-if="currentRelease?.summary">{{ currentRelease.summary }}</p><Link href="/settings/changelog">查看完整版本历史</Link></article>
        <article class="mvp-card"><h3>常用设置</h3><div class="settings-links"><Link href="/settings/users">用户</Link><Link href="/settings/roles-permissions">角色与权限</Link><Link href="/settings/warehouses">仓库设置</Link><Link href="/settings/statuses">状态设置</Link></div></article>
      </div>
      <div class="mvp-card"><div class="recent-heading"><h3>近期发布</h3><Link v-if="canManage" href="/settings/changelog/create">新建版本记录</Link></div><div v-if="recentReleases.length" class="recent-list"><Link v-for="note in recentReleases" :key="note.id" :href="`/settings/changelog/${note.id}`"><strong>{{ note.version }}</strong><span>{{ note.title }}</span><small>{{ note.released_on }}</small></Link></div><p v-else>暂无已发布版本记录。</p></div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}.settings-links,.recent-list{display:grid;gap:10px}.recent-heading{display:flex;justify-content:space-between;align-items:center}.recent-list a{display:grid;grid-template-columns:100px 1fr auto;gap:12px;text-decoration:none;padding:10px 0;border-top:1px solid var(--border,#dbe2e8)}@media(max-width:800px){.settings-grid{grid-template-columns:1fr}.recent-list a{grid-template-columns:1fr}}
</style>
