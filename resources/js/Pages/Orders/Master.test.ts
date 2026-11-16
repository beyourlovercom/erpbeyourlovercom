import { mount } from '@vue/test-utils'
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
vi.mock('../../Components/DataGrid/GlobalGrid.vue', () => ({ default: {
  props: ['rows', 'columns', 'namespace'], template: '<div data-testid="master-global-grid" />',
} }))

const props = {
  orders: { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0 },
  counts: { all: 8104, normal: 7978, operational: 126, trash: 0, pending: 450 },
  filters: { search: '', view: 'all', kind: 'all', source: 'all', sort: 'date', direction: 'desc', per_page: '50' },
  canSeeTrash: true,
}

describe('Orders read-only master workspace', () => {
  beforeEach(() => get.mockClear())

  it('shows one compact filter workspace without duplicate all/archive navigation tabs', () => {
    const wrapper = mount(Master, { props })
    expect(wrapper.find('.master-toolbar').exists()).toBe(true)
    expect(wrapper.findAll('.quick-filters .quick-chip')).toHaveLength(5)
    expect(wrapper.find('.master-heading').text()).toContain('8,104')
    expect(wrapper.find('.order-views-bar').exists()).toBe(false)
    expect(wrapper.find('a[href="/orders/archive"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('routes archive chip, kind selector and filters to server pagination', async () => {
    const wrapper = mount(Master, { props })
    await wrapper.findAll('.quick-chip').find(button => button.text().includes('运营归档'))!.trigger('click')
    expect(get).toHaveBeenCalledWith('/orders/master', expect.objectContaining({ view: 'operational', page: 1 }), expect.any(Object))
    await wrapper.find('select[aria-label="订单类型"]').setValue('kol_fulfillment')
    expect(get).toHaveBeenCalledWith('/orders/master', expect.objectContaining({ kind: 'kol_fulfillment', page: 1 }), expect.any(Object))
    wrapper.unmount()
  })

  it('does not show the trash filter to a non-admin reader', () => {
    const wrapper = mount(Master, { props: { ...props, canSeeTrash: false } })
    expect(wrapper.findAll('.quick-chip')).toHaveLength(4)
    wrapper.unmount()
  })
})
