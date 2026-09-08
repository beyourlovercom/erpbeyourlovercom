import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Orders from './Orders.vue'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { get, post },
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

const base = {
  id: 1,
  erp_order_id: '007-0908-c1',
  recipient_name: 'Test Customer',
  status: 'needs_confirmation',
  synced_at: null,
  tracking_number: 'TRACK-1',
  shipment_status: 'shipped',
  carrier: 'DHL',
  source_type: 'woocommerce',
}

describe('Customer fulfillment review UI', () => {
  beforeEach(() => { get.mockClear(); post.mockClear() })

  it('renders only the simple fulfillment review surface', () => {
    const wrapper = mount(Orders, { props: { orders: [base], filters: {} } })
    expect(wrapper.text()).toContain('发货审核')
    expect(wrapper.text()).toContain('物流商')
    expect(wrapper.text()).toContain('物流单号')
    expect(wrapper.text()).not.toContain('批量写回所选')
    expect(wrapper.text()).not.toContain('重试到期任务')
    expect(wrapper.text()).not.toContain('Run')
    expect(wrapper.text()).not.toContain('冲突')
  })

  it('shows canonical ERP order number and shipment facts', () => {
    const wrapper = mount(Orders, { props: { orders: [base], filters: {} } })
    expect(wrapper.text()).toContain('007-0908-c1')
    expect(wrapper.text()).toContain('Test Customer')
    expect(wrapper.text()).toContain('DHL')
    expect(wrapper.text()).toContain('TRACK-1')
  })

  it('offers only approval while waiting for operations review', async () => {
    const wrapper = mount(Orders, { props: { orders: [base], filters: {} } })
    expect(wrapper.text()).toContain('待审核')
    const approve = wrapper.findAll('button').find((button) => button.text() === '审核通过')
    expect(approve?.exists()).toBe(true)
    expect(wrapper.findAll('button').some((button) => button.text() === '同步到网站')).toBe(false)
    await approve!.trigger('click')
    expect(post).toHaveBeenCalledWith('/sync/orders/1/approve', {})
  })

  it('offers website sync only after approval', async () => {
    const approved = { ...base, status: 'pending' }
    const wrapper = mount(Orders, { props: { orders: [approved], filters: {} } })
    expect(wrapper.text()).toContain('已审核 · 待同步')
    expect(wrapper.findAll('button').some((button) => button.text() === '审核通过')).toBe(false)
    const sync = wrapper.findAll('button').find((button) => button.text() === '同步到网站')
    expect(sync?.exists()).toBe(true)
    await sync!.trigger('click')
    expect(post).toHaveBeenCalledWith('/sync/orders/1/tracking-outbound')
  })

  it('shows completed orders without another business action', () => {
    const completed = { ...base, status: 'completed', synced_at: '2026-09-08T09:00:00Z' }
    const wrapper = mount(Orders, { props: { orders: [completed], filters: {} } })
    expect(wrapper.text()).toContain('已同步')
    expect(wrapper.text()).toContain('已完成')
    expect(wrapper.findAll('button').some((button) => ['审核通过', '同步到网站'].includes(button.text()))).toBe(false)
  })
})
