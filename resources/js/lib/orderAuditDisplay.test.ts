import { describe, expect, it } from 'vitest'
import { orderAuditAction, orderAuditActor, orderAuditTime } from './orderAuditDisplay'

describe('order audit display', () => {
  it('translates screenshot events and common logistics actions', () => {
    const actions = {
      'order.note_appended': '添加订单备注',
      'order.inbound_updated': '更新同步订单信息',
      'finance.fixed_cost_materialization.applied': '核算固定费用',
      'finance.payment_fee_materialization.applied': '核算支付手续费',
      'finance.product_cost_materialization.applied': '核算商品成本',
      'order.inbound_created': '同步并创建订单',
      'domestic.review_approve': '国内物流审核通过',
      'oversea.review_reject': '海外仓审核驳回',
    }
    for (const [action, label] of Object.entries(actions)) expect(orderAuditAction(action)).toBe(label)
    expect(orderAuditAction('new.event')).toBe('其他操作（new.event）')
  })
  it('uses the actual actor, distinguishes system and unresolved accounts', () => {
    expect(orderAuditActor({ actor_id: 12, actor_name: 'Vita' })).toBe('Vita')
    expect(orderAuditActor({ actor_id: null })).toBe('系统自动执行')
    expect(orderAuditActor({ actor_id: 1 })).toBe('未知账号（ID：1）')
  })
  it('converts UTC, date rollover, and already offset timestamps without double conversion', () => {
    expect(orderAuditTime('2026-11-24T07:52:51+00:00')).toBe('2026-11-24 15:52:51')
    expect(orderAuditTime('2026-11-23T19:30:17+00:00')).toBe('2026-11-24 03:30:17')
    expect(orderAuditTime('2026-11-24T15:52:51+08:00')).toBe('2026-11-24 15:52:51')
    expect(orderAuditTime('2026-11-23T16:00:00Z')).toBe('2026-11-24 00:00:00')
    expect(orderAuditTime(null)).toBe('时间未知')
  })
})
