<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

defineProps<{ module: { key: string; label: string } }>()
</script>

<template>
  <Head :title="`${module.label} · BYL ERP`" />
  <AuthenticatedLayout :title="module.label" :breadcrumbs="['BYL ERP', module.label]">
    <section class="shell-placeholder" aria-labelledby="placeholder-title"><p class="shell-eyebrow">APPLICATION SHELL</p><h2 id="placeholder-title">{{ module.label }}模块尚未开始实现</h2><p>此页面用于验证导航、权限和统一布局。业务功能将在后续授权工作包中交付。</p></section>
  </AuthenticatedLayout>
</template>
