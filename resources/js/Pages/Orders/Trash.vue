<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed as gridComputed } from 'vue'
import type { ColDef } from 'ag-grid-community'
import GlobalGridTemplates from '../../Components/DataGrid/GlobalGridTemplates.vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
type Row = { contact_user_name?: string | null; id: number; number: string; kind: string | null; owner: string | null; deleted_at: string | null; deleted_by: number | null; archival_mode: 'preserved' | 'soft_deleted' }
const props=defineProps<{ orders: { data: Row[]; links: { url: string | null; label: string; active: boolean }[]; current_page?:number; last_page?:number; per_page?:number; total?:number } }>()
const restore = (order: Row) => {
  if (!window.confirm(`恢复订单 ${order.number}？`)) return
  router.post(`/orders/trash/${order.id}/restore`)
}
type GridRow=Record<string,unknown>
const trashRows=gridComputed<GridRow[]>(()=>props.orders.data.map(r=>({...r,
  contact_label:r.contact_user_name??'未分配',owner_label:r.owner||'—',mode_label:r.archival_mode==='preserved'?'保留业务证据':'软删除'})))
const trashColumns:ColDef<GridRow>[]=[
  {field:'number',headerName:'订单编号',width:190},{field:'contact_label',headerName:'订单联络人',width:140},
  {field:'kind',headerName:'类型',width:125},{field:'owner_label',headerName:'负责人',width:120},
  {field:'mode_label',headerName:'方式',width:160},{field:'deleted_at',headerName:'移除时间',width:180},
  {colId:'actions',headerName:'操作',width:220,pinned:'right',lockPinned:true,lockVisible:true},
]
const trashPager=gridComputed(()=>({current_page:props.orders.current_page??1,last_page:props.orders.last_page??1,per_page:props.orders.per_page??50,total:props.orders.total??props.orders.data.length}))
function goGridPage(page:number){
  const exact=props.orders.links.find(l=>l.url&&l.label===String(page))?.url
  if(exact)router.get(exact)
  else router.get('/orders/trash',{page})
}
</script>
<template>
  <Head title="订单回收站 · BYL ERP" />
  <AuthenticatedLayout title="订单回收站" :breadcrumbs="[{ label: '订单管理', href: '/orders' }, '回收站']">
    <div class="erp-form-page">
      <div class="erp-form-content">
        <div class="erp-form-header-row">
          <div><h1 class="erp-form-title">订单回收站</h1><p class="erp-form-subtitle">仅管理员可见。所有订单保留历史记录，不允许永久删除。</p></div>
          <a href="/orders" class="erp-btn erp-btn-secondary">返回订单</a>
        </div>
        <div class="erp-form-card">
      <GlobalGridTemplates namespace="grid.orders.trash.v2" title="订单回收站" :rows="trashRows" :columns="trashColumns"
        :field-groups="{'订单信息':['number','contact_label','kind','owner_label'],'移除信息':['mode_label','deleted_at'],'操作':['actions']}"
        :required-column-ids="['number','actions']" :page="trashPager" :show-search="false" server-page-size-locked cell-text-selection @page-change="goGridPage">
        <template #cell-actions="{row: order}"><button type="button" class="erp-btn erp-btn-secondary" @click="restore(order)">恢复</button><span class="text-slate-500 text-xs">保留记录 · 不可永久删除</span></template>
        <template #footer-left><div class="flex gap-2"><template v-for="(link,index) in orders.links" :key="index"><a v-if="link.url" :href="link.url" class="erp-btn erp-btn-secondary" :aria-current="link.active?'page':undefined" v-html="link.label"/></template></div></template>
      </GlobalGridTemplates>
      <p v-if="!orders.data.length">回收站为空</p>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
