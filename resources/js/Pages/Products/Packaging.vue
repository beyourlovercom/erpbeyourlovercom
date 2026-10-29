<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import PackagingEditor from '../../Components/Products/PackagingEditor.vue'

type ImageRef = { id:number|null; name:string|null; url:string }
type PackagingProfile = {
  id:number; preset_key:string|null; method:string; scenario:string|null; version:string|null; units_per_carton:number|null;
  inherits_global_template:boolean; default_image:ImageRef|null; rendered_image:ImageRef|null; examples:ImageRef[]
}
const props=defineProps<{
  product:{id:number;sku:string|null;name:string;barcode_69:string|null}
  packagingProfile:PackagingProfile|null
  presets:{key:string;label:string}[]
}>()
const barcodeForm = useForm({ barcode_69: props.product.barcode_69 ?? '' })
function saveBarcode() {
  barcodeForm.put('/products/' + props.product.id + '/barcode-69', { preserveScroll: true })
}
</script>
<template>
  <Head :title="product.name+' · 包装方案'" />
  <AuthenticatedLayout title="产品包装方案" :breadcrumbs="[{label:'产品',href:'/products'},{label:'产品360',href:'/products/'+product.id},'包装方案']">
    <section class="mb-5 rounded-lg border border-slate-200 p-5">
      <h2 class="mb-2 text-base font-semibold">物流条码 · 包装参数</h2>
      <form class="flex items-end gap-3" @submit.prevent="saveBarcode">
        <label class="flex flex-col gap-1 text-sm">69条形码
          <input v-model="barcodeForm.barcode_69" type="text" inputmode="numeric" maxlength="14" placeholder="8–14位数字（留空表示未知）" class="rounded border px-3 py-2" />
        </label>
        <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white" type="submit" :disabled="barcodeForm.processing">保存条码</button>
      </form>
      <p v-if="barcodeForm.errors.barcode_69" class="mt-2 text-sm text-red-600">{{ barcodeForm.errors.barcode_69 }}</p>
      <p class="mt-2 text-xs text-slate-500">仅供欧洲国内小包物流使用；不会修改 SKU 或现有 GTIN。</p>
    </section>
    <PackagingEditor :product="props.product" :packaging-profile="props.packagingProfile" :presets="props.presets"/>
  </AuthenticatedLayout>
</template>
