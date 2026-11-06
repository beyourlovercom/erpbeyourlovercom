import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

const { get, post, del } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), del: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get, post, delete: del },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' } }))

const props = {
  orderKind: 'all',
  orderScope: 'all',
  orders: { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0 },
  filters: { view: 'all' },
  sourceTypes: [{ value: 'manual', label: '手动创建' }],
  savedViews: [{ id: 7, name: '我的待发', filters_json: { view: 'pending', source_type: 'manual' }, is_pinned: true, created_by: 1 }],
  canExport: true,
}

describe('Orders Saved Views', () => {
  beforeEach(() => { get.mockClear(); post.mockClear(); del.mockClear() })

  it('shows history identity preview only in the authorized KOL order list', () => {
    const manager = mount(Index, { props: {
      ...props, orderKind: 'kol_fulfillment', canPreviewKolLinks: true,
    } })
    expect(manager.get('[data-testid="kol-order-link-preview"]').attributes('href')).toBe('/kol/orders/link-preview')
    manager.unmount()

    const staff = mount(Index, { props: {
      ...props, orderKind: 'kol_fulfillment', canPreviewKolLinks: false,
    } })
    expect(staff.find('[data-testid="kol-order-link-preview"]').exists()).toBe(false)
    staff.unmount()

    const customer = mount(Index, { props: {
      ...props, orderKind: 'customer', canPreviewKolLinks: true,
    } })
    expect(customer.find('[data-testid="kol-order-link-preview"]').exists()).toBe(false)
    customer.unmount()
  })

  it('keeps all order lists free of website sync health notices and actions', () => {
    for (const orderKind of ['all', 'customer', 'kol_fulfillment']) {
      const wrapper = mount(Index, { props: { ...props, orderKind } })
      expect(wrapper.text()).not.toContain('网站订单同步')
      expect(wrapper.text()).not.toContain('最近成功任务启动')
      expect(wrapper.text()).not.toContain('今日未同步')
      expect(wrapper.text()).not.toContain('同步网站订单')
      expect(wrapper.text()).not.toContain('核对网站未同步')
      wrapper.unmount()
    }
    const procurement = mount(Index, { props: { ...props, orderScope: 'external_warehouse' } })
    expect(procurement.text()).not.toContain('网站订单同步')
  })

  it('renders the nine frozen business views plus custom view creation', () => {
    const wrapper = mount(Index, { props })
    for (const label of ['全部订单', '待发订单', '异常订单', '运输中', '已发货', '退货单', '亏本单', '超重单', '缺货单', '＋ 新建视图']) {
      expect(wrapper.text()).toContain(label)
    }
    expect(wrapper.text()).toContain('我的待发')
  })

  it('applies overweight system view without inventing a copied order set', async () => {
    const wrapper = mount(Index, { props })
    const button = wrapper.findAll('button').find(b => b.text() === '超重单')!
    await button.trigger('click')
    expect(get).toHaveBeenCalledWith('/orders', expect.objectContaining({ view: 'overweight' }), { preserveState: true, replace: true })
  })

  it('applies a saved filter definition from the shared saved_views authority', async () => {
    const wrapper = mount(Index, { props })
    await wrapper.findAll('button').find(b => b.text() === '我的待发')!.trigger('click')
    expect(get).toHaveBeenCalledWith('/orders', expect.objectContaining({ view: 'pending', source_type: 'manual' }), { preserveState: true, replace: true })
  })

  it('saves the current filters as a definition', async () => {
    const wrapper = mount(Index, { props })
    await wrapper.findAll('button').find(b => b.text() === '＋ 新建视图')!.trigger('click')
    await wrapper.find('.new-view-panel input').setValue('美国待发')
    await wrapper.find('.new-view-panel').trigger('submit')
    expect(post).toHaveBeenCalledWith('/orders/views', expect.objectContaining({
      name: '美国待发',
      filters_json: expect.objectContaining({ view: 'all' }),
      is_pinned: true,
    }), expect.objectContaining({ preserveScroll: true }))
  })

  it('keeps the existing table by default and carries the R3 opt-in through server filtering', async () => {
    const legacy = mount(Index, { props: { ...props, omsGridAvailable: true, omsGridEnabled: false } })
    expect(legacy.find('.mvp-table-wrap').exists()).toBe(true)
    expect(legacy.text()).toContain('体验 OMS Grid')
    legacy.unmount()

    const pilot = mount(Index, { props: {
      ...props, omsGridAvailable: true, omsGridEnabled: true,
      filters: { view: 'all', sort: 'date', direction: 'desc', per_page: '50', oms_grid: '1' },
    } })
    expect(pilot.find('.mvp-table-wrap').exists()).toBe(false)
    expect(pilot.text()).toContain('返回原列表')
    await pilot.find('form.mvp-toolbar').trigger('submit')
    expect(get).toHaveBeenCalledWith('/orders', expect.objectContaining({
      oms_grid: '1', per_page: '50', sort: 'date', direction: 'desc', page: 1,
    }), { preserveState: true, replace: true })
    pilot.unmount()
  })

  it('renders the ERP order identity with the external order as secondary text', () => {
    const wrapper = mount(Index, { props: {
      ...props,
      orders: { ...props.orders, data: [{ id: 9, erp_order_id: '007-0009', external_order_number: 'WEB-9', internal_order_number: 'INT-9', source_label: 'WooCommerce', order_date: '2026-09-08', order_status_label: '待发货', address: { recipient_name: 'Tester', country: 'US' }, owner_label: null }] },
    } })
    expect(wrapper.text()).toContain('007-0009')
    expect(wrapper.text()).toContain('来源订单：WEB-9')
    expect(wrapper.text()).not.toContain('INT-9')
    expect(wrapper.find('thead th:last-child .column-settings-trigger').exists()).toBe(true)
  })
})
