<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed, reactive, ref, watch } from 'vue'
import type { FinanceFactRow } from './financeFacts'

const props = defineProps<{ order: FinanceFactRow | null; fields: string[] }>()
const emit = defineEmits<{ (event: 'close'): void; (event: 'saved'): void }>()
const editValues = reactive<Record<string, string>>({})
const saving = ref(false)
const saved = ref(false)
watch(() => props.order?.id, () => {
    saved.value = false
    if (props.order) {
        for (const field of props.fields) editValues[field] = props.order.values[field] ?? ''
    }
}, { immediate: true })

const fieldLabels: Record<string, string> = {
    merchandise_list_amount: '商品原价合计',
    discount_amount: '折扣',
    shipping_revenue: '向客户收取运费',
    amount_paid: '实付金额（旧/手工）',
    refund_amount: '退款',
    product_cost: '产品成本',
    warehouse_cost: '仓库成本',
    first_mile_cost: '头程成本',
    overseas_handling_cost: '海外处理成本',
    last_mile_cost: '尾程 / 配送成本',
    kol_commission: 'KOL / Affiliate 佣金',
    payment_fee: '支付手续费',
    customs_tax: '关税 / 税费成本',
    refund_replacement_loss: '退款 / 补发损失',
    other_variable_cost: '其他变动成本',
}

const revenueFields = ['merchandise_list_amount', 'discount_amount', 'shipping_revenue', 'amount_paid', 'refund_amount']
const costFields = props.fields.filter(field => !revenueFields.includes(field))

const decimalCents = (value: string | undefined): number | null => {
    const normalized = (value ?? '').trim()
    if (!/^-?\d+(?:\.\d{0,2})?$/.test(normalized)) return null
    const negative = normalized.startsWith('-')
    const [whole, fraction = ''] = normalized.replace('-', '').split('.')
    const cents = Number(whole) * 100 + Number((fraction + '00').slice(0, 2))
    return negative ? -cents : cents
}
const centsMoney = (cents: number | null, currency: string): string => cents === null ? '—' : `${currency} ${(cents / 100).toFixed(2)}`
const livePreview = computed(() => {
    const row = props.order
    if (!row) return null
    const refund = decimalCents(editValues.refund_amount)
    const merchandise = decimalCents(editValues.merchandise_list_amount)
    const discount = decimalCents(editValues.discount_amount)
    const shipping = decimalCents(editValues.shipping_revenue)
    const amountPaid = decimalCents(editValues.amount_paid)
    let netRevenue: number | null = null
    if (refund !== null && merchandise !== null && discount !== null && shipping !== null) {
        netRevenue = merchandise - discount + shipping - refund
    } else if (refund !== null && amountPaid !== null) {
        netRevenue = amountPaid - refund
    }

    let knownCost = 0
    let posted = 0
    let invalidCost = false
    const missing: string[] = []
    for (const field of costFields) {
        const raw = (editValues[field] ?? '').trim()
        if (raw === '') { missing.push(field); continue }
        const cents = decimalCents(raw)
        if (cents === null) { invalidCost = true; continue }
        knownCost += cents
        posted++
    }
    const stageProfit = netRevenue !== null && !invalidCost ? netRevenue - knownCost : null
    const stageMargin = stageProfit !== null && netRevenue !== null && netRevenue !== 0 ? stageProfit / netRevenue : null
    const finalReady = netRevenue !== null && !invalidCost && posted === costFields.length
    return {
        currency: row.currency, netRevenue, knownCost, stageProfit, stageMargin, posted, required: costFields.length,
        finalProfit: finalReady ? stageProfit : null, missing, invalidCost,
    }
})
const liveMoney = (cents: number | null) => centsMoney(cents, livePreview.value?.currency ?? props.order?.currency ?? 'USD')
const liveMargin = computed(() => livePreview.value?.stageMargin === null || livePreview.value?.stageMargin === undefined ? '—' : `${(livePreview.value.stageMargin * 100).toFixed(2)}%`)
const liveCoverage = computed(() => !livePreview.value?.required ? 0 : Math.round(livePreview.value.posted / livePreview.value.required * 100))
const liveMissingText = computed(() => livePreview.value?.missing.length ? livePreview.value.missing.map(field => fieldLabels[field] ?? field).join('、') : '无')

const save = () => {
    const row = props.order
    if (!row || saving.value) return
    router.put('/accounting/order-costs/' + row.id, { ...editValues }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { saving.value = true; saved.value = false },
        onSuccess: () => { saved.value = true; emit('saved') },
        onFinish: () => { saving.value = false },
    })
}
</script>

<template>
            <Teleport to="body">
                <div v-if="order" class="finance-drawer-backdrop" @click.self="emit('close')">
                    <aside class="finance-drawer" aria-label="订单财务事实编辑" role="dialog" aria-modal="true">
                        <div class="finance-editor-header">
                            <div>
                                <p class="finance-editor-kicker">财务事实 / 成本</p>
                                <h3>{{ (order.erp_order_id ?? '—') }}</h3>
                            </div>
                            <button class="finance-drawer-close" type="button" aria-label="关闭" title="关闭" @click="emit('close')">×</button>
                        </div>

                        <div class="finance-drawer-scroll">
                            <section v-if="livePreview" class="finance-live-preview">
                                <div class="finance-live-head">
                                    <div><strong>利润实时预览</strong><span>可见即所得 · 修改下方字段会立即联动，保存前不会写入数据</span></div>
                                    <span class="finance-live-badge">成本覆盖 {{ liveCoverage }}%</span>
                                </div>
                                <div class="finance-live-grid">
                                    <article><span>净收入</span><strong>{{ liveMoney(livePreview.netRevenue) }}</strong><small>收入公式实时计算</small></article>
                                    <article><span>已知成本</span><strong>{{ liveMoney(livePreview.knownCost) }}</strong><small>{{ livePreview.posted }} / {{ livePreview.required }} 项已填写</small></article>
                                    <article class="emphasis"><span>当前阶段利润</span><strong>{{ liveMoney(livePreview.stageProfit) }}</strong><small>阶段 Margin {{ liveMargin }}</small></article>
                                    <article><span>最终确认利润</span><strong>{{ livePreview.finalProfit === null ? '待确认' : liveMoney(livePreview.finalProfit) }}</strong><small>{{ livePreview.finalProfit === null ? `尚缺：${liveMissingText}` : '全部必要成本已填写' }}</small></article>
                                </div>
                                <div class="finance-live-formula">
                                    <span><b>当前阶段利润</b> = 净收入 − 当前已填写成本</span>
                                    <span><b>最终确认利润</b> = 净收入 − 全部必要成本</span>
                                </div>
                                <p v-if="livePreview.invalidCost" class="finance-live-warning">存在无法识别的成本金额，利润预览已暂停；请使用最多两位小数的数字。</p>
                                <p v-if="saved" class="finance-live-saved">✓ 已保存，订单利润事实已更新。</p>
                            </section>

                            <div class="fact-meta">
                                <span>收入来源：<b>{{ order.revenue_provenance || '未入账' }}</b></span>
                                <span>Woo 状态：<b>{{ order.provider_order_status || '—' }}</b></span>
                                <span>Woo 对账：<b>{{ order.provider_reconciliation_status || '—' }}</b></span>
                                <span>付款时间：<b>{{ order.paid_at || '—' }}</b></span>
                            </div>
                            <p class="editor-note">手工修改收入字段会明确切换为 confirmed_manual；只补成本且收入值未变化时，会保留 Woo provider_import 来源。</p>

                            <div class="finance-editor-columns">
                                <section class="finance-editor-column">
                                    <div class="finance-editor-section-title">收入事实</div>
                                    <div class="finance-editor-table">
                                        <label v-for="field in revenueFields" :key="field" class="finance-editor-field">
                                            <span>{{ fieldLabels[field] ?? field }}</span>
                                            <input v-model="editValues[field]" inputmode="decimal">
                                        </label>
                                    </div>
                                </section>

                                <section class="finance-editor-column">
                                    <div class="finance-editor-section-title">成本事实</div>
                                    <div class="finance-editor-table">
                                        <label v-for="field in costFields" :key="field" class="finance-editor-field">
                                            <span>{{ fieldLabels[field] ?? field }}</span>
                                            <input v-model="editValues[field]" inputmode="decimal">
                                        </label>
                                    </div>
                                </section>
                            </div>
                        </div>

                        <div class="row-actions finance-editor-actions">
                            <button class="mvp-button" type="button" @click="emit('close')">取消</button>
                            <button class="mvp-button mvp-button-primary" type="button" :disabled="saving" @click="save">{{ saving ? '保存中…' : '保存财务事实' }}</button>
                        </div>
                    </aside>
                </div>
            </Teleport>

</template>

<style scoped>
.finance-drawer-backdrop { position: fixed; inset: 0; z-index: 1000; background: rgb(18 31 36 / 26%); }
.finance-drawer { position: absolute; top: 0; right: 0; width: min(1120px, 96vw); height: 100vh; display: grid; grid-template-rows: auto minmax(0, 1fr) auto; background: #fff; border-left: 1px solid #cfdadd; box-shadow: -14px 0 36px rgb(20 33 38 / 18%); }
.finance-editor-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 14px 18px; border-bottom: 1px solid #dfe6e8; background: #f7f9f9; }
.finance-editor-header h3 { margin: 2px 0 0; font-size: 18px; color: #18282d; }
.finance-editor-kicker { margin: 0; font-size: 11px; font-weight: 700; letter-spacing: .08em; color: #65777c; }
.finance-drawer-close { width: 34px; height: 34px; padding: 0; border: 1px solid #c9d4d7; border-radius: 6px; background: #fff; color: #40545a; font-size: 24px; line-height: 30px; cursor: pointer; }
.finance-drawer-close:hover { border-color: #879aa0; background: #f1f5f5; color: #18282d; }
.finance-drawer-scroll { min-height: 0; overflow-y: auto; overscroll-behavior: contain; }
.finance-live-preview { position: sticky; top: 0; z-index: 5; margin: 0; padding: 14px 18px; border-bottom: 1px solid #dfe6e8; background: rgba(255,255,255,.98); backdrop-filter: blur(10px); }
.finance-live-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:10px; }
.finance-live-head > div { display:grid; gap:3px; }
.finance-live-head strong { color:#18282d; font-size:15px; }
.finance-live-head span { color:#71848b; font-size:11px; }
.finance-live-badge { display:inline-flex; padding:5px 9px; border-radius:999px; background:#eef7f5; color:#16705f !important; font-weight:700; white-space:nowrap; }
.finance-live-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; }
.finance-live-grid article { display:grid; gap:4px; min-width:0; padding:11px 12px; border:1px solid #e0e7e9; border-radius:9px; background:#fafcfc; }
.finance-live-grid article > span,.finance-live-grid article > small { color:#71848b; font-size:11px; }
.finance-live-grid article > strong { color:#18282d; font-size:18px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.finance-live-grid article.emphasis { border-color:#b9dfcb; background:#f2fbf6; }
.finance-live-grid article.emphasis > strong { color:#15865f; }
.finance-live-formula { display:flex; gap:18px; flex-wrap:wrap; margin-top:9px; color:#5f7075; font-size:11px; }
.finance-live-formula b { color:#33474e; }
.finance-live-warning { margin:9px 0 0; color:#a65c00; font-size:11px; }
.finance-live-saved { margin:9px 0 0; color:#16705f; font-size:11px; font-weight:700; }
.fact-meta { display: flex; flex-wrap: wrap; gap: 8px 24px; padding: 14px 18px 0; font-size: 12px; color: #5f7075; }
.editor-note { margin: 8px 18px 14px; color: #5f7075; font-size: 12px; }
.finance-editor-columns { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: start; gap: 14px; padding: 0 18px 18px; }
.finance-editor-column { min-width: 0; border: 1px solid #d8e0e3; border-radius: 6px; overflow: hidden; background: #fff; }
.finance-editor-section-title { padding: 10px 12px; border-bottom: 1px solid #d8e0e3; background: #eef3f4; font-size: 13px; font-weight: 700; color: #24383e; text-align: left; }
.finance-editor-table { display: grid; }
.finance-editor-field { display: grid; grid-template-columns: minmax(120px, 42%) minmax(110px, 1fr); align-items: center; min-height: 48px; margin: 0; border-bottom: 1px solid #e6ecee; background: #fff; }
.finance-editor-field:last-child { border-bottom: 0; }
.finance-editor-field > span { align-self: stretch; display: flex; align-items: center; padding: 9px 10px; border-right: 1px solid #e6ecee; background: #fafbfb; color: #40545a; font-size: 12px; text-align: left; }
.finance-editor-field input { width: calc(100% - 16px); min-width: 0; height: 34px; margin: 6px 8px; padding: 6px 9px; border: 1px solid #9fb0b5; border-radius: 4px; background: #fff; color: #18282d; font: inherit; box-sizing: border-box; }
.finance-editor-field input:hover { border-color: #617a82; }
.finance-editor-field input:focus { outline: 2px solid rgba(27, 95, 114, .18); outline-offset: 0; border-color: #1b5f72; }
.row-actions { display: flex; justify-content: flex-end; gap: 8px; }
.finance-editor-actions { padding: 12px 18px; border-top: 1px solid #dfe6e8; background: #f7f9f9; }
.finance-drawer { animation: facts-enter .18s ease-out; }
@keyframes facts-enter { from { transform: translateX(22px); opacity: .7; } to { transform: translateX(0); opacity: 1; } }
@media (max-width: 900px) { .finance-live-grid { grid-template-columns: repeat(2,minmax(0,1fr)); } .finance-editor-columns { grid-template-columns: 1fr; } }
@media (max-width: 720px) { .finance-drawer { width: 100vw; } .finance-live-grid { grid-template-columns: 1fr; } }
</style>
