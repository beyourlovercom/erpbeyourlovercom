import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import Cell from './DomesticGridPendingCell.vue'

const source = {
  order_id: 42, review_status: 'approved', shipment_status: 'pending',
  carrier: '4PX', service: 'SD经济挂号-带电', carrier_ready: true,
  tracking_number: null,
}
const params = () => ({
  data: { id: 42, order_number: '007-OLD-42', review_status: 'approved', shipment_status: 'pending' },
  compactActions: true,
  canOperate: true, canReview: true, canTrackingSync: false,
  carrierOptions: [{ value: '4PX', label: '速达', services: [{ value: 'SD经济挂号-带电', label: '带电' }] }],
  source: () => source, carrierBusy: () => false, trackingBusy: () => false,
  feedback: () => '', saveCarrier: vi.fn(), saveTracking: vi.fn(),
  confirmShip: vi.fn(), reopenPending: vi.fn(),
  syncEligible: () => false, syncBusy: () => false, syncTracking: vi.fn(),
})

describe('G-P1 compact cumulative Speeda history actions', () => {
  afterEach(() => { document.querySelectorAll('.domestic-pending-compact-menu,.domestic-pending-popover').forEach(n => n.remove()) })
  it('saves horizontal space and reuses the guarded inline shipment editor', async () => {
    const p = params()
    const view = mount(Cell, { props: { params: p }, attachTo: document.body })
    expect(view.findAll('button')).toHaveLength(1)
    await view.get('button[aria-label="操作"]').trigger('click')
    expect(document.querySelector('.domestic-pending-compact-menu')).not.toBeNull()
    const edit = [...document.querySelectorAll('.domestic-pending-compact-menu button')]
      .find(b => b.textContent === '填写物流') as HTMLButtonElement
    expect(edit).toBeTruthy()
    edit.click()
    await view.vm.$nextTick()
    expect(document.querySelector('.domestic-pending-compact-menu')).toBeNull()
    const editor = document.querySelector('.domestic-pending-popover') as HTMLElement
    expect(editor).toBeTruthy()
    const ship = [...editor.querySelectorAll('button')].find(b => b.textContent === '确认发货') as HTMLButtonElement
    expect(ship.disabled).toBe(true)
    expect(p.confirmShip).not.toHaveBeenCalled()
    const cancel = [...editor.querySelectorAll('button')].find(b => b.textContent === '取消') as HTMLButtonElement
    cancel.click()
    await view.vm.$nextTick()
    expect(document.querySelector('.domestic-pending-popover')).toBeNull()
    view.unmount()
  })
  it('with no permission and no sync-eligible shipment renders only a dash', () => {
    const p = { ...params(), canOperate: false, canReview: false }
    const view = mount(Cell, { props: { params: p } })
    expect(view.text()).toContain('—')
    expect(view.find('button').exists()).toBe(false)
    view.unmount()
  })
  it('retains the original unmodified direct actions on daily and pending views', () => {
    const view = mount(Cell, { props: { params: { ...params(), compactActions: false } } })
    expect(view.find('button[aria-label="操作"]').exists()).toBe(false)
    expect(view.findAll('button').map(b => b.text())).toContain('确认发货')
    view.unmount()
  })
})
