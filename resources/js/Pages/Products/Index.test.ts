import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { get: vi.fn(), delete: vi.fn() },
  usePage: () => ({ url: '/products/list', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

const emptyPage = { data: [], current_page: 1, last_page: 1, total: 0, per_page: 50, from: null, to: null, links: [] }

const makeProduct = (overrides = {}) => ({
  id: 1, woocommerce_product_id: 9001, source_type: 'woocommerce', product_type: 'simple',
  sku: null, name: 'Test', unit: null, is_active: true,
  shipping_weight_kg: null, standard_production_cost: null, standard_packaging_cost: null,
  cost_currency: null, sale_price: null, regular_price: '89.00',
  ...overrides,
})

describe('Products/Index', () => {
  it('shows Chinese column headers', () => {
    const wrapper = mount(Index, { props: { products: emptyPage, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('SKU')
    expect(wrapper.text()).toContain('产品名称')
    expect(wrapper.text()).toContain('来源')
    expect(wrapper.text()).toContain('单位')
    expect(wrapper.text()).toContain('运重')
    expect(wrapper.text()).toContain('标准生产成本')
    expect(wrapper.text()).toContain('标准包材成本')
    expect(wrapper.text()).toContain('成本币种')
    expect(wrapper.text()).toContain('售价')
    expect(wrapper.text()).toContain('原价')
    expect(wrapper.text()).toContain('状态')
    expect(wrapper.text()).toContain('操作')
  })

  it('renders blank values as —', () => {
    const product = makeProduct()
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('—')
    expect(wrapper.text()).toContain('89.00')
  })

  it('shows empty state when no products', () => {
    const wrapper = mount(Index, { props: { products: emptyPage, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('暂无产品')
  })

  it('shows total product count', () => {
    const page = { ...emptyPage, total: 116 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('116')
  })

  it('renders SKU as Product360 link when SKU exists', () => {
    const product = makeProduct({ sku: 'TEST-SKU' })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: true } })
    const link = wrapper.find('a[href="/products/1"]')
    expect(link.exists()).toBe(true)
    expect(link.text()).toBe('TEST-SKU')
  })

  it('renders SKU as Product360 link when canOperate is false', () => {
    const product = makeProduct({ sku: 'TEST-SKU' })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.find('a[href="/products/1"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('TEST-SKU')
  })

  it('shows 新增产品 and 导入 CSV action buttons when canOperate', () => {
    const wrapper = mount(Index, { props: { products: emptyPage, filters: {}, canOperate: true } })
    expect(wrapper.text()).toContain('新增产品')
    expect(wrapper.text()).toContain('导入 CSV')
  })

  it('hides 新增产品 and 导入 CSV when view-only', () => {
    const wrapper = mount(Index, { props: { products: emptyPage, filters: {}, canOperate: false } })
    expect(wrapper.text()).not.toContain('新增产品')
    expect(wrapper.text()).not.toContain('导入 CSV')
  })

  it('shows 编辑 and 删除 row actions when canOperate', () => {
    const product = makeProduct({ id: 5 })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: true } })
    expect(wrapper.find('a[href="/products/5/edit"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/products/5"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('删除')
  })

  it('hides 编辑 and 删除 row actions when view-only', () => {
    const product = makeProduct({ id: 5 })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.find('a[href="/products/5/edit"]').exists()).toBe(false)
    expect(wrapper.find('a[href="/products/5"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('编辑')
    expect(wrapper.text()).not.toContain('删除')
  })

  it('shows delete confirmation dialog when 删除 clicked', async () => {
    const product = makeProduct({ id: 5 })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: true } })
    const deleteBtn = wrapper.findAll('button').find(b => b.text() === '删除')
    expect(deleteBtn).toBeDefined()
    await deleteBtn!.trigger('click')
    expect(wrapper.text()).toContain('确认删除此产品')
  })

  it('shows source label Woo for woocommerce source', () => {
    const product = makeProduct({ source_type: 'woocommerce' })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('Woo')
  })

  it('shows source label 手动 for manual source', () => {
    const product = makeProduct({ source_type: 'manual', woocommerce_product_id: null })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('手动')
  })

  it('shows 启用 for active products', () => {
    const product = makeProduct({ is_active: true })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('启用')
  })

  it('shows 停用 for inactive products', () => {
    const product = makeProduct({ is_active: false })
    const page = { ...emptyPage, data: [product], total: 1 }
    const wrapper = mount(Index, { props: { products: page, filters: {}, canOperate: false } })
    expect(wrapper.text()).toContain('停用')
  })
})
