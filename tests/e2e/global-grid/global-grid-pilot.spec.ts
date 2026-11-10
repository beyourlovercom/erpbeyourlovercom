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


test('R3 single built-in search replaces outer search and queries all server rows',async ({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().authorized)
  for(const entry of pages){
    await page.goto(entry.pilot,{waitUntil:'domcontentloaded'})
    await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toBeVisible()
    const search=page.getByRole('textbox',{name:'表格内搜索全部记录'})
    await expect(search).toBeVisible()
    await expect(search).toHaveCount(1)
    if(entry.label==='product'){
      await expect(page.locator('.product-search')).toHaveCount(0)
    }else if(entry.label==='inventory'){
      await expect(page.locator('.filters input[placeholder="搜索 SKU / 产品名称"]')).toHaveCount(0)
      await expect(page.locator('.filters select')).toHaveCount(2)
    }else{
      await expect(page.locator('.purchase-filters input')).toHaveCount(0)
      await expect(page.locator('.purchase-filters select')).toHaveCount(1)
    }
    await search.fill('NO-SUCH-ERP-SEARCH-98765')
    await search.press('Enter')
    await expect(page).toHaveURL(/search=NO-SUCH-ERP-SEARCH-98765/)
    await expect(page.getByText(entry.row,{exact:true})).toHaveCount(0)
    await page.getByRole('button',{name:'清空表格搜索'}).click()
    await expect(page).not.toHaveURL(/search=NO-SUCH-ERP-SEARCH-98765/)
    await expect(page.getByText(entry.row,{exact:true}).first()).toBeVisible({timeout:15000})
    await search.fill('E2E-SKU-0001')
    await search.press('Enter')
    await expect(page).toHaveURL(/search=E2E-SKU-0001/)
    await expect(page.getByText(entry.row,{exact:true}).first()).toBeVisible()
    // Subsequent pagination/filtering must preserve the server-side search.
    if(entry.label==='inventory'){
      await page.locator('.filters select').first().selectOption('us-la')
      await expect(page).toHaveURL(/search=E2E-SKU-0001/)
      await expect(page).toHaveURL(/warehouse=us-la/)
    }
    if(entry.label==='procurement'){
      await page.getByLabel('服务端每页条数').selectOption('20')
      await expect(page).toHaveURL(/search=E2E-SKU-0001/)
      await expect(page).toHaveURL(/per_page=20/)
    }
    console.log('GLOBAL_GRID_SERVER_SEARCH_PASS',entry.label)
  }
})


test('R4-L1 logistics shared Grid handles domestic, allocation and review without changing native operations',async ({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().authorized)
  // China business review: V1 display plus native per-order workflow controls.
  await page.goto('/logistics/china?view=review',{waitUntil:'domcontentloaded'})
  await expect(page.locator('.table-wrap.orders table')).toBeVisible()
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toHaveCount(0)
  await page.goto('/logistics/china?view=review&global_grid=1',{waitUntil:'domcontentloaded'})
  const domestic=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(domestic).toBeVisible({timeout:20000})
  await expect(domestic.getByText('007-1110-c99992',{exact:true}).first()).toBeVisible()
  await expect(page.locator('.table-wrap.orders table')).toHaveCount(0)
  await domestic.getByRole('button',{name:'操作此单'}).first().click()
  await expect(page.locator('.table-wrap.orders table')).toBeVisible()
  await expect(page.locator('.table-wrap.orders')).toContainText('007-1110-c99992')
  await expect(page.getByRole('button',{name:'通过',exact:true})).toBeVisible()
  await page.getByTestId('domestic-native-batch-toggle').click()
  await expect(page.locator('.table-wrap.orders table')).toBeVisible()
  await expect(domestic).toBeVisible()
  await page.getByTestId('domestic-global-grid-toggle').click()
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toHaveCount(0)
  await expect(page.locator('.table-wrap.orders table')).toBeVisible()
  console.log('R4_L1_DOMESTIC_GRID_NATIVE_ACTIONS_PASS')

  await page.goto('/logistics/wait-stock?global_grid=1',{waitUntil:'domcontentloaded'})
  const allocation=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(allocation).toBeVisible({timeout:20000})
  await expect(page.locator('.allocation-table')).toHaveCount(0)
  await allocation.getByRole('textbox',{name:'表格内搜索全部记录'}).fill('IMPOSSIBLE-ORDER-327')
  await allocation.getByRole('textbox',{name:'表格内搜索全部记录'}).press('Enter')
  await expect(page).toHaveURL(/search=IMPOSSIBLE-ORDER-327/)
  await expect(allocation.getByText('007-1110-c99990')).toHaveCount(0)
  await page.getByTestId('allocation-global-grid-toggle').click()
  await expect(page.locator('.allocation-table')).toBeVisible()
  console.log('R4_L1_ALLOCATION_SERVER_SEARCH_FALLBACK_PASS')

  await page.goto('/logistics/review?global_grid=1',{waitUntil:'domcontentloaded'})
  const review=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(review).toBeVisible({timeout:20000})
  await expect(review.getByText('007-1110-c99992',{exact:true}).first()).toBeVisible()
  await expect(page.locator('.mvp-table-wrap table')).toHaveCount(0)
  // The E2E authorized user is super_admin, which is deliberately read-only
  // under LogisticsExecutionAccess. Never reveal edit forms to that role.
  await expect(review.getByRole('button',{name:'填写复核'})).toHaveCount(0)
  await expect(page.locator('.mvp-table-wrap table')).toHaveCount(0)
  await page.getByTestId('review-global-grid-toggle').click()
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toHaveCount(0)
  await expect(page.locator('.mvp-table-wrap table')).toBeVisible()
  await expect(page.locator('.mvp-table-wrap')).toContainText('E2E Synthetic Review')
  await expect(page.getByRole('button',{name:'确认复核'})).toHaveCount(0)
  console.log('R4_L1_REVIEW_READONLY_PERMISSION_FALLBACK_PASS')
})
