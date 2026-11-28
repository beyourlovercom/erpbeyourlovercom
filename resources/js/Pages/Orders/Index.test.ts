import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'
import axios from 'axios'

const { get, post, del, reload } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), del: vi.fn(), reload: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get, post, delete: del, reload },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' } }))

vi.mock('../../Components/DataGrid/GlobalGrid.vue', () => ({ __esModule: true, default: {
  name: 'MockGlobalGrid', props: ['enableSelection', 'selectedOrderIds'],
  emits: ['selection-change'],
  template: '<div data-testid="mock-grid"><slot name="footer-left" /></div>',
} }))

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

  it('uses the unified recovery endpoint from the global exception list', async () => {
    const request = vi.spyOn(axios, 'post').mockResolvedValue({data:{message:'异常已解除'}})
    const wrapper = mount(Index, {props:{...props, canResolveException:true, filters:{view:'exception'}, orders:{...props.orders,data:[{id:99,erp_order_id:'TEST-99',workflow_revision:3}],total:1} as any}})
    await wrapper.findAll('button').find(b=>b.text()==='解除异常')!.trigger('click')
    await flushPromises()
    expect(request).toHaveBeenCalledWith('/logistics/orders/99/resolve-workbench-exception',{expected_workflow_revision:3},expect.any(Object))
    expect(reload).toHaveBeenCalledWith({only:['orders']})
    wrapper.unmount()
    request.mockRestore()
  })

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

  it('uses default customer Global Grid without leaking OMS canary flag or removing legacy fallback', async () => {
    const modern = mount(Index, { props: {
      ...props, orderKind: 'customer', customerGlobalGridEnabled: true,
    } })
    expect(modern.text()).toContain('返回原表')
    expect(modern.find('a[href*="legacy_grid=1"]').exists()).toBe(true)
    await modern.find('form.mvp-toolbar').trigger('submit')
    expect(get).toHaveBeenCalledWith('/customers/orders', expect.objectContaining({
      legacy_grid: '0', per_page: '50', page: 1,
    }), { preserveState: true, replace: true })
    expect(get.mock.calls.at(-1)?.[1]).not.toHaveProperty('oms_grid')
    modern.unmount()
  })

  it('downloads only the current-page selected collaboration orders and clears on pagination', async () => {
    const row = (id: number) => ({ id, erp_order_id: 'CY'+id+'-007', external_order_number: 'EXT-'+id, internal_order_number: 'INT-'+id,
      source_label: '合作订单', order_date: '2026-11-26', order_status_label: '待发货' })
    const data = [row(11), row(12), row(13)]
    const wrapper = mount(Index, { props: { ...props, orderScope: 'collaboration', customerGlobalGridEnabled: true, filters: { view: 'all', partner_id: '1' }, orders: { ...props.orders, data, total: 3 } } })
    await flushPromises()
    const grid = wrapper.findComponent({ name: 'MockGlobalGrid' })
    expect(grid.props('enableSelection')).toBe(true)
    expect(wrapper.find('[data-testid="download-collaboration-selected"]').exists()).toBe(false)
    grid.vm.$emit('selection-change', [{ id: 11 }, { id: 13 }, { id: 13 }, { id: 999 }])
    await flushPromises()
    const selected = wrapper.get('[data-testid="download-collaboration-selected"]')
    expect(selected.text()).toBe('下载已选 2 单')
    const url = new URL(selected.attributes('href')!, 'https://erp.example')
    expect(url.pathname).toBe('/orders/collaboration/export')
    expect(url.searchParams.getAll('order_ids[]')).toEqual(['11','13'])
    expect(url.searchParams.get('partner_id')).toBe('1')
    await wrapper.setProps({ orders: { ...props.orders, data, current_page: 2, total: 3 } })
    expect(wrapper.find('[data-testid="download-collaboration-selected"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('shows just the latest order remark in red in the legacy list', () => {
    const row = { id: 8, erp_order_id: '007-0008', external_order_number: 'WEB-8', internal_order_number: 'INT-8',
      source_label: 'WooCommerce', order_date: '2026-11-13', order_status_label: '待发货',
      latest_order_note: '最新备注：订单已经发货，请注意签收', address: { recipient_name: 'Tester', country: 'US' }, owner_label: null }
    const wrapper = mount(Index, { props: { ...props, orders: { ...props.orders, data: [row] } } })
    const remark = wrapper.get('.orders-latest-note')
    expect(remark.text()).toBe(row.latest_order_note)
    expect(remark.attributes('title')).toBe(row.latest_order_note)
    expect(wrapper.get('thead').text()).toContain('备注')
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
