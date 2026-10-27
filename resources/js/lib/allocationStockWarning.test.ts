import { describe, it, expect } from 'vitest'
import { allocationStockWarnings } from './allocationStockWarning'

describe('manual fulfillment stock exception warnings', () => {
  it('warns when available inventory is below demand and when the source is unknown', () => {
    const warnings = allocationStockWarnings([
      { warehouseCode: 'US-LA', warehouseName: 'MorePlus', productId: 123, sku: '414sleeve', quantity: 2, availableQuantity: 1 },
      { warehouseCode: 'FACTORY', warehouseName: '中国工厂', productId: null, sku: 'SPECIAL', quantity: 1, availableQuantity: null },
      { warehouseCode: 'US-NY', warehouseName: '纽约仓', productId: 124, sku: 'IN-STOCK', quantity: 1, availableQuantity: 3 },
    ])
    expect(warnings).toHaveLength(2)
    expect(warnings[0]).toMatchObject({ warehouseCode: 'US-LA', requiredQuantity: 2, availableQuantity: 1 })
    expect(warnings[1]).toMatchObject({ warehouseCode: 'FACTORY', reason: 'unknown' })
  })
  it('sums split lines of the same product in the same warehouse, without blocking gift lines', () => {
    const warnings = allocationStockWarnings([
      { warehouseCode: 'us-la', warehouseName: 'MorePlus', productId: 12, sku: 'SAME', quantity: 2, availableQuantity: 3 },
      { warehouseCode: 'US-LA', warehouseName: 'MorePlus', productId: 12, sku: 'SAME', quantity: 2, availableQuantity: 3 },
      { warehouseCode: 'US-LA', warehouseName: 'MorePlus', productId: 14, sku: 'gift', isGift: true, quantity: 1, availableQuantity: 0 },
    ])
    expect(warnings).toHaveLength(1)
    expect(warnings[0]).toMatchObject({ requiredQuantity: 4, availableQuantity: 3, reason: 'insufficient' })
  })
})
