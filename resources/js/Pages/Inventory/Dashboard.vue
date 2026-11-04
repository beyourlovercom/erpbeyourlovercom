<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type WarehouseSummary = {
    slug: string
    public_slug: string
    code: string
    name: string
    name_en: string | null
    country_code: string | null
    address: string
    role_label: string
    is_fulfillment_warehouse: boolean
    is_inventory_only_warehouse: boolean
    product_count: number | null
    quantity_on_hand: number | null
    is_active: boolean
    inventory_state: 'confirmed' | 'partial_only' | 'manual' | 'never_imported'
    inventory_state_label: string
    fulfillment_state: 'preparing' | 'awaiting_inventory' | 'ready' | 'not_ready'
    fulfillment_state_label: string
    last_inventory_at: string | null
}

type Summary = {
    warehouse_count: number
    warehouse_total_count: number
    fulfillment_warehouse_count: number
    inventory_only_warehouse_count: number
    warehouse_data_gap_count: number
    warehouse_confirmed_count: number
    sku_rows: number
    quantity_on_hand: number
    quantity_reserved: number
    quantity_available: number
    out_of_stock_rows: number
    low_stock_rows: number
    in_stock_rows: number
    stock_alert_rows: number
    warehouses: WarehouseSummary[]
}

type OverdueSummary = {overdue_units:number;overdue_products:number;overdue_lot_rows:number;unknown_age_units:number;lot_mismatch_skus:number}
withDefaults(defineProps<{ summary: Summary; overdue?: OverdueSummary; canManage: boolean }>(), {overdue:()=>({overdue_units:0,overdue_products:0,overdue_lot_rows:0,unknown_age_units:0,lot_mismatch_skus:0})})
const breadcrumbs=[{label:'BYL ERP',href:'/dashboard'},'库存']
const formatDate=(value:string|null)=>value?new Intl.DateTimeFormat('zh-CN',{dateStyle:'medium'}).format(new Date(value)):'—'
const statScroller=ref<HTMLElement|null>(null)
const scrollMetrics=(direction:-1|1)=>{
  statScroller.value?.scrollBy({left:direction*440,behavior:'smooth'})
}
const handleMetricKey=(event:KeyboardEvent)=>{
  if(event.key==='ArrowLeft'){event.preventDefault();scrollMetrics(-1)}
  if(event.key==='ArrowRight'){event.preventDefault();scrollMetrics(1)}
}

</script>

<template>
<Head title="库存 · BYL ERP"/>
<AuthenticatedLayout title="库存" :breadcrumbs="breadcrumbs">
<section class="mvp-page">
  <div class="mvp-page-heading">
    <div><p class="mvp-kicker">Warehouse Master / Inventory Truth</p><h2>库存概览</h2><p>7 个 Warehouse Master；其中 6 个参与待分仓，007 仅用于库存统计。</p></div>
    <div class="mvp-actions"><Link class="mvp-button" href="/inventory/list">仓库清单</Link><Link class="mvp-button" href="/inventory/products">产品库存</Link><Link v-if="canManage" class="mvp-button mvp-button-primary" href="/inventory/add">增加仓库</Link></div>
  </div>

  <div class="inventory-stat-ticker" aria-label="库存统计概况">
    <button class="inventory-ticker-arrow" type="button" aria-label="向左滑动库存指标" @click="scrollMetrics(-1)">‹</button>
    <div ref="statScroller" class="inventory-ticker-scroll" role="region" aria-label="库存统计指标，左右滑动查看更多" tabindex="0" @keydown="handleMetricKey">
      <div class="inventory-ticker-track">
        <article class="inventory-ticker-metric"><small>可用库存</small><strong>{{summary.quantity_available.toLocaleString('zh-CN')}}</strong></article>
        <article class="inventory-ticker-metric"><small>已记录库存</small><strong>{{summary.quantity_on_hand.toLocaleString('zh-CN')}}</strong></article>
        <Link href="/inventory/overdue" class="inventory-ticker-metric inventory-ticker-link is-overdue" aria-label="查看超期库存清单"><small>超期库存 ≥90天</small><strong>{{overdue.overdue_units.toLocaleString('zh-CN')}} <em>件</em></strong></Link>
        <article class="inventory-ticker-metric"><small>预留库存</small><strong>{{summary.quantity_reserved.toLocaleString('zh-CN')}}</strong></article>
        <article class="inventory-ticker-metric is-danger"><small>没货 SKU</small><strong>{{summary.out_of_stock_rows.toLocaleString('zh-CN')}}</strong></article>
        <article class="inventory-ticker-metric is-warning"><small>库存紧张 SKU</small><strong>{{summary.low_stock_rows.toLocaleString('zh-CN')}}</strong></article>
        <article class="inventory-ticker-metric"><small>有货 SKU</small><strong>{{summary.in_stock_rows.toLocaleString('zh-CN')}}</strong></article>
        <article class="inventory-ticker-metric"><small>库存记录</small><strong>{{summary.sku_rows.toLocaleString('zh-CN')}}</strong></article>
        <article class="inventory-ticker-metric"><small>仓库总数</small><strong>{{summary.warehouse_total_count.toLocaleString('zh-CN')}}</strong></article>
        <article class="inventory-ticker-metric"><small>待分仓仓库</small><strong>{{summary.fulfillment_warehouse_count.toLocaleString('zh-CN')}}</strong></article>
        <article class="inventory-ticker-metric"><small>库存统计仓</small><strong>{{summary.inventory_only_warehouse_count.toLocaleString('zh-CN')}}</strong></article>
        <article class="inventory-ticker-metric"><small>已确认仓库</small><strong>{{summary.warehouse_confirmed_count.toLocaleString('zh-CN')}}</strong></article>
        <article class="inventory-ticker-metric"><small>库存未导入仓</small><strong>{{summary.warehouse_data_gap_count.toLocaleString('zh-CN')}}</strong></article>
      </div>
    </div>
    <button class="inventory-ticker-arrow" type="button" aria-label="向右滑动库存指标" @click="scrollMetrics(1)">›</button>
  </div>

  <div class="mvp-card warehouse-master-card">
    <div class="warehouse-section-heading"><div><h3>仓库</h3><p>一行一个仓库；点击仓库名称进入仓库详情与编辑页面。</p></div><span>{{summary.warehouse_total_count}} 个 Warehouse Master</span></div>
    <div class="warehouse-list">
      <article v-for="w in summary.warehouses" :key="w.code" class="warehouse-row">
        <div class="warehouse-identity">
          <Link class="warehouse-title" :href="`/inventory/${w.public_slug || w.slug}`">{{w.name}}</Link>
          <span>{{w.name_en || w.code}} · {{w.code}}</span>
        </div>
        <div class="warehouse-cell"><small>用途</small><strong>{{w.role_label}}</strong><span>{{w.is_fulfillment_warehouse?'待分仓可见':'不进入待分仓'}}</span></div>
        <div class="warehouse-cell"><small>库存状态</small><strong>{{w.inventory_state_label}}</strong><span>{{formatDate(w.last_inventory_at)}}</span></div>
        <div class="warehouse-cell"><small>库存</small><strong v-if="w.inventory_state!=='never_imported'">{{w.product_count}} 个产品 · {{w.quantity_on_hand}}</strong><strong v-else>—</strong><span>当前记录</span></div>
        <div class="warehouse-cell warehouse-address"><small>地区 / 地址</small><strong>{{w.country_code||'国家未填写'}}</strong><span>{{w.address||'地址未填写'}}</span></div>
        <div class="warehouse-cell warehouse-status"><small>状态</small><strong :class="w.is_active?'status-on':'status-off'">{{w.is_active?'启用':'停用'}}</strong><span>{{w.fulfillment_state_label}}</span></div>
      </article>
    </div>
  </div>
</section>
</AuthenticatedLayout>
</template>

<style scoped>
.inventory-stat-ticker{display:flex;align-items:stretch;gap:7px;min-width:0;width:100%;height:76px;margin-bottom:2px}
.inventory-ticker-arrow{flex:0 0 28px;align-self:stretch;border:1px solid #e1e7ea;border-radius:8px;background:#fff;color:#637480;font-size:23px;line-height:1;cursor:pointer}
.inventory-ticker-arrow:hover{background:#f2f7f8;color:#177d77}
.inventory-ticker-arrow:focus-visible,.inventory-ticker-scroll:focus-visible,.inventory-ticker-link:focus-visible{outline:2px solid #168f87;outline-offset:2px}
.inventory-ticker-scroll{min-width:0;flex:1;overflow-x:auto;overflow-y:hidden;scrollbar-width:thin;scrollbar-color:#cbd5da transparent;overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch}
.inventory-ticker-track{display:flex;flex-wrap:nowrap;gap:7px;width:max-content;min-width:100%;height:69px}
.inventory-ticker-metric{box-sizing:border-box;display:flex;flex:0 0 145px;min-width:145px;flex-direction:column;justify-content:center;gap:6px;padding:9px 12px;border:1px solid #e1e7ea;border-radius:9px;background:#fff;white-space:nowrap;text-decoration:none;color:#283b46}
.inventory-ticker-metric small{display:block;margin:0;color:#71818c;font-size:11px;line-height:1.25;white-space:nowrap}
.inventory-ticker-metric strong{display:block;font-size:22px;font-weight:750;line-height:1.1;color:#217c77;letter-spacing:-.35px;font-variant-numeric:tabular-nums}
.inventory-ticker-metric em{font-style:normal;font-size:12px;color:inherit;font-weight:500}
.inventory-ticker-metric.is-overdue strong{color:#aa6320}
.inventory-ticker-metric.is-danger strong{color:#bf4444}
.inventory-ticker-metric.is-warning strong{color:#b17c23}
.inventory-ticker-link:hover{border-color:#c9dcd8;background:#f8fbfa}
@media(max-width:760px){.inventory-stat-ticker{height:73px;gap:4px}.inventory-ticker-arrow{flex-basis:24px}.inventory-ticker-track{gap:6px;height:67px}.inventory-ticker-metric{flex-basis:130px;min-width:130px;padding:8px 10px}.inventory-ticker-metric strong{font-size:20px}}
.warehouse-master-card{padding:0;overflow:hidden}.warehouse-section-heading{display:flex;justify-content:space-between;align-items:flex-start;padding:18px 20px;border-bottom:1px solid #e5e7eb}.warehouse-section-heading h3{margin:0;color:#111827}.warehouse-section-heading p{margin:5px 0 0;font-size:13px;color:#64748b}.warehouse-section-heading>span{font-size:12px;font-weight:700;color:#475569;background:#f1f5f9;border-radius:999px;padding:5px 10px}.warehouse-list{display:flex;flex-direction:column}.warehouse-row{display:grid;grid-template-columns:minmax(220px,1.3fr) minmax(130px,.8fr) minmax(150px,.9fr) minmax(150px,.8fr) minmax(220px,1.35fr) minmax(110px,.65fr);gap:18px;align-items:center;padding:17px 20px;border-bottom:1px solid #e5e7eb}.warehouse-row:last-child{border-bottom:0}.warehouse-row:hover{background:#f8fafc}.warehouse-identity,.warehouse-cell{display:flex;flex-direction:column;gap:4px;min-width:0}.warehouse-title{font-size:16px;font-weight:800;color:#111827;text-decoration:none}.warehouse-title:hover{color:#0f766e;text-decoration:underline}.warehouse-identity span,.warehouse-cell span{font-size:12px;color:#64748b}.warehouse-cell small{font-size:11px;color:#94a3b8}.warehouse-cell strong{font-size:13px;color:#111827}.warehouse-address span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.status-on{color:#047857!important}.status-off{color:#b91c1c!important}.alert-danger{border-color:#fecaca}.alert-warning{border-color:#fed7aa}@media(max-width:1100px){.warehouse-row{grid-template-columns:1.4fr 1fr 1fr}.warehouse-address{grid-column:1/3}}@media(max-width:760px){.warehouse-row{grid-template-columns:1fr;gap:10px}.warehouse-address{grid-column:auto}.warehouse-section-heading{display:block}.warehouse-section-heading>span{display:inline-flex;margin-top:10px}}
</style>
