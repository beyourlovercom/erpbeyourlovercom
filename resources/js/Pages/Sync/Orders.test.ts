import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Orders from './Orders.vue'

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
  erp_order_id: '007-0908-c1',
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
  beforeEach(() => post.mockClear())

  it('shows the canonical 007 ERP order id instead of the internal number', () => {
    const wrapper = mount(Orders, { props: { orders: [woo], filters: {} } })
    expect(wrapper.text()).toContain('007-0908-c1')
    expect(wrapper.text()).not.toContain('WOO-1')
  })

  it('offers the legacy outbound action only for shipped Woo orders with Tracking', async () => {
    const wrapper = mount(Orders, { props: { orders: [woo], filters: {} } })
    expect(wrapper.text()).toContain('官网物流')
    expect(wrapper.text()).toContain('待同步')
    const button = wrapper.findAll('button').find((item) => item.text() === '同步官网物流')
    expect(button?.exists()).toBe(true)
    await button!.trigger('click')
    expect(post).toHaveBeenCalledWith('/sync/orders/1/tracking-outbound')
  })

  it('hides outbound action for non-Woo or unshipped orders', () => {
    const manual = { ...woo, id: 2, source_type: 'manual' }
    const unshipped = { ...woo, id: 3, shipment_status: 'pending', tracking_number: null }
    const wrapper = mount(Orders, { props: { orders: [manual, unshipped], filters: {} } })
    expect(wrapper.findAll('button').filter((item) => item.text() === '同步官网物流')).toHaveLength(0)
  })

  it('renders retry and completed outbox states explicitly', () => {
    const retry = { ...woo, id: 4, tracking_outbound_status: 'pending', tracking_outbound_attempts: 1 }
    const done = { ...woo, id: 5, status: 'completed', tracking_outbound_status: 'completed', tracking_outbound_attempts: 1 }
    const wrapper = mount(Orders, { props: { orders: [retry, done], filters: {} } })
    expect(wrapper.text()).toContain('待重试')
    expect(wrapper.text()).toContain('已完成')
  })
  it('hides the sync action after verified success and restores retry after a failure', () => {
    const verified = { ...woo, id: 8, tracking_sync_status: 'completed' }
    const failed = { ...woo, id: 9, tracking_sync_status: 'failed', tracking_sync_reason: 'Woo write failed' }
    const wrapper = mount(Orders, { props: { orders: [verified, failed], filters: {} } })
    expect(wrapper.findAll('button').filter((item) => item.text() === '同步官网物流')).toHaveLength(0)
    expect(wrapper.findAll('button').filter((item) => item.text() === '重新同步')).toHaveLength(1)
    expect(wrapper.text()).toContain('Woo write failed')
  })

  it('posts selected and orchestration actions to canonical routes', async () => {
    const wrapper = mount(Orders, { props: { orders: [woo], filters: {} } })
    await wrapper.find('input[type="checkbox"]').setValue(true)

    const click = async (label: string) => {
      const button = wrapper.findAll('button').find((item) => item.text() === label)
      expect(button?.exists()).toBe(true)
      await button!.trigger('click')
    }

    await click('批量写回所选')
    expect(post).toHaveBeenCalledWith('/sync/orchestration/tracking/selected', { order_ids: [1] })
    await click('写回全部待同步')
    expect(post).toHaveBeenCalledWith('/sync/orchestration/tracking/all-pending', { limit: 100 })
    await click('重试到期任务')
    expect(post).toHaveBeenCalledWith('/sync/orchestration/retry', { limit: 100 })
    await click('恢复超时任务')
    expect(post).toHaveBeenCalledWith('/sync/orchestration/recover-stale', { age_seconds: 300, limit: 100 })
    await click('核对所选 Tracking')
    expect(post).toHaveBeenCalledWith('/sync/orchestration/reconcile', { order_ids: [1] })
  })

  it('confines Woo health and manual actions to the sync orders workspace', async () => {
    const wrapper = mount(Orders, { props: {
      orders: [], filters: {},
      wooOrderSync: { health: 'failed', last_success_at: null, last_failure: { run_id: 18, at: null, error_code: 'network', message: 'retry' }, can_import_today: true },
    } })
    expect(wrapper.text()).toContain('网站订单同步')
    expect(wrapper.text()).toContain('最近执行失败')
    expect(wrapper.text()).toContain('今日未同步：待核对')
    expect(wrapper.find('a[href="/sync/runs/18"]').exists()).toBe(true)
    await wrapper.findAll('button').find((button) => button.text() === '同步网站订单')!.trigger('click')
    expect(post).toHaveBeenCalledWith('/sync/woocommerce/incremental', {}, expect.objectContaining({
      preserveScroll: true, onFinish: expect.any(Function),
    }))
  })

  it('never shows Woo manual controls to users without import access', () => {
    const wrapper = mount(Orders, { props: {
      orders: [], filters: {},
      wooOrderSync: { health: 'disabled', last_success_at: null, last_failure: null, can_import_today: false },
    } })
    expect(wrapper.text()).toContain('状态：未启用')
    expect(wrapper.text()).not.toContain('同步网站订单')
    expect(wrapper.text()).not.toContain('核对网站未同步')
  })

  it('renders queue summary and recent sync runs', () => {
    const wrapper = mount(Orders, { props: {
      orders: [], filters: {},
      queueSummary: { retry_due: 2, retry_waiting: 3, processing: 4, failed: 5, conflicts: 6 },
      syncRuns: [{
        id: 9, run_type: 'tracking_all_pending', status: 'partial', requested_items: 7,
        succeeded_items: 4, retrying_items: 1, failed_items: 1, conflict_items: 1,
        skipped_items: 0, started_at: '2026-08-28T04:00:00Z',
      }],
    } })

    expect(wrapper.text()).toContain('到期重试')
    expect(wrapper.text()).toContain('等待重试')
    expect(wrapper.text()).toContain('tracking_all_pending')
    expect(wrapper.text()).toContain('partial')
    expect(wrapper.find('a[href="/sync/runs/9"]').exists()).toBe(true)
  })
})
