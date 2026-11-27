<script setup lang="ts">
import { computed } from 'vue'
type Row=Record<string,unknown>
const props=defineProps<{params:{data?:Row;value?:unknown;mode?:'image'|'text';label?:(row:Row)=>string;activate?:(row:Row)=>void;canActivate?:(row:Row)=>boolean;canAdd?:(row:Row)=>boolean;add?:(row:Row)=>void}}>()
const row=computed(()=>props.params.data??{})
const text=computed(()=>props.params.label?.(row.value)??String(props.params.value??'—'))
const clickable=computed(()=>!!props.params.activate&&(props.params.canActivate?.(row.value)??true))
const image=computed(()=>{const url=String(props.params.value??'');return url.startsWith('https://')||(url.startsWith('/')&&!url.startsWith('//'))?url:null})
</script>
<template>
  <div class="sku-grid-cell">
    <template v-if="params.mode==='image'">
      <button v-if="image" type="button" class="sku-grid-thumb" aria-label="放大产品图片" @click.stop="params.activate?.(row)"><img :src="image" :alt="String(row.sku??'')" loading="lazy" referrerpolicy="no-referrer"/></button><span v-else>—</span>
    </template>
    <template v-else>
      <button v-if="clickable" type="button" class="sku-grid-text sku-grid-link" :title="text" @click.stop="params.activate?.(row)">{{text}}</button>
      <span v-else class="sku-grid-text" :title="text">{{text}}</span>
      <button v-if="params.canAdd?.(row)" type="button" class="sku-grid-add" aria-label="新增物料版本" title="新增物料版本" @click.stop="params.add?.(row)">+</button>
    </template>
  </div>
</template>
<style>
.sku-grid-cell{display:flex;align-items:center;gap:5px;width:100%;height:100%;min-width:0}
.sku-grid-text{flex:1;min-width:0;text-overflow:ellipsis;overflow:hidden;white-space:nowrap;text-align:left}
.sku-grid-link,.sku-grid-add,.sku-grid-thumb{border:0;background:transparent;padding:0;color:#116b57;cursor:pointer}
.sku-grid-link:hover{text-decoration:underline}.sku-grid-add{flex:none;font-weight:700;font-size:16px;padding:0 4px}
.sku-grid-thumb img{width:28px;height:28px;object-fit:contain;border-radius:3px;display:block}
</style>
