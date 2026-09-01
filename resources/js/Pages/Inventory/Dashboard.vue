<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type WarehouseSummary = {
    slug: string
    name: string
    country_code: string | null
    address: string
    product_count: number | null
    quantity_on_hand: number | null
    is_active: boolean
    inventory_state: 'confirmed' | 'partial_only' | 'manual' | 'never_imported'
    inventory_state_label: string
    last_inventory_at: string | null
}

defineProps<{summary:{warehouse_count:number;warehouse_data_gap_count:number;warehouse_confirmed_count:number;sku_rows:number;quantity_on_hand:number;quantity_reserved:number;quantity_available:number;out_of_stock_rows:number;warehouses:WarehouseSummary[]};canManage:boolean}>()
const breadcrumbs=[{label:'BYL ERP',href:'/dashboard'},'库存']
const formatDate=(value:string|null)=>value?new Intl.DateTimeFormat('zh-CN',{dateStyle:'medium'}).format(new Date(value)):'—'
</script>

<template><Head title="库存 · BYL ERP"/><AuthenticatedLayout title="库存" :breadcrumbs="breadcrumbs"><section class="mvp-page">
<div class="mvp-page-heading"><div><p class="mvp-kicker">Warehouse / Inventory Truth</p><h2>库存概览</h2><p>库存、仓库与发货仓选择的统一事实源。</p></div><div class="mvp-actions"><Link class="mvp-button" href="/inventory/list">仓库清单</Link><Link class="mvp-button" href="/inventory/products">产品库存</Link><Link v-if="canManage" class="mvp-button mvp-button-primary" href="/inventory/add">增加仓库</Link></div></div>
<div class="mvp-stat-grid">
<article class="mvp-card mvp-stat-card"><small>启用仓库</small><strong>{{summary.warehouse_count}}</strong></article>
<article class="mvp-card mvp-stat-card"><small>已确认仓库</small><strong>{{summary.warehouse_confirmed_count}}</strong></article>
<article class="mvp-card mvp-stat-card"><small>库存未导入仓</small><strong>{{summary.warehouse_data_gap_count}}</strong></article>
<article class="mvp-card mvp-stat-card"><small>库存记录</small><strong>{{summary.sku_rows}}</strong></article>
<article class="mvp-card mvp-stat-card"><small>已记录库存</small><strong>{{summary.quantity_on_hand}}</strong></article>
<article class="mvp-card mvp-stat-card"><small>可用库存</small><strong>{{summary.quantity_available}}</strong></article>
<article class="mvp-card mvp-stat-card"><small>预留库存</small><strong>{{summary.quantity_reserved}}</strong></article>
<article class="mvp-card mvp-stat-card"><small>无可用库存记录</small><strong>{{summary.out_of_stock_rows}}</strong></article>
</div>
<div class="mvp-card"><h3>仓库</h3><div class="inventory-grid"><Link v-for="w in summary.warehouses" :key="w.slug" class="warehouse-card" :href="`/inventory/${w.slug}`"><strong>{{w.name}}</strong><span>{{w.country_code||'国家未填写'}} · {{w.address||'地址未填写'}}</span><span>{{w.inventory_state_label}} · {{formatDate(w.last_inventory_at)}}</span><span v-if="w.inventory_state==='never_imported'">— 个产品 · 库存 —</span><span v-else>{{w.product_count}} 个产品 · 库存 {{w.quantity_on_hand}}</span></Link></div></div>
</section></AuthenticatedLayout></template>
<style scoped>.inventory-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.warehouse-card{display:flex;flex-direction:column;gap:6px;padding:14px;border:1px solid #e5e7eb;border-radius:12px;text-decoration:none;color:inherit}.warehouse-card:hover{border-color:#0f766e}.warehouse-card span{font-size:13px;color:#64748b}</style>
