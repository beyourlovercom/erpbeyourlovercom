import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { post: vi.fn() },
  useForm: (initial: Record<string, unknown>) => ({
    ...initial,
    processing: false,
    errors: {},
    patch: vi.fn(),
  }),
  usePage: () => ({ url: '/customers/orders/1', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

vi.mock('../../Components/Products/SkuCombobox.vue', () => ({
  default: { props: ['modelValue', 'productId'], template: '<input class="sku-combobox-stub" />' },
}))

const baseOrder = {
  id: 1,
  internal_order_number: 'INT-001',
  external_order_number: 'BIZ-001',
  source_type: 'manual',
  source_label: '手动创建',
  order_kind: 'customer',
  order_kind_label: '客户订单',
  order_date: '2026-08-25',
  order_status: 'pending_shipment',
  order_status_label: '待发货',
  order_note: '测试备注',
  owner: { id: 1, name: 'Test Owner' },
  owner_label: 'Owner(Test Owner)',
  address: {
    recipient_name: 'Test Recipient',
    phone: '+1 555 0100',
    country: 'US',
    province_state: 'CA',
    city: 'Los Angeles',
    address_line_1: '100 Main St',
    address_line_2: '',
    postal_code: '90001',
  },
  items: [
    { id: 10, product_id: 5, product_summary: 'Test Product', sku: 'SKU-001', quantity: 2, unit_price: '19.99' },
  ],
  shipment: { shipment_status: 'pending', carrier: null, tracking_number: null, shipped_at: null },
  manual_sync: null,
}

const kolOrder = { ...baseOrder, order_kind: 'kol_fulfillment', order_kind_label: '网红履约订单' }

const audit = [
  { action: 'created', actor_id: 1, created_at: '2026-08-25T10:00:00Z' },
]

describe('Orders/Show V2', () => {
  it('renders identity strip with order number', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    const strip = wrapper.find('.ord-identity-strip')
    expect(strip.exists()).toBe(true)
    expect(strip.text()).toContain('BIZ-001')
  })

  it('shows order kind label in identity strip', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('.ord-identity-strip').text()).toContain('客户订单')
  })

  it('shows source label in identity strip', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('.ord-identity-strip').text()).toContain('手动创建')
  })

  it('shows order status label in identity strip', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('.ord-identity-strip').text()).toContain('待发货')
  })

  it('shows owner in identity strip', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('.ord-identity-strip').text()).toContain('Test Owner')
  })

  it('hides internal_order_number in disclosure by default', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('.ord-details-disclosure').exists()).toBe(true)
    expect(wrapper.find('.ord-details-value').text()).toBe('INT-001')
  })

  it('renders five business cards: fulfillment, order info, address, items, note', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.text()).toContain('履约状态')
    expect(wrapper.text()).toContain('订单信息')
    expect(wrapper.text()).toContain('收件与地址')
    expect(wrapper.text()).toContain('订单商品')
    expect(wrapper.text()).toContain('内部备注')
  })

  it('shows 保存订单 as the only primary submit action', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    const submitBtn = wrapper.find('button[type="submit"]')
    expect(submitBtn.exists()).toBe(true)
    expect(submitBtn.text()).toBe('保存订单')
  })

  it('does not introduce a delete action', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.text()).not.toContain('删除订单')
    expect(wrapper.text()).not.toContain('Danger Zone')
  })

  it('shows 返回列表 link for customers', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('a[href="/customers/orders"]').exists()).toBe(true)
  })

  it('shows 返回列表 link for kol', () => {
    const wrapper = mount(Show, { props: { order: kolOrder, audit } })
    expect(wrapper.find('a[href="/kol/orders"]').exists()).toBe(true)
  })

  it('renders editable order date field', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('input[type="date"]').exists()).toBe(true)
  })

  it('renders identity fields as disabled (immutable)', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    const disabledInputs = wrapper.findAll('input[disabled]')
    const values = disabledInputs.map(i => (i.element as HTMLInputElement).value)
    expect(values).toContain('BIZ-001')
    expect(values).toContain('客户订单')
    expect(values).toContain('手动创建')
    expect(values).toContain('Test Owner')
  })

  it('renders SKU combobox for editable items', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('.sku-combobox-stub').exists()).toBe(true)
  })

  it('shows existing item sku combobox in items card (product relation preserved)', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('.sku-combobox-stub').exists()).toBe(true)
    expect(wrapper.text()).toContain('订单商品')
  })

  it('renders note textarea', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('textarea').exists()).toBe(true)
  })

  it('shows fulfillment status in fulfillment card', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.text()).toContain('待发货')
  })

  it('shows reopen button when tracking number is present', () => {
    const orderWithTracking = {
      ...baseOrder,
      shipment: { shipment_status: 'shipped', carrier: 'DHL', tracking_number: 'TRACK123', shipped_at: null },
    }
    const wrapper = mount(Show, { props: { order: orderWithTracking, audit } })
    const reopenBtn = wrapper.findAll('button[type="button"]').find(b => b.text().includes('重开发货'))
    expect(reopenBtn).toBeTruthy()
  })

  it('does not show reopen button without tracking number', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    const reopenBtn = wrapper.findAll('button[type="button"]').find(b => b.text().includes('重开发货'))
    expect(reopenBtn).toBeFalsy()
  })

  it('shows audit log section', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.text()).toContain('操作审计')
    expect(wrapper.text()).toContain('created')
  })

  it('shows address fields pre-filled', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.text()).toContain('收件人姓名')
    expect(wrapper.text()).toContain('联系电话')
  })
})
