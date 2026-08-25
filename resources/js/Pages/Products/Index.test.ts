import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { get: vi.fn() },
  usePage: () => ({ url: '/products/list', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

const emptyPage = { data: [], current_page: 1, last_page: 1, total: 0, per_page: 50, from: null, to: null, links: [] }

describe('Products/Index', () => {
  it('shows Chinese column headers', () => {
    const wrapper = mount(Index, { props: { products: emptyPage, filters: {} } })
    expect(wrapper.text()).toContain('SKU')
    expect(wrapper.text()).toContain('产品名称')
    expect(wrapper.text()).toContain('Woo ID')
    expect(wrapper.text()).toContain('重量')
    expect(wrapper.text()).toContain('售价')
    expect(wrapper.text()).toContain('原价')
  })

  it('renders blank values as —', () => {
    const product = { id: 1, woocommerce_product_id: 9001, product_type: 'simple', sku: null, gtin: null, name: 'Test', weight_kg: null, sale_price: null, regular_price: '89.00' }
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {} } })
    const cells = wrapper.findAll('td')
    expect(cells[0].text()).toBe('—')
    expect(cells[4].text()).toBe('—')
    expect(cells[5].text()).toBe('—')
    expect(cells[6].text()).toBe('89.00')
  })

  it('shows empty state when no products', () => {
    const wrapper = mount(Index, { props: { products: emptyPage, filters: {} } })
    expect(wrapper.text()).toContain('暂无产品')
  })

  it('shows total product count', () => {
    const page = { ...emptyPage, total: 116 }
    const wrapper = mount(Index, { props: { products: page, filters: {} } })
    expect(wrapper.text()).toContain('116')
  })
})
