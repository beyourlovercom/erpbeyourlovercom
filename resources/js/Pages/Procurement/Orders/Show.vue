<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type StatusOption = { value: string; label: string }
type PurchaseItem = {
  id: number
  sku: string | null
  product_summary: string | null
  source_quantity: number
  purchase_url: string | null
  purchase_size: string | null
  purchase_quantity: number
}
type ProcurementOrder = {
  id: number
  procurement_number: string
  status: string
  status_label: string
  carrier: string | null
  tracking_number: string | null
  source_order_id: number
  source_order_number: string
  created_by_name: string | null
  updated_by_name: string | null
  created_at: string | null
  updated_at: string | null
  status_updated_at: string | null
  items: PurchaseItem[]
}

const props = defineProps<{
  procurementOrder: ProcurementOrder
  statusOptions: StatusOption[]
  canOperate: boolean
}>()
const carrierOptions = ['顺丰速运', '京东物流', '中通快递', '圆通速递', '申通快递', '韵达快递', '极兔速递', '德邦快递', '邮政 EMS', '中国邮政']
const availableCarrierOptions = computed(() => {
  const current = props.procurementOrder.carrier
  return current && !carrierOptions.includes(current) ? [current, ...carrierOptions] : carrierOptions
})

const form = useForm({
  status: props.procurementOrder.status,
  carrier: props.procurementOrder.carrier || '',
  tracking_number: props.procurementOrder.tracking_number || '',
})
const submit = () => form.patch(`/purchase/orders/${props.procurementOrder.id}`, { preserveScroll: true })
const formatDate = (value: string | null) => value ? new Date(value).toLocaleString('zh-CN') : '—'
</script>

<template>
  <Head :title="`${procurementOrder.procurement_number} · BYL ERP`" />
  <AuthenticatedLayout title="采购订单" :breadcrumbs="[{ label: '采购', href: '/purchase' }, procurementOrder.procurement_number]">
    <div class="erp-form-page">
      <div class="erp-form-content">
        <div class="erp-form-header-row">
          <div>
            <p class="ord-kicker">采购订单</p>
            <h1 class="erp-form-title">{{ procurementOrder.procurement_number }}</h1>
            <p class="erp-form-subtitle">{{ procurementOrder.status_label }} · 源订单 {{ procurementOrder.source_order_number }}</p>
          </div>
          <div class="erp-form-header-actions page-header-actions">
            <Link href="/purchase/orders" class="erp-btn erp-btn-secondary">返回采购订单</Link>
            <Link :href="`/customers/orders/${procurementOrder.source_order_id}`" class="erp-btn erp-btn-secondary">查看源订单</Link>
          </div>
        </div>

        <form v-if="canOperate" class="erp-form-card" @submit.prevent="submit">
          <h2 class="erp-form-card-title">采购进度与物流</h2>
          <div class="workspace-fields">
            <label>
              <span>采购状态</span>
              <select v-model="form.status" class="erp-input">
                <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
              </select>
              <small v-if="form.errors.status" class="field-error">{{ form.errors.status }}</small>
            </label>
            <label>
              <span>快递公司</span>
              <select v-model="form.carrier" class="erp-input">
                <option value="" disabled>请选择快递公司</option>
                <option v-for="carrier in availableCarrierOptions" :key="carrier" :value="carrier">{{ carrier }}</option>
              </select>
            </label>
            <label>
              <span>快递单号</span>
              <input v-model="form.tracking_number" class="erp-input" placeholder="填写采购物流单号" />
            </label>
          </div>
          <div class="form-actions">
            <button class="erp-btn erp-btn-primary" type="submit" :disabled="form.processing">保存采购信息</button>
          </div>
        </form>

        <section v-else class="erp-form-card">
          <h2 class="erp-form-card-title">采购进度与物流</h2>
          <dl class="read-only-grid">
            <dt>采购状态</dt><dd>{{ procurementOrder.status_label }}</dd>
            <dt>快递公司</dt><dd>{{ procurementOrder.carrier || '—' }}</dd>
            <dt>快递单号</dt><dd>{{ procurementOrder.tracking_number || '—' }}</dd>
          </dl>
        </section>

        <section class="erp-form-card">
          <h2 class="erp-form-card-title">采购商品</h2>
          <article v-for="item in procurementOrder.items" :key="item.id" class="purchase-item">
            <div><strong>{{ item.product_summary || item.sku || '商品' }}</strong><span>{{ item.sku || '—' }}</span></div>
            <dl>
              <dt>订单数量</dt><dd>{{ item.source_quantity }}</dd>
              <dt>采购数量</dt><dd>{{ item.purchase_quantity }}</dd>
              <dt>尺寸 / 规格</dt><dd>{{ item.purchase_size || '—' }}</dd>
              <dt>采购 URL</dt><dd><a v-if="item.purchase_url" :href="item.purchase_url" target="_blank" rel="noopener noreferrer">打开采购链接</a><span v-else>—</span></dd>
            </dl>
          </article>
        </section>

        <section class="erp-form-card">
          <h2 class="erp-form-card-title">记录</h2>
          <dl class="read-only-grid">
            <dt>创建人</dt><dd>{{ procurementOrder.created_by_name || '—' }}</dd>
            <dt>创建时间</dt><dd>{{ formatDate(procurementOrder.created_at) }}</dd>
            <dt>最近更新人</dt><dd>{{ procurementOrder.updated_by_name || '—' }}</dd>
            <dt>状态更新时间</dt><dd>{{ formatDate(procurementOrder.status_updated_at) }}</dd>
          </dl>
        </section>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<style scoped>
.workspace-fields{display:grid;grid-template-columns:180px minmax(200px,1fr) minmax(240px,1fr);gap:14px}.workspace-fields label{display:grid;gap:6px}.workspace-fields label>span{font-size:12px;color:#64748b}.form-actions{display:flex;justify-content:flex-end;margin-top:16px}.read-only-grid,.purchase-item dl{display:grid;grid-template-columns:110px minmax(0,1fr);gap:8px 14px;margin:0}.read-only-grid dt,.purchase-item dt{color:#7a8c94}.read-only-grid dd,.purchase-item dd{margin:0;min-width:0;overflow-wrap:anywhere}.purchase-item{padding:14px 0;border-bottom:1px solid #e5e7eb}.purchase-item:last-child{border-bottom:0}.purchase-item>div{display:flex;justify-content:space-between;gap:12px;margin-bottom:12px}.purchase-item>div span{color:#7a8c94}.field-error{color:#b91c1c}.page-header-actions{display:flex;gap:8px;flex-wrap:wrap}
@media(max-width:767px){.workspace-fields{grid-template-columns:1fr}.form-actions .erp-btn{width:100%}.read-only-grid,.purchase-item dl{grid-template-columns:90px 1fr}.page-header-actions{width:100%}.page-header-actions .erp-btn{flex:1}.purchase-item>div{display:grid}}
</style>
