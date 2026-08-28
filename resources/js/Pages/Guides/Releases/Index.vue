<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
type ReleaseNote={id:number;version:string;title:string;summary:string|null;status:string;released_on:string|null;published_at:string|null}
defineProps<{releaseNotes:ReleaseNote[];canManage:boolean}>()
</script>
<template><Head title="版本更新 · BYL ERP"/><AuthenticatedLayout title="版本更新" :breadcrumbs="['BYL ERP','指南','版本更新']"><section class="mvp-page"><div class="mvp-page-heading"><div><p class="mvp-kicker">RELEASE CHANGELOG</p><h2>版本更新</h2><p>由内容负责人整理并发布的 ERP 版本说明，不直接展示 Git commit。</p></div><Link v-if="canManage" class="mvp-button mvp-button-primary" href="/guides/changelog/create">新建版本记录</Link></div><div class="release-list"><article v-for="note in releaseNotes" :key="note.id" class="mvp-card"><div class="release-meta"><strong>{{note.version}}</strong><span>{{note.released_on||'未设置日期'}}</span><span v-if="canManage" class="mvp-status">{{note.status==='published'?'已发布':'草稿'}}</span></div><h3><Link :href="`/guides/changelog/${note.id}`">{{note.title}}</Link></h3><p>{{note.summary||'暂无摘要'}}</p><Link v-if="canManage" :href="`/guides/changelog/${note.id}/edit`">编辑</Link></article><div v-if="!releaseNotes.length" class="mvp-empty">暂无{{canManage?'版本记录':'已发布版本记录'}}。</div></div></section></AuthenticatedLayout></template>
<style scoped>.release-list{display:grid;gap:12px}.release-meta{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.release-list h3{margin:10px 0 6px}.release-list p{color:#64748b}</style>
