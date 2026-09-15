import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { post: vi.fn(), get: vi.fn() },
  usePage: () => ({ url: '/logistics/pending' }),
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const warehouse = { id: 2, slug: 'us-ny', name: '美东纽约仓', code: 'US-NY', country_code: null, priority: 20, inventory_state: 'confirmed', can_auto_allocate: true, service_regions: [{ country_code: 'US', priority: 1 }] }
const order = {
  id: 10, erp_order_id: null, external_order_number: 'TEST-10', order_kind_label: '客户订单', recipient_name: 'Tester', phone: null, country: 'US', owner_label: null, item_count: 1,
  items: [{ id: 1, product_id: 99, sku: 'SKU-99', product_summary: 'Product', quantity: 1, availability: [{ warehouse_id: 2, warehouse_slug: 'us-ny', warehouse_name: '美东纽约仓', inventory_state: 'confirmed', can_auto_allocate: true, quantity_on_hand: 5, quantity_reserved: 0, quantity_available: 5 }] }],
  warehouse_recommendation: { status: 'recommended' as const, reason_code: 'recommended', reason_label: '可采用系统推荐。', country_code: 'US', required_sku_count: 1, candidate_count: 1, warehouse: { id: 2, slug: 'us-ny', code: 'US-NY', name: '美东纽约仓' } },
  automatic_allocation: { state: 'COUNTRY_NOT_AUTO_ENABLED', status: 'blocked' as const, reason_code: 'country_not_auto_enabled', reason_label: 'US 尚未开放自动分配，继续使用人工履约。', country_code: 'US', warehouse: null, can_apply: false },
  shipment: null,
}
const carrierOptions = [{ value: 'DHL', label: 'DHL' }, { value: 'UPS', label: 'UPS' }, { value: 'FedEx', label: 'FedEx' }]
const props = { orders: { data: [order], current_page: 1, last_page: 1, per_page: 50, total: 1 }, warehouses: [warehouse], carrierOptions, canOperate: true }

describe('Logistics pending grid contract', () => {
  it('renders ERP order id first and source as secondary text', () => {
    const publicOrder = { ...order, external_order_number: '40301-VA617A-gift', erp_order_id: '007-0908-c6091', internal_order_number: 'ERP-TECH-6091' }
    const wrapper = mount(Index, { props: { ...props, orders: { ...props.orders, data: [publicOrder] } } })
    expect(wrapper.text()).toContain('007-0908-c6091')
    expect(wrapper.text()).not.toContain('来源订单：40301-VA617A-gift')
    expect(wrapper.text()).not.toContain('ERP-TECH-6091')
  })

  it('provides account-synced column visibility and ordering from the top-right ellipsis', async () => {
    const wrapper = mount(Index, { props: { ...props, gridPreferenceNamespace: 'grid.logistics.pending.v1', gridPreference: null } })
    expect(wrapper.find('.column-settings-panel').exists()).toBe(false)
    await wrapper.get('.column-settings-trigger').trigger('click')
    const panel = wrapper.get('.column-settings-panel')
    expect(panel.text()).toContain('列设置')
    expect(panel.text()).toContain('固定显示')
    expect(wrapper.get('input[aria-label="订单号固定显示"]').attributes('disabled')).toBeDefined()
  })
})

describe('Logistics role real UX', () => {
  it('keeps view-only users read-only while preserving copy/detail access', async () => {
    const wrapper = mount(Index, { props: { ...props, canOperate: false } })
    expect(wrapper.text()).toContain('当前角色为只读模式')
    const detail = wrapper.findAll('button').find(button => button.text() === '查看详情')
    expect(detail).toBeDefined()
    await detail!.trigger('click')

    expect(wrapper.text()).not.toContain('采用推荐并预留库存')
    expect(wrapper.text()).not.toContain('保存草稿')
    expect(wrapper.text()).not.toContain('确认发货')
    expect(wrapper.text()).toContain('复制收件人')
    for (const control of [...wrapper.findAll('select'), ...wrapper.findAll('input'), ...wrapper.findAll('textarea')]) {
      expect(control.attributes('disabled')).toBeDefined()
    }
  })
})

describe('Logistics warehouse recommendation', () => {
  it('syncs warehouse_id into existing draft when props refresh with adopted recommended shipment', async () => {
    const wrapper = mount(Index, { props })
    await wrapper.findAll('button').find(b => b.text() === '履约处理')!.trigger('click')
    const warehouseSelect = wrapper.findAll('select')[0]
    expect((warehouseSelect.element as HTMLSelectElement).value).toBe('')

    const adoptedOrder = {
      ...order,
      shipment: { warehouse_id: 2, warehouse_name: '美东纽约仓', warehouse_selection_source: 'recommended', carrier: null, service: null, tracking_number: null, shipped_at: null, logistics_note: null },
    }
    await wrapper.setProps({ orders: { ...props.orders, data: [adoptedOrder] } })
    expect((warehouseSelect.element as HTMLSelectElement).value).toBe('2')
  })

  it('adopts a recommendation through JSON fetch without Inertia navigation', async () => {
    const csrfMeta = document.createElement('meta')
    csrfMeta.name = 'csrf-token'
    csrfMeta.content = 'test-csrf-token'
    document.head.appendChild(csrfMeta)
    const wrapper = mount(Index, { props })
    const { router } = await import('@inertiajs/vue3')
    vi.mocked(router.post).mockClear()
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ warehouse_id: 2, warehouse_name: '美东纽约仓' }) })
    vi.stubGlobal('fetch', fetchMock)
    document.cookie = 'XSRF-TOKEN=test-token'

    await wrapper.findAll('button').find(button => button.text() === '履约处理')!.trigger('click')
    expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('')
    await wrapper.findAll('button').find(button => button.text() === '采用推荐并预留库存')!.trigger('click')
    await flushPromises()

    expect(router.post).not.toHaveBeenCalled()
    expect(fetchMock).toHaveBeenCalledOnce()
    expect(fetchMock.mock.calls[0][0]).toBe('/logistics/orders/10/adopt-recommendation')
    expect(fetchMock.mock.calls[0][1]).toMatchObject({
      method: 'POST',
      credentials: 'same-origin',
      headers: expect.objectContaining({ Accept: 'application/json', 'X-CSRF-TOKEN': 'test-csrf-token' }),
    })
    csrfMeta.remove()
    vi.unstubAllGlobals()
  })

  it('updates only row warehouse and warehouse select after adopt while preserving the open draft', async () => {
    const draftOrder = {
      ...order,
      warehouse_recommendation: { ...order.warehouse_recommendation, status: 'recommended' as const },
      shipment: { warehouse_id: null, warehouse_name: null, warehouse_selection_source: null, carrier: 'UPS', service: 'Ground', tracking_number: 'TRK-001', shipped_at: '2026-09-01T10:00:00', logistics_note: 'keep-note' },
    }
    const wrapper = mount(Index, { props: { ...props, orders: { ...props.orders, data: [draftOrder] } } })
    const { router } = await import('@inertiajs/vue3')
    vi.mocked(router.post).mockClear()
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ warehouse_id: 2, warehouse_name: '美东纽约仓' }) })
    vi.stubGlobal('fetch', fetchMock)
    document.cookie = 'XSRF-TOKEN=test-token'

    await wrapper.findAll('button').find(button => button.text() === '履约处理')!.trigger('click')
    const warehouseSelect = wrapper.findAll('select')[0]
    const carrierSelect = wrapper.get('select[aria-label="物流商"]')
    expect((warehouseSelect.element as HTMLSelectElement).value).toBe('')
    expect((carrierSelect.element as HTMLSelectElement).value).toBe('UPS')

    await wrapper.findAll('button').find(button => button.text() === '采用推荐并预留库存')!.trigger('click')
    await flushPromises()

    expect(wrapper.findAll('td')[5].text()).toBe('美东纽约仓')
    expect((warehouseSelect.element as HTMLSelectElement).value).toBe('2')
    expect((carrierSelect.element as HTMLSelectElement).value).toBe('UPS')
    expect((wrapper.get('input[placeholder="例如 Ground"]').element as HTMLInputElement).value).toBe('Ground')
    expect((wrapper.get('input[placeholder="追踪号"]').element as HTMLInputElement).value).toBe('TRK-001')
    expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('keep-note')
    expect(wrapper.findAll('button').some(button => button.text() === '收起')).toBe(true)
    const adoptButton = wrapper.findAll('button').find(button => button.text() === '采用推荐并预留库存')!
    expect(adoptButton.attributes('disabled')).toBeUndefined()
    expect(router.post).not.toHaveBeenCalled()
    vi.unstubAllGlobals()
  })


  it('uses the canonical carrier dropdown instead of free text', async () => {
    const wrapper = mount(Index, { props })
    await wrapper.findAll('button').find(button => button.text() === '履约处理')!.trigger('click')
    const carrierSelect = wrapper.get('select[aria-label="物流商"]')
    expect(carrierSelect.text()).toContain('请选择承运商')
    expect(carrierSelect.text()).toContain('DHL')
    expect(carrierSelect.text()).toContain('UPS')
    expect(carrierSelect.text()).toContain('FedEx')
    expect(wrapper.find('input[placeholder="例如 UPS"]').exists()).toBe(false)
  })

  it('renders unknown inventory as unknown instead of numeric zero', async () => {
    const unknown = { ...order, items: [{ ...order.items[0], availability: [{ warehouse_id: 1, warehouse_slug: 'us-la', warehouse_name: '美西洛杉矶仓', inventory_state: 'never_imported', can_auto_allocate: false, quantity_on_hand: null, quantity_reserved: null, quantity_available: null }] }] }
    const wrapper = mount(Index, { props: { ...props, orders: { ...props.orders, data: [unknown] } } })
    await wrapper.findAll('button').find(button => button.text() === '履约处理')!.trigger('click')
    expect(wrapper.text()).toContain('美西洛杉矶仓：库存未知')
    expect(wrapper.text()).not.toContain('美西洛杉矶仓：可用 0')
  })

  it('keeps manual warehouse selection available and explains why shipping is blocked', async () => {
    const blocked = { ...order, warehouse_recommendation: { ...order.warehouse_recommendation, status: 'blocked' as const, reason_code: 'missing_product_master', reason_label: '订单存在未关联 Product Master 的商品，需人工处理。', warehouse: null } }
    const wrapper = mount(Index, { props: { ...props, orders: { ...props.orders, data: [blocked] } } })
    await wrapper.findAll('button').find(button => button.text() === '履约处理')!.trigger('click')
    expect(wrapper.text()).toContain('仍可人工选择任一启用仓库发货')
    const warehouseSelect = wrapper.findAll('select')[0]
    expect(warehouseSelect.text()).toContain('美东纽约仓 (US-NY)')
    const shipButton = wrapper.findAll('button').find(button => button.text() === '确认发货')!
    expect(shipButton.attributes('disabled')).toBeUndefined()
    await shipButton.trigger('click')
    expect(wrapper.text()).toContain('请先选择发货仓库')
  })

  it('does not render adopt action when recommendation is blocked', async () => {
    const blocked = { ...order, warehouse_recommendation: { ...order.warehouse_recommendation, status: 'blocked' as const, reason_code: 'service_regions_unconfigured', reason_label: '服务区域未配置', warehouse: null } }
    const wrapper = mount(Index, { props: { ...props, orders: { ...props.orders, data: [blocked] } } })
    await wrapper.findAll('button').find(button => button.text() === '履约处理')!.trigger('click')
    expect(wrapper.text()).toContain('服务区域未配置')
    expect(wrapper.findAll('button').some(button => button.text() === '采用推荐并预留库存')).toBe(false)
  })
})
