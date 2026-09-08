<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import StatusBar from '../../Components/Logistics/StatusBar.vue'
type Item = { sku:string|null; product_summary:string|null; product_url:string|null }
type Row = { order_id:number; external_order_number:string|null; erp_order_id:string|null; order_url:string|null; reason:string; trigger:string; requested_at:string|null; items:Item[] }
const props = defineProps<{ reviews:{data:Row[];current_page:number;last_page:number;total:number}; statusBar:{key:string;label:string;url:string;count:number}[] }>()
const forms = reactive<Record<number,{destination:string;note:string}>>({})
const form = (row:Row) => forms[row.order_id] ??= { destination:'pending', note:'' }
const labels:Record<string,string> = { pending:'待发货', awaiting_tracking:'已发待单号', in_transit:'运输中', exception:'异常单', completed:'已完结', cancelled:'已取消' }
const decide = (row:Row) => { const value=form(row); if (!value.note.trim()) return; router.post(`/logistics/orders/${row.order_id}/review`, { destination:value.destination, decision_note:value.note.trim() }, { preserveScroll:true }) }
</script>
<template><Head title="复核单 · BYL ERP"/><AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP','物流','复核单']"><section class="mvp-page"><StatusBar :statuses="props.statusBar"/><div class="mvp-page-heading"><div><p class="mvp-kicker">Logistics Review</p><h2>复核单</h2><p>复核中的订单会阻断正常履约操作。</p></div></div><div class="mvp-table-wrap"><table class="mvp-table"><thead><tr><th>订单号</th><th>商品 / SKU</th><th>触发</th><th>原因</th><th>目标状态</th><th>复核说明</th><th>操作</th></tr></thead><tbody><tr v-for="row in props.reviews.data" :key="row.order_id"><td><Link v-if="row.order_url" :href="row.order_url" class="mvp-link">{{ row.external_order_number ?? '—' }}</Link><span v-else>{{ row.external_order_number ?? '—' }}</span></td><td><template v-for="item in row.items" :key="item.product_url ?? item.sku"><Link v-if="item.product_url" :href="item.product_url" class="mvp-link">{{ item.sku ?? item.product_summary }}</Link><span v-else>{{ item.sku ?? item.product_summary }}</span> </template></td><td>{{ row.trigger }}</td><td>{{ row.reason }}</td><td><select v-model="form(row).destination"><option v-for="(label,value) in labels" :key="value" :value="value">{{ label }}</option></select></td><td><textarea v-model="form(row).note" rows="2" required placeholder="必填" /></td><td><button class="mvp-button mvp-button-primary" :disabled="!form(row).note.trim()" @click="decide(row)">确认复核</button></td></tr><tr v-if="!props.reviews.data.length"><td colspan="7" class="mvp-empty">暂无复核单</td></tr></tbody></table></div></section></AuthenticatedLayout></template>
