<script setup lang="ts">
import { computed } from 'vue'
import OrderWarehouseRouteSelect from './OrderWarehouseRouteSelect.vue'
type Route = { code:string; name:string; source:string; revision:number }
type Option = { code:string; name:string }
type GridRow = { id?:number; order_id?:number; routing_warehouse?:Route|null; can_edit_warehouse_route?:boolean; _is_additional_item?:boolean }
type Params = { data?:GridRow; warehouseRouteOptions?:Option[]; canEditWarehouseRoute?:boolean }
const props = defineProps<{ params:Params }>()
const row = computed(() => props.params.data)
const orderId = computed(() => Number(row.value?.order_id ?? row.value?.id ?? 0))
</script>
<template>
  <span class="erp-route-grid-cell" @click.stop @mousedown.stop @keydown.stop>
    <span v-if="row?._is_additional_item" aria-label="同订单附加SKU行">·</span>
    <OrderWarehouseRouteSelect v-else-if="orderId && row?.routing_warehouse"
      :order-id="orderId" :projection="row.routing_warehouse"
      :can-edit="Boolean(params.canEditWarehouseRoute && row.can_edit_warehouse_route)"
      :options="params.warehouseRouteOptions ?? []" />
    <span v-else>—</span>
  </span>
</template>
<style>.erp-route-grid-cell { display:inline-flex;align-items:center;max-width:100%;min-width:0;height:100% }</style>
