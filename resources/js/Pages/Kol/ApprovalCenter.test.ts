import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApprovalCenter from './ApprovalCenter.vue'

const { postMock } = vi.hoisted(() => ({ postMock: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { post: postMock },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const workspace = {
  counts: { kols: 1, collaborations: 1, payouts: 1, history: 2 },
  kols: [{ id: 5, display_name: 'Pending KOL', owner_name: 'Owner A', created_at: '2026-09-01T01:00:00+00:00', approval_updated_at: null, reason_code: 'policy_all_requires_manager' }],
  collaborations: [{ id: 8, kol_id: 5, kol_name: 'Pending KOL', owner_name: 'Owner A', title: 'Paid post', cooperation_type: '付费发帖', amount: '250.00', currency: 'USD', created_at: '2026-09-01T02:00:00+00:00' }],
  payouts: [{ id: 11, kol_id: 5, kol_name: 'Pending KOL', requester_name: 'Specialist A', affiliate_id: 7, amount: '10.000000', currency: 'USD', referral_ids: [91, 92], created_at: '2026-09-01T03:00:00+00:00' }],
  history: [
    { record_key: 'kol:20', type: 'kol' as const, object_id: 9, object_label: 'Approved Creator', outcome: 'approved' as const, actor_name: 'Manager A', decided_at: '2026-09-01T04:00:00+00:00', source: 'manager_manual', detail: 'manager_approved', reason: null, amount: null, currency: null },
    { record_key: 'collaboration:21', type: 'collaboration' as const, object_id: 13, object_label: 'Rejected Creator · Paid post', outcome: 'rejected' as const, actor_name: 'Manager A', decided_at: '2026-09-01T05:00:00+00:00', source: 'manager_rejected', detail: '付费发帖', reason: '预算不符', amount: '300.00', currency: 'USD' },
  ],
}

describe('KOL Manager Approval Center', () => {
  beforeEach(() => postMock.mockReset())
  it('aggregates the three manager approval queues', () => {
    const wrapper = mount(ApprovalCenter, { props: { workspace } })
    expect(wrapper.text()).toContain('KOL 审批')
    expect(wrapper.text()).toContain('付费合作')
    expect(wrapper.text()).toContain('佣金付款')
    expect(wrapper.text()).toContain('3待处理')
  })
  it('approves KOL from the manager surface', async () => {
    const wrapper = mount(ApprovalCenter, { props: { workspace } })
    await wrapper.findAll('button').find(button => button.text() === '批准')!.trigger('click')
    expect(postMock).toHaveBeenCalledWith('/kol/approvals/kols/5/approve', {}, { preserveScroll: true })
  })

  it('shows approved and rejected history with audit details', async () => {
    const wrapper = mount(ApprovalCenter, { props: { workspace } })
    await wrapper.findAll('button').find(button => button.text().includes('审批记录'))!.trigger('click')
    expect(wrapper.text()).toContain('Approved Creator')
    expect(wrapper.text()).toContain('已批准')
    expect(wrapper.text()).toContain('Rejected Creator')
    expect(wrapper.text()).toContain('已驳回')
    expect(wrapper.text()).toContain('预算不符')
    expect(wrapper.text()).toContain('Manager A')
  })

  it('payout approval does not expose finance execution controls', async () => {
    const wrapper = mount(ApprovalCenter, { props: { workspace } })
    await wrapper.findAll('button').find(button => button.text().includes('佣金付款'))!.trigger('click')
    expect(wrapper.text()).toContain('批准付款')
    expect(wrapper.text()).toContain('Finance 已完成真实付款')
    expect(wrapper.text()).not.toContain('PayPal Transaction ID')
  })
})
