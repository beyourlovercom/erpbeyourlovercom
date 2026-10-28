export type AllocationStockWarning = {
  warehouseCode: string
  warehouseName: string
  sku: string
  requiredQuantity: number
  availableQuantity: number | null
  reason: 'unknown' | 'insufficient'
}
export type AllocationStockSelection = {
  warehouseCode: string
  warehouseName: string
  sku: string
  productId: number | null
  quantity: number
  availableQuantity: number | null
  isGift?: boolean
}
export function allocationStockWarnings(lines: AllocationStockSelection[]): AllocationStockWarning[] {
  const grouped = new Map<string, AllocationStockSelection>()
  for (const line of lines) {
    if (line.isGift || !line.warehouseCode) continue
    const code = line.warehouseCode.toUpperCase()
    const key = code + ':' + (line.productId ? String(line.productId) : 'sku:' + line.sku)
    const existing = grouped.get(key)
    if (existing) existing.quantity += line.quantity
    else grouped.set(key, { ...line, warehouseCode: code })
  }
  return Array.from(grouped.values()).filter(line =>
    line.availableQuantity === null || line.availableQuantity < line.quantity,
  ).map(line => ({
    warehouseCode: line.warehouseCode,
    warehouseName: line.warehouseName,
    sku: line.sku,
    requiredQuantity: line.quantity,
    availableQuantity: line.availableQuantity,
    reason: line.availableQuantity === null ? 'unknown' : 'insufficient',
  }))
}
