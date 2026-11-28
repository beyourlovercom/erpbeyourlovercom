<script setup lang="ts">
import { computed, reactive } from 'vue'
import { usePage } from '@inertiajs/vue3'
import type { ColDef, ICellRendererParams } from 'ag-grid-community'
import GlobalGrid from '../../Components/DataGrid/GlobalGrid.vue'
import GlobalGridCell from '../../Components/DataGrid/GlobalGridCell.vue'
import MatrixStatusFilter from './MatrixStatusFilter.vue'

type Cell = {on_hand:number|null;transit:number;pending:number}
type MatrixRow = {id:number;sku:string;material_name:string;warehouses:Record<string,Cell>}
type GridRow = Record<string,unknown>
const props = defineProps<{ rows: MatrixRow[] }>()
const warehouses = [
  ['FACTORY','中国工厂'], ['US-LA','美西 MorePlus'], ['US-NY','美东'],
  ['US-SM','美世迈仓'], ['DE','德国仓'], ['CA','加拿大仓'],
] as const
const actor = Number((usePage().props as {auth?:{user?:{id?:number}}}).auth?.user?.id)
const key = actor > 0 ? 'inventory-matrix:status:v1:user:'+actor : null
const statuses = reactive<Record<string,string>>({})
const options = ['','zero','low','mid','high','incoming','noincoming','risk','pending','unknown']
try {
  const saved = key ? JSON.parse(localStorage.getItem(key) ?? '{}') : {}
  for (const [code] of warehouses) if (options.includes(saved?.[code])) statuses[code] = saved[code]
} catch { /* optional local preference */ }
function setStatus(code:string, value:string) {
  if (!options.includes(value)) return
  statuses[code] = value
  try { if (key) localStorage.setItem(key, JSON.stringify(statuses)) } catch { /* optional cache */ }
}
const blank:Cell = {on_hand:null,transit:0,pending:0}
function match(c:Cell, status:string) {
  const n = c.on_hand
  switch(status) {
    case 'zero': return n===0
    case 'low': return n!==null && n>=1 && n<=20
    case 'mid': return n!==null && n>=21 && n<=50
    case 'high': return n!==null && n>50
    case 'incoming': return c.transit>0
    case 'noincoming': return c.transit===0
    case 'risk': return n===0 && c.transit===0 && c.pending===0
    case 'pending': return c.pending>0
    case 'unknown': return n===null
    default: return true
  }
}
const gridRows = computed<GridRow[]>(() => props.rows
  .filter(r=>warehouses.every(([code])=>match(r.warehouses[code]??blank,statuses[code]??'')))
  .map(r=>({id:r.id,sku:r.sku,material_name:r.material_name,warehouses:r.warehouses,
    ...Object.fromEntries(warehouses.map(([code])=>[code,r.warehouses[code]?.on_hand??null]))})))
function stockRenderer(params:ICellRendererParams<GridRow>,code:string) {
  const cell = (params.data?.warehouses as Record<string,Cell>|undefined)?.[code] ?? blank
  const wrap = document.createElement('span')
  wrap.className='inventory-matrix-stock'
  const actual=document.createElement('strong')
  actual.textContent=cell.on_hand===null?'—':String(cell.on_hand)
  actual.className=cell.on_hand===null?'inventory-matrix-unknown':cell.on_hand===0?'inventory-matrix-zero':''
  wrap.append(actual)
  if(cell.transit>0) {
    const incoming=document.createElement('span')
    incoming.className='inventory-matrix-incoming'
    incoming.textContent=' (+'+cell.transit+')'
    incoming.title='海运在途，未计入当前库存'
    wrap.append(incoming)
  }
  if(cell.pending>0) {
    const pending=document.createElement('span')
    pending.className='inventory-matrix-pending'
    pending.textContent=' 待激活 '+cell.pending
    pending.title=code==='US-LA'?'已收货，实际库存以 MorePlus 同步为准':'已收货，尚未激活库存'
    wrap.append(pending)
  }
  return wrap
}
const columns:ColDef<GridRow>[] = [
  {colId:'sku',field:'sku',headerName:'SKU',width:154,pinned:'left',lockPinned:true,filter:'agTextColumnFilter',
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'link',hrefFor:(r:GridRow)=>'/products/'+r.id}},
  {colId:'material_name',field:'material_name',headerName:'物料名称',width:220,pinned:'left',lockPinned:true,filter:'agTextColumnFilter'},
  ...warehouses.map(([code,label]):ColDef<GridRow>=>({
    colId:code,field:code,headerName:label,width:146,minWidth:125,
    filter:'agNumberColumnFilter', floatingFilter:true,
    floatingFilterComponent:MatrixStatusFilter,
    floatingFilterComponentParams:{label,current:()=>statuses[code]??'',setStatus:(value:string)=>setStatus(code,value)},
    cellRenderer:(p:ICellRendererParams<GridRow>)=>stockRenderer(p,code),
    getQuickFilterText:()=>'', cellDataType:'number',
  })),
]
</script>

<template>
  <div class="inventory-matrix">
    <div class="inventory-matrix-legend">
      <span>实际库存</span><span class="inventory-matrix-incoming">(+N) 海运在途</span>
      <span class="inventory-matrix-zero">0 缺货</span><span class="inventory-matrix-unknown">— 未记录</span>
      <span class="inventory-matrix-pending">待激活 已收货未确认库存</span>
      <span>按实际库存排序；每列独立筛选</span>
    </div>
    <GlobalGrid :column-settings-version="2" :field-groups="{'商品信息':['sku','material_name'],'仓库库存':warehouses.map(([code])=>code)}" namespace="grid.inventory.matrix.v1" title="全球多仓库存总表"
      :rows="gridRows" :columns="columns" :required-column-ids="['sku','material_name']"
      :default-pinned-left="2" :default-pinned-right="0" remember-client-filters
      search-placeholder="搜索 SKU / 物料名称...">
      <template #actions><button type="button" class="mvp-button" @click="warehouses.forEach(([code])=>setStatus(code,''))">重置库存状态</button></template>
    </GlobalGrid>
    <p class="inventory-matrix-note">按产品主档和正式库存记录统计；未识别 SKU 不计入海运数量。MorePlus 当前库存仅以仓库同步为准，海运数字不是可销售库存。</p>
  </div>
</template>

<style>
.inventory-matrix-legend{display:flex;gap:16px;flex-wrap:wrap;padding:10px 0;font-size:12px;color:#64748b}
.inventory-matrix-stock{display:inline-flex;align-items:center;gap:3px;white-space:nowrap;font-variant-numeric:tabular-nums}
.inventory-matrix-incoming{color:#16834b;font-weight:600}.inventory-matrix-zero{color:#b91c1c}.inventory-matrix-unknown{color:#94a3b8}.inventory-matrix-pending{color:#a16207;font-size:11px}
.inventory-matrix-note{font-size:12px;color:#64748b;margin:8px 0}
</style>
