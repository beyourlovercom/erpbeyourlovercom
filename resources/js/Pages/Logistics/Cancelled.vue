<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import StatusBar from '../../Components/Logistics/StatusBar.vue'
import ColumnSettingsHeader from '../../Components/DataGrid/ColumnSettingsHeader.vue'
import { useAccountGridPreferences, type GridColumnDefinition } from '../../Composables/useAccountGridPreferences'
type Row={order_url:string|null;erp_order_id:string|null;external_order_number:string|null;items:{sku:string|null;product_summary:string|null;product_url:string|null}[]}
defineProps<{orders:{data:Row[]};statusBar:{key:string;label:string;url:string;count:number}[]}>()
type ColumnKey = 'order_number' | 'items'
const definitions: GridColumnDefinition<ColumnKey>[] = [{ key: 'order_number', label: '订单号' }, { key: 'items', label: '商品 / SKU' }]
const { columns, visibleColumns, syncState, toggle, startDrag, drop, reset } = useAccountGridPreferences<ColumnKey>({ namespace: 'grid.logistics.cancelled.v1', definitions, requiredKeys: ['order_number'] })
</script>
<template><Head title="已取消 · BYL ERP"/><AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP','物流','已取消']"><section class="mvp-page"><StatusBar :statuses="statusBar"/><h2>已取消</h2><div class="mvp-table-wrap"><table class="mvp-table"><thead><tr><th v-for="column in visibleColumns" :key="column.key">{{ column.label }}</th><th>操作</th><ColumnSettingsHeader :columns="columns" :required-keys="['order_number']" :sync-state="syncState" @toggle="toggle" @start-drag="startDrag" @drop="drop" @reset="reset" /></tr></thead><tbody><tr v-for="(row,index) in orders.data" :key="index"><td v-for="column in visibleColumns" :key="column.key"><template v-if="column.key === 'order_number'"><Link v-if="row.order_url" :href="row.order_url" class="mvp-link">{{ row.erp_order_id ?? '—' }}</Link><span v-else>{{ row.erp_order_id ?? '—' }}</span><small v-if="row.external_order_number">来源订单：{{ row.external_order_number }}</small></template><template v-else><template v-for="item in row.items" :key="item.product_url ?? item.sku"><Link v-if="item.product_url" :href="item.product_url" class="mvp-link">{{ item.sku ?? item.product_summary }}</Link><span v-else>{{ item.sku ?? item.product_summary }}</span></template></template></td><td>—</td></tr><tr v-if="orders.data.length === 0"><td :colspan="visibleColumns.length + 2" class="mvp-empty">暂无已取消订单</td></tr></tbody></table></div></section></AuthenticatedLayout></template>
