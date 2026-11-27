<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Row = {
  code: string; sequence: string; name_zh: string; name_en: string
  mutable: boolean; feature: string; pages: string
}
type Editable = Pick<Row, 'code' | 'sequence' | 'name_zh' | 'name_en'>
const props = defineProps<{ statuses: Row[]; defaults: Editable[]; version: string }>()
const form = useForm<{ statuses: Editable[]; expected_version: string }>({
  statuses: props.statuses.map(({ code, sequence, name_zh, name_en }) => ({ code, sequence, name_zh, name_en })),
  expected_version: props.version,
})
const expanded = ref(false)
const orderKeys = ref(props.statuses.map((row) => row.code))
const compare = (left: string, right: string): number => {
  const a = left.split('.').map(Number), b = right.split('.').map(Number)
  for (let index = 0; index < Math.max(a.length, b.length); index++) {
    const difference = (a[index] ?? 0) - (b[index] ?? 0)
    if (difference !== 0) return difference
  }
  return left.localeCompare(right)
}
const reorder = () => {
  orderKeys.value = [...form.statuses].sort((a, b) => compare(a.sequence, b.sequence) || a.code.localeCompare(b.code)).map(row => row.code)
}
const rows = computed(() => orderKeys.value.map(code => {
  const index = form.statuses.findIndex(row => row.code === code)
  return { ...props.statuses.find(row => row.code === code), ...form.statuses[index], index }
}))
const duplicates = computed(() => new Set(form.statuses.map(row => row.sequence)).size !== form.statuses.length)
const invalidSequence = computed(() => form.statuses.some(row => !/^(0|[1-9][0-9]{0,2})(?:\.(0|[1-9][0-9]{0,2})){1,2}$/.test(row.sequence)))
const firstError = computed(() => Object.values(form.errors)[0] ?? '')
watch(() => props.version, next => { form.expected_version = next })
const restore = () => {
  if (!window.confirm('恢复全部默认序号与名称？仅修改当前草稿，仍需点击「保存状态设置」才能生效。')) return
  form.statuses = props.defaults.map(row => ({ ...row }))
  reorder()
}
const submit = () => {
  if (duplicates.value || invalidSequence.value) return
  form.put('/settings/statuses', { preserveScroll: true, onSuccess: reorder })
}
</script>

<template>
  <Head title="状态设置 · BYL ERP" />
  <AuthenticatedLayout title="状态设置" :breadcrumbs="['BYL ERP','设置','状态设置']">
    <section class="mvp-page status-settings-page">
      <div class="mvp-page-heading status-settings-heading">
        <div><p class="mvp-kicker">ORDER WORKFLOW</p><h2>状态设置</h2></div>
        <div class="status-settings-actions">
          <button type="button" class="mvp-button" :disabled="form.processing" @click="restore">恢复默认</button>
          <button type="button" class="mvp-button mvp-button-primary" :disabled="form.processing || duplicates || invalidSequence" @click="submit">
            {{ form.processing ? '保存中…' : '保存状态设置' }}
          </button>
        </div>
      </div>

      <article class="mvp-card status-settings-card">
        <div class="status-settings-card-header">
          <div><h3>订单工作状态</h3><span v-if="form.recentlySuccessful" class="status-settings-saved" role="status">已保存</span></div>
          <button type="button" class="status-settings-expand" :aria-expanded="expanded" :aria-label="expanded ? '收起功能与适用页面' : '展开功能与适用页面'" :title="expanded ? '收起功能与适用页面' : '临时展开全部功能与适用页面'" @click="expanded = !expanded">…</button>
        </div>
        <p v-if="firstError" class="status-settings-error" role="alert">{{ firstError }}</p>
        <p v-if="duplicates" class="status-settings-error" role="alert">数字序号不能重复，请修改后保存。</p>
        <p v-if="invalidSequence" class="status-settings-error" role="alert">数字序号必须为数字点数字，例如 0.1、1.2、1.10 或 1.1.1。</p>
        <div class="status-settings-columns"><span>数字序号</span><span>状态名称</span></div>
        <div v-for="row in rows" :key="row.code" class="status-settings-row">
          <div class="status-settings-fields">
            <label :for="`sequence-${row.code}`" class="sr-only">数字序号 {{ row.code }}</label>
            <input :id="`sequence-${row.code}`" v-model.trim="form.statuses[row.index].sequence" class="erp-input status-settings-sequence" inputmode="decimal" maxlength="16" :disabled="form.processing" @blur="reorder" />
            <label :for="`name-${row.code}`" class="sr-only">状态名称 {{ row.code }}</label>
            <input :id="`name-${row.code}`" v-model="form.statuses[row.index].name_zh" class="erp-input" maxlength="80" :disabled="form.processing" />
          </div>
          <div v-if="expanded" class="status-settings-details">
            <div><strong>功能</strong><span>{{ row.feature }}</span></div>
            <div><strong>适用页面</strong><span>{{ row.pages }}</span></div>
            <div><strong>英文名称</strong><input v-model="form.statuses[row.index].name_en" class="erp-input" :aria-label="`英文名称 ${row.code}`" maxlength="80" :disabled="form.processing" /></div>
            <div><strong>内部代码</strong><code>{{ row.code }}</code></div>
          </div>
        </div>
      </article>
      <p class="status-settings-help">序号按分段数字从小到大排序（1.2 排在 1.10 前）；调整完成后离开输入框重新排序。右上角「…」仅临时展开功能与适用页面，刷新后自动收起。名称和序号只影响显示，不会改变真实发货、签收、支付记录或任何订单的内部状态代码。</p>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.status-settings-page{display:grid;gap:12px;max-width:1080px;margin-inline:auto}
.status-settings-heading{align-items:center}.status-settings-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.status-settings-card{padding:18px 20px}.status-settings-card-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.status-settings-card-header h3{margin:0;font-size:18px}.status-settings-saved{display:inline-block;color:#17633b;font-size:12px;margin-top:5px}
.status-settings-expand{min-width:38px;min-height:34px;border:1px solid #d9e3dd;border-radius:9px;background:#fff;color:#344b53;font-size:22px;line-height:1;cursor:pointer}
.status-settings-expand:hover,.status-settings-expand:focus-visible{border-color:#2d8c84;outline:2px solid #2d8c8422}
.status-settings-columns,.status-settings-fields{display:grid;grid-template-columns:112px minmax(0,1fr);gap:10px;align-items:center}
.status-settings-columns{color:#667085;font-weight:700;font-size:13px;padding:0 0 5px}
.status-settings-row{margin-bottom:7px}.status-settings-fields input{width:100%;min-width:0;height:39px;box-sizing:border-box}
.status-settings-sequence{font-variant-numeric:tabular-nums}
.status-settings-details{padding:10px 12px;margin:6px 0 10px 122px;background:#f5f8f8;border-radius:9px;display:grid;gap:8px;font-size:12px;color:#344b53}
.status-settings-details>div{display:grid;grid-template-columns:74px minmax(0,1fr);align-items:center;gap:8px}
.status-settings-details strong{color:#53646d}.status-settings-details .erp-input{max-width:360px;height:34px}
.status-settings-details code{font-size:12px;color:#64748b}
.status-settings-help{color:#667085;font-size:12px;line-height:1.65;margin:0 2px 12px}
.status-settings-error{color:#a33a3a;background:#fff2f2;border-radius:8px;padding:8px 10px;margin:5px 0;font-size:13px}
@media(max-width:600px){.status-settings-card{padding:12px}.status-settings-columns,.status-settings-fields{grid-template-columns:80px minmax(0,1fr);gap:7px}.status-settings-details{margin-left:0}.status-settings-heading{align-items:flex-start}}
</style>
