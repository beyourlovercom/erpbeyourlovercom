import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { router } from '@inertiajs/vue3'
import FulfillmentAllocation from './FulfillmentAllocation.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { name: 'Head', template: '<div />' },
  Link: { name: 'Link', template: '<a><slot /></a>' },
  router: { get: vi.fn(), reload: vi.fn(), visit: vi.fn() },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { name: 'AuthenticatedLayout', template: '<main><slot /></main>' },
}))
vi.mock('../../Components/Logistics/StockExceptionConfirmDialog.vue', () => ({
  default: { name: 'StockExceptionConfirmDialog', template: '<div />' },
}))

const props = {
  orders: { data: [], current_page: 1, last_page: 1, per_page: 20, total: 0 },
  warehouses: [], carrierOptions: [],
  pendingTotal: 31, rejectionCount: 2, rejectionNoticeVersion: 'first-rejection-version', rejectionFilter: false,
  canOperate: false, canArchive: false,
  globalGridPilotAvailable: false, globalGridPilotEnabled: false, gridSearch: '',
}
describe('Wait-stock rejection R2 warning UX', () => {
  beforeEach(() => {
    sessionStorage.clear()
    vi.clearAllMocks()
  })

  it('shows the backend total in the second tab and an actionable dismissible alert', async () => {
    const wrapper = mount(FulfillmentAllocation, { props })
    await nextTick()
    const tabs = wrapper.findAll('.allocation-page .tabs button')
    expect(tabs[0].text()).toContain('全部 31')
    expect(tabs[1].text()).toContain('驳回待处理 2')
    expect(wrapper.get('[data-testid="allocation-rejection-alert"]').text()).toContain('2 个订单被驳回')
    await wrapper.get('[data-testid="allocation-rejected-tab"]').trigger('click')
    expect(router.get).toHaveBeenCalledWith('/logistics/wait-stock',
      expect.objectContaining({ rejected: '1', page: 1, per_page: 20 }),
      expect.objectContaining({ preserveState: true, replace: true }))
    await wrapper.get('[aria-label="知道了，不再重复提醒"]').trigger('click')
    expect(wrapper.find('[data-testid="allocation-rejection-alert"]').exists()).toBe(false)
    const afterRefresh = mount(FulfillmentAllocation, { props })
    await nextTick()
    expect(afterRefresh.find('[data-testid="allocation-rejection-alert"]').exists()).toBe(false)
    expect(afterRefresh.get('[data-testid="allocation-rejected-tab"]').text()).toContain('2')
    const nextEvent = mount(FulfillmentAllocation, { props: {
      ...props, rejectionNoticeVersion: 'second-rejection-version',
    } })
    await nextTick()
    expect(nextEvent.find('[data-testid="allocation-rejection-alert"]').exists()).toBe(true)
  })

  it('opens the rejected tab when server filtering is active and has no banner for zero', () => {
    const wrapper = mount(FulfillmentAllocation, { props: {
      ...props, rejectionFilter: true, rejectionCount: 0, pendingTotal: 29,
    } })
    expect(wrapper.get('[data-testid="allocation-rejected-tab"]').classes()).toContain('active')
    expect(wrapper.find('[data-testid="allocation-rejection-alert"]').exists()).toBe(false)
  })
})
