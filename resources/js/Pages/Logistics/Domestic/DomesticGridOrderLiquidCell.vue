<script setup lang="ts">
type Row = {
  order_number: string
  order_url?: string
  has_liquid?: boolean
  liquid_skus?: string[]
}
const props = defineProps<{params:{data:Row;showLiquid?:boolean}}>()
</script>

<template>
  <div class="domestic-order-liquid-cell">
    <a v-if="props.params.data.order_url" :href="props.params.data.order_url">{{ props.params.data.order_number }}</a>
    <span v-else>{{ props.params.data.order_number }}</span>
    <span v-if="props.params.showLiquid && props.params.data.has_liquid"
      class="domestic-liquid-warning" :title="'含液体 SKU：'+(props.params.data.liquid_skus??[]).join('、')"
      role="note">⚠ 含液体</span>
  </div>
</template>

<style scoped>
.domestic-order-liquid-cell{height:100%;display:flex;align-items:center;gap:6px;min-width:0}
.domestic-order-liquid-cell a{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.domestic-liquid-warning{display:inline-flex;flex:0 0 auto;align-items:center;padding:2px 5px;border:1px solid #eab54f;background:#fff2cc;border-radius:5px;color:#92400e;font-size:11px;font-weight:700;line-height:1.4}
</style>
