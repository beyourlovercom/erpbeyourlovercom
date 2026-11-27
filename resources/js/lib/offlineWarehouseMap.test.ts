import { describe, expect, it } from 'vitest'
import { locate, warehouseRows, stockState, type Geography } from './offlineWarehouseMap'
import geography from './offline-geography.json'
import us from './offline-cities/US.json'
const g = { ...geography, cities:us } as Geography
describe('native offline geographic reference', () => {
  it('uses city or country fallback and fails closed for unknown country', () => {
    expect(locate({country:'US',city:'Los Angeles',province_state:'CA'},g)?.country).toBe('US')
    expect(locate({country:'DE',city:'not a city'},g)?.precision).toBe('country')
    expect(locate({country:'invalid'},g)).toBeNull()
  })
  it('sorts known distances and does not fabricate unknown locations', () => {
    const a=locate({country:'US',city:'Los Angeles'},g)
    const rows=warehouseRows(a,[{id:1,code:'US-LA',name:'LA'},{id:2,code:'DE',name:'DE'},{id:3,code:'UNKNOWN',name:'Unknown'},{id:4,code:'007',name:'007'}],[],g)
    expect(rows.map(r=>r.code)).toEqual(['US-LA','DE','UNKNOWN'])
    expect(rows[2]?.km).toBeNull()
  })
  it('aggregates duplicate SKU quantities and preserves unknown inventory and gifts', () => {
    const w={id:1,code:'US-LA',name:'LA'}
    const line={sku:'A',quantity:2,availability:[{warehouseId:1,available:3}]}
    expect(stockState(w,[line,line])).toBe('short')
    expect(stockState(w,[line])).toBe('ok')
    expect(stockState(w,[{...line,is_gift:true}])).toBe('unknown')
    expect(stockState(w,[{...line,availability:[]}])).toBe('unknown')
  })
})
