<script setup lang="ts">
import { computed, defineAsyncComponent, ref, watch } from 'vue'
import type { ColDef } from 'ag-grid-community'
import GlobalGridCell from '../../Components/DataGrid/GlobalGridCell.vue'
import { gridColumn, useGlobalGridListAdapter, type GridListRow } from '../../Components/DataGrid/useGlobalGridListAdapter'
import { router } from '@inertiajs/vue3'
const GlobalGrid = defineAsyncComponent(() => import('../../Components/DataGrid/GlobalGrid.vue'))

export type L2Mode = 'awaiting' | 'transit' | 'exceptions'
export type L2Row = {
  shipment_id:number;order_id:number;external_order_number:string|null;erp_order_id:string|null
  recipient_name:string|null;country:string|null;warehouse_name:string|null;carrier:string|null
  service:string|null;tracking_number:string|null;shipped_at:string|null;logistics_note:string|null
  shipment_status:string;order_url:string|null;paused?:boolean
}
type Pager = {data:L2Row[];current_page:number;last_page:number;per_page:number;total:number}
const props = defineProps<{
  mode:L2Mode;path:string;title:string;shipments:Pager;canOperate:boolean
  search?:string;sort?:{colId:string;direction:'asc'|'desc'}
}>()
const emit = defineEmits<{
  (event:'open',row:L2Row):void
  (event:'copy',row:L2Row):void
  (event:'resolve',row:L2Row):void
  (event:'choose-action',row:L2Row,path:string,label:string):void
}>()
const term = ref(props.search??'')
watch(()=>props.search,v=>{term.value=v??''})
const orderSort = ref(props.sort??{colId:'shipped_at',direction:'desc' as const})
watch(()=>props.sort,v=>{orderSort.value=v??{colId:'shipped_at',direction:'desc'}})
const navigation = useGlobalGridListAdapter({
  path:props.path,enabled:()=>true,search:()=>term.value,
  pageSize:()=>props.shipments.per_page,
  context:()=>({sort:orderSort.value.colId,direction:orderSort.value.direction}),
})
const rows = computed<GridListRow[]>(()=>props.shipments.data.map(row=>({
  ...row, id:row.shipment_id,
  order_number:row.erp_order_id??row.external_order_number??'—',
  logistics_company:[row.carrier,row.service].filter(Boolean).join(' / ')||'—',
  date:row.shipped_at?.replace('T',' ').slice(0,16)??'—',
  tracking:row.tracking_number??'—',
  note_summary:[row.paused?'已暂停':null,row.logistics_note].filter(Boolean).join(' · ')||'—',
})))
const columns = computed<ColDef<GridListRow>[]>(()=>{
  const base:ColDef<GridListRow>[]=[
    gridColumn({colId:'order',field:'order_number',headerName:'订单号',width:185,
      cellRenderer:GlobalGridCell,cellRendererParams:{mode:'link',hrefFor:(r:GridListRow)=>r.order_url}}),
    gridColumn({colId:'recipient',field:'recipient_name',headerName:'收件人',width:145}),
    gridColumn({colId:'country',field:'country',headerName:'国家',width:95}),
    gridColumn({colId:'warehouse',field:'warehouse_name',headerName:'仓库',width:135}),
    gridColumn({colId:'carrier',field:'logistics_company',headerName:'物流商 / 渠道',width:180,sortable:true}),
    gridColumn({colId:'tracking_number',field:'tracking',headerName:'追踪号',width:180,sortable:true}),
    gridColumn({colId:'shipped_at',field:'date',headerName:'发货日期',width:160,sortable:true}),
  ]
  if(props.mode==='exceptions')base.push(gridColumn({colId:'reason',field:'note_summary',headerName:'异常原因',width:225}))
  // Owner P0: render ALL row actions inline, never the GlobalGridCell
  // compact "操作 ▾" dropdown. 6 exception actions require real column width.
  const actionWidth = props.mode==='exceptions' ? 560 : props.mode==='transit' ? 194 : 142
  base.push(gridColumn({colId:'actions',field:'order_id',headerName:'操作',
    width:actionWidth,minWidth:actionWidth,
    cellRenderer:GlobalGridCell,
    cellRendererParams:{mode:'actions',compactActions:false,
      actionsFor:(row:GridListRow)=>{
        const origin=props.shipments.data.find(x=>x.shipment_id===Number(row.shipment_id))
        if(!origin)return []
        if(props.mode==='awaiting')return props.canOperate?[{label:'填写 Tracking',onClick:()=>emit('open',origin)}]:[]
        if(props.mode==='transit')return [
          {label:'复制追踪',onClick:()=>emit('copy',origin)},
          ...(props.canOperate?[{label:'标记异常',onClick:()=>emit('open',origin)}]:[]),
        ]
        if(!props.canOperate)return []
        const actions=[
          ['pause','暂停订单'],['redispatch','重新派件'],['reship','重新发货'],
          ['contact-user','联系用户'],['cancel-exception','取消订单（不退款）'],
        ] as const
        return [
          ...actions.map(([path,label])=>({
            label,onClick:()=>emit('choose-action',origin,path,label),
          })),
          {label:'解除异常',onClick:()=>emit('resolve',origin)},
        ]
      },
    },
  }))
  return base
})
const onSort = (next:{colId:string;direction:'asc'|'desc'|null})=>{
  if(!next.direction)return
  if(!['carrier','tracking_number','shipped_at'].includes(next.colId))return
  orderSort.value={colId:next.colId,direction:next.direction}
  router.get(props.path,{
    global_grid:'1',...(term.value.trim()?{search:term.value.trim()}:{}),
    sort:next.colId,direction:next.direction,per_page:props.shipments.per_page,page:1,
  },{preserveScroll:true,preserveState:true,replace:true})
}
const runSearch = (value:string)=>{term.value=value;navigation.search(value)}
</script>
<template>
  <GlobalGrid :namespace="'grid.logistics.l2.'+mode+'.global.v1'"
    :title="title" :columns="columns" :rows="rows" :required-column-ids="['order']"
    :row-identity-key="'shipment_id'" :page="shipments" :search="props.search??''"
    :sort="orderSort" search-placeholder="搜索全部订单号 / 收件人 / Tracking / 物流商..."
    :default-pinned-right="1" @search-change="runSearch"
    @page-change="navigation.page($event)" @page-size-change="navigation.pageSize($event)"
    @sort-change="onSort($event)" />
</template>
