import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Master from './Master.vue'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: {
  props: ['title', 'breadcrumbs'], template: '<div><slot /></div>',
} }))
vi.mock('../../Components/DataGrid/GlobalGrid.vue', () => ({ __esModule: true, default: {
  props: ['rows', 'columns', 'namespace'], template: '<div data-testid="master-global-grid"><slot name="actions" /></div>',
} }))

vi.mock('../../Components/Orders/OrderOmsTransfer.vue', () => ({ default: {
  props: ['canImport', 'canConfirmImport'],
  template: '<button v-if="canImport" data-testid="master-import">导入</button>',
} }))

const props = {
  orders: { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0 },
  counts: { all: 8104, normal: 7978, operational: 126, trash: 0, pending: 450, exception: 1 },
  filters: { search: '', view: 'all', kind: 'all', source: 'all', sort: 'date', direction: 'desc', per_page: '50' },
  canSeeTrash: true,
  canImportOmsOrders: true,
  canConfirmOmsOrders: true,
}

describe('Orders read-only master workspace', () => {
  beforeEach(() => get.mockClear())

  it('shows one compact filter workspace without duplicate all/archive navigation tabs', () => {
    const wrapper = mount(Master, { props })
    expect(wrapper.find('.master-toolbar').exists()).toBe(true)
    expect(wrapper.findAll('.quick-filters .quick-chip')).toHaveLength(6)
    expect(wrapper.find('.master-heading').text()).toContain('8,104')
    expect(wrapper.find('.order-views-bar').exists()).toBe(false)
    expect(wrapper.find('a[href="/orders/archive"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('routes archive chip, kind selector and filters to server pagination', async () => {
    const wrapper = mount(Master, { props })
    await wrapper.findAll('.quick-chip').find(button => button.text().includes('运营归档'))!.trigger('click')
    expect(get).toHaveBeenCalledWith('/orders', expect.objectContaining({ view: 'operational', page: 1 }), expect.any(Object))
    await wrapper.find('select[aria-label="订单类型"]').setValue('kol_fulfillment')
    expect(get).toHaveBeenCalledWith('/orders', expect.objectContaining({ kind: 'kol_fulfillment', page: 1 }), expect.any(Object))
    wrapper.unmount()
  })

  it('places the existing import action in the canonical order master only for permitted users', async () => {
    const allowed = mount(Master, { props })
    await flushPromises()
    expect(allowed.find('[data-testid="master-import"]').exists()).toBe(true)
    allowed.unmount()
    const denied = mount(Master, { props: { ...props, canImportOmsOrders: false, canConfirmOmsOrders: false } })
    await flushPromises()
    expect(denied.find('[data-testid="master-import"]').exists()).toBe(false)
    denied.unmount()
  })

  it('does not show the trash filter to a non-admin reader', () => {
    const wrapper = mount(Master, { props: { ...props, canSeeTrash: false } })
    expect(wrapper.findAll('.quick-chip')).toHaveLength(5)
    wrapper.unmount()
  })
})
