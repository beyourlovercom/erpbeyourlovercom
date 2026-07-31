<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import type { SafeUser } from '../../types/navigation'

defineProps<{ user: SafeUser; title: string; breadcrumbs: string[]; sidebarExpanded: boolean; mobileExpanded: boolean }>()
const emit = defineEmits<{ toggleSidebar: []; toggleMobile: [] }>()
const logout = () => router.post('/logout')
</script>

<template>
  <header class="shell-topbar">
    <div class="shell-topbar-leading">
      <button type="button" class="shell-icon-button shell-mobile-toggle" :aria-expanded="mobileExpanded" :aria-label="mobileExpanded ? '关闭导航' : '打开导航'" @click="emit('toggleMobile')">☰</button>
      <button type="button" class="shell-icon-button shell-sidebar-toggle" :aria-expanded="sidebarExpanded" :aria-label="sidebarExpanded ? '收起模块导航' : '展开模块导航'" @click="emit('toggleSidebar')">{{ sidebarExpanded ? '‹' : '›' }}</button>
      <div><div class="shell-breadcrumbs" aria-label="面包屑"><span v-for="crumb in breadcrumbs" :key="crumb">{{ crumb }}</span></div><h1>{{ title }}</h1></div>
    </div>
    <div class="shell-user"><div><strong>{{ user.name }}</strong><span>{{ user.email }}</span></div><button type="button" class="shell-logout" @click="logout">退出登录</button></div>
  </header>
</template>
