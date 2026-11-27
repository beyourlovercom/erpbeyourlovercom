<script setup lang="ts">
import OfflineWarehouseMap from './OfflineWarehouseMap.vue';
import type {OrderAddress} from '#/api/erp-orders';
import type {LogisticsRow,AllocationWarehouse,AllocationItem} from '#/api/erp-logistics';
defineProps<{detail:LogisticsRow;address:OrderAddress|null;orderOwner:string;warehouses:AllocationWarehouse[];loading:boolean;error:string;effectiveItems?:AllocationItem[]}>();
</script>
<template>
<div class="space-y-3">
          <div class="offline-reference-grid">
            <section class="rounded-lg border border-slate-200 text-xs"><h3 class="rounded-t-lg bg-slate-50 p-3 font-semibold">订单信息</h3><dl class="offline-info-grid p-3"><dt>订单号</dt><dd>{{detail.order_number}}</dd><dt>收件人</dt><dd>{{address?.recipient_name||'—'}}</dd><dt>联系电话</dt><dd>{{address?.phone||'—'}}</dd><dt>订单商品</dt><dd>{{detail.sku}} × {{detail.qty}}</dd><dt>收货地址</dt><dd>{{[address?.city,address?.province_state,address?.country,address?.postal_code].filter(Boolean).join(' · ')||'—'}}</dd><dt>订单联络人</dt><dd>{{detail.owner_label||orderOwner||'—'}}</dd></dl></section>
            <section class="rounded-lg border border-slate-200 text-xs"><h3 class="rounded-t-lg bg-slate-50 p-3 font-semibold">地址区域判定</h3><dl class="offline-info-grid p-3"><dt>国家/地区</dt><dd>{{address?.country||'—'}}</dd><dt>州/邮编</dt><dd>{{[address?.province_state,address?.postal_code].filter(Boolean).join(' · ')||'—'}}</dd><dt>城市</dt><dd>{{address?.city||'—'}}</dd></dl><p class="m-3 rounded bg-slate-50 p-2 text-slate-500">地理距离只作人工参考，不替代运费、库存及跨境限制核对。</p></section>
            <OfflineWarehouseMap :key="'map-'+detail.order_id" :address="address" :warehouses="warehouses" :items="effectiveItems||detail.allocation_plan?.items||[]" :loading="loading" :error="error"/>
          </div>
          <section class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs"><h3 class="font-semibold">系统推荐结果说明</h3><p class="mt-2">地理距离辅助判断；实际运费、可售库存、跨境限制、产品包装和重量仍需人工确认。</p></section>
</div>
</template>
<style scoped>
.offline-reference-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:12px}.offline-info-grid{display:grid;grid-template-columns:88px minmax(0,1fr);gap:12px}.offline-info-grid dd{overflow-wrap:anywhere;margin:0}@media(min-width:1024px){.offline-reference-grid{grid-template-columns:minmax(210px,1fr) minmax(180px,.8fr) minmax(420px,2.1fr)}}
</style>
