<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type MoneyPart = {
    amount: string | null
    currency: string | null
    source_amount?: string | null
    source_currency?: string | null
    unit_rate?: string | null
    rule?: string | null
    state: string
}

type ShipmentRow = {
    shipment_id: number
    fulfillment_suffix: string | null
    warehouse_code: string | null
    warehouse_name: string | null
    warehouse_slug: string | null
    tracking_number: string | null
    carrier: string | null
    shipped_units: number | null
    items: Array<{ sku?: string | null; quantity?: number }>
    first_mile: MoneyPart | null
    handling: MoneyPart | null
    last_mile: MoneyPart | null
    shipment_total: string | null
    shipment_total_currency: string | null
    state: string
}

type CostLine = {
    cost_type: string
    label: string
    amount: string | null
    currency: string | null
    state: string
    formula: string
    provenance: string | null
    status: string | null
    source_reference: string | null
    source_at: string | null
    blocker: string | null
}

type Explanation = {
    order: {
        id: number
        erp_order_id: string | null
        external_order_number: string | null
        order_date: string | null
        source_type: string
        currency: string
        shipping_country: string | null
        product_count: number
        shipment_count: number
        multi_shipment: boolean
        updated_at: string | null
    }
    summary: Record<string, any>
    product: {
        state: string
        amount: string | null
        currency: string | null
        provenance?: string | null
        source_reference?: string | null
        blocker?: string | null
        lines: Array<Record<string, any>>
    }
    shipments: ShipmentRow[]
    other_costs: CostLine[]
    formula: CostLine[]
}

const props = defineProps<{ explanation: Explanation }>()
const e = props.explanation
const money = (amount: string | null | undefined, currency: string | null | undefined) => amount === null || amount === undefined ? '—' : `${currency ?? e.order.currency} ${amount}`
const pct = (value: string | null | undefined) => value === null || value === undefined ? '—' : `${(Number(value) * 100).toFixed(1)}%`
const stateLabel = (state: string) => ({ confirmed: 'Confirmed', estimated: 'Estimated', missing: 'Missing', blocked: 'Blocked', review: 'Review' }[state] ?? state)
const totalLogistics = e.formula.filter(line => ['first_mile_cost', 'overseas_handling_cost', 'last_mile_cost'].includes(line.cost_type))
const formulaSymbol = (index: number) => index === 0 ? '' : '+'
</script>

<template>
    <Head :title="`${e.order.erp_order_id ?? 'Order'} · Cost & Profit`" />
    <AuthenticatedLayout title="订单利润" :breadcrumbs="['BYL ERP', '订单', '财务']">
        <section class="profit-page">
            <header class="order-header">
                <div>
                    <p class="crumb"><Link href="/accounting/order-costs">Orders</Link> / Order Details / Finance</p>
                    <div class="title-row">
                        <h1>Order #{{ e.order.erp_order_id ?? e.order.id }}</h1>
                        <span class="badge">Customer Order</span>
                        <span class="badge blue">{{ e.order.shipment_count }} Shipment{{ e.order.shipment_count === 1 ? '' : 's' }}</span>
                    </div>
                    <p class="meta">Created: {{ e.order.order_date ?? '—' }} · Marketplace: {{ e.order.source_type }} · Shipping Country: {{ e.order.shipping_country ?? '—' }}</p>
                    <p class="submeta">{{ e.order.product_count }} products · {{ e.order.shipment_count }} shipments · {{ e.order.multi_shipment ? 'Multi-warehouse fulfillment' : 'Single fulfillment' }}</p>
                </div>
                <div class="finance-status" :class="`state-${String(e.summary.finance_status ?? '').toLowerCase()}`">
                    <strong>{{ e.summary.finance_status ?? 'UNPOSTED' }}</strong>
                    <span>{{ e.summary.canonical_final_ready ? 'All required canonical facts available' : 'Required evidence is still incomplete' }}</span>
                </div>
            </header>

            <nav class="tabs"><span>Overview</span><span>Items ({{ e.order.product_count }})</span><span>Shipments ({{ e.order.shipment_count }})</span><b>Cost & Profit</b><span>Timeline</span><span>Evidence</span></nav>

            <section class="summary-panel">
                <h2>Order Profit Summary</h2>
                <div class="summary-grid">
                    <article><span>Net Revenue</span><strong>{{ money(e.summary.net_revenue, e.order.currency) }}</strong></article>
                    <em>−</em>
                    <article class="danger"><span>Total Canonical Cost</span><strong>{{ money(e.summary.canonical_known_cost, e.order.currency) }}</strong></article>
                    <em>=</em>
                    <article><span>Contribution Profit</span><strong>{{ money(e.summary.canonical_contribution_profit, e.order.currency) }}</strong></article>
                    <article><span>Profit Margin</span><strong>{{ pct(e.summary.canonical_margin) }}</strong></article>
                    <article class="formula-card"><span>Formula</span><strong>Net Revenue − Total Canonical Cost</strong><small>Final profit stays unconfirmed when required evidence is missing.</small></article>
                </div>
            </section>

            <section class="cost-section">
                <div class="section-head"><h2>1. Product Cost <small>(Parent-owned, all items)</small></h2><strong>{{ money(e.product.amount, e.product.currency) }}</strong></div>
                <p class="rule-note">货本：只在 Parent Order 计算一次，包含所有商品；拆分 Shipment 不会重复计算。</p>
                <div class="table-wrap"><table><thead><tr><th>#</th><th>Product</th><th>SKU</th><th>Unit Cost</th><th>Qty</th><th>Subtotal</th><th>Cost Source</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr v-for="(line, index) in e.product.lines" :key="line.item_id ?? index">
                            <td>{{ index + 1 }}</td><td>{{ line.product_name ?? '—' }}</td><td>{{ line.sku ?? '—' }}</td>
                            <td>{{ money(line.unit_cost, line.currency) }}</td><td>{{ line.quantity }}</td><td>{{ money(line.subtotal, line.currency) }}</td>
                            <td><span>{{ line.source_key ?? line.source_reference ?? '—' }}</span><small>{{ line.effective_from ?? '' }}</small></td>
                            <td><span class="state" :class="`state-${e.product.state}`">{{ stateLabel(e.product.state) }}</span></td>
                        </tr>
                        <tr v-if="!e.product.lines.length"><td colspan="8" class="empty">Product cost evidence unavailable: {{ e.product.blocker ?? 'missing' }}</td></tr>
                    </tbody>
                    <tfoot><tr><th colspan="5">Total Product Cost</th><th>{{ money(e.product.amount, e.product.currency) }}</th><th colspan="2">Parent only · no duplicate on fulfillment children</th></tr></tfoot>
                </table></div>
            </section>

            <section class="cost-section">
                <div class="section-head"><h2>2. Logistics Cost <small>(Shipment-owned, aggregated to Parent Order)</small></h2></div>
                <p class="rule-note">物流成本：每个 Shipment 独立核算，最后汇总到 Parent Order。海外仓头程/操作费按 shipped unit × USD 1；中国直发两项均为 0。</p>
                <div class="table-wrap"><table><thead><tr><th>Shipment</th><th>Warehouse / Origin</th><th>Items / Qty</th><th>First Mile</th><th>Handling</th><th>Last Mile</th><th>Shipment Total</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr v-for="row in e.shipments" :key="row.shipment_id">
                            <td><b>#{{ row.shipment_id }}</b><small>{{ row.tracking_number ?? 'No tracking' }}</small></td>
                            <td>{{ row.warehouse_code ?? row.warehouse_name ?? row.warehouse_slug ?? '—' }}</td>
                            <td><span v-for="(item, idx) in row.items" :key="idx" class="item-chip">{{ item.sku ?? 'SKU' }} × {{ item.quantity ?? 0 }}</span><small>Total units: {{ row.shipped_units ?? '—' }}</small></td>
                            <td>{{ money(row.first_mile?.amount, row.first_mile?.currency) }}<small v-if="row.first_mile?.source_currency && row.first_mile.source_currency !== row.first_mile.currency">source {{ money(row.first_mile.source_amount, row.first_mile.source_currency) }}</small></td>
                            <td>{{ money(row.handling?.amount, row.handling?.currency) }}<small v-if="row.handling?.source_currency && row.handling.source_currency !== row.handling.currency">source {{ money(row.handling.source_amount, row.handling.source_currency) }}</small></td>
                            <td>{{ money(row.last_mile?.amount, row.last_mile?.currency) }}<small>{{ row.carrier ?? 'Carrier evidence' }}</small></td>
                            <td><strong>{{ money(row.shipment_total, row.shipment_total_currency) }}</strong></td>
                            <td><span class="state" :class="`state-${row.state}`">{{ stateLabel(row.state) }}</span></td>
                        </tr>
                        <tr v-if="!e.shipments.length"><td colspan="8" class="empty">No canonical Shipment evidence found.</td></tr>
                    </tbody>
                    <tfoot><tr><th colspan="3">Parent Logistics Aggregation</th><th v-for="line in totalLogistics" :key="line.cost_type">{{ money(line.amount, line.currency) }}</th><th colspan="2">Σ Shipment cost components</th></tr></tfoot>
                </table></div>
            </section>

            <section class="cost-section">
                <div class="section-head"><h2>3. Other Parent-level Costs</h2></div>
                <p class="rule-note">订单级成本只在 Parent Order 计算一次；不会复制到 fulfillment-derived child orders。</p>
                <div class="table-wrap"><table><thead><tr><th>Cost</th><th>Calculation Basis</th><th>Amount</th><th>Status</th><th>Provenance</th><th>Evidence / Source</th></tr></thead>
                    <tbody><tr v-for="line in e.other_costs" :key="line.cost_type">
                        <td><b>{{ line.label }}</b></td><td>{{ line.formula }}</td><td>{{ money(line.amount, line.currency) }}</td>
                        <td><span class="state" :class="`state-${line.state}`">{{ stateLabel(line.state) }}</span><small v-if="line.blocker">{{ line.blocker }}</small></td>
                        <td>{{ line.provenance ?? '—' }}</td><td><code>{{ line.source_reference ?? '—' }}</code><small>{{ line.source_at ?? '' }}</small></td>
                    </tr></tbody>
                </table></div>
            </section>

            <section class="final-panel">
                <div>
                    <h2>4. Final Calculation</h2>
                    <p>最终计算公式直接来自 canonical evidence / materialization preview。</p>
                    <div class="formula-line">
                        <template v-for="(line, index) in e.formula" :key="line.cost_type">
                            <span class="operator">{{ formulaSymbol(index) }}</span>
                            <article :class="{ incomplete: line.amount === null }"><strong>{{ money(line.amount, line.currency) }}</strong><small>{{ line.label }}</small></article>
                        </template>
                        <span class="operator">=</span>
                        <article class="total"><strong>{{ money(e.summary.canonical_known_cost, e.order.currency) }}</strong><small>Canonical Known Cost</small></article>
                    </div>
                </div>
                <aside class="profit-box" :class="{ pending: !e.summary.canonical_final_ready }">
                    <span>利润计算</span>
                    <strong>{{ money(e.summary.canonical_contribution_profit, e.order.currency) }}</strong>
                    <p>{{ money(e.summary.net_revenue, e.order.currency) }} − {{ money(e.summary.canonical_known_cost, e.order.currency) }}</p>
                    <b>Margin = {{ pct(e.summary.canonical_margin) }}</b>
                    <small v-if="!e.summary.canonical_final_ready">Required evidence incomplete — final profit remains unconfirmed.</small>
                </aside>
            </section>
        </section>
    </AuthenticatedLayout>
</template>

<style scoped>
.profit-page { display:grid; gap:14px; color:#18282d; }
.order-header { display:flex; justify-content:space-between; gap:24px; padding:6px 2px 2px; }
.crumb { margin:0 0 8px; color:#78878c; font-size:12px; }.crumb a{color:#536a72;text-decoration:none}
.title-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }.title-row h1{margin:0;font-size:30px;letter-spacing:-.025em}
.badge { padding:5px 9px; border-radius:6px; background:#e6f6ec; color:#1f7653; font-size:12px; font-weight:700; }.badge.blue{background:#e8f2ff;color:#2563b8}
.meta,.submeta { margin:7px 0 0; color:#718087; font-size:12px; }.submeta{font-style:italic}
.finance-status { min-width:280px; align-self:center; padding:14px 16px; border:1px solid #cfe7d8; border-radius:8px; background:#effaf3; display:grid; gap:3px; }
.finance-status strong{font-size:17px;color:#18744f}.finance-status span{font-size:11px;color:#61736a}.finance-status.state-incomplete,.finance-status.state-unposted{background:#fff8eb;border-color:#edd9ad}.finance-status.state-incomplete strong,.finance-status.state-unposted strong{color:#9a6500}
.tabs { display:flex; gap:26px; align-items:center; border-bottom:1px solid #dfe6e8; padding:0 12px; color:#607078; font-size:12px; }.tabs>*{padding:11px 2px}.tabs b{color:#1b63d8;border-bottom:2px solid #1b63d8}
.summary-panel { border:1px solid #cfe3f3; background:#eff7ff; border-radius:8px; padding:13px; }.summary-panel h2,.cost-section h2,.final-panel h2{margin:0;font-size:16px}.summary-grid{display:grid;grid-template-columns:1.1fr auto 1.1fr auto 1.1fr 1fr 1.7fr;gap:10px;align-items:center;margin-top:12px}
.summary-grid article{min-height:78px;background:#fff;border:1px solid #dce6ea;border-radius:7px;padding:12px;display:grid;gap:4px;align-content:center}.summary-grid article span{font-size:11px;color:#6b7b82}.summary-grid article strong{font-size:19px}.summary-grid article.danger strong{color:#d12e2e}.summary-grid em{font-style:normal;font-weight:800;color:#64757a}.formula-card{border-color:#84b6f6!important}.formula-card strong{font-size:13px!important;color:#235fb3}.formula-card small{color:#60758a}
.cost-section { border:1px solid #dfe6e8; border-radius:8px; padding:12px; background:#fff; }.section-head{display:flex;justify-content:space-between;gap:18px;align-items:center}.section-head h2 small{font-weight:500;color:#596b72}.rule-note{margin:7px 0 10px;color:#c0392b;font-size:12px}.table-wrap{overflow:auto;border:1px solid #e1e7e9;border-radius:6px}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:9px 10px;border-right:1px solid #e5eaec;border-bottom:1px solid #e5eaec;text-align:left;vertical-align:middle}th:last-child,td:last-child{border-right:0}thead th,tfoot th{background:#f4f7f8;font-weight:700;white-space:nowrap}tfoot th{border-bottom:0}td small,td span+small{display:block;color:#849198;margin-top:3px}.item-chip{display:block;white-space:nowrap}.empty{text-align:center;color:#87959a;padding:18px}.state{display:inline-flex;padding:3px 7px;border-radius:999px;font-weight:700;font-size:10px}.state-confirmed{background:#e5f6eb;color:#18734e}.state-estimated{background:#fff2cf;color:#956100}.state-missing,.state-blocked{background:#feeceb;color:#b83832}.state-review{background:#f3eafa;color:#7a3ea1}code{font-size:10px;color:#52656d;word-break:break-all}

.final-panel{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:14px;border:1px solid #dfe6e8;border-radius:8px;padding:14px;background:#fff}.final-panel>div>p{margin:5px 0 12px;color:#6d7d84;font-size:11px}.formula-line{display:flex;align-items:stretch;gap:7px;overflow:auto}.formula-line article{min-width:108px;border:1px solid #dfe6e8;border-radius:7px;padding:9px;display:grid;gap:3px;background:#fafcfc}.formula-line article strong{font-size:14px}.formula-line article small{font-size:10px;color:#75868d}.formula-line article.incomplete{border-style:dashed;opacity:.65}.formula-line article.total{background:#fff1f1;border-color:#f2c7c7;color:#b42323}.operator{align-self:center;font-weight:800;color:#61747b}.profit-box{border:1px solid #bfe3ce;border-radius:8px;background:#eefaf3;padding:14px;display:grid;gap:7px;align-content:center}.profit-box>span{font-size:12px;color:#567467}.profit-box>strong{font-size:24px;color:#168052}.profit-box p{margin:0;color:#2d6e54}.profit-box b{color:#137248}.profit-box small{color:#8a6500}.profit-box.pending{background:#fff9eb;border-color:#ead7a8}.profit-box.pending>strong{color:#9a6500}
@media(max-width:1180px){.summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.summary-grid em{display:none}.formula-card{grid-column:span 2}.final-panel{grid-template-columns:1fr}.finance-status{min-width:220px}}
@media(max-width:760px){.order-header{display:grid}.finance-status{min-width:0}.tabs{overflow:auto;gap:18px}.summary-grid{grid-template-columns:1fr 1fr}.formula-card{grid-column:1/-1}.final-panel{padding:10px}.title-row h1{font-size:24px}}
</style>
