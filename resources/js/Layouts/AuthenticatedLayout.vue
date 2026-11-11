<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import GlobalFeedbackIsland from '../Components/AppShell/GlobalFeedbackIsland.vue'
import GlobalNavigation from '../Components/AppShell/GlobalNavigation.vue'
import TopBar, { type BreadcrumbItem } from '../Components/AppShell/TopBar.vue'
import WorkspaceTabs from '../Components/AppShell/WorkspaceTabs.vue'
import { workspaceHotCache, workspaceHotStatus } from '../lib/workspaceHotCache'
import {
    canonicalFeedbackCopyText,
    copyFailureFeedback,
    copySuccessFeedback,
    feedbackFromFlash,
    globalFeedback,
    isCopyableFeedback,
    publishGlobalFeedback,
    scheduleGlobalFeedbackRestore,
} from '../lib/globalFeedback'
import type { ShellPageProps } from '../types/navigation'

defineProps<{ title: string; breadcrumbs: (string | BreadcrumbItem)[] }>()
const page = usePage<ShellPageProps>()
const sidebarCollapsed = ref(false)
const mobileOpen = ref(false)
const isNarrowScreen = ref(false)
let narrowScreenQuery: MediaQueryList | undefined
const storageKey = 'module_sidebar_collapsed'
// Compile capability separately from the user/session opt-in. A production
// build can expose R3 only to the allowlisted canary users, and they must
// explicitly visit a URL with ?erp_v4_tabs=1 to enable it in this browser tab.
const workspaceTabsEnabled = ref(false)
const canaryStorageKey = computed(() => 'byl:workspace:canary:user:' + page.props.auth.user.id)
const refreshWorkspaceCanary = () => {
    if (typeof window === 'undefined' || import.meta.env.VITE_ERP_WORKSPACE_TABS !== '1') {
        workspaceTabsEnabled.value = false
        return
    }
    const userId = String(page.props.auth.user.id)
    const allowed = (import.meta.env.VITE_ERP_WORKSPACE_CANARY_IDS ?? '')
        .split(',').map((value: string) => value.trim()).filter(Boolean).includes(userId)
    if (!allowed) { workspaceTabsEnabled.value = false; return }
    const mode = new URLSearchParams(page.url.split('?')[1] ?? '').get('erp_v4_tabs')
    try {
        if (mode === '1') window.sessionStorage.setItem(canaryStorageKey.value, '1')
        if (mode === '0') window.sessionStorage.removeItem(canaryStorageKey.value)
        workspaceTabsEnabled.value = window.sessionStorage.getItem(canaryStorageKey.value) === '1'
    } catch {
        workspaceTabsEnabled.value = mode === '1'
    }
}
watch(() => [page.url, page.props.auth.user.id], refreshWorkspaceCanary)
// Opt-out releases all browser-only hot snapshots, without touching order data.
watch(workspaceTabsEnabled, enabled => { if (!enabled) { workspaceHotCache.clear(); workspaceHotStatus.value = 'idle' } })

const updateNarrowScreen = (event: MediaQueryListEvent | MediaQueryList) => {
    isNarrowScreen.value = event.matches
}
const readCollapsed = () => {
    try {
        return window.localStorage.getItem(storageKey) === 'true'
    } catch {
        return false
    }
}
const writeCollapsed = (collapsed: boolean) => {
    try {
        window.localStorage.setItem(storageKey, String(collapsed))
    } catch {
        return
    }
}
const toggleSidebar = () => {
    sidebarCollapsed.value = !sidebarCollapsed.value
    writeCollapsed(sidebarCollapsed.value)
    publishGlobalFeedback({
        state: 'INFO',
        title: sidebarCollapsed.value ? '已收起导航' : '已展开导航',
        message: '',
    })
}
const toggleMobile = () => {
    mobileOpen.value = !mobileOpen.value
    publishGlobalFeedback({
        state: 'INFO',
        title: mobileOpen.value ? '已展开导航' : '已收起导航',
        message: '',
    })
}

const publishCurrentFlash = () => {
    const value = feedbackFromFlash(page.props.flash)
    if (value) publishGlobalFeedback(value)
}

watch(
    () => page.props.flash?.feedback_id,
    publishCurrentFlash,
    { immediate: true },
)

const copyGlobalFeedback = async () => {
    const original = globalFeedback.value
    if (!isCopyableFeedback(original)) return
    try {
        if (!navigator.clipboard?.writeText) throw new Error('CLIPBOARD_WRITE_UNAVAILABLE')
        await navigator.clipboard.writeText(canonicalFeedbackCopyText(original))
        if (globalFeedback.value !== original) return
        const overlay = copySuccessFeedback()
        publishGlobalFeedback(overlay)
        scheduleGlobalFeedbackRestore(overlay, original)
    } catch (error) {
        if (globalFeedback.value !== original) return
        publishGlobalFeedback(copyFailureFeedback(error))
    }
}

onMounted(() => {
    refreshWorkspaceCanary()
    sidebarCollapsed.value = readCollapsed()
    narrowScreenQuery = window.matchMedia?.('(max-width: 800px)')
    if (!narrowScreenQuery) return
    updateNarrowScreen(narrowScreenQuery)
    narrowScreenQuery.addEventListener('change', updateNarrowScreen)
})
onBeforeUnmount(() => narrowScreenQuery?.removeEventListener('change', updateNarrowScreen))
const currentPath = computed(() => page.url.split('?')[0])
</script>

<template>
  <div class="authenticated-shell" :class="{ 'mobile-nav-open': mobileOpen, 'sidebar-collapsed': sidebarCollapsed, 'workspace-data-locked': workspaceHotStatus !== 'idle' }">
    <GlobalFeedbackIsland :feedback="globalFeedback" @copy="copyGlobalFeedback" />
    <div class="shell-mobile-backdrop" aria-hidden="true" @click="mobileOpen = false"></div>
    <GlobalNavigation
      :items="page.props.shellNavigation"
      :current-path="currentPath"
      :mobile-hidden="isNarrowScreen && !mobileOpen"
      :collapsed="sidebarCollapsed"
      :user="page.props.auth.user"
      @toggle-collapse="toggleSidebar"
    />
    <div class="shell-workspace">
      <TopBar
        :title="title"
        :breadcrumbs="breadcrumbs"
        :mobile-expanded="mobileOpen"
        :notification-center="page.props.notificationCenter"
        @toggle-mobile="toggleMobile"
      />
      <WorkspaceTabs
        v-if="workspaceTabsEnabled"
        :current-url="page.url"
        :current-title="title"
        :user-id="page.props.auth.user.id"
      />
      <main id="main-content" class="shell-content" tabindex="-1" :aria-busy="workspaceHotStatus !== 'idle'"><slot /></main>
    </div>
  </div>
</template>

<style scoped>
/* Old cached order props may be shown instantly, but cannot be acted upon
   until the authoritative revalidation succeeds. Navbar remains usable. */
.workspace-data-locked .shell-content { pointer-events: none; opacity: .84; }
</style>
