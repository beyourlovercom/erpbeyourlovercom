<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
type Row = { id: number; number: string; kind: string | null; owner: string | null; deleted_at: string | null; deleted_by: number | null }
defineProps<{ orders: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] } }>()
const restore = (order: Row) => {
  if (!window.confirm(`恢复订单 ${order.number}？`)) return
  router.post(`/orders/trash/${order.id}/restore`)
}
const purge = (order: Row) => {
  if (!window.confirm(`永久删除订单 ${order.number}？此操作不可撤销。`)) return
  if (!window.confirm(`请再次确认永久删除：${order.number}`)) return
  router.delete(`/orders/trash/${order.id}`)
}
</script>
<template>
  <Head title="订单回收站 · BYL ERP" />
  <AuthenticatedLayout title="订单回收站" :breadcrumbs="[{ label: '订单管理', href: '/orders' }, '回收站']">
    <div class="erp-form-page">
      <div class="erp-form-content">
        <div class="erp-form-header-row">
          <div><h1 class="erp-form-title">订单回收站</h1><p class="erp-form-subtitle">仅管理员可见。关联物流、财务或库存业务的订单禁止永久删除。</p></div>
          <a href="/orders" class="erp-btn erp-btn-secondary">返回订单</a>
        </div>
        <div class="erp-form-card">
          <table class="w-full text-left text-sm">
            <thead><tr><th class="p-3">订单编号</th><th class="p-3">类型</th><th class="p-3">负责人</th><th class="p-3">移除时间</th><th class="p-3">操作</th></tr></thead>
            <tbody>
              <tr v-for="order in orders.data" :key="order.id" class="border-t">
                <td class="p-3">{{ order.number }}</td><td class="p-3">{{ order.kind }}</td><td class="p-3">{{ order.owner || '—' }}</td><td class="p-3">{{ order.deleted_at }}</td>
                <td class="p-3 flex gap-2"><button type="button" class="erp-btn erp-btn-secondary" @click="restore(order)">恢复</button><button type="button" class="erp-btn erp-btn-secondary text-red-700" @click="purge(order)">永久删除</button></td>
              </tr>
              <tr v-if="!orders.data.length"><td colspan="5" class="p-3">回收站为空</td></tr>
            </tbody>
          </table>
          <div class="flex gap-2 mt-4"><a v-for="(link, index) in orders.links" :key="index" v-if="link.url" :href="link.url" class="erp-btn erp-btn-secondary" :aria-current="link.active ? 'page' : undefined" v-html="link.label" /></div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
