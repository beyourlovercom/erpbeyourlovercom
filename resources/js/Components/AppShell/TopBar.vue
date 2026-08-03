<script setup lang="ts">
import UiButton from '../UI/UiButton.vue'

defineProps<{ title: string; breadcrumbs: string[]; sidebarExpanded: boolean; mobileExpanded: boolean }>()
const emit = defineEmits<{ toggleSidebar: []; toggleMobile: [] }>()
</script>

<template>
  <header class="shell-topbar">
    <div class="shell-topbar-leading">
      <UiButton class="shell-icon-button shell-mobile-toggle" tone="secondary" size="sm" :aria-expanded="mobileExpanded" :aria-label="mobileExpanded ? '关闭导航' : '打开导航'" @click="emit('toggleMobile')">☰</UiButton>
      <UiButton class="shell-icon-button shell-sidebar-toggle" tone="secondary" size="sm" :aria-expanded="sidebarExpanded" :aria-label="sidebarExpanded ? '收起模块导航' : '展开模块导航'" @click="emit('toggleSidebar')">{{ sidebarExpanded ? '‹' : '›' }}</UiButton>
      <div><div class="shell-breadcrumbs" aria-label="面包屑"><span v-for="crumb in breadcrumbs" :key="crumb">{{ crumb }}</span></div><h1>{{ title }}</h1></div>
    </div>
  </header>
</template>
