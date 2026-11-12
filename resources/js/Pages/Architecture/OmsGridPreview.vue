<script setup lang="ts">
import { computed, defineAsyncComponent, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import type { ColDef, ColGroupDef, ICellRendererParams } from 'ag-grid-community'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
const OmsGrid = defineAsyncComponent(() => import('../../Components/DataGrid/OmsGrid.vue'))

type DemoOrder = Record<string, unknown> & {
  id: number; orderNumber: string; customer: string; country: string;
  sku: string; quantity: number; warehouse: string; carrier: string;
  tracking: string; amount: number; cost: number; margin: number;
  orderAt: string; status: string; source: string
}
const countries = ['🇺🇸 US', '🇩🇪 DE', '🇨🇦 CA', '🇬🇧 GB', '🇫🇷 FR']
const customers = ['John Miller', 'Emma Davis', 'Liam Johnson', 'Olivia Wilson', 'Noah Brown', 'Sophia Garcia', 'Ava Taylor', 'Mia Clark']
const skus = ['MF414A', 'A1', 'MF414A, A1', 'LUB', 'MF414A, LUB']
const warehouses = ['US-NY', 'US-MorePlus', 'US-SM', 'DE-Tianyun', 'CA']
const statuses = ['待分仓', '待审核', '待发货', '已发货', '异常']
function makeOrder(index: number): DemoOrder {
  const quantity = index % 3 + 1
  const amount = 79 * quantity
  const cost = Number((amount * (0.52 + (index % 8) * 0.01)).toFixed(2))
  const day = String(index % 28 + 1).padStart(2, '0')
  return {
    id: index + 1,
    orderNumber: '007-0910-' + String(40414 - index).padStart(5, '0'),
    customer: customers[index % customers.length],
    country: countries[index % countries.length],
    sku: skus[index % skus.length],
    quantity,
    warehouse: warehouses[index % warehouses.length],
    carrier: index % 5 === 3 ? 'DHL' : 'USPS',
    tracking: '9400111206' + String(760000 + index),
    amount, cost, margin: Number(((amount - cost) / amount * 100).toFixed(1)),
    orderAt: '2026-10-' + day + ' 16:32',
    status: statuses[index % statuses.length],
    source: ['WooCommerce', 'KOL', 'Manual'][index % 3],
  }
}
const orders = ref<DemoOrder[]>(Array.from({ length: 1330 }, (_, index) => makeOrder(index)))
const selected = ref<DemoOrder | null>(null)
const selectedCount = ref(0)
const upload = ref<HTMLInputElement | null>(null)
const importMessage = ref('')
const buttonRenderer = (params: ICellRendererParams, action: string) => {
  const button = document.createElement('button')
  button.type = 'button'
  button.textContent = action === 'detail' ? String(params.value ?? '') : '查看'
  button.className = 'oms-preview-link'
  button.addEventListener('click', () => { selected.value = (params.data ?? null) as DemoOrder | null })
  return button
}
const columns: (ColDef<Record<string, unknown>> | ColGroupDef<Record<string, unknown>>)[] = [
  { headerName: '订单信息', children: [
    { field: 'orderNumber', colId: 'orderNumber', headerName: '订单号', width: 168, cellRenderer: (p: ICellRendererParams) => buttonRenderer(p, 'detail') },
    { field: 'customer', colId: 'customer', headerName: '客户 / 收件人', width: 136 },
    { field: 'country', colId: 'country', headerName: '国家', width: 100 },
    { field: 'source', colId: 'source', headerName: '来源', width: 122 },
  ] },
  { headerName: '商品信息', children: [
    { field: 'sku', colId: 'sku', headerName: 'SKU', width: 164 },
    { field: 'quantity', colId: 'quantity', headerName: '数量', width: 80, filter: 'agNumberColumnFilter' },
  ] },
  { headerName: '仓库与物流', children: [
    { field: 'warehouse', colId: 'warehouse', headerName: '仓库', width: 144 },
    { field: 'carrier', colId: 'carrier', headerName: '物流商', width: 105 },
    { field: 'tracking', colId: 'tracking', headerName: '物流单号', width: 162 },
  ] },
  { headerName: '财务', children: [
    { field: 'amount', colId: 'amount', headerName: '订单金额', width: 116, filter: 'agNumberColumnFilter', valueFormatter: p => '$' + Number(p.value ?? 0).toFixed(2) },
    { field: 'cost', colId: 'cost', headerName: '成本估算', width: 116, filter: 'agNumberColumnFilter', valueFormatter: p => '$' + Number(p.value ?? 0).toFixed(2) },
    { field: 'margin', colId: 'margin', headerName: '利润率', width: 102, filter: 'agNumberColumnFilter', valueFormatter: p => Number(p.value ?? 0).toFixed(1) + '%' },
  ] },
  { headerName: '时间与状态', children: [
    { field: 'orderAt', colId: 'orderAt', headerName: '下单时间', width: 170 },
    { field: 'status', colId: 'status', headerName: '状态', width: 104 },
  ] },
  { headerName: '操作', children: [
    { colId: 'actions', headerName: '操作', width: 92, sortable: false, filter: false, cellRenderer: (p: ICellRendererParams) => buttonRenderer(p, 'action') },
  ] },
]
const currentOrderLabel = computed(() => selected.value?.orderNumber ?? '')
const addDemo = () => {
  const next = makeOrder(orders.value.length)
  next.orderNumber = 'DEMO-' + String(orders.value.length + 1)
  next.customer = '新增演示订单'
  orders.value = [next, ...orders.value]
  selected.value = next
}
const importCsv = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  const lines = (await file.text()).replace(/\r/g, '').split('\n').filter(Boolean)
  // Only accept a small local sample, not real business imports.
  if (lines.length > 101 || lines.length < 2 || lines[0].trim().toLowerCase() !== 'sku,quantity,customer') {
    importMessage.value = '请使用样本格式 sku,quantity,customer（最多 100 行）；不写入 ERP。'
    input.value = ''
    return
  }
  const added = lines.slice(1).map((line, i) => {
    const [sku, quantityText, customer] = line.split(',')
    const item = makeOrder(orders.value.length + i)
    item.orderNumber = 'DEMO-CSV-' + String(i + 1)
    item.sku = (sku || 'A1').trim().slice(0, 64)
    item.quantity = Math.max(1, Math.min(100, Number(quantityText) || 1))
    item.customer = (customer || 'CSV 样本客户').trim().slice(0, 64)
    return item
  })
  orders.value = [...added, ...orders.value]
  importMessage.value = '已向当前预览添加 ' + added.length + ' 行样本（不会保存到 ERP）。'
  input.value = ''
}
</script>

<template>
  <Head title="OMS Grid UAT · BYL ERP" />
  <AuthenticatedLayout title="OMS Grid UAT" :breadcrumbs="['BYL ERP', '架构样板', 'OMS Grid UAT']">
    <div class="oms-preview">
      <div class="preview-notice"><strong>R2 / 交互验收样板</strong> · 1,330 条模拟数据 · 不修改真实 ERP 订单 · 仅在非 Production 环境开放</div>
      <OmsGrid
        namespace="grid.oms-uat-preview.v1"
        title="订单管理 · OMS Performance"
        preference-scope="local"
        :columns="columns"
        :rows="orders"
        :default-pinned-left="2"
        :default-pinned-right="1"
        :required-column-ids="['orderNumber']"
        @selection-change="selectedCount = $event.length"
      >
        <template #actions>
          <button class="preview-primary" type="button" @click="addDemo">＋ 新建样本</button>
          <button type="button" @click="upload?.click()">导入样本 CSV</button>
          <input ref="upload" type="file" accept=".csv,text/csv" hidden @change="importCsv" />
        </template>
      </OmsGrid>
      <div class="preview-note">当前选中 {{ selectedCount }} 行。{{ importMessage || '尝试：拖动列宽、过滤 SKU、固定左右列、切换密度、最大化，以及重新载入页面检查偏好是否保留。' }}</div>
      <div v-if="selected" class="preview-detail" role="dialog" aria-label="模拟订单详情">
        <div><strong>演示订单详情 · {{ currentOrderLabel }}</strong><button type="button" @click="selected = null">关闭 ×</button></div>
        <p>客户：{{ selected.customer }}　SKU：{{ selected.sku }}　数量：{{ selected.quantity }}</p>
        <p>仓库：{{ selected.warehouse }}　状态：{{ selected.status }}</p>
        <small>仅供 UI 交互验收，不会执行实际分仓、归档、发货或修改。</small>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
<style scoped>
.oms-preview{display:flex;flex-direction:column;gap:9px;min-height:0}.preview-notice{padding:8px 12px;background:#eff6ff;color:#1d4ed8;border:1px solid #dbeafe;border-radius:6px;font-size:12px}
:deep(.oms-actions button){border:1px solid #cbd5e1;background:#fff;border-radius:5px;padding:6px 11px;font-size:12px;cursor:pointer}
:deep(.oms-actions .preview-primary){color:#fff;background:#155dfb;border-color:#155dfb}
:deep(.oms-preview-link){background:transparent;border:0;color:#155dfb;cursor:pointer;text-align:left;font-weight:600;padding:0 4px}
.preview-note{font-size:11px;color:#64748b}.preview-detail{position:fixed;right:18px;bottom:24px;z-index:10010;width:350px;max-width:calc(100vw - 36px);padding:14px;background:#fff;border:1px solid #dbe1e9;box-shadow:0 6px 24px #0002;border-radius:9px;font-size:12px}
.preview-detail>div{display:flex;justify-content:space-between;align-items:center}.preview-detail button{border:0;color:#155dfb;background:transparent;cursor:pointer}.preview-detail small{color:#64748b}
</style>
