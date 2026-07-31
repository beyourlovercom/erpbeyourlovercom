<script setup lang="ts">
import { router } from '@inertiajs/vue3'
defineProps<{ user: { id: number; name: string; email: string }; permissions: { viewDashboard: boolean } }>()
</script>
<template>
  <main class="min-h-screen bg-slate-100 p-8 text-slate-900"><div class="mx-auto max-w-5xl"><header class="flex items-center justify-between"><div><p class="text-xs font-semibold tracking-[0.2em] text-teal-700">BYL ERP · ARCHITECTURE FOUNDATION</p><h1 class="mt-3 text-3xl font-semibold">工作空间</h1></div><button class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500" type="button" @click="router.post('/logout')">退出登录</button></header><section class="mt-8 rounded-2xl border border-slate-200 bg-white p-6"><p class="text-sm text-slate-500">当前用户</p><p class="mt-2 font-medium">{{ user.name }} · {{ user.email }}</p><p class="mt-6 text-sm text-slate-600">认证与授权基础已建立，业务模块将在后续工作包中接入。</p></section></div></main>
</template>
