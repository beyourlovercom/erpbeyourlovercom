<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
</script>
<template>
  <Head title="Dashboard · BYL ERP" />
  <AuthenticatedLayout title="Dashboard" :breadcrumbs="['BYL ERP', 'Dashboard']">
    <section class="shell-welcome"><p class="shell-eyebrow">AUTHENTICATED WORKSPACE</p><h2>BYL ERP 应用工作台</h2><p>应用Shell已就绪。业务统计和运营功能将在后续授权工作包中接入。</p></section>
  </AuthenticatedLayout>
</template>
