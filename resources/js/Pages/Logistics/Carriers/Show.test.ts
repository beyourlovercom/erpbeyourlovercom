import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ url: '/logistics/carriers' }),
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: vi.fn() },
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const summary = { shipment_count: 1, chargeable_weight_kg: 2.5, chargeable_weight_coverage: 1, costs_by_currency: [{ currency: 'USD', total: 18.5, average: 18.5, shipments: 1 }], this_month_count: 1, delivery_status_coverage: 1, in_transit_count: 0, delivered_count: 1, exception_count: 0, last_shipped_at: '2026-08-28T08:00:00Z' }
const row = { id: 1, shipped_at: '2026-08-28T08:00:00Z', external_order_number: 'CUS-1', internal_order_number: 'ERP-1', order_url: '/customers/orders/1', order_kind: 'customer', warehouse_name: 'US West', destination_country: 'US', carrier: 'DHL', service: 'Express', tracking_number: 'TRACK-1', items: [{ sku: 'SKU-1', product_summary: 'Item', quantity: 2 }], chargeable_weight_kg: '2.500', actual_cost: '18.5000', currency: 'USD', shipment_status: 'shipped', delivery_status: 'delivered', handler_name: 'Andy', recipient_name: 'Receiver', billing_document_number: 'BILL-1', provider_reference: null, data_source: 'carrier_bill', billed_at: null, raw_evidence: { discount: 3.5 } }

describe('Carrier shipment ledger', () => {
  it('renders canonical shipment fields plus billing evidence', () => {
    const wrapper = mount(Show, { props: { carrier: 'DHL', carrierDisplayName: 'DHL', summary, shipments: { data: [row], current_page: 1, last_page: 1, total: 1 } } })
    expect(wrapper.text()).toContain('Carrier Shipment Ledger')
    expect(wrapper.text()).toContain('CUS-1')
    expect(wrapper.text()).not.toContain('ERP-1')
    expect(wrapper.text()).toContain('TRACK-1')
    expect(wrapper.text()).toContain('2.500 kg')
    expect(wrapper.text()).toContain('USD 18.50')
    expect(wrapper.text()).toContain('BILL-1')
    expect(wrapper.text()).toContain('查看')
  })
})
