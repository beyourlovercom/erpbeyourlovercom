import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  router: { get: vi.fn(), post },
}))

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

const woo = {
  id: 1,
  internal_order_number: 'WOO-1',
  recipient_name: 'Test',
  status: 'pending',
  synced_at: null,
  synced_by: null,
  tracking_number: 'TRACK-1',
  shipment_status: 'shipped',
  carrier: 'DHL',
  source_type: 'woocommerce',
  tracking_outbound_status: null,
  tracking_outbound_attempts: null,
}

describe('Sync tracking outbound UI', () => {
  it('shows one-click outbound only for eligible Woo shipped orders', async () => {
    const wrapper = mount(Index, { props: { orders: [woo], filters: {} } })
    expect(wrapper.text()).toContain('Tracking 写回')
    expect(wrapper.text()).toContain('未执行')
    const button = wrapper.findAll('button').find((item) => item.text() === '写回 Tracking')
    expect(button?.exists()).toBe(true)
    await button!.trigger('click')
    expect(post).toHaveBeenCalledWith('/sync/orders/1/tracking-outbound')
  })

  it('hides outbound action for non-Woo or unshipped orders', () => {
    const manual = { ...woo, id: 2, source_type: 'manual' }
    const unshipped = { ...woo, id: 3, shipment_status: 'pending' }
    const wrapper = mount(Index, { props: { orders: [manual, unshipped], filters: {} } })
    expect(wrapper.findAll('button').filter((item) => item.text() === '写回 Tracking')).toHaveLength(0)
  })

  it('renders retry and completed outbox states explicitly', () => {
    const retry = { ...woo, id: 4, tracking_outbound_status: 'pending', tracking_outbound_attempts: 1 }
    const done = { ...woo, id: 5, status: 'completed', tracking_outbound_status: 'completed', tracking_outbound_attempts: 1 }
    const wrapper = mount(Index, { props: { orders: [retry, done], filters: {} } })
    expect(wrapper.text()).toContain('待重试')
    expect(wrapper.text()).toContain('已完成')
  })
})
