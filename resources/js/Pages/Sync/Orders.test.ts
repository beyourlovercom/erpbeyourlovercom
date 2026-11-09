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

const woo = {
  id: 1, erp_order_id: '007-0908-c1', external_order_number: 'SOURCE-007',
  order_date: '2026-11-09', recipient_name: 'Test', source_type: 'woocommerce',
  fulfillment_suffix: null, root_order_id: 1, archived: false,
  status: 'pending', synced_at: null, tracking_number: 'TRACK-1',
  shipment_status: 'shipped', carrier: 'DHL', tracking_outbound_status: null,
  tracking_outbound_attempts: null, tracking_sync_status: 'pending', tracking_sync_reason: null,
  tracking_sync_verified_at: null, progress: { code: 'unsynced' as 'unsynced' | 'failed' | 'synced' | 'notified', label: '未同步', reason: '待同步' as string | null },
  can_sync: true, sync_block_reason: null as string | null, can_reconcile: true, can_operate: true,
}
const pagination = { total: 100, current_page: 1, last_page: 2, per_page: 50, from: 1, to: 50 }
const props = (orders = [woo]) => ({ orders, filters: { search: '', source: '', per_page: 50 }, pagination })

describe('Sync all-orders simple status UI', () => {
  beforeEach(() => {
    get.mockClear()
    post.mockClear()
    vi.stubGlobal('confirm', vi.fn(() => true))
    vi.stubGlobal('alert', vi.fn())
  })

  it('shows source ERP and original order IDs with the operational progress at the right edge', () => {
    const wrapper = mount(Orders, { props: props() })
    expect(wrapper.text()).toContain('订单同步总表')
    expect(wrapper.text()).toContain('全部订单 100 单')
    expect(wrapper.text()).toContain('007-0908-c1')
    expect(wrapper.text()).toContain('SOURCE-007')
    expect(wrapper.text()).toContain('未同步')
    expect(wrapper.findAll('thead th').slice(0, 8).at(-1)?.text()).toBe('同步进度')
    expect(wrapper.text()).not.toContain('SYNC ORCHESTRATION')
  })

  it('distinguishes failed, synced, notified and unsynced without inferring email delivery', () => {
    const failed = { ...woo, id: 2, progress: { code: 'failed' as const, label: '同步失败', reason: '承运商冲突' }, can_sync: false, can_reconcile: true }
    const synced = { ...woo, id: 3, progress: { code: 'synced' as const, label: '已同步', reason: null }, can_sync: false }
    const notified = { ...woo, id: 4, progress: { code: 'notified' as const, label: '已通知', reason: 'Customer Note 回读通过' }, can_sync: false }
    const wrapper = mount(Orders, { props: props([woo, failed, synced, notified]) })
    const badges = wrapper.findAll('tbody tr .progress-badge')
    expect(badges.map((item) => item.text())).toEqual(['未同步', '同步失败', '已同步', '已通知'])
    expect(wrapper.text()).toContain('不保证邮件到达')
    expect(wrapper.findAll('.operational-actions button').filter((item) => item.text() === '查看原因')).toHaveLength(1)
  })

  it('shows 同步网站 on every unsuccessful operator row while retaining server-backed safety gates', async () => {
    const archived = { ...woo, id: 2, archived: true, can_sync: false, can_reconcile: false, sync_block_reason: '订单已归档，请先恢复' }
    const conflict = { ...woo, id: 3, progress: { code: 'failed' as const, label: '同步失败', reason: '承运商冲突' }, can_sync: false, can_reconcile: true, sync_block_reason: '官网物流存在冲突' }
    const readonly = { ...woo, id: 4, can_sync: false, can_reconcile: false, can_operate: false }
    const synced = { ...woo, id: 5, progress: { code: 'synced' as const, label: '已同步', reason: null }, can_sync: false }
    const notified = { ...woo, id: 6, progress: { code: 'notified' as const, label: '已通知', reason: null }, can_sync: false }
    const wrapper = mount(Orders, { props: props([woo, archived, conflict, readonly, synced, notified]) })
    const actionRows = wrapper.findAll('tbody tr .operational-actions')
    expect(actionRows.map(row => row.findAll('button').filter(item => item.text() === '同步网站').length)).toEqual([1, 1, 1, 0, 0, 0])
    const eligible = actionRows[0].findAll('button').find(item => item.text() === '同步网站')!
    expect(eligible.classes()).toContain('mvp-button-primary')
    await eligible.trigger('click')
    expect(post).toHaveBeenCalledWith('/sync/orders/1/tracking-outbound')
    expect(window.confirm).toHaveBeenCalled()

    const blocked = actionRows[1].findAll('button').find(item => item.text() === '同步网站')!
    expect(blocked.classes()).toContain('sync-blocked')
    await blocked.trigger('click')
    expect(window.alert).toHaveBeenCalledWith('订单已归档，请先恢复')
    expect(post).toHaveBeenCalledTimes(1)

    await actionRows[2].findAll('button').find(item => item.text() === '同步网站')!.trigger('click')
    expect(window.alert).toHaveBeenCalledWith('官网物流存在冲突')
    expect(post).toHaveBeenCalledTimes(1)
    await actionRows[2].findAll('button').find(item => item.text() === '核对官网')!.trigger('click')
    expect(post).toHaveBeenCalledWith('/sync/orchestration/reconcile', { order_ids: [3] })
  })

  it('shows 同步网站 for non-Woo unsynced orders but explains why no write can happen', async () => {
    const imported = { ...woo, id: 7, source_type: 'manual_other', can_sync: false,
      can_reconcile: false, sync_block_reason: '该订单未关联 WooCommerce 官网订单，不能直接同步网站。' }
    const wrapper = mount(Orders, { props: props([imported]) })
    await wrapper.findAll('.operational-actions button').find(item => item.text() === '同步网站')!.trigger('click')
    expect(window.alert).toHaveBeenCalledWith('该订单未关联 WooCommerce 官网订单，不能直接同步网站。')
    expect(post).not.toHaveBeenCalled()
  })

  it('does not trigger any write when an operator cancels the sync confirmation', async () => {
    vi.stubGlobal('confirm', vi.fn(() => false))
    const wrapper = mount(Orders, { props: props([woo]) })
    await wrapper.findAll('.operational-actions button').find(item => item.text() === '同步网站')!.trigger('click')
    expect(post).not.toHaveBeenCalled()
  })

  it('searches all orders, paginates, and keeps the source filter', async () => {
    const wrapper = mount(Orders, { props: props() })
    const search = wrapper.find('input[aria-label="搜索订单"]')
    await search.setValue('SOURCE-007')
    await wrapper.find('form.overview-filters').trigger('submit')
    expect(get).toHaveBeenCalledWith('/sync/orders', expect.objectContaining({ search: 'SOURCE-007', page: 1, per_page: 50 }), expect.any(Object))
    await wrapper.findAll('.page-controls button').find((item) => item.text() === '下一页')!.trigger('click')
    expect(get).toHaveBeenCalledWith('/sync/orders', expect.objectContaining({ page: 2, search: 'SOURCE-007' }), expect.any(Object))
    await wrapper.find('select[aria-label="订单来源"]').setValue('kol')
    expect(get).toHaveBeenCalledWith('/sync/orders', expect.objectContaining({ source: 'kol', page: 1 }), expect.any(Object))
  })

  it('keeps low-level retries and run metrics collapsed rather than before the orders table', () => {
    const wrapper = mount(Orders, { props: {
      ...props(),
      queueSummary: { retry_due: 2, retry_waiting: 3, processing: 4, failed: 5, conflicts: 6 },
      syncRuns: [{ id: 9, run_type: 'tracking_all_pending', status: 'partial', requested_items: 7,
        succeeded_items: 4, retrying_items: 1, failed_items: 1, conflict_items: 1, skipped_items: 0, started_at: '2026-11-09T04:00:00Z' }],
    } })
    const advanced = wrapper.find('details.advanced-panel')
    expect(advanced.exists()).toBe(true)
    expect(advanced.attributes('open')).toBeUndefined()
    expect(wrapper.find('.overview-table').exists()).toBe(true)
    expect(wrapper.find('.overview-table').element.compareDocumentPosition(advanced.element)).toBe(Node.DOCUMENT_POSITION_FOLLOWING)
    expect(advanced.text()).toContain('tracking_all_pending')
  })

  it('hides privileged actions on viewer-only order rows', () => {
    const viewer = { ...woo, can_sync: false, can_reconcile: false, can_operate: false }
    const wrapper = mount(Orders, { props: { ...props([viewer]), canOperate: false } })
    expect(wrapper.findAll('.operational-actions button')).toHaveLength(0)
    expect(wrapper.text()).not.toContain('恢复超时任务')
    expect(wrapper.text()).not.toContain('人工标记完成')
  })

  it('preserves validated Woo inbound import controls inside advanced tools', async () => {
    const wrapper = mount(Orders, { props: {
      ...props([]), canOperate: true,
      wooOrderSync: { health: 'failed' as const, last_success_at: null, last_failure: { run_id: 18, at: null, error_code: 'network', message: 'retry' }, can_import_today: true },
    } })
    const advanced = wrapper.find('details.advanced-panel')
    expect(advanced.text()).toContain('网站订单导入')
    expect(advanced.text()).toContain('最近执行失败')
    expect(advanced.find('a[href="/sync/runs/18"]').exists()).toBe(true)
    await advanced.findAll('button').find((button) => button.text() === '导入网站新订单')!.trigger('click')
    expect(post).toHaveBeenCalledWith('/sync/woocommerce/incremental', {}, expect.objectContaining({
      preserveScroll: true, onFinish: expect.any(Function),
    }))
  })

  it('never renders Woo inbound action for users without permission', () => {
    const wrapper = mount(Orders, { props: {
      ...props([]), canOperate: false,
      wooOrderSync: { health: 'disabled' as const, last_success_at: null, last_failure: null, can_import_today: false },
    } })
    expect(wrapper.text()).not.toContain('导入网站新订单')
    expect(wrapper.text()).not.toContain('核对网站未导入订单')
  })
})
