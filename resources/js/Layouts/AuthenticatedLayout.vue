<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import GlobalNavigation from '../Components/AppShell/GlobalNavigation.vue'
import TopBar from '../Components/AppShell/TopBar.vue'
import type { ShellPageProps } from '../types/navigation'

defineProps<{ title: string; breadcrumbs: string[] }>()
const page = usePage<ShellPageProps>()
const sidebarCollapsed = ref(false)
const mobileOpen = ref(false)
const isNarrowScreen = ref(false)
let narrowScreenQuery: MediaQueryList | undefined
const updateNarrowScreen = (event: MediaQueryListEvent | MediaQueryList) => { isNarrowScreen.value = event.matches }
const storageKey = 'module_sidebar_collapsed'
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
}
onMounted(() => {
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
  <div class="authenticated-shell" :class="{ 'mobile-nav-open': mobileOpen, 'sidebar-collapsed': sidebarCollapsed }">
    <div class="shell-mobile-backdrop" aria-hidden="true" @click="mobileOpen = false"></div>
    <GlobalNavigation :items="page.props.shellNavigation" :current-path="currentPath" :mobile-hidden="isNarrowScreen && !mobileOpen" :collapsed="sidebarCollapsed" :user="page.props.auth.user" @toggle-collapse="toggleSidebar" />
    <div class="shell-workspace">
      <TopBar :title="title" :breadcrumbs="breadcrumbs" :mobile-expanded="mobileOpen" @toggle-mobile="mobileOpen = !mobileOpen" />
      <main id="main-content" class="shell-content" tabindex="-1"><slot /></main>
    </div>
  </div>
</template>
