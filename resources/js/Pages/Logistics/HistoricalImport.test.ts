import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import HistoricalImport from './HistoricalImport.vue'

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: routerGet },
}))
vi.mock('axios', () => ({ default: { post: vi.fn() } }))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const batch = {
  id: 9, filename: '0902-网红订单.xlsx', checksum: 'a'.repeat(64), sheet: '工作表1', reconciliation_batch_id: 1,
  status: 'preview', total_rows: 1077, ready_count: 820, review_count: 185, skip_count: 72,
  rows: [{ id: 1, source_row_number: 6, source_order_no: 'KOL120204', kol: { id: 920, display_name: 'Creator', url: '/kol/library/920' }, carrier: '速达', tracking: '4PX3002296509905CN', tracking_raw: '4PX3002296509905CN', warehouse: 'Factory 国内仓', classification: 'ready' as const, reason: 'exact_tracking_and_kol_match' }],
  pagination: { current_page: 1, last_page: 22, per_page: 50, total: 1077 },
}

describe('Historical shipment evidence workspace', () => {
  it('renders exact KOL links and makes the non-canonical impact explicit', () => {
    const wrapper = mount(HistoricalImport, { props: { batch, commitEnabled: false, canOperate: false } })
    expect(wrapper.text()).toContain('历史发货证据导入')
    expect(wrapper.text()).toContain('Active Shipment 保持不变')
    expect(wrapper.text()).toContain('OFF · 默认')
    expect(wrapper.find('a[href="/kol/library/920"]').exists()).toBe(true)
    expect(wrapper.find('input[type="file"]').exists()).toBe(false)
  })

  it('uses server pagination and only exposes import controls to operators', async () => {
    routerGet.mockClear()
    const wrapper = mount(HistoricalImport, { props: { batch, commitEnabled: true, canOperate: true } })
    expect(wrapper.find('input[type="file"]').exists()).toBe(true)
    const next = wrapper.findAll('button').find((button) => button.text().includes('下一页'))
    expect(next).toBeTruthy()
    await next!.trigger('click')
    expect(routerGet).toHaveBeenCalledWith('/logistics/historical-import', { page: 2 }, { preserveState: true, replace: true, preserveScroll: true })
  })
})
