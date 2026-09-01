import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Dashboard from './Dashboard.vue'
import Products from './Products.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: vi.fn() },
  usePage: () => ({ url: '/inventory', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

describe('Inventory foundation UI', () => {
  it('dashboard shows canonical inventory totals and warehouse links', () => {
    const wrapper = mount(Dashboard, { props: {
      canManage: true,
      summary: { warehouse_count: 6, warehouse_data_gap_count: 4, warehouse_confirmed_count: 2, sku_rows: 12, quantity_on_hand: 100, quantity_reserved: 15, quantity_available: 85, out_of_stock_rows: 2, warehouses: [
        { slug: 'de', name: '德国仓', country_code: 'DE', address: '', product_count: 3, quantity_on_hand: 20, is_active: true, inventory_state: 'confirmed', inventory_state_label: '已确认', last_inventory_at: '2026-09-01T08:00:00Z' },
      ] },
    } })
    expect(wrapper.text()).toContain('库存概览')
    expect(wrapper.text()).toContain('可用库存')
    expect(wrapper.text()).toContain('85')
    expect(wrapper.find('a[href="/inventory/de"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/inventory/add"]').exists()).toBe(true)
  })

  it('dashboard hides warehouse create action from view-only users', () => {
    const wrapper = mount(Dashboard, { props: {
      canManage: false,
      summary: { warehouse_count: 0, warehouse_data_gap_count: 0, warehouse_confirmed_count: 0, sku_rows: 0, quantity_on_hand: 0, quantity_reserved: 0, quantity_available: 0, out_of_stock_rows: 0, warehouses: [] },
    } })
    expect(wrapper.find('a[href="/inventory/add"]').exists()).toBe(false)
  })

  it('products table renders cross-warehouse stock truth and empty search controls', () => {
    const wrapper = mount(Products, { props: {
      rows: { data: [{ id: 7, warehouse: { slug: 'us-la', name: '美西洛杉矶仓' }, product: { id: 9, sku: 'SKU-007', name: 'Test Product' }, inbound_at: '2026-08-20', stay_days: 5, location: 'A-01', quantity_on_hand: 12, quantity_reserved: 2, quantity_available: 10, standard_qoh: 8, expected_restock_qty: 4 }], links: [], current_page: 1, last_page: 1 },
      warehouses: [{ slug: 'us-la', name: '美西洛杉矶仓' }],
      filters: {},
    } })
    expect(wrapper.text()).toContain('SKU-007')
    expect(wrapper.text()).toContain('10')
    expect(wrapper.text()).toContain('5 天')
    expect(wrapper.find('input[placeholder="搜索 SKU / 产品名称"]').exists()).toBe(true)
  })
})
