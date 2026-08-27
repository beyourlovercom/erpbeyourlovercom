import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Edit from './Edit.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { patch: vi.fn(), delete: vi.fn() },
  usePage: () => ({ url: '/products/1/edit', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { name: 'AuthenticatedLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
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

const inactiveProduct = {
  ...manualProduct, id: 3, sku: 'INA-001', name: 'Inactive Product', is_active: false,
}

describe('Products/Edit V2', () => {
  it('renders page title and subtitle', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.text()).toContain('编辑产品')
    expect(wrapper.text()).toContain('管理产品资料、物流属性与会计参考成本')
  })

  it('renders breadcrumb with 产品列表 label', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const layout = wrapper.findComponent({ name: 'AuthenticatedLayout' })
    const crumbs = layout.props('breadcrumbs') as Array<string | { label: string; href?: string }>
    const labels = crumbs.map(c => (typeof c === 'string' ? c : c.label))
    const hrefs = crumbs.map(c => (typeof c === 'string' ? undefined : c.href))
    expect(labels).toContain('BYL ERP')
    expect(labels).toContain('产品')
    expect(labels).toContain('产品列表')
    expect(hrefs).toContain('/products/list')
  })

  it('shows identity strip with SKU, source, Woo ID and status', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const strip = wrapper.find('.erp-identity-strip')
    expect(strip.exists()).toBe(true)
    expect(strip.text()).toContain('TEST-SKU')
    expect(strip.text()).toContain('WooCommerce')
    expect(strip.text()).toContain('9001')
    expect(strip.text()).toContain('启用')
  })

  it('shows 手动 source in identity strip for manual products', () => {
    const wrapper = mount(Edit, { props: { product: manualProduct } })
    const strip = wrapper.find('.erp-identity-strip')
    expect(strip.text()).toContain('手动')
    expect(strip.text()).toContain('MAN-001')
    expect(strip.text()).toContain('—')
  })

  it('shows inactive status chip for inactive product', () => {
    const wrapper = mount(Edit, { props: { product: inactiveProduct } })
    const chip = wrapper.find('.erp-status-chip.inactive')
    expect(chip.exists()).toBe(true)
    expect(chip.text()).toContain('停用')
  })

  it('identity strip source fields are not editable inputs', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const strip = wrapper.find('.erp-identity-strip')
    // No text inputs inside identity strip
    expect(strip.find('input').exists()).toBe(false)
  })

  it('shows 保存产品 primary submit button', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.find('button[type="submit"]').text()).toBe('保存产品')
  })

  it('shows 取消 secondary link back to product list', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const cancelLinks = wrapper.findAll('a[href="/products/list"]')
    expect(cancelLinks.length).toBeGreaterThan(0)
  })

  it('renders edit form pre-filled with product name', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const nameInput = wrapper.find('#edit-name')
    expect((nameInput.element as HTMLInputElement).value).toBe('Test Product')
  })

  it('pre-fills unit and country_of_origin for manual product', () => {
    const wrapper = mount(Edit, { props: { product: manualProduct } })
    const unitInput = wrapper.find('#edit-unit')
    const countryInput = wrapper.find('#edit-country')
    expect((unitInput.element as HTMLInputElement).value).toBe('pcs')
    expect((countryInput.element as HTMLInputElement).value).toBe('CN')
  })

  it('shows all card section headings', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.text()).toContain('基本信息')
    expect(wrapper.text()).toContain('销售信息')
    expect(wrapper.text()).toContain('物流属性')
    expect(wrapper.text()).toContain('海关与产地')
    expect(wrapper.text()).toContain('成本参考')
    expect(wrapper.text()).toContain('内部备注')
  })

  it('shows accounting helper copy in 成本参考 card', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.text()).toContain('Accounting')
    expect(wrapper.text()).toContain('历史会计成本')
  })

  it('renders status segmented control (not a native checkbox)', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
    const statusButtons = wrapper.findAll('.erp-segmented-option')
    expect(statusButtons.length).toBeGreaterThanOrEqual(2)
  })

  it('active segmented option reflects product.is_active', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const selectedBtn = wrapper.find('.erp-segmented-option.selected')
    expect(selectedBtn.exists()).toBe(true)
    expect(selectedBtn.text()).toContain('启用')
  })

  it('shows 体积重量参考 read-only field labeled as reference value', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.text()).toContain('体积重量参考')
    expect(wrapper.text()).toContain('参考/计算值')
  })

  it('danger zone is present and separated from the action bar', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const dangerZone = wrapper.find('.erp-danger-zone')
    expect(dangerZone.exists()).toBe(true)
    expect(dangerZone.text()).toContain('删除此产品')
    expect(dangerZone.text()).toContain('删除产品')
    // Save button is NOT inside danger zone
    expect(dangerZone.find('button[type="submit"]').exists()).toBe(false)
  })

  it('danger zone shows confirmation dialog on delete click', async () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    const deleteBtn = wrapper.find('.erp-btn-danger')
    await deleteBtn.trigger('click')
    expect(wrapper.text()).toContain('确认删除')
    expect(wrapper.text()).toContain('取消')
  })

  it('cancel in confirmation hides the confirm dialog', async () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    await wrapper.find('.erp-btn-danger').trigger('click')
    expect(wrapper.text()).toContain('确认删除')
    const cancelBtn = wrapper.findAll('button').find(b => b.text().includes('取消') && b.attributes('type') !== 'submit')
    expect(cancelBtn).toBeDefined()
    await cancelBtn!.trigger('click')
    expect(wrapper.text()).toContain('删除此产品')
    expect(wrapper.text()).not.toContain('确认删除「')
  })

  it('uses erp-form-card class for card sections', () => {
    const wrapper = mount(Edit, { props: { product: wooProduct } })
    expect(wrapper.findAll('.erp-form-card').length).toBeGreaterThanOrEqual(6)
  })
})
