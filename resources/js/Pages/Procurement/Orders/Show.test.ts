import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<title />' } }))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const procurementOrder = {
  id: 4,
  procurement_number: 'PO-20260904-0001',
  status: 'pending',
  source_order_id: 7,
  source_order_number: 'ORD-007',
  items: [{ id: 10, sku: 'SKU-10', product_summary: '测试产品', source_quantity: 2, purchase_url: 'https://shop.test/item', purchase_size: 'M', purchase_quantity: 2 }],
}

describe('Procurement/Orders/Show', () => {
  it('renders the created procurement facts and source-order action', () => {
    const wrapper = mount(Show, { props: { procurementOrder } })
    expect(wrapper.text()).toContain('PO-20260904-0001')
    expect(wrapper.text()).toContain('ORD-007')
    expect(wrapper.text()).toContain('采购数量 2')
    expect(wrapper.find('a[href="/purchase/orders"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/customers/orders/7"]').exists()).toBe(true)
    expect(wrapper.find('a[href="https://shop.test/item"]').attributes('target')).toBe('_blank')
  })
})
