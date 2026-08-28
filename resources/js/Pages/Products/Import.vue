<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type ImportResult = {
  inserted: number
  updated: number
  skipped: number
  invalid: number
  errors: string[]
}

defineProps<{ result?: ImportResult }>()

const breadcrumbs = [{ label: 'BYL ERP', href: '/dashboard' }, { label: '产品', href: '/products' }, { label: '产品列表', href: '/products/list' }, '导入 CSV']

const fileInput = ref<HTMLInputElement | null>(null)
const submitting = ref(false)
const fieldError = ref('')

const submit = () => {
  const file = fileInput.value?.files?.[0]
  if (!file) {
    fieldError.value = '请选择 CSV 文件'
    return
  }
  fieldError.value = ''
  submitting.value = true
  const formData = new FormData()
  formData.append('csv_file', file)
  router.post('/products/import', formData as any, {
    forceFormData: true,
    onFinish: () => { submitting.value = false },
  })
}
</script>

<template>
  <Head title="导入产品 CSV · BYL ERP" />
  <AuthenticatedLayout title="导入产品 CSV" :breadcrumbs="breadcrumbs">
    <section class="mvp-page">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">产品主数据</p><h2>导入 WooCommerce CSV</h2></div>
        <div class="mvp-form-actions page-header-actions">
          <a href="/products/list" class="mvp-button mvp-button-secondary">返回列表</a>
          <button type="submit" form="product-import-form" class="mvp-button mvp-button-primary" :disabled="submitting">{{ submitting ? '导入中…' : '开始导入' }}</button>
        </div>
      </div>

      <form id="product-import-form" class="mvp-form" @submit.prevent="submit">
        <div class="mvp-form-field">
          <label class="mvp-label">选择 CSV 文件</label>
          <input ref="fileInput" type="file" accept=".csv,text/csv" class="mvp-input">
          <p v-if="fieldError" class="mvp-error">{{ fieldError }}</p>
        </div>
      </form>

      <div v-if="result" class="mvp-import-result">
        <h3>导入结果</h3>
        <ul class="mvp-import-counts">
          <li>新增：{{ result.inserted }} 条</li>
          <li>更新：{{ result.updated }} 条</li>
          <li>跳过：{{ result.skipped }} 条</li>
          <li>无效：{{ result.invalid }} 条</li>
        </ul>
        <ul v-if="result.errors.length > 0" class="mvp-import-errors">
          <li v-for="(err, i) in result.errors" :key="i" class="mvp-error">{{ err }}</li>
        </ul>
      </div>
    </section>
  </AuthenticatedLayout>
</template>
