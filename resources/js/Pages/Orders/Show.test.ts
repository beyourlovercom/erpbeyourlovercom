import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { post: vi.fn() },
  useForm: (initial: Record<string, unknown>) => {
    const data = { ...initial }
    return {
      ...data,
      processing: false,
      errors: {},
      patch: vi.fn(),
      reset: vi.fn(),
      clearErrors: vi.fn(),
    }
  },
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
  erp_order_id: '007-0908-c1',
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
  currency: 'USD',
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
  operational_markers: [],
  procurement_order: null,
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
    expect(strip.text()).toContain('007-0908-c1')
    expect(wrapper.text()).not.toContain('BIZ-001')
    expect(wrapper.text()).not.toContain('INT-001')
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

  it('does not expose source or technical order numbers in view mode', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('.ord-details-disclosure').exists()).toBe(false)
    expect(wrapper.text()).toContain('007-0908-c1')
    expect(wrapper.text()).not.toContain('BIZ-001')
    expect(wrapper.text()).not.toContain('INT-001')
  })

  it('renders five business cards: fulfillment, order info, address, items, note', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.text()).toContain('履约状态')
    expect(wrapper.text()).toContain('订单信息')
    expect(wrapper.text()).toContain('收件与地址')
    expect(wrapper.text()).toContain('订单商品')
    expect(wrapper.text()).toContain('内部备注')
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

  it('shows procurement creation only for a stockout order without a linked procurement order', () => {
    const wrapper = mount(Show, { props: { order: { ...baseOrder, operational_markers: ['stockout'] }, audit, canCreateProcurement: true } })
    expect(wrapper.find('a[href="/purchase/orders/from-order/1/create"]').text()).toBe('创建采购订单')
    expect(wrapper.find('.page-header-actions').text()).not.toContain('查看采购订单')
  })

  it('replaces procurement creation with the linked procurement action', () => {
    const wrapper = mount(Show, { props: { order: { ...baseOrder, operational_markers: ['stockout'], procurement_order: { id: 4, procurement_number: 'PO-20260904-0001' } }, audit, canCreateProcurement: true } })
    expect(wrapper.find('a[href="/purchase/orders/from-order/1/create"]').exists()).toBe(false)
    expect(wrapper.find('a[href="/purchase/orders/4"]').text()).toContain('PO-20260904-0001')
  })

  it('does not show procurement creation for a non-stockout order', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canCreateProcurement: false } })
    expect(wrapper.find('a[href="/purchase/orders/from-order/1/create"]').exists()).toBe(false)
  })

  // View Mode tests
  it('shows ISO currency beside every item unit price', () => {
    const eurOrder = { ...baseOrder, currency: 'EUR', items: [{ ...baseOrder.items[0], unit_price: '29.00' }] }
    const wrapper = mount(Show, { props: { order: eurOrder, audit } })
    expect(wrapper.text()).toContain('订单币种')
    expect(wrapper.text()).toContain('EUR 29.00')
  })

  it('Edit Mode exposes the canonical order currency selector', async () => {
    const eurOrder = { ...baseOrder, currency: 'EUR' }
    const wrapper = mount(Show, { props: { order: eurOrder, audit } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    const selects = wrapper.findAll('select')
    expect(selects.some(select => (select.element as HTMLSelectElement).value === 'EUR')).toBe(true)
    expect(wrapper.text()).toContain('单价（EUR）')
  })

  it('defaults to View Mode: no active submit button', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('button[type="submit"]').exists()).toBe(false)
  })

  it('defaults to View Mode: 编辑订单 button is visible', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    expect(editBtn).toBeTruthy()
  })

  it('defaults to View Mode: no active date input', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('input[type="date"]').exists()).toBe(false)
  })

  it('defaults to View Mode: no active textarea for note', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('textarea').exists()).toBe(false)
  })

  it('defaults to View Mode: no SKU combobox visible', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('.sku-combobox-stub').exists()).toBe(false)
  })

  it('defaults to View Mode: 保存订单 action not shown', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.text()).not.toContain('保存订单')
  })

  // Edit Mode tests
  it('clicking 编辑订单 enters Edit Mode: shows 保存订单 and 取消编辑', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    expect(wrapper.text()).toContain('保存订单')
    expect(wrapper.text()).toContain('取消编辑')
  })

  it('Edit Mode shows date input', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    expect(wrapper.find('input[type="date"]').exists()).toBe(true)
  })

  it('Edit Mode shows textarea for note', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    expect(wrapper.find('textarea').exists()).toBe(true)
  })

  it('Edit Mode shows SKU combobox for items', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    expect(wrapper.find('.sku-combobox-stub').exists()).toBe(true)
  })

  it('clicking 取消编辑 returns to View Mode', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    const cancelBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '取消编辑')
    await cancelBtn!.trigger('click')
    expect(wrapper.find('input[type="date"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('保存订单')
    const editBtnAgain = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    expect(editBtnAgain).toBeTruthy()
  })

  it('renders explicit operational markers without replacing order status', async () => {
    const marked = { ...baseOrder, operational_markers: ['exception'] }
    const wrapper = mount(Show, { props: { order: marked, audit, canMarkOperational: true } })
    expect(wrapper.text()).toContain('业务标记')
    expect(wrapper.text()).toContain('✓ 异常')
    expect(wrapper.text()).toContain('待发货')
  })

  // Immutable identity always read-only
  it('identity fields are immutable in View Mode (disabled inputs)', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    const disabledInputs = wrapper.findAll('input[disabled]')
    const values = disabledInputs.map(i => (i.element as HTMLInputElement).value)
    expect(values).toContain('007-0908-c1')
    expect(values).toContain('客户订单')
    expect(values).toContain('手动创建')
    expect(values).toContain('Test Owner')
  })

  it('identity fields remain immutable in Edit Mode (disabled inputs)', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    const disabledInputs = wrapper.findAll('input[disabled]')
    const values = disabledInputs.map(i => (i.element as HTMLInputElement).value)
    expect(values).toContain('007-0908-c1')
    expect(values).toContain('客户订单')
    expect(values).toContain('手动创建')
    expect(values).toContain('Test Owner')
  })

  // ERP V2 VI contract
  it('uses erp-form-page outer container', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('.erp-form-page').exists()).toBe(true)
  })

  it('uses erp-form-card for section cards', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    const cards = wrapper.findAll('.erp-form-card')
    expect(cards.length).toBeGreaterThanOrEqual(5)
  })

  it('uses erp-form-label — no mvp-label class', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('.erp-form-label').exists()).toBe(true)
    expect(wrapper.find('.mvp-label').exists()).toBe(false)
  })

  it('uses erp-btn — no mvp-button class', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.find('.erp-btn').exists()).toBe(true)
    expect(wrapper.find('.mvp-button').exists()).toBe(false)
  })

  it('View Mode shows erp-readonly-input for mutable fields', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    const readonlyDivs = wrapper.findAll('.erp-readonly-input')
    expect(readonlyDivs.length).toBeGreaterThan(0)
  })

  it('Edit Mode shows erp-input controls — no mvp-input class', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    expect(wrapper.find('.mvp-input').exists()).toBe(false)
    expect(wrapper.findAll('.erp-input').length).toBeGreaterThan(0)
  })

  // Fulfillment and audit
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

  it('shows address fields labels', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    expect(wrapper.text()).toContain('收件人姓名')
    expect(wrapper.text()).toContain('联系电话')
  })

  it('existing item product_id preserved for existing order items', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    // item.product_id = 5 from baseOrder — shown in SKU field (view mode shows SKU text)
    expect(wrapper.text()).toContain('SKU-001')
  })
})
