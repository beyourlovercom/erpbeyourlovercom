<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import type { SafeUser, ShellNavigationItem } from '../../types/navigation'
import UserAccountMenu from './UserAccountMenu.vue'

defineProps<{ items: ShellNavigationItem[]; currentPath: string; mobileHidden: boolean; user: SafeUser }>()
const isActive = (item: ShellNavigationItem, currentPath: string) => item.path === '/dashboard'
    ? currentPath === '/dashboard'
    : currentPath === item.path || currentPath.startsWith(`${item.path}/`)
</script>

<template>
  <nav class="shell-primary-nav" aria-label="ERP主导航" :inert="mobileHidden ? true : undefined" :aria-hidden="mobileHidden">
    <Link class="shell-brand" href="/dashboard" aria-label="BYL ERP Dashboard"><strong>BYL</strong><span>ERP</span></Link>
    <div class="shell-primary-links">
      <Link v-for="item in items" :key="item.key" :href="item.path" class="shell-primary-link" :class="{ 'is-active': isActive(item, currentPath) }" :aria-current="isActive(item, currentPath) ? 'page' : undefined">
        <span class="shell-nav-marker" aria-hidden="true"></span><span class="shell-primary-emoji" aria-hidden="true">{{ item.emoji ?? '' }}</span><span>{{ item.label }}</span>
      </Link>
    </div>
    <UserAccountMenu :user="user" :navigation-hidden="mobileHidden" />
  </nav>
</template>
