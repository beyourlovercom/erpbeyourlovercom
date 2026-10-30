export type WarehousePortal = { href: string; label: string }

// Navigation-only links. Credentials and ERP order data are never transferred.
const warehousePortals: Record<string, WarehousePortal> = {
  '/logistics/oversea/us-3sm': {
    href: 'https://oms.xlwms.com/login',
    label: '进入世迈仓库',
  },
  '/logistics/oversea/us-moreplus': {
    href: 'https://oms.shipout.com/z/#/login',
    label: '进入 ShipOut 正式仓库',
  },
}

export function resolveWarehousePortal(pageUrl: string): WarehousePortal | null {
  const path = pageUrl.split('?')[0].replace(/\/+$/, '') || '/'
  return warehousePortals[path] ?? null
}
