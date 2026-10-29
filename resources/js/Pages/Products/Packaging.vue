<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
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
</script>
<template>
  <Head :title="product.name+' · 包装方案'" />
  <AuthenticatedLayout title="产品包装方案" :breadcrumbs="[{label:'产品',href:'/products'},{label:'产品360',href:'/products/'+product.id},'包装方案']">
    <PackagingEditor :product="props.product" :packaging-profile="props.packagingProfile" :presets="props.presets"/>
  </AuthenticatedLayout>
</template>
