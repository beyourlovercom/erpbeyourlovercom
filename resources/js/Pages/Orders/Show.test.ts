import { mount, flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import Show from './Show.vue'
import { router } from '@inertiajs/vue3'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { post: vi.fn(), delete: vi.fn() },
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

const splitOrder = {
  ...baseOrder,
  shipment: null,
  fulfillment: {
    is_split: true,
    aggregate_status: 'partially_shipped',
    aggregate_status_label: '部分发货',
    segment_count: 2,
    original_segment_count: 2,
    replacement_count: 0,
    fulfilled_count: 1,
    has_exception: false,
    original_logistics_started: true,
    segments: [
      {
        order_id: 11,
        erp_order_id: '007-0908-c1A',
        suffix: 'A',
        role: 'original' as const,
        role_label: '原始履约',
        reason: null,
        reason_label: null,
        origin_fulfillment_id: null,
        origin_erp_order_id: null,
        can_reallocate: false,
        items: [{ id: 101, sku: 'GIFT-RED', product_summary: 'Gift Red', quantity: 1 }],
        shipment: { shipment_status: 'shipped', status_label: '运输中', warehouse_id: 1, warehouse_name: '美西 MorePlus 仓', carrier: 'USPS', service: 'Ground', tracking_number: 'TRACK-A', shipped_at: '2026-09-18T01:00:00Z' },
      },
      {
        order_id: 12,
        erp_order_id: '007-0908-c1B',
        suffix: 'B',
        role: 'original' as const,
        role_label: '原始履约',
        reason: null,
        reason_label: null,
        origin_fulfillment_id: null,
        origin_erp_order_id: null,
        can_reallocate: true,
        items: [{ id: 102, sku: 'SKU-B', product_summary: 'Product B', quantity: 2 }],
        shipment: { shipment_status: 'pending', status_label: '待发货', warehouse_id: 2, warehouse_name: '国内工厂', carrier: '4PX', service: '普通', tracking_number: null, shipped_at: null },
      },
    ],
  },
}

const audit = [
  { action: 'created', actor_id: 1, created_at: '2026-08-25T10:00:00Z' },
]

describe('Orders/Show V2', () => {
  beforeEach(() => {
    Element.prototype.scrollIntoView = vi.fn()
    const memory = new Map<string,string>()
    Object.defineProperty(window,'localStorage', {
      configurable:true,
      value: {
        getItem:(key:string) => memory.get(key) ?? null,
        setItem:(key:string,value:string) => { memory.set(key,String(value)) },
        removeItem:(key:string) => { memory.delete(key) },
        clear:() => { memory.clear() },
      },
    })
  })

  it('reveals trash only inside editable details and requires confirmation', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true, canTrashOrder: true } })
    expect(wrapper.find('[data-testid="move-to-trash"]').exists()).toBe(false)
    const edit = wrapper.findAll('.page-header-actions button').find(btn => btn.text().includes('编辑订单'))
    expect(edit).toBeDefined()
    await edit!.trigger('click')
    expect(wrapper.get('[data-testid="move-to-trash"]').text()).toContain('移至回收站')
    await wrapper.get('[data-testid="move-to-trash"]').trigger('click')
    expect(wrapper.get('[role="dialog"]').text()).toContain('007-0908-c1')
    const confirm = wrapper.findAll('[role="dialog"] button').find(btn => btn.text().includes('确认移至回收站'))
    await confirm!.trigger('click')
    expect(router.delete).toHaveBeenCalledWith('/orders/1/trash', expect.any(Object))
  })

  it('never reveals trash to a read-only order viewer', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: false, canTrashOrder: false } })
    expect(wrapper.findAll('.page-header-actions button').some(btn => btn.text().includes('编辑订单'))).toBe(false)
    expect(wrapper.findAll('.page-header-actions button').some(btn => btn.text().includes('保存订单'))).toBe(false)
    expect(wrapper.find('[data-testid="move-to-trash"]').exists()).toBe(false)
  })
  it('renders identity strip with order number', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const strip = wrapper.find('.ord-identity-strip')
    expect(strip.exists()).toBe(true)
    expect(strip.text()).toContain('007-0908-c1')
    expect(wrapper.text()).not.toContain('BIZ-001')
    expect(wrapper.text()).not.toContain('INT-001')
  })

  it('shows order kind label in identity strip', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.ord-identity-strip').text()).toContain('客户订单')
  })

  it('shows source label in identity strip', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.ord-identity-strip').text()).toContain('手动创建')
  })

  it('shows order status label in identity strip', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.ord-identity-strip').text()).toContain('待发货')
  })

  it('shows owner in identity strip', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.ord-identity-strip').text()).toContain('Test Owner')
  })

  it('does not expose source or technical order numbers in view mode', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.ord-details-disclosure').exists()).toBe(false)
    expect(wrapper.text()).toContain('007-0908-c1')
    expect(wrapper.text()).not.toContain('BIZ-001')
    expect(wrapper.text()).not.toContain('INT-001')
  })

  it('renders five business cards: fulfillment, order info, address, items, note', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.text()).toContain('履约状态')
    expect(wrapper.text()).toContain('订单信息')
    expect(wrapper.text()).toContain('收件与地址')
    expect(wrapper.text()).toContain('订单商品')
    expect(wrapper.text()).toContain('内部备注')
  })

  it('centralizes every shipping CTA in the fulfillment status action area', () => {
    const shippedOrder = {
      ...baseOrder,
      shipment: { shipment_status: 'shipped', warehouse_id: 1, warehouse_name: '中国工厂', carrier: '4PX', service: 'SD标准挂号-带电', tracking_number: '4PX3003', shipped_at: '2026-09-01T08:00:00Z' },
    }
    const wrapper = mount(Show, { props: { order: shippedOrder, audit, canOperateLogistics: true, canCreateReplacement: true } })
    const actions = wrapper.get('[data-testid="fulfillment-actions"]')
    expect(actions.text()).toContain('重开发货')
    expect(actions.text()).toContain('新增补发')
    expect(wrapper.get('.page-header-actions').text()).not.toContain('履约发货')
    expect(wrapper.find('.ord-fulfillment-action').exists()).toBe(false)
  })

  it('keeps split fulfillment shipping and reopen CTAs out of row actions', () => {
    const wrapper = mount(Show, { props: { order: splitOrder, audit, canOperateLogistics: true, canCreateReplacement: true } })
    const actions = wrapper.get('[data-testid="fulfillment-actions"]')
    expect(actions.text()).toContain('B · 履约发货')
    expect(actions.text()).toContain('A · 重开发货')
    expect(actions.text()).toContain('新增补发')
    const rowActions = wrapper.findAll('.ord-fulfillment-row-actions').map(row => row.text()).join(' ')
    expect(rowActions).not.toContain('履约发货')
    expect(rowActions).not.toContain('重开发货')
  })

  it('auto-fills USPS Priority when the selected fulfillment warehouse is in the US', async () => {
    const order = { ...baseOrder, shipment: { ...baseOrder.shipment, warehouse_id: 1, warehouse_name: '美西 MorePlus 仓' } }
    const wrapper = mount(Show, { props: {
      order,
      audit,
      canOperateLogistics: true,
      warehouses: [{ id: 1, slug: 'us-la', name: '美西 MorePlus 仓', code: 'US-LA', country_code: 'US' }],
      warehouseRouting: { 1: { carrier: 'USPS', service: 'USPS Priority Mail', carrier_service_id: 101, automatic: true } },
      carrierOptions: [{ value: 'USPS', label: 'USPS / 美国邮政', default_service_id: 101, default_service_value: 'USPS Priority Mail', services: [{ id: 101, key: 'priority_mail', value: 'USPS Priority Mail', label: 'Priority Mail / 优先件', name_en: 'Priority Mail', name_zh: '优先件' }] }],
    } })
    await wrapper.find('[data-testid="fulfillment-actions"]').findAll('button').find(btn => btn.text().includes('履约发货'))!.trigger('click')
    const selects = wrapper.find('.ord-shipment-form').findAll('select')
    expect((selects[0].element as HTMLSelectElement).value).toBe('1')
    expect((selects[1].element as HTMLSelectElement).value).toBe('USPS')
    expect((selects[2].element as HTMLSelectElement).value).toBe('USPS Priority Mail')
  })

  it('offers direct fulfillment execution on a pending single order', async () => {
    const wrapper = mount(Show, { props: {
      order: baseOrder,
      audit,
      canOperateLogistics: true,
      warehouses: [{ id: 1, slug: 'us-la', name: '美西 MorePlus 仓', code: 'US-LA', country_code: 'US' }],
      carrierOptions: [{ value: 'USPS', label: 'USPS / 美国邮政', default_service_id: 101, default_service_value: 'USPS Priority Mail', services: [{ id: 101, key: 'priority_mail', value: 'USPS Priority Mail', label: 'Priority Mail / 优先件', name_en: 'Priority Mail', name_zh: '优先件' }] }],
    } })
    const button = wrapper.find('[data-testid="fulfillment-actions"]').findAll('button').find(btn => btn.text().includes('履约发货'))
    expect(button).toBeTruthy()
    await button!.trigger('click')
    expect(wrapper.text()).toContain('填写物流并发货')
    expect(wrapper.find('.ord-shipment-form').exists()).toBe(true)
    expect(wrapper.text()).toContain('所有发货、重发、补发操作统一在上方“履约状态”处理')
  })

  it('offers fulfillment execution per pending split segment without changing shipped siblings', async () => {
    const wrapper = mount(Show, { props: {
      order: splitOrder,
      audit,
      canOperateLogistics: true,
      warehouses: [
        { id: 1, slug: 'us-la', name: '美西 MorePlus 仓', code: 'US-LA', country_code: 'US' },
        { id: 2, slug: 'factory', name: '国内工厂', code: 'FACTORY', country_code: 'CN' },
      ],
      carrierOptions: [{ value: '4PX', label: '4PX / 速达', default_service_id: 201, default_service_value: 'SD经济挂号-带电', services: [{ id: 201, key: 'sd_economy_registered_battery', value: 'SD经济挂号-带电', label: 'Economy Registered - Battery / SD经济挂号-带电', name_en: 'Economy Registered - Battery', name_zh: 'SD经济挂号-带电' }] }, { value: 'USPS', label: 'USPS / 美国邮政', default_service_id: 101, default_service_value: 'USPS Priority Mail', services: [{ id: 101, key: 'priority_mail', value: 'USPS Priority Mail', label: 'Priority Mail / 优先件', name_en: 'Priority Mail', name_zh: '优先件' }] }],
    } })
    const shipButtons = wrapper.find('[data-testid="fulfillment-actions"]').findAll('button').filter(btn => btn.text().includes('履约发货'))
    expect(shipButtons).toHaveLength(1)
    expect(wrapper.findAll('button').some(btn => btn.text().includes('重开发货'))).toBe(true)
    await shipButtons[0].trigger('click')
    expect(wrapper.text()).toContain('B · 007-0908-c1B')
    const carrier = wrapper.find('.ord-shipment-form select:nth-of-type(2)')
    expect(wrapper.find('.ord-shipment-form').exists()).toBe(true)
    expect(wrapper.html()).toContain('4PX')
  })

  it('allows only the pending sibling to adjust warehouse after another segment has shipped', async () => {
    const allocationUi = {
      status: 'submitted' as const,
      can_operate: false,
      can_reallocate: false,
      can_partial_reallocate: true,
      logistics_started: true,
      requires_full_flow: false,
      selectable_warehouse_ids: [1, 2, 3],
      recommended_warehouse_ids: {},
      selected_warehouse_ids: {},
      recommended_plan_key: null,
      can_use_recommendation: false,
      full_allocation_url: '/logistics/wait-stock',
    }
    const wrapper = mount(Show, { props: {
      order: splitOrder,
      audit,
      allocationUi,
      warehouses: [
        { id: 1, slug: 'us-la', name: '美西 MorePlus 仓', code: 'US-LA', country_code: 'US' },
        { id: 2, slug: 'factory', name: '国内工厂', code: 'FACTORY', country_code: 'CN' },
        { id: 3, slug: 'us-ny', name: '美东纽约仓', code: 'US-NY', country_code: 'US' },
      ],
    } })
    const adjustButtons = wrapper.findAll('button').filter(btn => btn.text().includes('调整分仓'))
    expect(adjustButtons).toHaveLength(1)
    await adjustButtons[0].trigger('click')
    expect(wrapper.text()).toContain('调整 B 分仓')
    expect(wrapper.text()).toContain('已经发货的 A/B/C 不会被改动')
    expect(wrapper.text()).toContain('美东纽约仓')
    expect(wrapper.find('[role="dialog"][aria-label="调整未发货履约分仓"]').exists()).toBe(true)
  })

  it('asks before changing an unshipped fulfillment segment to an inventory-unknown warehouse', async () => {
    const allocationUi = {
      status: 'submitted' as const, can_operate: false, can_reallocate: false,
      can_partial_reallocate: true, logistics_started: true, requires_full_flow: false,
      selectable_warehouse_ids: [1, 2, 3],
      recommended_warehouse_ids: {}, selected_warehouse_ids: {},
      recommended_plan_key: null, can_use_recommendation: false, full_allocation_url: '/logistics/wait-stock',
    }
    const wrapper = mount(Show, { props: {
      order: splitOrder, audit, allocationUi,
      warehouses: [
        { id: 1, slug: 'us-la', name: '美西 MorePlus 仓', code: 'US-LA', country_code: 'US' },
        { id: 2, slug: 'factory', name: '国内工厂', code: 'FACTORY', country_code: 'CN' },
        { id: 3, slug: 'us-ny', name: '美东纽约仓', code: 'US-NY', country_code: 'US' },
      ],
    }, attachTo: document.body })
    const adjust = wrapper.findAll('button').find(button => button.text() === '调整分仓')
    await adjust!.trigger('click')
    await wrapper.get('[aria-label="调整未发货履约分仓"] select').setValue(3)
    const callsBefore = vi.mocked(router.post).mock.calls.length
    await wrapper.findAll('button').find(button => button.text() === '确认调整分仓')!.trigger('click')
    expect(document.body.textContent).toContain('所选仓库库存不足或未知')
    expect(vi.mocked(router.post).mock.calls.length).toBe(callsBefore)
    ;(document.body.querySelector('.stock-exception-confirm') as HTMLButtonElement).click()
    await nextTick()
    expect(router.post).toHaveBeenCalledWith('/logistics/orders/12/reallocate-pending-fulfillment', {
      warehouse_id: 3, note: null, stock_exception_acknowledged: true,
    }, expect.any(Object))
    wrapper.unmount()
  })

  it('renders split fulfillment aggregation from child shipments instead of the empty parent shipment', () => {
    const wrapper = mount(Show, { props: { order: splitOrder, audit } })
    expect(wrapper.text()).toContain('已拆分 · 2 个履约单')
    expect(wrapper.text()).toContain('部分发货')
    expect(wrapper.text()).toContain('1/2 已进入物流')
    expect(wrapper.text()).toContain('007-0908-c1A')
    expect(wrapper.text()).toContain('美西 MorePlus 仓')
    expect(wrapper.text()).toContain('USPS')
    expect(wrapper.text()).toContain('TRACK-A')
    expect(wrapper.text()).toContain('007-0908-c1B')
    expect(wrapper.text()).toContain('国内工厂')
    expect(wrapper.text()).toContain('待发货')
    expect(wrapper.find('.ord-fulfillment-table').exists()).toBe(true)
    expect(wrapper.find('.ord-inventory-card').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('库存分布')
  })

  it('does not introduce a delete action', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.text()).not.toContain('删除订单')
    expect(wrapper.text()).not.toContain('Danger Zone')
  })

  it('shows 返回列表 link for customers', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('a[href="/customers/orders"]').exists()).toBe(true)
  })

  it('shows 返回列表 link for kol', () => {
    const wrapper = mount(Show, { props: { order: kolOrder, audit } })
    expect(wrapper.find('a[href="/kol/orders"]').exists()).toBe(true)
  })

  it('shows 履约发货 only inside the fulfillment action area for an authorized pending order', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canOperateLogistics: true } })
    const actions = wrapper.get('[data-testid="fulfillment-actions"]')
    expect(actions.text()).toContain('履约发货')
    expect(wrapper.find('a[href="/logistics/pending?order_id=1"]').exists()).toBe(false)
    expect(wrapper.get('.page-header-actions').text()).not.toContain('履约发货')
  })

  it('shows centralized read-only fulfillment access without logistics execution authority', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canOperateLogistics: false } })
    const actions = wrapper.get('[data-testid="fulfillment-actions"]')
    expect(actions.text()).toContain('查看履约')
    expect(actions.text()).not.toContain('履约发货')
    expect(wrapper.find('a[href="/logistics/pending?order_id=1"]').exists()).toBe(false)
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
    const wrapper = mount(Show, { props: { order: eurOrder, audit, canEditOrder: true } })
    expect(wrapper.get('[aria-label="订单信息摘要"]').text()).toContain('币种EUR')
    expect(wrapper.text()).toContain('EUR 29.00')
  })

  it('Edit Mode exposes the canonical order currency selector', async () => {
    const eurOrder = { ...baseOrder, currency: 'EUR' }
    const wrapper = mount(Show, { props: { order: eurOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    const selects = wrapper.findAll('select')
    expect(selects.some(select => (select.element as HTMLSelectElement).value === 'EUR')).toBe(true)
    expect(wrapper.text()).toContain('单价（EUR）')
  })

  it('defaults to View Mode: no active submit button', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('button[type="submit"]').exists()).toBe(false)
  })

  it('defaults to View Mode: 编辑订单 button is visible', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    expect(editBtn).toBeTruthy()
  })

  it('defaults to View Mode: no active date input', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('input[type="date"]').exists()).toBe(false)
  })

  it('defaults to View Mode: no active textarea for note', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('textarea').exists()).toBe(false)
  })

  it('defaults to View Mode: no SKU combobox visible', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.sku-combobox-stub').exists()).toBe(false)
  })

  it('defaults to View Mode: 保存订单 action not shown', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.text()).not.toContain('保存订单')
  })

  // Edit Mode tests
  it('clicking 编辑订单 enters Edit Mode: shows 保存订单 and 取消编辑', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    expect(wrapper.text()).toContain('保存订单')
    expect(wrapper.text()).toContain('取消编辑')
  })

  it('Edit Mode shows date input', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    expect(wrapper.find('input[type="date"]').exists()).toBe(true)
  })

  it('Edit Mode shows textarea for note', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    expect(wrapper.find('textarea').exists()).toBe(true)
  })

  it('Edit Mode shows SKU combobox for items', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    expect(wrapper.find('.sku-combobox-stub').exists()).toBe(true)
  })

  it('clicking 取消编辑 returns to View Mode', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
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
    expect(wrapper.text()).toContain('辅助标记')
    expect(wrapper.text()).toContain('✓ 异常')
    expect(wrapper.text()).toContain('待发货')
  })

  // Immutable identity always read-only
  it('immutable identity fields are displayed as text, without disabled inputs in View Mode', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const summary = wrapper.get('[aria-label="订单信息摘要"]')
    expect(summary.findAll('input[disabled]')).toHaveLength(0)
    expect(summary.text()).toContain('007-0908-c1')
    expect(summary.text()).toContain('客户订单')
    expect(summary.text()).toContain('手动创建')
    expect(summary.text()).toContain('Test Owner')
  })

  it('identity fields remain immutable in Edit Mode (disabled inputs)', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
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
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.erp-form-page').exists()).toBe(true)
  })

  it('uses erp-form-card for section cards', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const cards = wrapper.findAll('.erp-form-card')
    expect(cards.length).toBeGreaterThanOrEqual(5)
  })

  it('uses canonical erp-form-label only when entering the editable form', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    await wrapper.findAll('.page-header-actions button').find(b => b.text() === '编辑订单')!.trigger('click')
    expect(wrapper.find('.erp-form-label').exists()).toBe(true)
    expect(wrapper.find('.mvp-label').exists()).toBe(false)
  })

  it('uses erp-btn — no mvp-button class', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.find('.erp-btn').exists()).toBe(true)
    expect(wrapper.find('.mvp-button').exists()).toBe(false)
  })

  it('View Mode uses plain text summaries, no faux input boxes or form controls', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.get('.erp-form-page').classes()).toContain('ord-view-mode')
    expect(wrapper.findAll('.erp-readonly-input')).toHaveLength(0)
    for (const id of ['order-info', 'address', 'items', 'notes']) {
      const card = wrapper.get('#order-section-' + id)
      expect(card.findAll('input, textarea, select')).toHaveLength(0)
    }
    expect(wrapper.get('[aria-label="订单信息摘要"]').text()).toContain('USD')
    expect(wrapper.get('[aria-label="收件地址摘要"]').text()).toContain('100 Main St')
    expect(wrapper.get('[aria-label="订单商品摘要"]').text()).toContain('SKU-001')
  })

  it('Edit Mode shows erp-input controls — no mvp-input class', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const editBtn = wrapper.findAll('button[type="button"]').find(b => b.text() === '编辑订单')
    await editBtn!.trigger('click')
    expect(wrapper.find('.mvp-input').exists()).toBe(false)
    expect(wrapper.findAll('.erp-input').length).toBeGreaterThan(0)
  })

  it('switches between condensed summary and editable order fields with cancel restoring original values', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const edit = wrapper.findAll('.page-header-actions button').find(b => b.text() === '编辑订单')
    expect(wrapper.find('.ord-summary-table').exists()).toBe(true)
    await edit!.trigger('click')
    expect(wrapper.get('.erp-form-page').classes()).toContain('ord-edit-mode')
    expect(wrapper.find('.ord-summary-table').exists()).toBe(false)
    expect(wrapper.find('.ord-item-row').exists()).toBe(true)
    const recipient = wrapper.get('#order-section-address input')
    await recipient.setValue('Edited, not saved')
    await wrapper.findAll('.page-header-actions button').find(b => b.text() === '取消编辑')!.trigger('click')
    expect(wrapper.find('.ord-summary-table').exists()).toBe(true)
    expect(wrapper.get('[aria-label="收件地址摘要"]').text()).toContain('Test Recipient')
    expect(wrapper.get('[aria-label="收件地址摘要"]').text()).not.toContain('Edited, not saved')
    await wrapper.findAll('.page-header-actions button').find(b => b.text() === '编辑订单')!.trigger('click')
    expect((wrapper.get('#order-section-address input').element as HTMLInputElement).value).toBe('Test Recipient')
    wrapper.unmount()
  })

  it('shows every order address attribute and item in View Mode without faking a payment total', () => {
    const order = { ...baseOrder, address: { ...baseOrder.address, address_line_2: 'Unit 5' },
      items: [baseOrder.items[0], { ...baseOrder.items[0], id: 11, sku: 'SKU-002', quantity: 1 }] }
    const wrapper = mount(Show, { props: { order, audit } })
    expect(wrapper.get('[aria-label="收件地址摘要"]').text()).toContain('100 Main St, Unit 5')
    expect(wrapper.get('[aria-label="收件地址摘要"]').text()).toContain('90001')
    expect(wrapper.get('[aria-label="订单商品摘要"]').findAll('tbody tr')).toHaveLength(2)
    expect(wrapper.get('[aria-label="订单商品摘要"]').text()).not.toContain('已付款金额')
  })

  // Fulfillment and audit
  it('shows fulfillment status in fulfillment card', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.text()).toContain('待发货')
  })

  it('shows reopen button when tracking number is present and logistics execution is allowed', () => {
    const orderWithTracking = {
      ...baseOrder,
      shipment: { shipment_status: 'shipped', carrier: 'DHL', tracking_number: 'TRACK123', shipped_at: null },
    }
    const wrapper = mount(Show, { props: { order: orderWithTracking, audit, canOperateLogistics: true } })
    const reopenBtn = wrapper.find('[data-testid="fulfillment-actions"]').findAll('button').find(b => b.text().includes('重开发货'))
    expect(reopenBtn).toBeTruthy()
  })

  it('does not show reopen button without tracking number', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    const reopenBtn = wrapper.find('[data-testid="fulfillment-actions"]').findAll('button').find(b => b.text().includes('重开发货'))
    expect(reopenBtn).toBeFalsy()
  })

  it('shows audit log section', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.text()).toContain('操作审计')
    expect(wrapper.text()).toContain('created')
  })

  it('shows address fields labels', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    expect(wrapper.text()).toContain('收件人')
    expect(wrapper.text()).toContain('联系电话')
  })

  it('existing item product_id preserved for existing order items', () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    // item.product_id = 5 from baseOrder — shown in SKU field (view mode shows SKU text)
    expect(wrapper.text()).toContain('SKU-001')
  })

  it('uses the inventory matrix itself as the pending warehouse selector', async () => {
    const inventoryAvailability = {
      5: [
        { warehouse_id: 1, warehouse_slug: 'us-la', warehouse_name: '美西 MorePlus 仓', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 8, quantity_reserved: 1, quantity_available: 7 },
        { warehouse_id: 2, warehouse_slug: 'us-ny', warehouse_name: '美东纽约仓', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 4, quantity_reserved: 0, quantity_available: 4 },
      ],
    }
    const allocationUi = {
      status: 'pending' as const, can_operate: true, requires_full_flow: false,
      selectable_warehouse_ids: [1, 2], recommended_warehouse_ids: { 10: 1 }, selected_warehouse_ids: { 10: null },
      recommended_plan_key: 'plan-us-la', can_use_recommendation: true, full_allocation_url: '/logistics/wait-stock',
    }
    const wrapper = mount(Show, { props: { order: baseOrder, audit, inventoryAvailability, allocationUi } })
    const choices = wrapper.findAll('input[type="radio"][name="allocation-10"]')
    expect(choices).toHaveLength(2)
    expect((choices[0].element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.text()).toContain('推荐')
    expect(wrapper.text()).toContain('采用推荐并提交分仓')

    await choices[1].trigger('change')
    expect(wrapper.text()).toContain('确认分仓')
    const confirm = wrapper.findAll('button[type="button"]').find(button => button.text() === '确认分仓')
    await confirm!.trigger('click')
    expect(router.post).toHaveBeenCalledWith('/logistics/orders/1/fulfillment-allocation/quick', {
      assignments: [{ order_item_id: 10, warehouse_id: 2 }],
      stock_exception_acknowledged: false,
    }, expect.any(Object))
    const submitOptions = vi.mocked(router.post).mock.lastCall?.[2] as { onError: (errors: Record<string, string>) => void }
    submitOptions.onError({ assignments: '发货仓 US-LA 尚未启用' })
    await nextTick()
    expect(wrapper.get('[role="alert"]').text()).toContain('发货仓 US-LA 尚未启用')
    await choices[0].trigger('change')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('requires explicit confirmation before allocating an out-of-stock warehouse', async () => {
    const availability = { 5: [{
      warehouse_id: 2, warehouse_slug: 'us-ny', warehouse_name: '美东纽约仓',
      inventory_state: 'confirmed', can_auto_allocate: true,
      quantity_on_hand: 0, quantity_reserved: 0, quantity_available: 0,
    }] }
    const allocationUi = {
      status: 'pending' as const, can_operate: true, requires_full_flow: false,
      selectable_warehouse_ids: [2], recommended_warehouse_ids: { 10: null }, selected_warehouse_ids: { 10: null },
      recommended_plan_key: null, can_use_recommendation: false, full_allocation_url: '/logistics/wait-stock',
    }
    const wrapper = mount(Show, { props: { order: baseOrder, audit, inventoryAvailability: availability, allocationUi, warehouses: [{ id: 2, slug: 'us-ny', name: '美东纽约仓', code: 'US-NY', country_code: 'US' }] }, attachTo: document.body })
    await wrapper.get('input[type="radio"][name="allocation-10"]').trigger('change')
    const submit = wrapper.findAll('button').find(button => button.text() === '确认分仓')
    const callsBefore = vi.mocked(router.post).mock.calls.length
    await submit!.trigger('click')
    expect(document.body.textContent).toContain('所选仓库库存不足或未知')
    expect(router.post).toHaveBeenCalledTimes(callsBefore)
    ;(document.body.querySelector('.stock-exception-confirm') as HTMLButtonElement).click()
    await nextTick()
    expect(router.post).toHaveBeenCalledWith('/logistics/orders/1/fulfillment-allocation/quick', {
      assignments: [{ order_item_id: 10, warehouse_id: 2 }],
      stock_exception_acknowledged: true,
    }, expect.any(Object))
    wrapper.unmount()
  })

  it('closes inventory allocation UI once warehouse allocation is committed', () => {
    const inventoryAvailability = {
      5: [{ warehouse_id: 2, warehouse_slug: 'us-ny', warehouse_name: '美东纽约仓', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 4, quantity_reserved: 0, quantity_available: 4 }],
    }
    const allocationUi = {
      status: 'submitted' as const, can_operate: false, requires_full_flow: false,
      selectable_warehouse_ids: [2], recommended_warehouse_ids: { 10: null }, selected_warehouse_ids: { 10: 2 },
      recommended_plan_key: null, can_use_recommendation: false, full_allocation_url: '/logistics/wait-stock',
    }
    const wrapper = mount(Show, { props: { order: baseOrder, audit, inventoryAvailability, allocationUi } })
    expect(wrapper.find('.ord-inventory-card').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('库存分布')
    expect(wrapper.find('input[type="radio"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('确认分仓')
  })

  it('shows ten ordered navigation links and only one inventory link for multiple SKUs', async () => {
    const items = [baseOrder.items[0], { ...baseOrder.items[0], id: 11, sku: 'SKU-002' }]
    const wrapper = mount(Show, { props: { order: { ...baseOrder, items }, audit, canEditOrder: true } })
    await flushPromises()
    await nextTick()
    const buttons = wrapper.findAll('.ord-detail-nav-item')
    expect(buttons.map(button => button.text())).toEqual([
      '概览', '履约状态', '辅助标记', '库存分布',
      '订单信息', '收件与地址', '订单商品', '包装与重量', '内部备注', '操作审计',
    ])
    expect(buttons.filter(button => button.text() === '库存分布')).toHaveLength(1)
    expect(wrapper.findAll('.ord-inventory-card')).toHaveLength(2)
    expect(wrapper.findAll('#order-section-inventory')).toHaveLength(1)
    expect(wrapper.findAll('[aria-current="location"]')).toHaveLength(1)
  })

  it('hides navigation entries when the corresponding card does not exist', async () => {
    const wrapper = mount(Show, { props: { order: splitOrder, audit } })
    await flushPromises()
    await nextTick()
    expect(wrapper.find('#order-section-inventory').exists()).toBe(false)
    expect(wrapper.findAll('.ord-detail-nav-item').map(button => button.text())).not.toContain('库存分布')
  })

  it('highlights the final audit section when scrolled to page bottom', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    await flushPromises()
    const height = vi.spyOn(document.documentElement, 'scrollHeight', 'get').mockReturnValue(2400)
    const scroll = vi.spyOn(window, 'scrollY', 'get').mockReturnValue(1600)
    try {
      window.dispatchEvent(new Event('scroll'))
      await nextTick()
      expect(wrapper.get('.ord-detail-nav-item[aria-current="location"]').text()).toBe('操作审计')
    } finally {
      height.mockRestore()
      scroll.mockRestore()
      wrapper.unmount()
    }
  })

  it('preserves an unsaved order note while opening and closing a shipment editor', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true, canOperateLogistics: true } })
    await flushPromises()
    await wrapper.findAll('.page-header-actions button').find(button => button.text() === '编辑订单')!.trigger('click')
    await wrapper.get('#order-section-notes textarea').setValue('本地未保存草稿 - U4 发货弹窗')
    const before = vi.mocked(router.post).mock.calls.length
    const open = wrapper.findAll('[data-testid="fulfillment-actions"] button').find(button => button.text().includes('履约发货'))
    expect(open).toBeDefined()
    await open!.trigger('click')
    expect(wrapper.find('[role="dialog"][aria-label="填写物流并发货"]').exists()).toBe(true)
    expect((wrapper.get('#order-section-notes textarea').element as HTMLTextAreaElement).value).toBe('本地未保存草稿 - U4 发货弹窗')
    await wrapper.get('[role="dialog"][aria-label="填写物流并发货"] .ord-replacement-close').trigger('click')
    expect(wrapper.find('[role="dialog"][aria-label="填写物流并发货"]').exists()).toBe(false)
    expect((wrapper.get('#order-section-notes textarea').element as HTMLTextAreaElement).value).toBe('本地未保存草稿 - U4 发货弹窗')
    expect(router.post).toHaveBeenCalledTimes(before)
    wrapper.unmount()
  })

  it('preserves an unsaved order note while opening and canceling a partial warehouse reallocation', async () => {
    const wrapper = mount(Show, { props: {
      order: splitOrder, audit, canEditOrder: true, canOperateLogistics: true,
      allocationUi: {
        status: 'submitted', can_partial_reallocate: true, can_operate: false, can_reallocate: false,
        requires_full_flow: false, selectable_warehouse_ids: [1, 2],
        recommended_warehouse_ids: {}, selected_warehouse_ids: {},
        recommended_plan_key: null, can_use_recommendation: false,
        full_allocation_url: '/logistics/wait-stock',
      },
      warehouses: [
        { id: 1, slug: 'us-la', name: '美西 MorePlus 仓', code: 'US-LA', country_code: 'US' },
        { id: 2, slug: 'cn-factory', name: '国内工厂', code: 'CN', country_code: 'CN' },
      ],
    } })
    await flushPromises()
    await wrapper.findAll('.page-header-actions button').find(button => button.text() === '编辑订单')!.trigger('click')
    await wrapper.get('#order-section-notes textarea').setValue('本地未保存草稿 - U4 调整分仓')
    const before = vi.mocked(router.post).mock.calls.length
    const open = wrapper.findAll('.ord-fulfillment-row-actions button').find(button => button.text() === '调整分仓')
    expect(open).toBeDefined()
    await open!.trigger('click')
    expect(wrapper.get('[role="dialog"][aria-label="调整未发货履约分仓"]').text()).toContain('调整 B 分仓')
    await wrapper.get('[role="dialog"][aria-label="调整未发货履约分仓"] .ord-replacement-close').trigger('click')
    expect(wrapper.find('[role="dialog"][aria-label="调整未发货履约分仓"]').exists()).toBe(false)
    expect((wrapper.get('#order-section-notes textarea').element as HTMLTextAreaElement).value).toBe('本地未保存草稿 - U4 调整分仓')
    expect(router.post).toHaveBeenCalledTimes(before)
    wrapper.unmount()
  })

  it('provides drag handles and collapsible top-right controls for each business panel', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    await flushPromises()
    const panels = wrapper.findAll('.ord-sortable-panel')
    expect(panels).toHaveLength(9)
    for (const panel of panels) {
      expect(panel.find('.ord-section-header .ord-section-drag').attributes('draggable')).toBe('true')
      expect(panel.find('.ord-section-header .ord-section-toggle').exists()).toBe(true)
    }
    const packaging = wrapper.get('#order-section-packaging')
    expect(packaging.classes()).toContain('ord-section-collapsed')
    expect(packaging.get('.ord-section-toggle').attributes('aria-expanded')).toBe('false')
    await packaging.get('.ord-section-toggle').trigger('click')
    expect(packaging.classes()).not.toContain('ord-section-collapsed')
    expect(packaging.get('.ord-section-toggle').attributes('aria-expanded')).toBe('true')
    wrapper.unmount()
  })

  it('sorts packaging just before internal notes by default and moves all multi-SKU inventory together', async () => {
    const items = [baseOrder.items[0], { ...baseOrder.items[0], id: 11, sku: 'SKU-002' }]
    const wrapper = mount(Show, { props: { order: { ...baseOrder, items }, audit } })
    await flushPromises()
    expect(wrapper.findAll('.ord-inventory-card')).toHaveLength(2)
    expect(wrapper.findAll('#order-section-inventory')).toHaveLength(1)
    const nav = () => wrapper.findAll('.ord-detail-nav-item').map(item => item.text())
    expect(nav().indexOf('包装与重量')).toBe(nav().indexOf('内部备注') - 1)
    const inventory = wrapper.get('#order-section-inventory')
    const initialOrder = Number(inventory.attributes('style')?.match(/order:\s*(\d+)/)?.[1])
    expect(initialOrder).toBeGreaterThan(0)
    await inventory.get('.ord-section-drag').trigger('keydown', { altKey: true, key: 'ArrowDown' })
    await nextTick()
    expect(Number(inventory.attributes('style')?.match(/order:\s*(\d+)/)?.[1])).toBe(initialOrder + 10)
    expect(nav().indexOf('库存分布')).toBe(nav().indexOf('订单信息') + 1)
    expect(wrapper.findAll('.ord-inventory-card')).toHaveLength(2)
    wrapper.unmount()
  })

  it('drag-dropping a panel reorders navigation and stores per-employee preferences', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    await flushPromises()
    const note = wrapper.get('#order-section-notes')
    const address = wrapper.get('#order-section-address')
    await note.get('.ord-section-drag').trigger('dragstart')
    await address.trigger('drop')
    const nav = wrapper.findAll('.ord-detail-nav-item').map(item => item.text())
    expect(nav.indexOf('内部备注')).toBe(nav.indexOf('收件与地址') - 1)
    const state = JSON.parse(window.localStorage.getItem('byl:erp:order-detail:v1.1:panels:1')!)
    expect(state.order.indexOf('order-section-notes')).toBe(state.order.indexOf('order-section-address') - 1)
    wrapper.unmount()
    const restored = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    await flushPromises()
    expect(restored.findAll('.ord-detail-nav-item').map(item => item.text()).indexOf('内部备注'))
      .toBe(restored.findAll('.ord-detail-nav-item').map(item => item.text()).indexOf('收件与地址') - 1)
    restored.unmount()
  })

  it('keeps unsaved edit fields mounted when a business section is collapsed and reordered', async () => {
    const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
    await flushPromises()
    await wrapper.findAll('.page-header-actions button').find(button => button.text() === '编辑订单')!.trigger('click')
    const textarea = wrapper.get('#order-section-notes textarea')
    await textarea.setValue('UAT 还没有保存的内部备注')
    const postCalls = vi.mocked(router.post).mock.calls.length
    await wrapper.get('#order-section-notes .ord-section-toggle').trigger('click')
    await wrapper.get('#order-section-notes .ord-section-drag').trigger('keydown', { altKey:true, key:'ArrowUp' })
    expect(wrapper.get('#order-section-notes').classes()).toContain('ord-section-collapsed')
    expect((wrapper.get('#order-section-notes textarea').element as HTMLTextAreaElement).value).toBe('UAT 还没有保存的内部备注')
    const jump = wrapper.findAll('.ord-detail-nav-item').find(item => item.text() === '内部备注')
    await jump!.trigger('click')
    expect(wrapper.get('#order-section-notes').classes()).not.toContain('ord-section-collapsed')
    expect((wrapper.get('#order-section-notes textarea').element as HTMLTextAreaElement).value).toBe('UAT 还没有保存的内部备注')
    expect(router.post).toHaveBeenCalledTimes(postCalls)
    wrapper.unmount()
  })

  it('ignores corrupted layout preferences and never allows dragging the fixed overview header', async () => {
    window.localStorage.setItem('byl:erp:order-detail:v1.1:panels:1','{bad-json')
    const wrapper = mount(Show, { props: { order: baseOrder, audit } })
    await flushPromises()
    expect(wrapper.findAll('.ord-detail-nav-item')).toHaveLength(10)
    expect(wrapper.find('#order-section-overview .ord-section-drag').exists()).toBe(false)
    expect(wrapper.get('#order-section-packaging').classes()).toContain('ord-section-collapsed')
    wrapper.unmount()
  })

  it('scrolls within the document without route navigation or discarding an unsaved draft', async () => {
    const originalScroll = Element.prototype.scrollIntoView
    const scroll = vi.fn()
    Element.prototype.scrollIntoView = scroll
    try {
      const wrapper = mount(Show, { props: { order: baseOrder, audit, canEditOrder: true } })
      await flushPromises()
      await nextTick()
      const edit = wrapper.findAll('.page-header-actions button').find(button => button.text() === '编辑订单')
      await edit!.trigger('click')
      await wrapper.get('#order-section-notes textarea').setValue('未保存草稿须保留')
      const postCalls = vi.mocked(router.post).mock.calls.length
      const button = wrapper.findAll('.ord-detail-nav-item').find(item => item.text() === '订单商品')
      await button!.trigger('click')
      expect(scroll).toHaveBeenCalledWith({ behavior: 'smooth', block: 'start' })
      expect((wrapper.get('#order-section-notes textarea').element as HTMLTextAreaElement).value).toBe('未保存草稿须保留')
      expect(router.post).toHaveBeenCalledTimes(postCalls)
      await wrapper.get('.ord-detail-nav-top').trigger('click')
      expect(scroll).toHaveBeenCalledTimes(2)
      wrapper.unmount()
    } finally {
      if (originalScroll) Element.prototype.scrollIntoView = originalScroll
      else delete (Element.prototype as unknown as Record<string, unknown>).scrollIntoView
    }
  })

})

describe('WooCommerce admin order link', () => {
  const url = 'https://beyourlover.com/wp-admin/admin.php?page=wc-orders&action=edit&id=28518'

  it('opens the server-resolved Woo order in a separate safe tab', () => {
    const wrapper = mount(Show, { props: { order: { ...baseOrder, source_type: 'woocommerce' }, audit, wooAdminEditUrl: url } })
    const link = wrapper.get('[data-testid="woo-admin-order-link"]')
    expect(link.attributes('href')).toBe(url)
    expect(link.attributes('target')).toBe('_blank')
    expect(link.attributes('rel')).toContain('noopener')
    expect(link.attributes('rel')).toContain('noreferrer')
  })

  it('does not display a link without a validated URL or for KOL orders', () => {
    const withoutLink = mount(Show, { props: { order: baseOrder, audit, wooAdminEditUrl: null } })
    expect(withoutLink.find('[data-testid="woo-admin-order-link"]').exists()).toBe(false)
    const kol = mount(Show, { props: { order: kolOrder, audit, wooAdminEditUrl: url } })
    expect(kol.find('[data-testid="woo-admin-order-link"]').exists()).toBe(false)
  })
})
