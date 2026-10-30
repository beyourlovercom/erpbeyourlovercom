<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps<{
  productId: number
  approved: Record<string, string | number | null>
  pendingCount?: number
}>()

const fields = [
  { key: 'material', label: '材质', unit: '', numeric: false },
  { key: 'weight_composition', label: '重量组成说明', unit: '', numeric: false },
  { key: 'net_weight_kg', label: '工厂净重', unit: 'kg', numeric: true },
  { key: 'color_box_gross_kg', label: '彩盒毛重', unit: 'kg', numeric: true },
  { key: 'courier_gross_kg', label: '快递毛重', unit: 'kg', numeric: true },
  { key: 'package_length_cm', label: '单件包装长度', unit: 'cm', numeric: true },
  { key: 'package_width_cm', label: '单件包装宽度', unit: 'cm', numeric: true },
  { key: 'package_height_cm', label: '单件包装高度', unit: 'cm', numeric: true },
  { key: 'carton_length_cm', label: '外箱长度', unit: 'cm', numeric: true },
  { key: 'carton_width_cm', label: '外箱宽度', unit: 'cm', numeric: true },
  { key: 'carton_height_cm', label: '外箱高度', unit: 'cm', numeric: true },
  { key: 'units_per_carton', label: '每箱数量', unit: '件', numeric: true },
  { key: 'carton_gross_weight_kg', label: '整箱毛重', unit: 'kg', numeric: true },
] as const
const values = ref<Record<string,string>>({})
const errors = ref<Record<string,string>>({})
const submitting = ref(false)
const submitted = ref(false)
const changes = computed(() => Object.fromEntries(
  fields.map(field => [field.key, (values.value[field.key] ?? '').trim()])
    .filter(([key, value]) => value !== '' && value !== String(props.approved[key] ?? ''))
))
const approvedValue = (key: string) => {
  const value = props.approved[key]
  return value === null || value === undefined || value === '' ? '待审核 / 待填写' : String(value)
}
const submit = () => {
  if (!Object.keys(changes.value).length || submitting.value) return
  errors.value = {}
  submitted.value = false
  submitting.value = true
  router.post('/products/' + props.productId + '/measurement-submissions', { measurements: changes.value }, {
    preserveScroll: true,
    onError: next => { errors.value = next },
    onSuccess: () => { values.value = {}; submitted.value = true },
    onFinish: () => { submitting.value = false },
  })
}
</script>

<template>
  <div class="product-review-parameters">
    <p class="review-help">左侧为已审核数据；右侧填写需要更改的新值。只提交有变化的字段，审核通过前不会修改现有产品参数。</p>
    <p v-if="pendingCount" class="review-note">已有 {{ pendingCount }} 条参数待审核，请避免重复提交。</p>
    <p v-if="submitted" class="review-success" role="status">新参数已提交审核，原值保持不变。</p>
    <div class="review-grid">
      <label v-for="field in fields" :key="field.key" class="review-field">
        <span>{{ field.label }} <small v-if="field.unit">({{ field.unit }})</small></span>
        <span class="review-approved">已确认：{{ approvedValue(field.key) }}</span>
        <input v-model="values[field.key]" :type="field.numeric ? 'number' : 'text'" :step="field.key === 'units_per_carton' ? '1' : 'any'" :min="field.numeric ? '0.0001' : undefined" :placeholder="'填写新' + field.label" :aria-invalid="!!errors['measurements.' + field.key]" />
        <small v-if="errors['measurements.' + field.key]" class="review-error">{{ errors['measurements.' + field.key] }}</small>
      </label>
    </div>
    <p v-if="errors.measurements" class="review-error">{{ errors.measurements }}</p>
    <div class="review-actions">
      <a :href="'/products/factory-parameters/' + productId" class="review-link">查看工厂参数填报与审核记录</a>
      <button type="button" class="erp-btn erp-btn-secondary" :disabled="submitting || !Object.keys(changes).length" @click="submit">{{ submitting ? '提交中…' : '提交参数变更审核' }}</button>
    </div>
  </div>
</template>

<style scoped>
.product-review-parameters { padding:8px 0 4px; }
.review-help,.review-note { color:#607780; font-size:12px; margin:0 0 12px; line-height:1.6; }
.review-note { color:#9d741a; }
.review-success { color:#177366; font-size:12px; margin-bottom:12px; }
.review-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
.review-field { display:flex; flex-direction:column; gap:6px; min-width:0; }
.review-field>span:first-child { color:#334b53; font-size:13px; font-weight:600; }
.review-field small { font-weight:400; }
.review-approved { font-size:12px; color:#607780; }
.review-field input { min-height:40px; border:1px solid #dce6e8; border-radius:9px; padding:8px 10px; font-size:13px; width:100%; }
.review-field input:focus { outline:2px solid #2D8C8433; border-color:#2D8C84; }
.review-error { color:#b42318; font-size:12px; }
.review-actions { display:flex; align-items:center; justify-content:space-between; gap:14px; margin-top:20px; }
.review-link { color:#2D8C84; font-size:12px; }
@media(max-width:900px) { .review-grid { grid-template-columns:1fr; } .review-actions { align-items:flex-start; flex-direction:column; } }
</style>
