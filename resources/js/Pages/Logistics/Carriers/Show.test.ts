import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get },
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('../../../Components/Logistics/StatusBar.vue', () => ({ default: { template: '<div />' } }))

const summary = {
  shipment_count: 51, chargeable_weight_kg: 4.125, chargeable_weight_coverage: 1, costs_by_currency: [{ currency: 'USD', total: 33.75, average: 33.75, shipments: 1 }],
  this_month_count: 1, delivery_status_coverage: 0, in_transit_count: 0, delivered_count: 0, exception_count: 0, last_shipped_at: '2026-09-15T00:00:00Z',
}
const row = {
  id: 1, shipped_at: '2026-09-15T00:00:00Z', erp_order_id: '007-0915-c41001', order_kind: 'customer', order_url: '/customers/orders/1', warehouse_name: '美东纽约', destination_country: 'US', carrier: 'DHL', service: 'Express', tracking_number: 'DHL-1',
  items: [{ sku: 'SKU-1', product_summary: 'Product', product_url: '/products/1', quantity: 2 }], chargeable_weight_kg: 4.125, actual_cost: 33.75, currency: 'USD', shipment_status: 'shipped', delivery_status: null, handler_name: 'Dede', recipient_name: 'Receiver', billing_document_number: 'BILL-1', provider_reference: 'REF-1', data_source: 'carrier_bill_import:tianyun', billed_at: null, raw_evidence: { source: 'bill' },
}

describe('Carrier detail ledger', () => {
  beforeEach(() => get.mockReset())

  it('renders only the canonical ERP order identity and does not infer delivery state', () => {
    const wrapper = mount(Show, { props: { carrier: 'DHL', carrierDisplayName: 'DHL', summary, shipments: { data: [row], current_page: 1, last_page: 2, total: 51 } } })
    expect(wrapper.text()).toContain('007-0915-c41001')
    expect(wrapper.text()).toContain('暂无真实签收状态证据')
    expect(wrapper.text()).toContain('USD 33.75')
    expect(wrapper.text()).toContain('BILL-1')
    expect(wrapper.text()).not.toContain('ORDER-DHL-1')
    expect(wrapper.text()).not.toContain('ERP-DHL-1')
  })

  it('paginates without mutating business data', async () => {
    const wrapper = mount(Show, { props: { carrier: 'DHL', carrierDisplayName: 'DHL', summary, shipments: { data: [row], current_page: 1, last_page: 2, total: 51 } } })
    await wrapper.findAll('button').find((button) => button.text() === '下一页')?.trigger('click')
    expect(get).toHaveBeenCalledWith('/logistics/carriers/DHL', { page: 2 }, { preserveState: true, replace: true })
  })
})
