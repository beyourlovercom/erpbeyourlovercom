import { describe, expect, it, vi } from 'vitest'
import { domesticReviewGridActions, type DomesticReviewRow } from './reviewGridActions'

const handlers = () => ({
  approve: vi.fn(), stockout: vi.fn(), reject: vi.fn(),
  restock: vi.fn(), returnAllocation: vi.fn(), reopen: vi.fn(),
})
const row: DomesticReviewRow = { order_id: 7, review_status: 'pending', shipment_status: 'pending' }

describe('R4 domestic review Grid preserves native operational actions', () => {
  it('places the 3 direct approval actions in review and calls existing handlers', () => {
    const h = handlers()
    const actions = domesticReviewGridActions('review', true, false, row, h)
    expect(actions.map(a => a.label)).toEqual(['通过', '缺货', '驳回'])
    expect(actions[2].danger).toBe(true)
    actions[0].onClick?.()
    actions[1].onClick?.()
    actions[2].onClick?.()
    expect(h.approve).toHaveBeenCalledOnce()
    expect(h.stockout).toHaveBeenCalledOnce()
    expect(h.reject).toHaveBeenCalledOnce()
  })
  it('shows exactly the one restock action for out-of-stock pending orders', () => {
    const h = handlers()
    const actions = domesticReviewGridActions('stockout', true, false,
      {...row, review_status: 'stockout'}, h)
    expect(actions.map(a => a.label)).toEqual(['已到货-可发货', '进待分仓'])
    actions[0].onClick?.()
    expect(h.restock).toHaveBeenCalledOnce()
    actions[1].onClick?.()
    expect(h.returnAllocation).toHaveBeenCalledOnce()
  })
  it('only exposes reopen for an approved pending order outside review', () => {
    const actions = domesticReviewGridActions('pending', true, false,
      {...row, review_status: 'approved'}, handlers())
    expect(actions.map(a => a.label)).toEqual(['重新审核'])
  })
  it('never exposes unauthorized review writes and avoids invalid shipped restock', () => {
    const h = handlers()
    expect(domesticReviewGridActions('review', false, false, row, h)).toEqual([])
    expect(domesticReviewGridActions('stockout', true, false,
      {...row, review_status: 'stockout', shipment_status: 'shipped'}, h)).toEqual([])
    expect(domesticReviewGridActions('review', true, false,
      {...row, review_status: 'stockout'}, h).map(a=>a.label)).toEqual(['通过','驳回'])
    expect(Object.values(h).every(fn=>fn.mock.calls.length===0)).toBe(true)
  })
  it('disables all review actions while a write is in flight', () => {
    const actions = domesticReviewGridActions('review', true, true, row, handlers())
    expect(actions.map(a => a.disabled)).toEqual([true,true,true])
  })
})
