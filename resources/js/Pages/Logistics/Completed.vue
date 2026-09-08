<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import StatusBar from '../../Components/Logistics/StatusBar.vue'
type Row={order_url:string|null;external_order_number:string|null;erp_order_id:string|null;tracking_number:string|null}
defineProps<{shipments:{data:Row[]};statusBar:{key:string;label:string;url:string;count:number}[]}>()
</script>
<template><Head title="已完结 · BYL ERP"/><AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP','物流','已完结']"><section class="mvp-page"><StatusBar :statuses="statusBar"/><h2>已完结</h2><div class="mvp-table-wrap"><table class="mvp-table"><tbody><tr v-for="(row,index) in shipments.data" :key="index"><td><Link v-if="row.order_url" :href="row.order_url" class="mvp-link">{{ row.external_order_number ?? '—' }}</Link><span v-else>{{ row.external_order_number ?? '—' }}</span></td><td>{{ row.tracking_number ?? '—' }}</td></tr></tbody></table></div></section></AuthenticatedLayout></template>
