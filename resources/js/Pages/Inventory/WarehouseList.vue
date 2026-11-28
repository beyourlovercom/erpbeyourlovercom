<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed as gridComputed } from 'vue'
import type { ColDef } from 'ag-grid-community'
import GlobalGridTemplates from '../../Components/DataGrid/GlobalGridTemplates.vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
type W={slug:string;public_slug:string;code:string;name:string;name_en:string|null;country_code:string|null;address:string;role_label:string;is_fulfillment_warehouse:boolean;ownership_type:'owned'|'external';is_active:boolean;priority:number;product_count:number|null;quantity_on_hand:number|null;inventory_state:'confirmed'|'partial_only'|'manual'|'never_imported';inventory_state_label:string;last_inventory_at:string|null;fulfillment_available_on:string|null;fulfillment_state:'preparing'|'awaiting_inventory'|'ready'|'not_ready';fulfillment_state_label:string}
const props=defineProps<{warehouses:W[];canManage:boolean}>()
const breadcrumbs=[{label:'BYL ERP',href:'/dashboard'},{label:'库存',href:'/inventory'},'仓库清单']
const formatDate=(value:string|null)=>value?new Intl.DateTimeFormat('zh-CN',{dateStyle:'medium'}).format(new Date(value)):'—'
type GridRow=Record<string,unknown>
const warehouseRows=gridComputed<GridRow[]>(()=>props.warehouses.map(w=>({...w,
  display_name:w.name+' · '+(w.name_en||w.code),
  country_label:w.country_code||'—',address_label:w.address||'未填写',
  ownership_label:w.ownership_type==='external'?'外部仓':'自有仓',
  active_label:w.is_active?'启用':'停用',last_update:formatDate(w.last_inventory_at),
})))
const warehouseColumns:ColDef<GridRow>[]=[
  {colId:'name',field:'display_name',headerName:'仓库',width:230,pinned:'left',lockPinned:true},
  {field:'code',headerName:'代码',width:100},
  {field:'role_label',headerName:'用途',width:136},{field:'country_label',headerName:'国家',width:90},
  {field:'address_label',headerName:'地址',width:260,tooltipField:'address_label'},
  {field:'product_count',headerName:'产品数量',width:100,filter:'agNumberColumnFilter'},
  {field:'quantity_on_hand',headerName:'总库存',width:110,filter:'agNumberColumnFilter'},
  {field:'inventory_state_label',headerName:'库存数据',width:128},
  {field:'last_update',headerName:'最近更新',width:135},
  {field:'ownership_label',headerName:'归属',width:95},{field:'priority',headerName:'优先级',width:90,filter:'agNumberColumnFilter'},
  {field:'fulfillment_state_label',headerName:'履约状态',width:130},
  {field:'fulfillment_available_on',headerName:'预计可发货',width:135},
  {field:'active_label',headerName:'状态',width:85},
]
const warehouseGroups={'仓库信息':['name','code','role_label','country_label','address_label','ownership_label','priority','active_label'],
  '库存与履约':['product_count','quantity_on_hand','inventory_state_label','last_update','fulfillment_state_label','fulfillment_available_on']}
</script>
<template><Head title="仓库清单 · BYL ERP"/><AuthenticatedLayout title="仓库清单" :breadcrumbs="breadcrumbs"><section class="mvp-page">
<div class="mvp-page-heading"><div><p class="mvp-kicker">Warehouse Master</p><h2>仓库清单</h2></div><Link v-if="canManage" class="mvp-button mvp-button-primary" href="/inventory/add">增加仓库</Link></div>
<GlobalGridTemplates namespace="grid.inventory.warehouse-list.v2" title="仓库清单"
  :columns="warehouseColumns" :rows="warehouseRows" :field-groups="warehouseGroups"
  :required-column-ids="['name','code']" row-identity-key="code" :default-pinned-right="0" cell-text-selection>
  <template #cell-name="{row: w}"><div><Link :href="`/inventory/${w.public_slug||w.slug}`"><strong>{{w.name}}</strong></Link><small class="warehouse-en">{{w.name_en||w.code}}</small></div></template>
  <template #cell-product_count="{row: w}">{{w.product_count??'—'}}</template>
  <template #cell-quantity_on_hand="{row: w}">{{w.quantity_on_hand??'—'}}</template>
  <template #cell-fulfillment_available_on="{row: w}">{{w.fulfillment_available_on||'—'}}</template>
</GlobalGridTemplates></section></AuthenticatedLayout></template>
<style scoped>.warehouse-en{display:block;color:#64748b;font-size:11px;line-height:1.1}</style>
