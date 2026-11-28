export type WarehousePortal = { href: string; label: string }

// Navigation-only links. Credentials and ERP order data are never transferred.
const warehousePortals: Record<string, WarehousePortal> = {
  '/logistics/oversea/us-3sm': {
    href: 'https://oms.xlwms.com/login',
    label: '进入世迈仓库',
  },
  '/logistics/oversea/us-ny': {
    href: 'https://oms.xlwms.com/login',
    label: '进入美东纽约仓库',
  },
  '/logistics/oversea/de-tianyun': {
    href: 'https://oms.xlwms.com/login',
    label: '进入德国天运仓库',
  },
  '/logistics/oversea/ca': {
    href: 'https://order.wlwytech.com/ioms/',
    label: '进入加拿大仓库',
  },
  '/logistics/oversea/us-moreplus': {
    href: 'https://oms.shipout.com/z/#/login',
    label: '进入 ShipOut 正式仓库',
  },
}

export function resolveWarehousePortal(pageUrl: string): WarehousePortal | null {
  const path = pageUrl.split('?')[0].replace(/\/+$/, '') || '/'
  // Keep legacy overseas warehouse aliases consistent with Laravel's canonical US-SM mapping.
  const canonicalPath = ['/logistics/oversea/us-sm', '/logistics/oversea/us-sj', '/logistics/oversea/usa-sj'].includes(path)
    ? '/logistics/oversea/us-3sm'
    : path
  return warehousePortals[canonicalPath] ?? null
}
