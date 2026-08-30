import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Dashboard from './Dashboard.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<title />' },
  Link: { template: '<a><slot /></a>' },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

describe('Customer Dashboard support KPI', () => {
  it('renders the customer pending support metric', () => {
    const wrapper = mount(Dashboard, { props: {
      metrics: { total_customers: 1, new_this_month: 0, orders_this_month: 0, revenue_this_month: [], subscriber_identity_links: 0, pending_support_tickets: 3 },
      canOperate: false, canImport: false, canExport: false,
    } })
    expect(wrapper.text()).toContain('待处理工单')
    expect(wrapper.text()).toContain('3')
  })
})
