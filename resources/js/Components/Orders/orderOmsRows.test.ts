import { describe, expect, it } from 'vitest'
import { toOrderOmsRow, type OrderGridOrder } from './orderOmsRows'

const base = (overrides: Partial<OrderGridOrder> = {}): OrderGridOrder => ({
  id: 7870,
  erp_order_id: '007-1105-40223',
  external_order_number: 'WEB-40223',
  currency: 'USD',
  items: [],
  ...overrides,
})

describe('OMS order-column projection', () => {
  it('shows SKU separately from total ordered units across multiple item lines', () => {
    const row = toOrderOmsRow(base({ items: [
      { sku: 'MF414A', product_summary: 'Product One', quantity: 2, unit_price: '79.00', line_revenue: '158.00' },
      { sku: 'A1', product_summary: 'Product Two', quantity: 3, unit_price: '29.00', line_revenue: '87.00' },
      { sku: 'MF414A', product_summary: 'Product One', quantity: 1, unit_price: '79.00', line_revenue: '79.00' },
    ] }))
    expect(row.sku_list).toBe('MF414A, A1')
    expect(row.order_quantity).toBe(6)
    expect(row.item_line_count).toBe(3)
    expect(row.sku_type_count).toBe(2)
    expect(row.sku_quantity_detail).toBe('MF414A × 2; A1 × 3; MF414A × 1')
    expect(row.product_names).toBe('Product One, Product Two')
    expect(row.item_subtotal).toBe(324)
  })
  it('does not invent zero units or order totals when no order items are provided', () => {
    const row = toOrderOmsRow(base())
    expect(row.order_quantity).toBeNull()
    expect(row.sku_list).toBe('—')
    expect(row.item_subtotal).toBeNull()
    expect(row.item_line_count).toBeNull()
  })
  it('keeps known zero-price gift line quantity and finance values distinct', () => {
    const row = toOrderOmsRow(base({ currency: 'EUR', items: [
      { sku: 'GIFT', quantity: 1, line_revenue: '0', unit_price: '0' },
      { sku: 'A1', quantity: 2, line_revenue: null },
    ] }))
    expect(row.sku_list).toBe('GIFT, A1')
    expect(row.order_quantity).toBe(3)
    expect(row.item_subtotal).toBe(0)
    expect(row.currency).toBe('EUR')
    expect(row.unit_price_detail).toContain('GIFT EUR 0.00')
  })
  it('flattens existing address, shipment, source, sync and notes without additional API reads', () => {
    const row = toOrderOmsRow(base({
      address: { recipient_name: 'Emma', country: 'US', city: 'Los Angeles', postal_code: '90001' },
      shipment: { warehouse_name: 'US-NY', carrier: 'USPS', tracking_number: '9400' },
      manual_sync: { status: 'synced', synced_at: '2026-11-05' },
      order_note: 'Handle with care',
      operational_markers: ['exception'],
    }))
    expect(row).toMatchObject({
      recipient: 'Emma', country: 'US', city: 'Los Angeles', postal_code: '90001',
      warehouse_name: 'US-NY', carrier: 'USPS', tracking_number: '9400',
      manual_sync_status: 'synced', order_note: 'Handle with care',
      operational_markers_label: 'exception',
    })
  })
})
