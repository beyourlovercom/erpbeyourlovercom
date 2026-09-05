import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  router: { post: vi.fn(), get: vi.fn() },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const warehouse = { id: 2, slug: 'us-ny', name: '美东纽约仓', code: 'US-NY', country_code: null, priority: 20, inventory_state: 'confirmed', can_auto_allocate: true, service_regions: [{ country_code: 'US', priority: 1 }] }
const order = {
  id: 10, external_order_number: 'TEST-10', order_kind_label: '客户订单', recipient_name: 'Tester', phone: null, country: 'US', owner_label: null, item_count: 1,
  items: [{ id: 1, product_id: 99, sku: 'SKU-99', product_summary: 'Product', quantity: 1, availability: [{ warehouse_id: 2, warehouse_slug: 'us-ny', warehouse_name: '美东纽约仓', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 5, quantity_reserved: 0, quantity_available: 5 }] }],
  warehouse_recommendation: { status: 'recommended' as const, reason_code: 'recommended', reason_label: '可采用系统推荐。', country_code: 'US', required_sku_count: 1, candidate_count: 1, warehouse: { id: 2, slug: 'us-ny', code: 'US-NY', name: '美东纽约仓' } },
  shipment: null,
}
const carrierOptions = [{ value: 'DHL', label: 'DHL' }, { value: 'UPS', label: 'UPS' }, { value: 'FedEx', label: 'FedEx' }]
const props = { orders: { data: [order], current_page: 1, last_page: 1, per_page: 50, total: 1 }, warehouses: [warehouse], carrierOptions }

describe('Logistics warehouse recommendation', () => {
  it('adopts a recommendation into the local draft without saving automatically', async () => {
    const wrapper = mount(Index, { props })
    await wrapper.findAll('button').find(button => button.text() === '履约处理')!.trigger('click')
    expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('')
    await wrapper.findAll('button').find(button => button.text() === '采用推荐')!.trigger('click')
    expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('2')
  })


  it('uses the canonical carrier dropdown instead of free text', async () => {
    const wrapper = mount(Index, { props })
    await wrapper.findAll('button').find(button => button.text() === '履约处理')!.trigger('click')
    const carrierSelect = wrapper.get('select[aria-label="物流商"]')
    expect(carrierSelect.text()).toContain('请选择承运商')
    expect(carrierSelect.text()).toContain('DHL')
    expect(carrierSelect.text()).toContain('UPS')
    expect(carrierSelect.text()).toContain('FedEx')
    expect(wrapper.find('input[placeholder="例如 UPS"]').exists()).toBe(false)
  })

  it('renders unknown inventory as unknown instead of numeric zero', async () => {
    const unknown = { ...order, items: [{ ...order.items[0], availability: [{ warehouse_id: 1, warehouse_slug: 'us-la', warehouse_name: '美西洛杉矶仓', inventory_state: 'never_imported', can_auto_allocate: false, quantity_on_hand: null, quantity_reserved: null, quantity_available: null }] }] }
    const wrapper = mount(Index, { props: { ...props, orders: { ...props.orders, data: [unknown] } } })
    await wrapper.findAll('button').find(button => button.text() === '履约处理')!.trigger('click')
    expect(wrapper.text()).toContain('美西洛杉矶仓：库存未知')
    expect(wrapper.text()).not.toContain('美西洛杉矶仓：可用 0')
  })

  it('does not render adopt action when recommendation is blocked', async () => {
    const blocked = { ...order, warehouse_recommendation: { ...order.warehouse_recommendation, status: 'blocked' as const, reason_code: 'service_regions_unconfigured', reason_label: '服务区域未配置', warehouse: null } }
    const wrapper = mount(Index, { props: { ...props, orders: { ...props.orders, data: [blocked] } } })
    await wrapper.findAll('button').find(button => button.text() === '履约处理')!.trigger('click')
    expect(wrapper.text()).toContain('服务区域未配置')
    expect(wrapper.findAll('button').some(button => button.text() === '采用推荐')).toBe(false)
  })
})
