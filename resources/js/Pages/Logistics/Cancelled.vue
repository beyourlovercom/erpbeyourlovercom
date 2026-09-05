<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import StatusBar from '../../Components/Logistics/StatusBar.vue'
type Row={order_url:string|null;order_number:string|null;items:{sku:string|null;product_summary:string|null;product_url:string|null}[]}
defineProps<{orders:{data:Row[]};statusBar:{key:string;label:string;url:string;count:number}[]}>()
</script>
<template><Head title="已取消 · BYL ERP"/><AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP','物流','已取消']"><section class="mvp-page"><StatusBar :statuses="statusBar"/><h2>已取消</h2><div class="mvp-table-wrap"><table class="mvp-table"><tbody><tr v-for="(row,index) in orders.data" :key="index"><td><Link v-if="row.order_url" :href="row.order_url" class="mvp-link">{{ row.order_number }}</Link><span v-else>{{ row.order_number }}</span></td><td><template v-for="item in row.items" :key="item.product_url ?? item.sku"><Link v-if="item.product_url" :href="item.product_url" class="mvp-link">{{ item.sku ?? item.product_summary }}</Link><span v-else>{{ item.sku ?? item.product_summary }}</span></template></td></tr></tbody></table></div></section></AuthenticatedLayout></template>
