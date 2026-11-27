<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import UiButton from '../UI/UiButton.vue'
import GlobalSearchOverlay from './GlobalSearchOverlay.vue'
import NotificationBell from './NotificationBell.vue'
import UserAccountMenu from './UserAccountMenu.vue'
import type { NotificationCenterSummary, SafeUser } from '../../types/navigation'

export type BreadcrumbItem = { label: string; href?: string }

const props = withDefaults(defineProps<{ title: string; breadcrumbs: (string | BreadcrumbItem)[]; mobileExpanded: boolean; sidebarCollapsed?: boolean; user?: SafeUser; notificationCenter?: NotificationCenterSummary }>(), { notificationCenter: () => ({ unread_count: 0, items: [] }) })
const emit = defineEmits<{ toggleMobile: []; toggleSidebar: [] }>()

const fullscreenAvailable = typeof document !== 'undefined' && document.fullscreenEnabled
const toggleFullscreen = async () => { try { if(document.fullscreenElement) await document.exitFullscreen(); else await document.documentElement.requestFullscreen() } catch { /* browser may deny fullscreen */ } }
const parentHref = computed<string | null>(() => {
  const withHref = props.breadcrumbs.filter(
    (c): c is BreadcrumbItem => typeof c === 'object' && !!c.href,
  )
  return withHref.length > 0 ? (withHref[withHref.length - 1].href ?? null) : null
})
</script>

<template>
  <header class="shell-topbar">
    <div class="shell-topbar-leading">
      <UiButton class="shell-icon-button shell-desktop-toggle" tone="secondary" size="sm" :aria-label="sidebarCollapsed ? '展开侧边菜单' : '收起侧边菜单'" :aria-expanded="!sidebarCollapsed" @click="emit('toggleSidebar')">☰</UiButton>
      <a v-if="parentHref" :href="parentHref" class="shell-back-control" aria-label="返回上一层">‹</a>
      <div class="shell-topbar-title-line">
        <div class="shell-breadcrumbs" aria-label="面包屑">
          <template v-for="(crumb, i) in breadcrumbs" :key="i">
            <a v-if="typeof crumb === 'object' && crumb.href" :href="crumb.href">{{ crumb.label }}</a>
            <span v-else>{{ typeof crumb === 'object' ? crumb.label : crumb }}</span>
          </template>
        </div>
        <h1>{{ title }}</h1>
      </div>
    </div>
    <div class="shell-topbar-trailing">
      <GlobalSearchOverlay />
      <UiButton class="shell-icon-button" tone="secondary" size="sm" aria-label="刷新当前页面" @click="router.reload()">↻</UiButton>
      <UiButton v-if="fullscreenAvailable" class="shell-icon-button" tone="secondary" size="sm" aria-label="切换全屏" @click="toggleFullscreen">⛶</UiButton>
      <NotificationBell :center="notificationCenter" />
      <UserAccountMenu v-if="user" class="shell-header-account" :user="user" :navigation-hidden="false" />
      <UiButton class="shell-icon-button shell-mobile-toggle" tone="secondary" size="sm" :aria-expanded="mobileExpanded" :aria-label="mobileExpanded ? '关闭导航' : '打开导航'" @click="emit('toggleMobile')">☰</UiButton>
    </div>
  </header>
</template>
