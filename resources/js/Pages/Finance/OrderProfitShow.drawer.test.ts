import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { router } from '@inertiajs/vue3'
import Show from './OrderProfitShow.vue'

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { put: vi.fn() },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<main><slot /></main>' },
}))

const explanation = {
    order: {
        id: 7396, erp_order_id: 'UAT-7396-TEST', external_order_number: 'PR541-TEST',
        order_date: '2026-10-29', source_type: 'manual', currency: 'USD', shipping_country: 'US',
        product_count: 1, shipment_count: 0, original_shipment_count: 0, replacement_shipment_count: 0,
        multi_shipment: false, updated_at: null,
    },
    summary: {
        finance_status: 'incomplete', canonical_final_ready: false, canonical_estimated_ready: false,
        revenue_status: 'incomplete', net_revenue: '99.00',
        canonical_estimated_total_cost: '20.00', canonical_estimated_profit: '79.00',
        canonical_estimated_margin: '0.7979', estimated_cost_count: 0,
        confirmed_cost_count: 0, posted_cost_count: 0, projection_confidence: 'low',
        cost_completion_percent: 0, age_days: 1, maturity_state: 'fresh_estimate_allowed',
        reconciliation_due: false, unresolved_estimate_count: 0,
    },
    product: { state: 'incomplete', amount: null, currency: 'USD', lines: [], blocker: null },
    shipments: [],
    logistics: { state: 'incomplete', shipment_count: 0, confirmed_shipment_count: 0 },
    replacement_loss: { state: 'incomplete', amount: null, currency: 'USD', replacement_count: 0, breakdown: [], blocker: null, double_count_guard: null },
    other_costs: [], formula: [],
}
const financeFacts = {
    id: 7396, erp_order_id: 'UAT-7396-TEST', currency: 'USD', revenue_provenance: 'confirmed_manual',
    provider_order_status: null, provider_reconciliation_status: null, paid_at: null,
    values: {
        merchandise_list_amount: null, discount_amount: null, shipping_revenue: null,
        amount_paid: '99.00', refund_amount: '0.00', product_cost: '20.00',
    },
}
const fields = ['merchandise_list_amount', 'discount_amount', 'shipping_revenue', 'amount_paid', 'refund_amount', 'product_cost']

describe('Cost formula in-place finance facts drawer V1.1', () => {
    let wrapper: ReturnType<typeof mount> | null = null
    beforeEach(() => vi.clearAllMocks())
    afterEach(() => { wrapper?.unmount(); wrapper = null })

    it('opens on the detail page and closes without navigation or changing the page body', async () => {
        wrapper = mount(Show, { props: { explanation, financeFacts, financeFields: fields } })
        const urlBefore = location.href
        expect(wrapper.text()).toContain('订单 #UAT-7396-TEST')
        expect(document.body.querySelector('[aria-label="订单财务事实编辑"]')).toBeNull()
        await wrapper.get('button.edit-facts-link').trigger('click')
        await nextTick()
        const drawer = document.body.querySelector('[aria-label="订单财务事实编辑"]')
        expect(drawer).not.toBeNull()
        expect(drawer?.textContent).toContain('利润实时预览')
        expect(location.href).toBe(urlBefore)
        ;(drawer?.querySelector('button[aria-label="关闭"]') as HTMLButtonElement).click()
        await nextTick()
        expect(document.body.querySelector('[aria-label="订单财务事实编辑"]')).toBeNull()
        expect(wrapper.text()).toContain('订单 #UAT-7396-TEST')
        expect(location.href).toBe(urlBefore
        )
    })

    it('saves the exact order with existing PUT contract and refreshes formula props in place', async () => {
        wrapper = mount(Show, { props: { explanation, financeFacts, financeFields: fields } })
        await wrapper.get('button.edit-facts-link').trigger('click')
        await nextTick()
        const drawer = document.body.querySelector('[aria-label="订单财务事实编辑"]') as HTMLElement
        const product = [...drawer.querySelectorAll('.finance-editor-field')].find(node => node.textContent?.includes('产品成本'))
        expect(product).toBeTruthy()
        const productInput = product!.querySelector('input') as HTMLInputElement
        productInput.value = '12.34'
        productInput.dispatchEvent(new Event('input', { bubbles: true }))
        await nextTick()
        ;(drawer.querySelector('.finance-editor-actions .mvp-button-primary') as HTMLButtonElement).click()
        expect(router.put).toHaveBeenCalledWith(
            '/accounting/order-costs/7396',
            expect.objectContaining({ product_cost: '12.34', amount_paid: '99.00' }),
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        )
        const options = vi.mocked(router.put).mock.calls[0][2]!
        options.onSuccess?.({} as never)
        await wrapper.setProps({ explanation: {
            ...explanation, summary: { ...explanation.summary, canonical_estimated_ready: true, canonical_estimated_profit: '86.66' },
        } })
        await nextTick()
        expect(wrapper.text()).toContain('86.66')
        expect(document.body.querySelector('[aria-label="订单财务事实编辑"]')).not.toBeNull()
        expect(drawer.textContent).toContain('已保存')
    })
})
