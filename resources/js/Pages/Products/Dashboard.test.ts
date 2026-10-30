import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { router } from '@inertiajs/vue3'
import Dashboard from './Dashboard.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { get: vi.fn() },
  usePage: () => ({ url: '/products', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

const props = {
  totalProducts: 116,
  metrics: { erpActive: 90, wooPublishedSnapshot: 70, needsData: 21, pendingReview: 2 },
  attentionCounts: {
    needs_data: 21,
    missing_image: 3,
    missing_brand: 4,
    missing_barcode_69: 12,
    missing_shipping_weight: 7,
    missing_dimensions: 8,
    pending_review: 2,
  },
  recentProducts: [{ id: 360, sku: 'MF414A', name: 'Product 360', updated_at: '2026-10-30 12:00:00' }],
  canOperate: true,
}

beforeEach(() => vi.clearAllMocks())

describe('Products/Dashboard R1–R4', () => {
  it('uses read-only real counts with explicit Woo snapshot semantics', () => {
    const wrapper = mount(Dashboard, { props })
    const text = wrapper.text()
    expect(text).toContain('产品管理工作台')
    expect(text).toContain('116')
    expect(text).toContain('ERP 已启用')
    expect(text).toContain('90')
    expect(text).toContain('Woo 已发布 · 最近快照')
    expect(text).toContain('70')
    expect(text).toContain('资料需补齐')
    expect(text).toContain('21')
    expect(text).not.toContain('产品销售图表')
    expect(text).toContain('未知库存不会被当作 0')
  })

  it('links every attention count to the same canonical list filter', () => {
    const wrapper = mount(Dashboard, { props })
    for (const key of ['needs_data', 'missing_image', 'missing_brand',
      'missing_barcode_69', 'missing_shipping_weight', 'missing_dimensions', 'pending_review']) {
      expect(wrapper.find('a[href="/products/list?attention=' + key + '"]').exists()).toBe(true)
    }
    expect(wrapper.find('a[href="/products/360"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/products/360/edit"]').exists()).toBe(true)
  })

  it('searches SKU/name/barcode via existing product grid, not a new endpoint', async () => {
    const wrapper = mount(Dashboard, { props })
    await wrapper.get('input[type="search"]').setValue(' 6901234567890 ')
    await wrapper.get('form[role="search"]').trigger('submit')
    expect(router.get).toHaveBeenCalledWith('/products/list', { search: '6901234567890' })
  })

  it('hides mutating actions for product view-only users', () => {
    const wrapper = mount(Dashboard, { props: { ...props, canOperate: false } })
    expect(wrapper.find('a[href="/products/create"]').exists()).toBe(false)
    expect(wrapper.find('a[href="/products/import"]').exists()).toBe(false)
    expect(wrapper.find('a[href="/products/barcode-69/import"]').exists()).toBe(false)
    expect(wrapper.find('a[href="/products/360/edit"]').exists()).toBe(false)
    expect(wrapper.find('a[href="/products/360"]').exists()).toBe(true)
  })

  it('places the order-detail-style sticky navigation before the centered content', async () => {
    const wrapper = mount(Dashboard, { props })
    const rail = wrapper.get('aside.product-home-nav-rail')
    expect(rail.attributes('aria-label')).toBe('产品模块快捷定位')
    expect(wrapper.get('.product-home-layout').element.firstElementChild).toBe(rail.element)
    expect(wrapper.findAll('button.product-home-nav-item')).toHaveLength(4)
    for (const id of ['product-section-overview', 'product-section-shortcuts',
      'product-section-attention', 'product-section-recent']) {
      expect(wrapper.find('#' + id).exists()).toBe(true)
    }

    const scrollIntoView = vi.fn()
    const el = wrapper.get('#product-section-attention').element
    Object.defineProperty(el, 'scrollIntoView', { value: scrollIntoView, configurable: true })
    await wrapper.findAll('button.product-home-nav-item')[2].trigger('click')
    expect(scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'start' })
    expect(wrapper.findAll('button.product-home-nav-item')[2].attributes('aria-current')).toBe('location')
  })

  it('renders honest empty state without fake product suggestions', () => {
    const wrapper = mount(Dashboard, { props: {
      ...props, totalProducts: 0, recentProducts: [],
      metrics: { erpActive: 0, wooPublishedSnapshot: 0, needsData: 0, pendingReview: 0 },
      attentionCounts: Object.fromEntries(Object.keys(props.attentionCounts).map(key => [key, 0])),
    } })
    expect(wrapper.text()).toContain('暂无产品记录')
    expect(wrapper.text()).not.toContain('暂无数据（发布状态待接入）')
  })
})
