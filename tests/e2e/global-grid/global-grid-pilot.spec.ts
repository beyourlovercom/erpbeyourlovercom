import { expect, test } from '@playwright/test'
import { loginThroughUi, syntheticUsers } from '../support/auth-helpers'
import { installBrowserGuards } from '../support/browser-guards'

const pages = [
  {label:'product',legacy:'/products/list',pilot:'/products/list?global_grid=1',
    row:'E2E-SKU-0001',oldSelector:'.product-data-table'},
  {label:'inventory',legacy:'/inventory/products',pilot:'/inventory/products?global_grid=1',
    row:'E2E-BIN-007',oldSelector:'.table-wrap table'},
  {label:'procurement',legacy:'/purchase',pilot:'/purchase?global_grid=1',
    row:'PO-E2E-GG-0001',oldSelector:'.purchase-table-wrap table'},
]

test('R3 authenticated Chrome verifies three canary pages, legacy fallback, exact links, paging and frontend performance', async ({context,page}) => {
  await page.setViewportSize({width:1440,height:900})
  const browserGuard = await installBrowserGuards(context,page)
  await loginThroughUi(page,syntheticUsers().authorized)
  const perf:Record<string,{
    legacyVisibleMs:number;legacyIdleMs:number;globalVisibleMs:number;globalIdleMs:number;loadedGridAssets:number
  }> = {}
  for(const item of pages){
    const legacyStart=performance.now()
    await page.goto(item.legacy,{waitUntil:'domcontentloaded'})
    await expect(page.locator(item.oldSelector)).toBeVisible({timeout:15000})
    const legacyVisibleMs=Math.round(performance.now()-legacyStart)
    await page.waitForLoadState('networkidle')
    const legacyIdleMs=Math.round(performance.now()-legacyStart)
    await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toHaveCount(0)

    const pilotStart=performance.now()
    await page.goto(item.pilot,{waitUntil:'domcontentloaded'})
    await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toBeVisible({timeout:20000})
    await expect(page.getByRole('grid')).toBeVisible({timeout:15000})
    await expect(page.getByText(item.row,{exact:true}).first()).toBeVisible({timeout:15000})
    const globalVisibleMs=Math.round(performance.now()-pilotStart)
    await page.waitForLoadState('networkidle')
    const globalIdleMs=Math.round(performance.now()-pilotStart)
    // One local Chrome sample per page. Visible-time measures the actual
    // table + seeded row; idle-time includes late preference requests.
    // Neither is a production SLA nor a statistical speed comparison.
    const loadedGridAssets=await page.evaluate(() => performance.getEntriesByType('resource')
      .filter(r=>r.name.includes('OmsGrid-')||r.name.includes('GlobalGrid-')).length)
    perf[item.label]={legacyVisibleMs,legacyIdleMs,globalVisibleMs,globalIdleMs,loadedGridAssets}
    expect(loadedGridAssets).toBeGreaterThan(0)
    const grid=page.locator('[data-testid="erp-global-grid-v1"]')
    await expect(grid.getByRole('button',{name:'⚙ 列设置'})).toBeVisible()
    await grid.getByRole('button',{name:'⚙ 列设置'}).click()
    await expect(grid.getByText('拖拽调整顺序')).toBeVisible()
    await grid.getByRole('button',{name:'关闭列设置'}).click()
    expect(await grid.getByRole('option',{name:'全部'}).count()).toBe(0)
    if(item.label==='product'){
      await expect(page.locator('a[href="/products/1"]').first()).toBeVisible()
      await expect(page.getByText('E2E Synthetic Product',{exact:true}).first()).toBeVisible()
    }
    if(item.label==='inventory'){
      await expect(page.getByText('15',{exact:true}).first()).toBeVisible()
      await expect(page.locator('a[href^="/inventory/"]').first()).toBeVisible()
    }
    if(item.label==='procurement'){
      await expect(page.locator('a[href^="/purchase/orders/"]').first()).toBeVisible()
      await expect(page.locator('a[href^="/customers/orders/"]').first()).toBeVisible()
      await page.getByLabel('服务端每页条数').selectOption('20')
      await expect(page).toHaveURL(/per_page=20/)
      await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toBeVisible()
    }
    console.log('GLOBAL_GRID_CHROME_PASS',item.label,JSON.stringify(perf[item.label]))
  }
  // Unauthorized users are never allowed to access sensitive business data.
  await context.clearCookies()
  await page.goto('/products/list?global_grid=1',{waitUntil:'domcontentloaded'})
  await expect(page).toHaveURL(/\/login/)
  await browserGuard.assertClean()
  console.log('GLOBAL_GRID_R3_PERF_EVIDENCE',JSON.stringify(perf))
})

test('Dev pilot has no effects on the normal procurement mobile card view',async ({page})=>{
  await page.setViewportSize({width:390,height:844})
  await loginThroughUi(page,syntheticUsers().authorized)
  await page.goto('/purchase?global_grid=1',{waitUntil:'domcontentloaded'})
  await expect(page.locator('.mobile-list .purchase-card')).toBeVisible({timeout:15000})
  await expect(page.locator('.mobile-list')).toContainText('PO-E2E-GG-0001')
  await expect(page.locator('.desktop-list')).toBeHidden()
  console.log('GLOBAL_GRID_MOBILE_FALLBACK_PASS')
})
