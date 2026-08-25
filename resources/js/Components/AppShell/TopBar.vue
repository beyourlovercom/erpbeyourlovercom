<script setup lang="ts">
import UiButton from '../UI/UiButton.vue'

export type BreadcrumbItem = { label: string; href?: string }

defineProps<{ title: string; breadcrumbs: (string | BreadcrumbItem)[]; mobileExpanded: boolean }>()
const emit = defineEmits<{ toggleMobile: [] }>()
</script>

<template>
  <header class="shell-topbar">
    <div class="shell-topbar-leading">
      <UiButton class="shell-icon-button shell-mobile-toggle" tone="secondary" size="sm" :aria-expanded="mobileExpanded" :aria-label="mobileExpanded ? '关闭导航' : '打开导航'" @click="emit('toggleMobile')">☰</UiButton>
      <div>
        <div class="shell-breadcrumbs" aria-label="面包屑">
          <template v-for="(crumb, i) in breadcrumbs" :key="i">
            <a v-if="typeof crumb === 'object' && crumb.href" :href="crumb.href">{{ crumb.label }}</a>
            <span v-else>{{ typeof crumb === 'object' ? crumb.label : crumb }}</span>
          </template>
        </div>
        <h1>{{ title }}</h1>
      </div>
    </div>
  </header>
</template>
