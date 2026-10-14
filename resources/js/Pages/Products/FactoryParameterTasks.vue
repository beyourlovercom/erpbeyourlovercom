<script setup lang="ts">
import { computed,ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Task = {product_id:number;sku:string|null;name:string|null;status:'已填'|'必填'|'待填';latest_status:string|null;required:boolean}
const props=defineProps<{tasks:Task[]}>()
const filter=ref('全部')
const search=ref('')
const categories=['全部','已填','待填','必填','其他']
const count=(f:string)=>props.tasks.filter(x=>f==='全部'||x.status===f).length
const rows=computed(()=>props.tasks.filter(t=>(filter.value==='全部'||t.status===filter.value)
 && (t.sku+' '+t.name).toLowerCase().includes(search.value.trim().toLowerCase())))
</script>
<template>
 <Head title="我的产品参数填报任务"/>
 <AuthenticatedLayout title="我的产品参数任务" :breadcrumbs="['BYL ERP','Product OS','我的填报任务']">
 <main class="factory-inbox">
  <header><h1>我的产品参数任务</h1><p>仅展示已指派给我的产品 · 直接填写参数与配件，无需另行发送表格</p></header>
  <div class="toolbar">
   <nav aria-label="填报状态筛选"><button v-for="x in categories" :key="x" type="button"
      :class="{on:filter===x}" @click="filter=x">{{x}} <span>{{count(x)}}</span></button></nav>
   <input v-model="search" type="search" placeholder="搜索 SKU / 产品名" aria-label="搜索指派产品"/>
  </div>
  <div class="scroller" role="region" aria-label="我的填报任务清单" tabindex="0">
   <table><thead><tr><th>产品 / SKU</th><th>分类</th><th>最新状态</th><th>操作</th></tr></thead>
    <tbody><tr v-for="t in rows" :key="t.product_id">
     <td><strong>{{t.name}}</strong><span>{{t.sku}}</span></td>
     <td><span class="pill">{{t.status}}</span></td>
     <td>{{t.latest_status??'尚未填报'}}</td>
     <td><a :href="'/products/factory-parameters/'+t.product_id">填写资料 →</a></td>
    </tr><tr v-if="!rows.length"><td colspan="4" class="empty">当前筛选没有指派产品</td></tr></tbody>
   </table>
  </div>
 </main>
 </AuthenticatedLayout>
</template>
<style scoped>
.factory-inbox{max-width:1170px;margin:0 auto;padding:18px 14px 35px;color:#243e30}
header h1{font-size:22px;color:#18583b;margin:0 0 6px}
header p{color:#768c7d;font-size:12px}.toolbar{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:18px 0 12px}
.toolbar nav{display:flex;gap:6px;flex-wrap:wrap}.toolbar button{border:1px solid #d8e7dd;border-radius:7px;background:#fff;color:#54806a;padding:9px 12px;cursor:pointer;font-weight:600}
.toolbar button.on{background:#176c50;border-color:#176c50;color:#fff}.toolbar button span{font-size:10px;opacity:.7;margin-left:4px}
.toolbar input{border:1px solid #d8e7dd;border-radius:7px;padding:9px;min-width:190px}
.scroller{overflow:auto;background:#fff;border:1px solid #dbe6dc;border-radius:9px}
table{width:100%;min-width:490px;border-collapse:collapse;font-size:12px}
th{background:#176c50;color:white;padding:12px;text-align:left}
td{padding:13px;border-bottom:1px solid #eef1ee}td strong{display:block;color:#264836}
td span{display:block;font-size:11px;color:#759283;margin-top:5px}
.pill{display:inline-flex;border-radius:5px;background:#edf5ee;color:#167252;padding:5px 8px}
td a{color:#187351;text-decoration:none;font-weight:700}.empty{text-align:center;color:#849786;padding:22px}
@media(max-width:600px){.factory-inbox{padding:12px 9px}.toolbar input{width:100%;flex:1}header h1{font-size:19px}}
</style>
