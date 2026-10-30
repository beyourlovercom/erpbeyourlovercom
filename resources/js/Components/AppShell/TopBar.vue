<script setup lang="ts">
import { computed } from 'vue'
import UiButton from '../UI/UiButton.vue'
import GlobalSearchOverlay from './GlobalSearchOverlay.vue'
import NotificationBell from './NotificationBell.vue'
import type { NotificationCenterSummary } from '../../types/navigation'
import type { WarehousePortal } from '../../lib/warehousePortal'

export type BreadcrumbItem = { label: string; href?: string }

const props = withDefaults(defineProps<{ title: string; breadcrumbs: (string | BreadcrumbItem)[]; mobileExpanded: boolean; notificationCenter?: NotificationCenterSummary; warehousePortal?: WarehousePortal | null }>(), { notificationCenter: () => ({ unread_count: 0, items: [] }) })
const emit = defineEmits<{ toggleMobile: [] }>()

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
      <a v-if="parentHref" :href="parentHref" class="shell-back-control" aria-label="返回上一层">‹</a>
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
    <div class="shell-topbar-trailing">
      <a v-if="warehousePortal" class="shell-warehouse-portal" :href="warehousePortal.href" target="_blank" rel="noopener noreferrer">
        {{ warehousePortal.label }} <span aria-hidden="true">↗</span>
      </a>
      <GlobalSearchOverlay />
      <NotificationBell :center="notificationCenter" />
      <UiButton class="shell-icon-button shell-mobile-toggle" tone="secondary" size="sm" :aria-expanded="mobileExpanded" :aria-label="mobileExpanded ? '关闭导航' : '打开导航'" @click="emit('toggleMobile')">☰</UiButton>
    </div>
  </header>
</template>

<style scoped>
.shell-warehouse-portal{display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:0 12px;border:1px solid #0f8f82;border-radius:8px;background:#fff;color:#0d796f;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap}
.shell-warehouse-portal:hover,.shell-warehouse-portal:focus-visible{background:#f0fdfa;border-color:#0d796f}
</style>
