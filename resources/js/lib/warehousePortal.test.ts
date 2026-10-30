import { describe, expect, it } from 'vitest'
import { resolveWarehousePortal } from './warehousePortal'

describe('warehouse portal links', () => {
  it('shows the 世迈 OMS portal for US-SM across list views', () => {
    expect(resolveWarehousePortal('/logistics/oversea/us-3sm?view=pending')).toEqual({
      href: 'https://oms.xlwms.com/login',
      label: '进入世迈仓库',
    })
  })

  it('supports historical 世迈 route aliases still accepted by the ERP', () => {
    for (const alias of ['us-sm', 'us-sj', 'usa-sj']) {
      expect(resolveWarehousePortal('/logistics/oversea/' + alias)?.href).toBe('https://oms.xlwms.com/login')
    }
  })

  it('shows the ShipOut production OMS portal for MorePlus', () => {
    expect(resolveWarehousePortal('/logistics/oversea/us-moreplus/')).toEqual({
      href: 'https://oms.shipout.com/z/#/login',
      label: '进入 ShipOut 正式仓库',
    })
  })

  it('does not show a portal on the all-warehouse or other logistics pages', () => {
    for (const path of ['/logistics/oversea', '/logistics/oversea/us-ny', '/logistics/china', '/logistics/oversea/us-3sm-other']) {
      expect(resolveWarehousePortal(path)).toBeNull()
    }
  })
})
