<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

defineProps<{ metrics: Record<'pending_orders' | 'pending_shipment' | 'shipped_pending_sync' | 'completed_today', number> }>()
</script>
<template>
  <Head title="Dashboard · BYL ERP" />
  <AuthenticatedLayout title="Dashboard" :breadcrumbs="['BYL ERP', 'Dashboard']">
    <section class="mvp-page">
      <div class="mvp-page-heading"><div><p class="mvp-kicker">业务工作台</p><h2>BYL ERP 应用工作台</h2><p>订单、发货和人工同步状态集中在这里。</p></div></div>
      <div class="mvp-stat-grid">
        <a class="mvp-stat-card" href="/customers/orders?order_status=pending_shipment"><span>待处理订单</span><strong>{{ metrics.pending_orders }}</strong></a>
        <a class="mvp-stat-card" href="/logistics/pending"><span>待发货</span><strong>{{ metrics.pending_shipment }}</strong></a>
        <a class="mvp-stat-card" href="/sync?status=pending"><span>已发货待同步网站</span><strong>{{ metrics.shipped_pending_sync }}</strong></a>
        <a class="mvp-stat-card" href="/sync?status=completed"><span>今日已完成</span><strong>{{ metrics.completed_today }}</strong></a>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
