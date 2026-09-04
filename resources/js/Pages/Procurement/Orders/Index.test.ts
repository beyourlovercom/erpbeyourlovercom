import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<title />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}))
vi.mock('../../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const orders = [{
  id: 4,
  procurement_number: 'PO-20260904-0001',
  status: 'pending',
  source_order_id: 7,
  source_order_number: 'ORD-007',
  created_at: '2026-09-04T08:00:00Z',
}]

describe('Procurement/Orders/Index', () => {
  it('renders procurement identity and canonical purchase links', () => {
    const wrapper = mount(Index, { props: { orders } })
    expect(wrapper.text()).toContain('PO-20260904-0001')
    expect(wrapper.text()).toContain('ORD-007')
    expect(wrapper.find('a[href="/customers/orders/7"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/purchase/orders/4"]').exists()).toBe(true)
  })
})
