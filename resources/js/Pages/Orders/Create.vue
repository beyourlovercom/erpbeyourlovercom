<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
const props = defineProps<{ defaultOrderKind: string; orderKinds: { value: string; label: string }[]; sourceTypes: { value: string; label: string }[]; owners: { id: number; name: string }[] }>()
const form = useForm({ order_kind: props.defaultOrderKind, source_type: 'website', external_order_number: '', order_date: new Date().toISOString().slice(0, 10), order_note: '', owner_id: '', recipient_name: '', phone: '', country: '', province_state: '', city: '', address_line_1: '', address_line_2: '', postal_code: '', items: [{ product_summary: '', sku: '', quantity: 1, unit_price: '0.00' }] })
const addItem = () => form.items.push({ product_summary: '', sku: '', quantity: 1, unit_price: '0.00' })
const removeItem = (index: number) => { if (form.items.length > 1) form.items.splice(index, 1) }
const submit = () => form.post(props.defaultOrderKind === 'kol_fulfillment' ? '/kol/orders' : '/customers/orders')
</script>
<template>
  <Head title="新建订单 · BYL ERP" />
  <AuthenticatedLayout title="新建订单" :breadcrumbs="['BYL ERP', '客户', '订单', '新建']">
    <section class="mvp-page"><div class="mvp-page-heading"><div><p class="mvp-kicker">订单核心</p><h2>新建订单</h2></div></div>
      <form class="mvp-form" @submit.prevent="submit"><div class="mvp-form-grid">
        <label>订单类型<select v-model="form.order_kind"><option v-for="kind in props.orderKinds" :key="kind.value" :value="kind.value">{{ kind.label }}</option></select></label>
        <label>来源<select v-model="form.source_type"><option v-for="source in props.sourceTypes" :key="source.value" :value="source.value">{{ source.label }}</option></select></label>
        <label>订单日期<input v-model="form.order_date" type="date"></label>
        <label>业务订单号<input v-model="form.external_order_number" required placeholder="操作员填写的业务订单号"><small v-if="form.errors.external_order_number" class="mvp-error">{{ form.errors.external_order_number }}</small></label>
        <label>负责人<select v-model="form.owner_id"><option value="">当前用户</option><option v-for="owner in props.owners" :key="owner.id" :value="owner.id">{{ owner.name }}</option></select></label>
      </div>
        <h3>收件人快照</h3><div class="mvp-form-grid"><label>姓名<input v-model="form.recipient_name" required></label><label>电话<input v-model="form.phone" required></label><label>国家<input v-model="form.country" required></label><label>省/州<input v-model="form.province_state" required></label><label>城市<input v-model="form.city" required></label><label>邮编<input v-model="form.postal_code"></label><label class="mvp-form-wide">地址<input v-model="form.address_line_1" required></label><label class="mvp-form-wide">地址补充<input v-model="form.address_line_2"></label></div>
        <div class="mvp-section-heading"><h3>订单商品</h3><button class="mvp-button" type="button" @click="addItem">添加商品</button></div><div v-for="(item, index) in form.items" :key="index" class="mvp-item-row"><input v-model="item.product_summary" placeholder="商品摘要" required><input v-model="item.sku" placeholder="SKU"><input v-model="item.quantity" type="number" min="1" placeholder="数量" required><input v-model="item.unit_price" type="number" min="0" step="0.01" placeholder="单价" required><button class="mvp-button" type="button" @click="removeItem(index)">删除</button></div>
        <label class="mvp-form-wide">备注<textarea v-model="form.order_note"></textarea></label><div class="mvp-form-actions"><button class="mvp-button mvp-button-primary" :disabled="form.processing">保存订单</button><a class="mvp-button" :href="props.defaultOrderKind === 'kol_fulfillment' ? '/kol/orders' : '/customers/orders'">取消</a></div>
      </form>
    </section>
  </AuthenticatedLayout>
</template>
