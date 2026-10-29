<script setup lang="ts">
import { ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Row = { sku:string; barcode_69:string; product_id:number|null; current_barcode_69:string|null; status:string }
const file = ref<File|null>(null)
const rows = ref<Row[]>([])
const fingerprint = ref('')
const busy = ref(false)
const error = ref('')
const result = ref('')
const labels:Record<string,string> = {
  ready:'可导入', unchanged:'已存在', conflict:'已有不同条码（跳过）',
  missing_erp:'ERP未匹配（跳过）', duplicate_erp:'ERP重复SKU（跳过）',
  duplicate_source:'源表重复SKU（跳过）'
}
async function preview() {
  if (!file.value) return
  busy.value=true; error.value=''; result.value=''; rows.value=[]; fingerprint.value=''
  try {
    const form = new FormData()
    form.append('file',file.value)
    const {data} = await axios.post('/products/barcode-69/import/preview',form)
    rows.value=data.rows; fingerprint.value=data.fingerprint
  } catch(e:any) { error.value=e?.response?.data?.message ?? '预检失败' }
  finally {busy.value=false}
}
async function confirm() {
  if (!fingerprint.value || !window.confirm('确认仅导入可导入且当前为空的条码？已有值不会覆盖。')) return
  busy.value=true; error.value=''
  try {
    const {data} = await axios.post('/products/barcode-69/import/confirm',{fingerprint:fingerprint.value})
    result.value='导入成功：'+data.updated+'；跳过：'+data.skipped
    fingerprint.value=''
  } catch(e:any) { error.value=e?.response?.data?.message ?? '导入失败，请重新预检' }
  finally {busy.value=false}
}
</script>
<template>
  <Head title="69条形码导入" />
  <AuthenticatedLayout title="69条形码导入" :breadcrumbs="[{label:'产品',href:'/products'},{label:'产品列表',href:'/products/list'},'69条形码导入']">
    <section class="rounded-lg border p-5">
      <h2 class="text-lg font-semibold">SKU → 69条形码</h2>
      <p class="mt-2 text-sm">上传 CSV，两列必须为 sku,barcode_69。先核对预检结果，再确认导入。不会覆盖已有条码；异常行全部跳过。</p>
      <div class="mt-4 flex gap-3">
        <input type="file" accept=".csv,text/csv" @change="file=($event.target as HTMLInputElement).files?.[0] ?? null" />
        <button type="button" :disabled="busy || !file" class="rounded bg-slate-800 px-4 py-2 text-white" @click="preview">预检 Preview</button>
      </div>
      <p v-if="error" class="mt-3 text-red-600">{{ error }}</p>
      <p v-if="result" class="mt-3 text-green-700">{{ result }}</p>
      <template v-if="rows.length">
        <p class="mt-5 text-sm">总数 {{ rows.length }}；可导入 {{ rows.filter(r=>r.status==='ready').length }}</p>
        <div class="mt-2 max-h-[500px] overflow-auto border">
          <table class="w-full text-sm">
            <thead class="sticky top-0 bg-slate-100"><tr><th class="p-2 text-left">SKU</th><th class="p-2 text-left">新69条形码</th><th class="p-2 text-left">现有69条形码</th><th class="p-2 text-left">ERP ID</th><th class="p-2 text-left">结果</th></tr></thead>
            <tbody><tr v-for="r in rows" :key="r.sku" class="border-t"><td class="p-2">{{ r.sku }}</td><td class="p-2">{{ r.barcode_69 }}</td><td class="p-2">{{ r.current_barcode_69 ?? '—' }}</td><td class="p-2">{{ r.product_id ?? '—' }}</td><td class="p-2">{{ labels[r.status] ?? r.status }}</td></tr></tbody>
          </table>
        </div>
        <button v-if="fingerprint && rows.some(r=>r.status==='ready')" type="button" :disabled="busy" class="mt-4 rounded bg-slate-800 px-4 py-2 text-white" @click="confirm">确认导入可匹配产品</button>
      </template>
    </section>
  </AuthenticatedLayout>
</template>
