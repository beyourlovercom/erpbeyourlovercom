<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type CurrencyCost = { currency: string; total: number; average: number | null; shipments: number }
type Carrier = {
  name: string
  shipment_count: number
  chargeable_weight_kg: number | null
  chargeable_weight_coverage: number
  costs_by_currency: CurrencyCost[]
  this_month_count: number
  last_shipped_at: string | null
}

defineProps<{ carriers: Carrier[] }>()

const money = (costs: CurrencyCost[]) => costs.length === 0
  ? '—'
  : costs.map((row) => `${row.currency === 'UNSPECIFIED' ? '未标币种' : row.currency} ${row.total.toFixed(2)}`).join(' / ')
const dateTime = (value: string | null) => value ? new Date(value).toLocaleString() : '—'
</script>

<template>
  <Head title="承运商 · BYL ERP" />
  <AuthenticatedLayout title="物流" :breadcrumbs="['BYL ERP', '物流', '承运商']">
    <section class="mvp-page carrier-page">
      <div class="mvp-page-heading">
        <div>
          <p class="mvp-kicker">Carrier Authority Workspace</p>
          <h2>承运商</h2>
          <p>按 canonical Shipment 汇总真实发货历史；费用与计费重来自独立 Billing Evidence，不复制 Shipment truth。</p>
        </div>
      </div>

      <div class="mvp-table-wrap">
        <table class="mvp-table">
          <thead><tr><th>承运商</th><th>总发货票数</th><th>总计费重量</th><th>总物流费用</th><th>本月发货</th><th>最后发货</th></tr></thead>
          <tbody>
            <tr v-for="carrier in carriers" :key="carrier.name">
              <td><Link class="carrier-link" :href="`/logistics/carriers/${encodeURIComponent(carrier.name)}`">{{ carrier.name }}</Link></td>
              <td>{{ carrier.shipment_count }}</td>
              <td>{{ carrier.chargeable_weight_kg === null ? '—' : `${carrier.chargeable_weight_kg.toFixed(3)} kg` }}</td>
              <td>{{ money(carrier.costs_by_currency) }}</td>
              <td>{{ carrier.this_month_count }}</td>
              <td>{{ dateTime(carrier.last_shipped_at) }}</td>
            </tr>
            <tr v-if="carriers.length === 0"><td colspan="6" class="mvp-empty">暂无已记录承运商。承运商会从真实 Shipment 发货记录自动汇总。</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.carrier-link { font-weight: 700; text-decoration: none; }
.carrier-link:hover { text-decoration: underline; }
</style>
