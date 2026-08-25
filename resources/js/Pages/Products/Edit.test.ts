import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Edit from './Edit.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { patch: vi.fn() },
  usePage: () => ({ url: '/products/1/edit', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

const wooProduct = {
  id: 1, woocommerce_product_id: 9001, source_type: 'woocommerce', product_type: 'simple',
  sku: 'TEST-SKU', gtin: null, name: 'Test Product', unit: null, is_active: true,
  weight_kg: null, sale_price: null, regular_price: '89.00',
  shipping_weight_kg: null, length_cm: null, width_cm: null, height_cm: null,
  country_of_origin: null, hs_code: null,
  standard_production_cost: null, standard_packaging_cost: null, standard_purchase_cost: null,
  cost_currency: null, cost_note: null, notes: null,
}

const manualProduct = {
  id: 2, woocommerce_product_id: null, source_type: 'manual', product_type: 'simple',
  sku: 'MAN-001', gtin: null, name: 'Manual Product', unit: 'pcs', is_active: true,
  weight_kg: null, sale_price: null, regular_price: null,
  shipping_weight_kg: null, length_cm: null, width_cm: null, height_cm: null,
  country_of_origin: 'CN', hs_code: null,
  standard_production_cost: '12.5000', standard_packaging_cost: null, standard_purchase_cost: null,
  cost_currency: 'CNY', cost_note: null, notes: null,
}

describe('Products/Edit', () => {
  it('renders edit form pre-filled with product data', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.text()).toContain('编辑产品')
    const skuInput = wrapper.find('input[type="text"]')
    expect((skuInput.element as HTMLInputElement).value).toBe('TEST-SKU')
  })

  it('shows source type as read-only metadata', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.text()).toContain('WooCommerce')
    expect(wrapper.text()).toContain('9001')
  })

  it('shows 手动 for manual products', () => {
    const wrapper = mount(Edit, { props: { product: manualProduct } })
    expect(wrapper.text()).toContain('手动')
  })

  it('shows 保存更改 submit button', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.find('button[type="submit"]').text()).toBe('保存更改')
  })

  it('shows link back to product list', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.find('a[href="/products/list"]').exists()).toBe(true)
  })

  it('shows accounting/logistics section headings', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.text()).toContain('物流尺寸')
    expect(wrapper.text()).toContain('海关信息')
    expect(wrapper.text()).toContain('标准成本（参考值）')
  })

  it('pre-fills unit and country_of_origin from product', () => {
    const wrapper = mount(Edit, { props: { product: manualProduct } })
    const inputs = wrapper.findAll('input[type="text"]')
    const values = inputs.map(i => (i.element as HTMLInputElement).value)
    expect(values).toContain('pcs')
    expect(values).toContain('CN')
  })
})
