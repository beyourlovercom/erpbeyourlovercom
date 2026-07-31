<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import GlobalNavigation from '../Components/AppShell/GlobalNavigation.vue'
import ModuleSidebar from '../Components/AppShell/ModuleSidebar.vue'
import TopBar from '../Components/AppShell/TopBar.vue'
import type { ShellPageProps } from '../types/navigation'

defineProps<{ title: string; breadcrumbs: string[] }>()
const page = usePage<ShellPageProps>()
const sidebarExpanded = ref(true)
const mobileOpen = ref(false)
const isNarrowScreen = ref(false)
let narrowScreenQuery: MediaQueryList | undefined
const updateNarrowScreen = (event: MediaQueryListEvent | MediaQueryList) => { isNarrowScreen.value = event.matches }
onMounted(() => {
    narrowScreenQuery = window.matchMedia?.('(max-width: 800px)')
    if (!narrowScreenQuery) return
    updateNarrowScreen(narrowScreenQuery)
    narrowScreenQuery.addEventListener('change', updateNarrowScreen)
})
onBeforeUnmount(() => narrowScreenQuery?.removeEventListener('change', updateNarrowScreen))
const currentPath = computed(() => page.url.split('?')[0])
const activeItem = computed(() => page.props.shellNavigation.find((item) => item.path === '/dashboard'
    ? currentPath.value === '/dashboard'
    : currentPath.value === item.path || currentPath.value.startsWith(`${item.path}/`)))
</script>

<template>
  <div class="authenticated-shell" :class="{ 'mobile-nav-open': mobileOpen, 'sidebar-collapsed': !sidebarExpanded }">
    <div class="shell-mobile-backdrop" aria-hidden="true" @click="mobileOpen = false"></div>
    <GlobalNavigation :items="page.props.shellNavigation" :current-path="currentPath" :mobile-hidden="isNarrowScreen && !mobileOpen" />
    <ModuleSidebar :item="activeItem" :current-path="currentPath" :collapsed="!sidebarExpanded" />
    <div class="shell-workspace">
      <TopBar :user="page.props.auth.user" :title="title" :breadcrumbs="breadcrumbs" :sidebar-expanded="sidebarExpanded" :mobile-expanded="mobileOpen" @toggle-sidebar="sidebarExpanded = !sidebarExpanded" @toggle-mobile="mobileOpen = !mobileOpen" />
      <main id="main-content" class="shell-content" tabindex="-1"><slot /></main>
    </div>
  </div>
</template>
