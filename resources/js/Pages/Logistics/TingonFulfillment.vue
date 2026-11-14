<script setup lang="ts">
import { computed, defineAsyncComponent, reactive, ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import type { ColDef } from 'ag-grid-community'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import GlobalGridCell from '../../Components/DataGrid/GlobalGridCell.vue'
import { gridColumn, useGlobalGridListAdapter, type GridListRow } from '../../Components/DataGrid/useGlobalGridListAdapter'
const GlobalGrid = defineAsyncComponent(() => import('../../Components/DataGrid/GlobalGrid.vue'))

type TingonRow = {
  id:number;order_id:number;order_number:string|null;order_url:string
  recipient_name:string|null;country:string|null;sku:string
  warehouse:string|null;carrier:string|null;service:string|null
  tracking_number:string|null;logistics_note:string|null
  shipment_status:string;can_edit:boolean
}
type Pager={data:TingonRow[];current_page:number;last_page:number;per_page:number;total:number}
const props=defineProps<{orders:Pager;view:string;search:string;canOperate:boolean;carrierOptions:{value:string;label:string}[]}>()
const tabs=[
  {key:'pending',label:'待 Tingon 发货'},
  {key:'brand',label:'Tingon 品牌订单'},
  {key:'all',label:'合作代发全部'},
  {key:'sent',label:'已发货 / 待回填'},
]
const term=ref(props.search)
watch(()=>props.search,v=>term.value=v)
const nav=useGlobalGridListAdapter({
  path:'/logistics/tingon',enabled:()=>true,search:()=>term.value,
  pageSize:()=>props.orders.per_page,context:()=>({view:props.view}),
})
function changeView(view:string){
  term.value=''
  router.get('/logistics/tingon',{view,per_page:props.orders.per_page,page:1},{replace:true,preserveScroll:true})
}
const rows=computed<GridListRow[]>(()=>props.orders.data.map(r=>({
  ...r,order_number:r.order_number??'—',
  carrier_service:[r.carrier,r.service].filter(Boolean).join(' / ')||'—',
  tracking:r.tracking_number??'—',
  status:r.shipment_status==='pending'?'待发货':r.shipment_status==='shipped'?'已发货':r.shipment_status,
})))
const selected=ref<TingonRow|null>(null)
const form=reactive({carrier:'',service:'',tracking_number:'',logistics_note:''})
const busy=ref(false)
const error=ref('')
function open(r:TingonRow){
  if(!props.canOperate||!r.can_edit)return
  selected.value=r
  form.carrier=r.carrier??''
  form.service=r.service??''
  form.tracking_number=r.tracking_number??''
  form.logistics_note=r.logistics_note??''
  error.value=''
}
function close(){if(!busy.value){selected.value=null;error.value=''}}
function save(action:'save'|'ship'|'tracking'){
  const r=selected.value
  if(!r||busy.value||!props.canOperate||!r.can_edit)return
  if(action==='ship'&&(!form.carrier.trim()||!form.tracking_number.trim())){
    error.value='确认实际发货前必须填写物流商和真实 Tracking 单号'
    return
  }
  busy.value=true
  error.value=''
  router.post(`/logistics/tingon/orders/${r.order_id}/${action}`,{...form},{
    preserveScroll:true,
    onSuccess:()=>{selected.value=null;router.reload({only:['orders']})},
    onError:errors=>{error.value=Object.values(errors).flat().join('；')||'保存失败'},
    onFinish:()=>{busy.value=false},
  })
}
const columns=computed<ColDef<GridListRow>[]>(()=>[
  gridColumn({colId:'order',field:'order_number',headerName:'订单号',width:190,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'link',hrefFor:(r:GridListRow)=>r.order_url}}),
  gridColumn({colId:'recipient',field:'recipient_name',headerName:'收件人',width:150}),
  gridColumn({colId:'country',field:'country',headerName:'国家',width:90}),
  gridColumn({colId:'sku',field:'sku',headerName:'SKU / 数量',width:220}),
  gridColumn({colId:'warehouse',field:'warehouse',headerName:'发货仓',width:142}),
  gridColumn({colId:'carrier',field:'carrier_service',headerName:'物流商 / 服务',width:190}),
  gridColumn({colId:'tracking',field:'tracking',headerName:'Tracking',width:170}),
  gridColumn({colId:'status',field:'status',headerName:'发货状态',width:115}),
  gridColumn({colId:'actions',field:'order_id',headerName:'操作',width:165,minWidth:165,
    cellRenderer:GlobalGridCell,cellRendererParams:{mode:'actions',compactActions:false,
      actionsFor:(row:GridListRow)=>{
        const original=props.orders.data.find(x=>x.id===row.id)
        return original?.can_edit&&props.canOperate
          ? [{label:'编辑 / 确认发货',onClick:()=>open(original)}]
          : []
      },
    },
  }),
])
</script>

<template>
  <Head title="Tingon发货 · BYL ERP" />
  <AuthenticatedLayout title="Tingon发货" :breadcrumbs="['物流','Tingon发货']">
    <section class="space-y-3 px-3 pb-4 pt-2">
      <div class="flex flex-wrap items-center gap-2">
        <button v-for="t in tabs" :key="t.key" type="button"
          class="rounded-md border px-3 py-1.5 text-sm"
          :class="props.view===t.key?'border-violet-600 bg-violet-50 font-semibold text-violet-800':'border-slate-200 bg-white text-slate-600'"
          @click="changeView(t.key)">{{t.label}}</button>
      </div>
      <GlobalGrid namespace="grid.logistics.tingon.v1" title="Tingon发货" :columns="columns"
        :rows="rows" :page="props.orders" :search="props.search"
        :required-column-ids="['order']" row-identity-key="id"
        search-placeholder="搜索订单号 / 收件人 / SKU / Tracking..."
        :default-pinned-right="1" @search-change="v=>{term=v;nav.search(v)}"
        @page-change="nav.page($event)" @page-size-change="nav.pageSize($event)"/>
      <p class="text-xs text-slate-500">Tingon 品牌筛选是查询视图；只有实际分仓为合作-Tingon的履约单可执行 Tingon 发货。提交发货后，由同步模块处理网站更新。</p>
    </section>
    <div v-if="selected" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" @click.self="close">
      <form class="w-full max-w-xl space-y-3 rounded-lg bg-white p-5 shadow-xl" @submit.prevent="save('save')">
        <div class="flex items-center justify-between">
          <h2 class="font-semibold text-slate-800">{{selected.order_number}} · Tingon发货</h2>
          <button type="button" class="text-slate-500" @click="close">关闭</button>
        </div>
        <p class="text-xs text-slate-500">请先联系 Tingon 确认真实发货信息。保存草稿不代表已发货。</p>
        <label class="block text-sm">物流商（确认发货必填）
          <select v-model="form.carrier" :disabled="selected.shipment_status!=='pending'" class="mt-1 w-full rounded border p-2"><option value="">请选择物流商</option><option v-for="c in props.carrierOptions" :key="c.value" :value="c.value">{{c.label}}</option></select>
        </label>
        <label class="block text-sm">服务类型
          <input v-model.trim="form.service" :disabled="selected.shipment_status!=='pending'" maxlength="128" class="mt-1 w-full rounded border p-2"/>
        </label>
        <label class="block text-sm">Tracking（确认发货必填）
          <input v-model.trim="form.tracking_number" maxlength="128" class="mt-1 w-full rounded border p-2"/>
        </label>
        <label class="block text-sm">备注
          <textarea v-model="form.logistics_note" :disabled="selected.shipment_status!=='pending'" maxlength="5000" rows="2" class="mt-1 w-full rounded border p-2"/>
        </label>
        <p v-if="error" role="alert" class="text-sm text-red-700">{{error}}</p>
        <div class="flex justify-end gap-2">
          <button type="button" class="rounded border px-3 py-2 text-sm" :disabled="busy" @click="close">取消</button>
          <button v-if="selected.shipment_status==='pending'" type="submit"
            class="rounded border px-3 py-2 text-sm" :disabled="busy">{{busy?'保存中…':'保存草稿'}}</button>
          <button type="button" class="rounded bg-violet-700 px-3 py-2 text-sm text-white" :disabled="busy"
            @click="save(selected.shipment_status==='pending'?'ship':'tracking')">{{selected.shipment_status==='pending'?'确认实际已发货':'更正 Tracking'}}</button>
        </div>
      </form>
    </div>
  </AuthenticatedLayout>
</template>
