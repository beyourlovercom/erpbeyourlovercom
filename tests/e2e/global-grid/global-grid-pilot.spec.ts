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
  const domestic=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(domestic).toBeVisible({timeout:20000})
  // PR712 replaced the duplicate page title with one shared compact toolbar.
  const titleActions=page.locator('.domestic-head-actions')
  const returnOriginal=titleActions.getByTestId('domestic-global-grid-toggle')
  await expect(returnOriginal).toHaveText('返回原表')
  await expect(titleActions.locator('button').last()).toHaveAttribute('data-testid','domestic-global-grid-toggle')
  await expect(page.locator('.domestic-grid-toggle')).toHaveCount(0)
  await expect(domestic.getByText('007-1110-c99992',{exact:true}).first()).toBeVisible()
  await expect(page.locator('.table-wrap.orders table')).toHaveCount(0)
  // Direct pinned-right review controls: no second native table or popup.
  await expect(domestic.getByRole('button',{name:'通过',exact:true})).toBeVisible()
  await expect(domestic.locator('.ag-cell[col-id="actions"]').first()).toHaveClass(/ag-cell-first-right-pinned/)
  await expect(domestic.getByRole('button',{name:'缺货',exact:true})).toBeVisible()
  const reviewAction=domestic.locator('.domestic-grid-cell-actions').first()
  const visibleButtons=reviewAction.locator('.domestic-grid-cell-main button')
  await expect(visibleButtons).toHaveCount(3)
  await expect(page.getByTestId('domestic-reject-popover')).toHaveCount(0)
  const compactHeight=await reviewAction.locator('xpath=ancestor::*[contains(@class,"ag-row")][1]').evaluate(
    node=>node.getBoundingClientRect().height)
  const top=await reviewAction.locator('.domestic-grid-cell-main').evaluate(root=>{
    const buttons=Array.from(root.querySelectorAll('button'))
    const boxes=buttons.map(node=>node.getBoundingClientRect())
    const cell=root.closest('.ag-cell')!.getBoundingClientRect()
    return {
      gaps:boxes.slice(1).map((rect,i)=>rect.left-boxes[i].right),
      color:getComputedStyle(buttons[0]).backgroundColor,
      lastButtonRight:boxes[2].right,
      cellRight:cell.right,
      cellWidth:cell.width,
    }
  })
  expect(top.gaps).toEqual([8,8])
  expect(top.color).toBe('rgb(0, 143, 124)')
  // Old width was 330px, with >150px of wasted space.
  expect(top.cellWidth).toBeLessThanOrEqual(190)
  expect(top.cellRight-top.lastButtonRight).toBeLessThan(35)
  await reviewAction.getByRole('button',{name:'驳回',exact:true}).click()
  const popover=page.getByTestId('domestic-reject-popover')
  await expect(popover).toBeVisible()
  await expect(popover.getByRole('textbox',{name:'驳回原因'})).toBeFocused()
  await expect(popover.getByRole('button',{name:'确认驳回'})).toBeDisabled()
  await expect(reviewAction.getByRole('button',{name:'驳回',exact:true})).toHaveAttribute('aria-expanded','true')
  await expect(popover).toHaveCSS('position','fixed')
  const expandedHeight=await reviewAction.locator('xpath=ancestor::*[contains(@class,"ag-row")][1]').evaluate(
    node=>node.getBoundingClientRect().height)
  expect(expandedHeight).toBe(compactHeight)
  // Check the overlay is at the top of the browser hit-test stack, not
  // clipped/covered by the following AG Grid row.
  const floatLayout=await popover.evaluate(el=>{
    const rect=el.getBoundingClientRect()
    const input=el.querySelector('input')!.getBoundingClientRect()
    const element=document.elementFromPoint(input.x+input.width/2,input.y+input.height/2)
    return {
      position:getComputedStyle(el).position,
      zIndex:Number(getComputedStyle(el).zIndex),
      width:rect.width,top:rect.top,bottom:rect.bottom,viewportHeight:window.innerHeight,
      inputOnTop:!!(element && el.contains(element)),
      inBody:el.parentElement===document.body,
    }
  })
  expect(floatLayout.inputOnTop).toBe(true)
  expect(floatLayout.inBody).toBe(true)
  expect(floatLayout.bottom).toBeLessThanOrEqual(floatLayout.viewportHeight)
  await popover.getByRole('textbox',{name:'驳回原因'}).fill('地址')
  await expect(popover.getByRole('button',{name:'确认驳回'})).toBeDisabled()
  await popover.getByRole('textbox',{name:'驳回原因'}).fill('客户地址需要再次核对')
  await expect(popover.getByRole('button',{name:'确认驳回'})).toBeEnabled()
  await popover.getByRole('button',{name:'取消'}).click()
  await expect(page.getByTestId('domestic-reject-popover')).toHaveCount(0)
  const restoredHeight=await reviewAction.locator('xpath=ancestor::*[contains(@class,"ag-row")][1]').evaluate(
    node=>node.getBoundingClientRect().height)
  expect(restoredHeight).toBe(compactHeight)
  // Reopen and close with Escape without calling the write endpoint.
  await reviewAction.getByRole('button',{name:'驳回',exact:true}).click()
  await expect(page.getByTestId('domestic-reject-popover')).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByTestId('domestic-reject-popover')).toHaveCount(0)
  console.log('R4_V12_REJECT_POPOVER_COMPACT_PINNED_PASS',JSON.stringify({compactHeight,expandedHeight,restoredHeight,...top,...floatLayout}))
  await expect(domestic.locator('.global-cell-badge').first()).toHaveCSS('background-color','rgb(239, 246, 244)')
  await expect(domestic.locator('.global-cell-badge').first()).toHaveCSS('padding','2px 7px')
  await expect(page.locator('#domestic-reject-reason')).toHaveCount(0)
  await expect(page.locator('.table-wrap.orders table')).toHaveCount(0)
  await expect(domestic.getByTestId('grid-batch-footer')).toHaveCount(0)
  // A selected row activates exactly ONE existing footer, with Apply conditional.
  const selection=domestic.locator('.ag-selection-checkbox').first()
  await selection.click()
  const footer=domestic.getByTestId('grid-batch-footer')
  await expect(footer).toContainText('已选 1 条')
  await expect(footer.getByRole('button',{name:'应用'})).toHaveCount(0)
  await footer.getByRole('combobox',{name:'批量操作'}).selectOption('approve')
  await expect(footer.getByRole('button',{name:'应用'})).toBeVisible()
  await footer.getByRole('combobox',{name:'批量操作'}).selectOption('reject')
  await expect(footer.getByRole('textbox',{name:'批量驳回原因'})).toBeVisible()
  await expect(footer.getByRole('button',{name:'应用'})).toBeDisabled()
  await footer.getByRole('textbox',{name:'批量驳回原因'}).fill('收件信息需要复核')
  await expect(footer.getByRole('button',{name:'应用'})).toBeEnabled()
  await expect(domestic.locator('.oms-toolbar .oms-title small')).toHaveCount(0)
  console.log('R4_M1_M2_FOOTER_SELECTION_DIRECT_REVIEW_NO_MODAL_PASS')
  await returnOriginal.click()
  await expect(page).toHaveURL(/global_grid=0/)
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toHaveCount(0)
  await expect(page.locator('.table-wrap.orders table')).toBeVisible()
  await expect(titleActions.getByTestId('domestic-global-grid-toggle')).toHaveText('切换新版表')
  // Legacy escape hatch remains active when the employee changes a view tab.
  await page.getByRole('button',{name:/待发货/}).first().click()
  await expect(page).toHaveURL(/global_grid=0/)
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toHaveCount(0)
  await titleActions.getByTestId('domestic-global-grid-toggle').click()
  await expect(page).toHaveURL(/global_grid=1/)
  await expect(domestic).toBeVisible()
  console.log('R4_L1_DOMESTIC_GRID_DEFAULT_HEADER_TOGGLE_AND_NATIVE_ACTIONS_PASS')

  // Owner-approved wait-stock default: no opt-in, six business tabs, saved column menu,
  // explicit header fallback to the original table and back to the same global grid.
  await page.goto('/logistics/wait-stock',{waitUntil:'domcontentloaded'})
  const allocation=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(allocation).toBeVisible({timeout:20000})
  await expect(page.locator('.allocation-table')).toHaveCount(0)
  await expect(page.getByTestId('allocation-global-grid-toggle')).toHaveText('返回原版表格')
  await expect(allocation.getByText('007-1110-c99990',{exact:true}).first()).toBeVisible()
  const allocationColumnSettings=allocation.getByRole('button',{name:'⚙ 列设置'})
  await allocationColumnSettings.click()
  await expect(allocation.getByText('原始订单号',{exact:true}).first()).toBeVisible()
  await expect(allocation.getByText('金额',{exact:true}).first()).toBeVisible()
  await allocation.getByRole('button',{name:'关闭列设置'}).click()
  for(const label of ['仓库建议','库存待核实','人工处理','礼品待确认','已处理','全部']){
    await page.locator('.allocation-page .tabs button').filter({hasText:label}).first().click()
    await expect(allocation).toBeVisible()
    await expect(page.locator('.allocation-table')).toHaveCount(0)
  }
  await page.getByTestId('allocation-global-grid-toggle').click()
  await expect(page).toHaveURL(/global_grid=0/)
  await expect(page.locator('.allocation-table')).toBeVisible()
  await expect(page.getByTestId('allocation-global-grid-toggle')).toHaveText('切换新版表格')
  await page.getByTestId('allocation-global-grid-toggle').click()
  await expect(page).toHaveURL(/global_grid=1/)
  await expect(allocation).toBeVisible()
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

test('R4-M3 CSRF test: SPA login token versus refreshed session cookie, no real orders',async ({page})=>{
  await loginThroughUi(page,syntheticUsers().authorized)
  const diag=await page.evaluate(async()=>{
    const meta=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content??''
    const encoded=document.cookie.split('; ').find(v=>v.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
    const cookie=encoded?decodeURIComponent(encoded):''
    const emptyOperation=async (header:Record<string,string>)=>{
      const response=await fetch('/orders/archive/batch',{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{Accept:'application/json','Content-Type':'application/json',...header},
        body:JSON.stringify({order_ids:[987654321]}),
      })
      return response.status
    }
    return {
      metaPresent:!!meta,cookiePresent:!!cookie,tokenEqual:meta===cookie,
      metaStatus:meta?await emptyOperation({'X-CSRF-TOKEN':meta}):null,
      cookieStatus:cookie?await emptyOperation({'X-XSRF-TOKEN':cookie}):null,
    }
  })
  console.log('R4_M3_CSRF_DIAG',JSON.stringify(diag))
  expect(diag.cookiePresent).toBe(true)
  expect(diag.cookieStatus).toBe(200)
})

test('R4-M3 batch archive survives stale Inertia meta token using the current session cookie',async ({page})=>{
  await loginThroughUi(page,syntheticUsers().authorized)
  await page.goto('/logistics/china?view=review',{waitUntil:'domcontentloaded'})
  const grid=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(grid).toBeVisible({timeout:18000})
  await expect(grid.getByText('007-1110-c99992',{exact:true}).first()).toBeVisible()
  await page.locator('meta[name="csrf-token"]').evaluate(el=>el.setAttribute('content','OUTDATED-HEAD-TOKEN'))
  await grid.locator('.ag-selection-checkbox').first().click()
  const footer=grid.getByTestId('grid-batch-footer')
  await footer.getByRole('combobox',{name:'批量操作'}).selectOption('archive')
  await footer.getByRole('button',{name:'应用'}).click()
  await expect(page.getByText('批量操作：成功 1 条，失败 0 条')).toBeVisible({timeout:15000})
  console.log('R4_M3_STALE_META_COOKIE_ARCHIVE_SYNTHETIC_PASS')
})


test('R4 stockout second separately approved release: fixed-right single action and fallback',async({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().authorized)
  await page.goto('/logistics/china?view=stockout',{waitUntil:'domcontentloaded'})
  const grid=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(grid).toBeVisible({timeout:15000})
  await expect(grid.getByText('007-1110-c99991',{exact:true})).toBeVisible()
  await expect(page.locator('.table-wrap.orders table')).toHaveCount(0)
  const cell=grid.locator('.domestic-grid-cell-actions').first()
  const availableActions=cell.locator('.domestic-grid-cell-main button')
  await expect(availableActions).toHaveCount(1)
  await expect(cell.getByRole('button',{name:'已到货-可发货',exact:true})).toBeVisible()
  await expect(cell.getByRole('button',{name:'通过',exact:true})).toHaveCount(0)
  await expect(cell.getByRole('button',{name:'缺货',exact:true})).toHaveCount(0)
  await expect(cell.getByRole('button',{name:'驳回',exact:true})).toHaveCount(0)
  const layout=await cell.evaluate(node=>{
    const col=node.closest('.ag-cell')!.getBoundingClientRect()
    const button=node.querySelector('button')!.getBoundingClientRect()
    return {width:col.width,spaceAfter:col.right-button.right,
      pinned:node.closest('.ag-cell')?.classList.contains('ag-cell-first-right-pinned')}
  })
  expect(layout.width).toBeLessThanOrEqual(148)
  expect(layout.spaceAfter).toBeLessThan(35)
  expect(layout.pinned).toBe(true)
  await expect(grid.getByTestId('grid-batch-footer')).toHaveCount(0)
  await grid.locator('.ag-selection-checkbox').first().click()
  const footer=grid.getByTestId('grid-batch-footer')
  await expect(footer).toContainText('已选 1 条')
  const options=await footer.getByRole('combobox',{name:'批量操作'}).locator('option').allTextContents()
  expect(options).toEqual(['批量操作','批量已到货-可发货'])
  await footer.getByRole('combobox',{name:'批量操作'}).selectOption('restock')
  await expect(footer.getByRole('button',{name:'应用'})).toBeVisible()
  // No real writes in the visual browser gate; backend-specific authorized
  // single- and batch-restock acceptance is covered in PHP feature tests.
  const toggle=page.getByTestId('domestic-global-grid-toggle')
  await toggle.click()
  await expect(page).toHaveURL(/global_grid=0/)
  await expect(page.locator('.table-wrap.orders table')).toBeVisible()
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toHaveCount(0)
  await page.getByRole('button',{name:/待审核/}).first().click()
  await expect(page).toHaveURL(/view=review/)
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toBeVisible()
  await page.getByRole('button',{name:/缺货单/}).first().click()
  await expect(page).toHaveURL(/view=stockout/)
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toBeVisible()
  console.log('R4_DOMESTIC_STOCKOUT_SINGLE_ACTION_RELEASE_PASS',JSON.stringify(layout))
})


test('R4 preference persistence: no default flash, no repeat GET and separate view configurations',async({page})=>{
  await page.setViewportSize({width:1450,height:900})
  await loginThroughUi(page,syntheticUsers().authorized)
  await page.goto('/logistics/china?view=review',{waitUntil:'domcontentloaded'})
  const review=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(review.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:18000})
  // Save one preference through the real column-settings UI so the test can
  // discover the actual account-scoped cache key after Inertia has hydrated.
  await review.getByRole('button',{name:'⚙ 列设置'}).click()
  await review.getByRole('button',{name:'恢复默认'}).click()
  await review.getByRole('button',{name:'关闭列设置'}).click()
  const keys=await page.evaluate(()=>Object.keys(window.localStorage).filter(key=>
    key.startsWith('oms-grid-pref:user:') && key.endsWith(':grid.logistics.domestic.review.global.v1')))
  expect(keys).toHaveLength(1)
  const reviewKey=keys[0]
  const actorId=Number(reviewKey.split(':')[2])
  expect(actorId).toBeGreaterThan(0)
  const stockKey='oms-grid-pref:user:'+actorId+':grid.logistics.domestic.stockout.global.v1'
  const cached=await page.evaluate(key=>window.localStorage.getItem(key),reviewKey)
  expect(cached).toBeTruthy()
  // A fresh browser must not mistake the shared legacy preference for the
  // stockout page's OWN column preferences.
  await page.evaluate(key=>window.localStorage.removeItem(key),stockKey)
  const saved={
    version:1,
    columns:[
      {colId:'order',hide:false,width:265},
      {colId:'quantity',hide:false,width:110},
      {colId:'sku',hide:false,width:180},
      {colId:'actions',hide:false,width:140},
    ],
    pinnedLeft:1,pinnedRight:1,density:'compact',pageSize:50,
  }
  let unlock!:()=>void
  const hold=new Promise<void>(resolve=>{unlock=resolve})
  let getCount=0
  let putCount=0
  await page.route('**/ui/preferences/grid.logistics.domestic.stockout.global.v1',async(route)=>{
    if(route.request().method()==='GET'){
      getCount++
      await hold
      await route.fulfill({status:200,contentType:'application/json',
        body:JSON.stringify({namespace:'grid.logistics.domestic.stockout.global.v1',value:saved})})
    }else{
      putCount++
      await route.continue()
    }
  })
  await page.goto('/logistics/china?view=stockout',{waitUntil:'domcontentloaded'})
  const stock=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(stock.locator('.oms-grid-layout-loading')).toBeVisible()
  await expect(stock.locator('.oms-ag-grid')).toHaveCSS('visibility','hidden')
  unlock()
  await expect(stock.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:15000})
  await expect(stock.locator('.oms-grid-layout-loading')).toHaveCount(0)
  await expect(stock.getByText('007-1110-c99991',{exact:true})).toBeVisible()
  const before=await stock.locator('.ag-header-cell[col-id]').evaluateAll(nodes=>
    nodes.map(node=>node.getAttribute('col-id')).filter(Boolean))
  expect(before).toContain('quantity')
  expect(before).toContain('sku')
  const stockLayout=await stock.locator('.ag-cell[col-id=actions]').first().evaluate(el=>
    ({width:el.getBoundingClientRect().width,pinned:el.classList.contains('ag-cell-first-right-pinned')}))
  expect(stockLayout.width).toBeLessThanOrEqual(148)
  // The old test expected the action to remain pinned even when a saved
  // layout put it in the middle. G removes this forced lock intentionally.
  expect(stockLayout.pinned).toBe(false)
  expect(getCount).toBe(1)
  expect(putCount).toBe(0)
  // The first load just seeded the scoped local cache. Repeat reloads must
  // show that same state WITHOUT a second account GET or visual re-layout.
  const newCache=await page.evaluate(key=>window.localStorage.getItem(key),stockKey)
  expect(newCache).toBeTruthy()
  await page.reload({waitUntil:'domcontentloaded'})
  await expect(page.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:15000})
  await expect(page.locator('.oms-grid-layout-loading')).toHaveCount(0)
  const after=await page.locator('.ag-header-cell[col-id]').evaluateAll(nodes=>
    nodes.map(node=>node.getAttribute('col-id')).filter(Boolean))
  expect(after).toEqual(before)
  expect(getCount).toBe(1)
  expect(putCount).toBe(0)
  await page.reload({waitUntil:'domcontentloaded'})
  await expect(page.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:15000})
  expect(getCount).toBe(1)
  expect(putCount).toBe(0)
  // Switching back preserves the review cache and does not replace it with
  // the stockout column order. Neither view's reset can overwrite the other.
  await page.goto('/logistics/china?view=review',{waitUntil:'domcontentloaded'})
  await expect(page.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:15000})
  const reviewAfter=await page.evaluate(key=>window.localStorage.getItem(key),reviewKey)
  expect(reviewAfter).toBe(cached)
  expect(getCount).toBe(1)
  expect(putCount).toBe(0)
  // An explicit user edit, unlike hydration, must be saved immediately to
  // account-scoped local cache and then persisted to the server.
  await page.goto('/logistics/china?view=stockout',{waitUntil:'domcontentloaded'})
  await expect(page.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:15000})
  await page.getByRole('button',{name:'⚙ 列设置'}).click()
  const skuSetting=page.locator('.oms-column-row').filter({hasText:'SKU'}).first()
  await expect(skuSetting).toBeVisible()
  await skuSetting.locator('input[type="checkbox"]').uncheck()
  const updated=await page.evaluate(key=>JSON.parse(localStorage.getItem(key)??'null'),stockKey)
  expect(updated.columns.find((column:{colId:string})=>column.colId==='sku')?.hide).toBe(true)
  await expect.poll(()=>putCount,{timeout:5000}).toBe(1)
  await page.reload({waitUntil:'domcontentloaded'})
  await expect(page.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:15000})
  await expect(page.locator('.ag-header-cell[col-id="sku"]')).toHaveCount(0)
  expect(getCount).toBe(1)
  expect(putCount).toBe(1)
  await page.goto('/logistics/china?view=review',{waitUntil:'domcontentloaded'})
  await expect(page.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:15000})
  expect(await page.evaluate(key=>localStorage.getItem(key),reviewKey)).toBe(cached)
  console.log('DOMESTIC_GRID_PREF_STABLE_FIRST_PAINT_AND_VIEW_ISOLATION_PASS',
    JSON.stringify({getCount,putCount,stockColumnWidth:stockLayout.width,actorId}))
})

test('R4 compact short-list auto-height: no empty canvas, footer follows last row',async({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().authorized)
  await page.goto('/logistics/china?view=stockout',{waitUntil:'domcontentloaded'})
  const grid=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(grid.getByText('007-1110-c99991',{exact:true})).toBeVisible({timeout:15000})
  await expect(grid.locator('.oms-ag-grid')).toHaveCSS('visibility','visible')
  await expect(grid.locator('.oms-grid')).toHaveClass(/is-auto-height/)
  const measured=await grid.evaluate(root=>{
    const card=root.querySelector('.oms-grid')!.getBoundingClientRect()
    const ag=root.querySelector('.oms-ag-grid')!.getBoundingClientRect()
    const last=[...root.querySelectorAll('.ag-row')].at(-1)?.getBoundingClientRect()
    const footer=root.querySelector('.oms-server-pagination')!.getBoundingClientRect()
    return {cardHeight:card.height,agHeight:ag.height,
      lastRowBottom:last?.bottom,footerTop:footer.top,footerBottom:footer.bottom,
      cardBottom:card.bottom,remaining:last?footer.top-last.bottom:-1}
  })
  expect(measured.cardHeight).toBeLessThan(300)
  expect(measured.cardHeight).toBeGreaterThan(130)
  expect(measured.remaining).toBeGreaterThanOrEqual(-2)
  expect(measured.remaining).toBeLessThan(15)
  expect(measured.cardBottom-measured.footerBottom).toBeLessThan(4)
  // The existing layout expand button still takes the whole viewport.
  await grid.getByRole('button',{name:'最大化列表'}).click()
  await expect(grid.locator('.oms-grid')).toHaveClass(/is-maximized/)
  await expect(grid.locator('.oms-grid')).not.toHaveClass(/is-auto-height/)
  const expanded=await grid.locator('.oms-grid').evaluate(node=>node.getBoundingClientRect().height)
  expect(expanded).toBeGreaterThan(700)
  await grid.getByRole('button',{name:'退出最大化'}).click()
  await expect(grid.locator('.oms-grid')).toHaveClass(/is-auto-height/)
  // Reload preserves the compact layout, including when personal preferences
  // were restored from the account-scoped cache.
  await page.reload({waitUntil:'domcontentloaded'})
  await expect(page.locator('.oms-ag-grid')).toHaveCSS('visibility','visible')
  const reloaded=await page.locator('.oms-grid').evaluate(node=>node.getBoundingClientRect().height)
  expect(reloaded).toBeLessThan(300)
  // Empty searches remain compact AND retain the native "暂无数据" overlay.
  await page.goto('/logistics/china?view=stockout&search=E2E-NONEXISTENT-SEARCH',{waitUntil:'domcontentloaded'})
  const empty=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(empty.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:15000})
  await expect(empty.locator('.oms-grid')).toHaveClass(/is-empty-short-list/)
  const emptyHeight=await empty.locator('.oms-grid').evaluate(node=>node.getBoundingClientRect().height)
  expect(emptyHeight).toBeLessThan(260)
  await expect(empty.getByText('暂无数据')).toBeVisible()
  console.log('GLOBAL_GRID_SHORT_LIST_FOOTER_TIGHT_PASS',JSON.stringify({...measured,expanded,reloaded,emptyHeight}))
})

test('R4-3 domestic pending: direct right actions, floating carrier/tracking panel and original file controls',async({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().logisticsOperator)
  await page.goto('/logistics/china?view=pending',{waitUntil:'domcontentloaded'})
  const grid=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(grid).toBeVisible({timeout:16000})
  await expect(grid.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:16000})
  await expect(grid.getByText('007-1111-c99990',{exact:true})).toBeVisible()
  await expect(page.locator('.table-wrap.orders table')).toHaveCount(0)
  const cell=grid.locator('.domestic-pending-actions').first()
  await expect(cell.getByRole('button',{name:'填写物流'})).toBeVisible()
  await expect(cell.getByRole('button',{name:'确认发货'})).toBeDisabled()
  // Logistics operator has shipping permission but NOT domestic review
  // permission. Super_admin has the inverse elevated read-only policy.
  await expect(cell.getByRole('button',{name:'重新审核'})).toHaveCount(0)
  const layout=await cell.evaluate(el=>{
    const c=el.closest('.ag-cell')!.getBoundingClientRect()
    const last=el.querySelector('button:last-of-type')!.getBoundingClientRect()
    return {width:c.width,spaceAfter:c.right-last.right,
      pinned:el.closest('.ag-cell')?.classList.contains('ag-cell-first-right-pinned')}
  })
  expect(layout.width).toBeLessThanOrEqual(175)
  expect(layout.spaceAfter).toBeLessThan(35)
  expect(layout.pinned).toBe(true)
  await cell.getByRole('button',{name:'填写物流'}).click()
  const pop=page.getByTestId('domestic-pending-popover')
  await expect(pop).toBeVisible()
  await expect(pop.getByRole('combobox',{name:'物流商'})).toBeFocused()
  await expect(pop.getByRole('combobox',{name:'服务类型'})).toBeDisabled()
  await expect(pop.getByRole('button',{name:'确认物流商及服务'})).toBeDisabled()
  await expect(pop.getByRole('button',{name:'暂存单号'})).toBeDisabled()
  await expect(pop.getByRole('button',{name:'确认发货'})).toBeDisabled()
  await expect(pop).toContainText('暂存不会发货')
  const overlay=await pop.evaluate(el=>{
    const r=el.getBoundingClientRect()
    const input=el.querySelector('input')!.getBoundingClientRect()
    return {
      position:getComputedStyle(el).position,zIndex:Number(getComputedStyle(el).zIndex),
      inBody:el.parentElement===document.body,
      inputOnTop:el.contains(document.elementFromPoint(input.x+input.width/2,input.y+input.height/2)),
      top:r.top,bottom:r.bottom,viewport:window.innerHeight,
    }
  })
  expect(overlay.position).toBe('fixed')
  expect(overlay.zIndex).toBeGreaterThan(12000)
  expect(overlay.inBody).toBe(true)
  expect(overlay.inputOnTop).toBe(true)
  expect(overlay.bottom).toBeLessThanOrEqual(overlay.viewport)
  await pop.getByRole('textbox',{name:'Tracking'}).fill('E2E-NO-SHIP-0001')
  await expect(pop.getByRole('button',{name:'暂存单号'})).toBeEnabled()
  await expect(pop.getByRole('button',{name:'确认发货'})).toBeDisabled()
  await page.keyboard.press('Escape')
  await expect(page.getByTestId('domestic-pending-popover')).toHaveCount(0)
  await expect(grid.locator('.oms-grid')).toHaveClass(/is-auto-height/)
  // EU barcode-69 read control is also preserved inside the Grid; it does
  // not require opening the original table or authorizing a shipment write.
  await page.route('**/logistics/china/orders/*/barcode-69',route=>
    route.fulfill({status:200,contentType:'application/json',
      body:JSON.stringify({items:[{sku:'E2E-SKU-0001',quantity:1,barcode_69:'6901234567890'}]})}))
  const sku=grid.locator('.domestic-pending-sku').first()
  await expect(sku.getByRole('button',{name:'69条码'})).toBeVisible()
  await sku.getByRole('button',{name:'69条码'}).click()
  const barcode=page.getByTestId('domestic-pending-barcode')
  await expect(barcode).toBeVisible()
  await expect(barcode).toContainText('6901234567890')
  await barcode.getByRole('button',{name:'关闭'}).click()
  await expect(page.getByTestId('domestic-pending-barcode')).toHaveCount(0)
  // Original shipping file operations stay ABOVE the Grid, no second table.
  await expect(page.locator('a[href="/logistics/china/pending-batch/export"]')).toBeVisible()
  await expect(page.getByRole('button',{name:'⏫上传物流单号'})).toBeVisible()
  await expect(page.locator('input[aria-label="上传物流单号表"]')).toHaveCount(1)
  await expect(page.getByRole('button',{name:'返回原表'})).toBeVisible()
  await page.getByRole('button',{name:'返回原表'}).click()
  await expect(page).toHaveURL(/global_grid=0/)
  await expect(page.locator('.table-wrap.orders table')).toBeVisible()
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toHaveCount(0)
  console.log('R4_PENDING_DIRECT_ACTIONS_FLOATING_SHIP_GATES_AND_LEGACY_PASS',JSON.stringify({layout,overlay}))
})

test('R4-3 synthetic pending explicit carrier then tracking then ship; never silently ships draft',async({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().logisticsOperator)
  await page.goto('/logistics/china?view=pending',{waitUntil:'domcontentloaded'})
  const grid=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(grid.getByText('007-1111-c99990',{exact:true})).toBeVisible({timeout:15000})
  const actions=grid.locator('.domestic-pending-actions').first()
  await actions.getByRole('button',{name:'填写物流'}).click()
  let pop=page.getByTestId('domestic-pending-popover')
  await pop.getByRole('combobox',{name:'物流商'}).selectOption('4PX')
  await pop.getByRole('combobox',{name:'服务类型'}).selectOption('SD经济挂号-带电')
  await expect(pop.getByRole('button',{name:'确认物流商及服务'})).toBeEnabled()
  const carrierWrite=page.waitForResponse(resp=>resp.url().includes('/carrier-service') &&
    resp.request().method()==='POST',{timeout:12000})
  await pop.getByRole('button',{name:'确认物流商及服务'}).click()
  expect((await carrierWrite).status()).toBeLessThan(400)
  await expect(grid.getByText('007-1111-c99990',{exact:true})).toBeVisible()
  await expect(actions.getByRole('button',{name:'确认发货'})).toBeDisabled()
  // A successful carrier save did NOT ship the pending order.
  await page.reload({waitUntil:'domcontentloaded'})
  await expect(grid.getByText('007-1111-c99990',{exact:true})).toBeVisible()
  await grid.locator('.domestic-pending-actions').first().getByRole('button',{name:'填写物流'}).click()
  pop=page.getByTestId('domestic-pending-popover')
  await pop.getByRole('textbox',{name:'Tracking'}).fill('E2E-PENDING-4PX-TRACKING-9001')
  await expect(pop.getByRole('button',{name:'确认发货'})).toBeDisabled()
  const draftWrite=page.waitForResponse(resp=>resp.url().includes('/tracking-draft') &&
    resp.request().method()==='POST',{timeout:12000})
  await pop.getByRole('button',{name:'暂存单号'}).click()
  expect((await draftWrite).status()).toBeLessThan(400)
  // Saving Tracking does not change shipment state or create Woo push.
  await page.reload({waitUntil:'domcontentloaded'})
  await expect(grid.getByText('007-1111-c99990',{exact:true})).toBeVisible()
  const confirm=grid.locator('.domestic-pending-actions').first().getByRole('button',{name:'确认发货'})
  await expect(confirm).toBeEnabled()
  const shipWrite=page.waitForResponse(resp=>resp.url().includes('/quick-ship') &&
    resp.request().method()==='POST',{timeout:12000})
  await confirm.click()
  expect((await shipWrite).status()).toBeLessThan(400)
  await expect(grid.getByText('007-1111-c99990',{exact:true})).toHaveCount(0,{timeout:15000})
  console.log('R4_PENDING_SYNTHETIC_CARRIER_DRAFT_SHIP_THREE_DISTINCT_WRITES_PASS')
})


test('R4-3F country route is prefilled and inline override saves without shipping',async({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().logisticsOperator)
  await page.goto('/logistics/china?view=pending',{waitUntil:'domcontentloaded'})
  const grid=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(grid).toBeVisible({timeout:16000})
  await expect(grid.getByText('007-1111-c99989',{exact:true})).toBeVisible()
  // Account-scoped column layouts can hide the carrier/service columns after
  // earlier Grid preference tests. Make this test deterministic by resetting
  // only its disposable synthetic account, never a real user's preferences.
  await grid.getByRole('button',{name:/列设置/}).click()
  await grid.getByRole('button',{name:'恢复默认'}).click()
  await grid.getByRole('button',{name:'完成'}).click()
  // AG Grid virtualizes center columns horizontally and keeps pinned order
  // IDs in another row DOM tree. Read the Mexico row index from the pinned
  // column, then locate its own inline dropdown after revealing the column.
  const rowIndex=await grid.getByText('007-1111-c99989',{exact:true})
    .locator('xpath=ancestor::div[contains(@class,"ag-row")][1]').getAttribute('row-index')
  expect(rowIndex).not.toBeNull()
  // Pinned column placement is user-configurable; do not assume route
  // cells live in AG Grid's center-cols DOM instead of a pinned container.
  const selectFor=(aria:string)=>grid.locator(
    '.ag-row[row-index="'+rowIndex+'"] select[aria-label="'+aria+'"]').first()
  const carrier=selectFor('物流商')
  const service=selectFor('服务渠道')
  const horizontal=grid.locator('.ag-body-horizontal-scroll-viewport')
  const max=await horizontal.evaluate(el=>el.scrollWidth-el.clientWidth)
  let found=false
  for(let offset=0;offset<=max+300;offset+=300){
    await horizontal.evaluate((el,left)=>{
      el.scrollLeft=left
      el.dispatchEvent(new Event('scroll',{bubbles:true}))
    },Math.min(offset,max))
    await page.waitForTimeout(70)
    if(await carrier.count()){found=true;break}
  }
  if(!found) console.log('R4_3F_COLUMN_DIAGNOSTIC',JSON.stringify({
    rowIndex,max,
    columns:await grid.locator('.ag-header-cell').allTextContents(),
    carrierCells:await grid.locator('.ag-cell[col-id="carrier"]').count(),
    routeSelects:await grid.locator('.domestic-pending-route-select').count(),
  }))
  expect(found,'Carrier column must be renderable in current AG Grid preferences').toBe(true)
  await expect(carrier).toHaveValue('Weship')
  await expect(service).toHaveValue('Standard')
  const response=page.waitForResponse(r=>r.url().includes('/carrier-service')&&r.request().method()==='POST')
  await carrier.selectOption('4PX')
  expect((await response).status()).toBeLessThan(400)
  // Verify persisted values from original native table after a fresh request:
  // this is independent of AG Grid's virtualized horizontal column viewport.
  await page.goto('/logistics/china?view=pending&global_grid=0',{waitUntil:'domcontentloaded'})
  const persisted=page.locator('.table-wrap.orders tbody tr').filter({hasText:'007-1111-c99989'}).first()
  await expect(persisted).toBeVisible()
  // PR716 renders Chinese-only labels; validate persisted machine values
  // without requiring the forbidden English 4PX name in visible text.
  await expect(persisted.getByRole('combobox',{name:/选择物流商/})).toHaveValue('4PX')
  await expect(persisted.getByRole('combobox',{name:/选择服务类型/})).toHaveValue('SD经济挂号-带电')
  // The order still exists in pending; changing its carrier never shipped it.
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toHaveCount(0)
  console.log('R4_3F_DEFAULT_WESHIP_INLINE_MANUAL_OVERRIDE_PENDING_PASS')
})

test('R4-4 今日订单 Global Grid retains exact three exports, barcode, one footer and native rollback',async({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().logisticsOperator)
  await page.goto('/logistics/china?view=today',{waitUntil:'domcontentloaded'})
  const grid=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(grid).toBeVisible({timeout:16000})
  await expect(grid.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:16000})
  await expect(grid.getByText('007-1111-c99990',{exact:true})).toBeVisible()
  await expect(page.locator('.domestic-compact-workspace')).toBeVisible()
  await expect(page.locator('.compact-workspace-help')).toContainText('单个异常订单')
  const shortcuts=page.locator('[aria-label="今日订单操作"] .today-shortcut')
  await expect(shortcuts).toHaveCount(3)
  await expect(shortcuts.nth(0)).toHaveText('⬇️这批订单')
  await expect(shortcuts.nth(1)).toHaveText('⏬今日速达')
  await expect(shortcuts.nth(2)).toHaveText('⏬今日纬狮')
  await expect(page.getByTestId('download-today-orders')).toHaveAttribute('href','/logistics/china/today/export')
  await expect(page.getByTestId('download-today-4px')).toHaveAttribute('href','/logistics/china/export/4px')
  await expect(page.getByTestId('download-today-weship')).toHaveAttribute('href','/logistics/china/export/weship')
  await expect(page.locator('.table-wrap.orders table')).toHaveCount(0)
  await expect(page.getByTestId('domestic-native-batch-toggle')).toHaveCount(0)
  await page.route('**/logistics/china/orders/*/barcode-69',route=>
    route.fulfill({status:200,contentType:'application/json',
      body:JSON.stringify({items:[{sku:'E2E-SKU-0001',quantity:1,barcode_69:'6901234567890'}]})}))
  const barcode=grid.locator('.domestic-pending-sku').first()
  await expect(barcode.getByRole('button',{name:'69条码'})).toBeVisible()
  await barcode.getByRole('button',{name:'69条码'}).click()
  await expect(page.getByTestId('domestic-pending-barcode')).toContainText('6901234567890')
  await page.getByTestId('domestic-pending-barcode').getByRole('button',{name:'关闭'}).click()
  const action=grid.locator('.domestic-grid-cell-actions').first()
  await expect(action).toBeVisible()
  await expect(action.getByText('—')).toBeVisible()
  // AG Grid can render checkboxes either in row-selection wrapper or its
  // accessible input, depending on the restored per-view column layout.
  const selectBox=grid.locator('.ag-row .ag-selection-checkbox').first()
  const accessibleBox=grid.locator('.ag-row [role="checkbox"]').first()
  if(!(await selectBox.count()) && !(await accessibleBox.count())){
    await grid.getByRole('button',{name:/列设置/}).click()
    await grid.getByRole('button',{name:'恢复默认'}).click()
    await grid.getByRole('button',{name:'完成'}).click()
  }
  if(await selectBox.count()) await selectBox.click()
  else if(await accessibleBox.count()) await accessibleBox.click()
  else {
    // AG Grid keyboard selection is a documented supported alternate input
    // and OmsGrid's onSelectionChanged accepts the spaceKey source. Keep the
    // real checkbox interaction covered by the R4-5A and wait-stock tests.
    const orderCell=grid.locator('.ag-cell[col-id="order"]').first()
    await expect(orderCell).toBeVisible({timeout:6000})
    await orderCell.press('Space')
    console.log('R4_4_KEYBOARD_SELECTION_FALLBACK_EXECUTED')
  }
  await expect(grid.getByTestId('grid-batch-footer')).toBeVisible()
  await expect(grid.getByTestId('grid-batch-footer').getByRole('combobox',{name:'批量操作'})).toBeVisible()
  await page.getByRole('button',{name:'返回原表'}).click()
  await expect(page).toHaveURL(/view=today/)
  await expect(page).toHaveURL(/global_grid=0/)
  await expect(page.locator('.table-wrap.orders table')).toBeVisible()
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toHaveCount(0)
  await expect(page.locator('[aria-label="今日订单操作"] .today-shortcut')).toHaveCount(3)
  console.log('R4_4_TODAY_GRID_3_EXPORTS_BARCODE_ONE_FOOTER_FALLBACK_PASS')
})

test('R4-5B both daily provider Grids preserve separate carriers, original downloads and rollback',async({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().logisticsOperator)
  await page.goto('/logistics/china?view=today-4px',{waitUntil:'domcontentloaded'})
  const grid=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(grid).toBeVisible({timeout:18000})
  await expect(grid.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:18000})
  await expect(grid.getByText('007-1111-c99986',{exact:true})).toBeVisible()
  await expect(grid.getByText('007-1111-c99985',{exact:true})).toHaveCount(0)
  await expect(page.locator('.domestic-compact-workspace')).toBeVisible()
  await expect(page.locator('.compact-workspace-help')).toContainText('27 列模板')
  await expect(page.locator('.provider-grid a[href="/logistics/china/export/4px"]')).toBeVisible()
  await expect(page.locator('.provider-grid a[href="/logistics/china/export/weship"]')).toHaveCount(0)
  await expect(page.locator('input[type="file"]')).toHaveCount(0)
  await expect(page.getByTestId('domestic-native-batch-toggle')).toHaveCount(0)
  await expect(grid.locator('.domestic-grid-cell-actions').first()).toBeVisible()
  await grid.locator('.ag-selection-checkbox').first().click()
  await expect(grid.getByTestId('grid-batch-footer')).toBeVisible()
  await page.getByRole('button',{name:'返回原表'}).click()
  await expect(page).toHaveURL(/view=today-4px/)
  await expect(page).toHaveURL(/global_grid=0/)
  await expect(page.locator('.table-wrap.orders')).toContainText('007-1111-c99986')
  await expect(page.locator('.table-wrap.orders')).not.toContainText('007-1111-c99985')
  // Exercise the tab's independent Grid default, not only the server URL.
  await page.getByRole('navigation',{name:'国内发货业务视图'})
    .getByRole('button',{name:/今日纬狮/}).click()
  await expect(page).toHaveURL(/view=today-weship/)
  await expect(page).toHaveURL(/global_grid=1/)
  const weshipGrid=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(weshipGrid).toBeVisible({timeout:18000})
  await expect(weshipGrid.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:18000})
  await expect(weshipGrid.getByText('007-1111-c99985',{exact:true})).toBeVisible()
  await expect(weshipGrid.getByText('007-1111-c99986',{exact:true})).toHaveCount(0)
  await expect(page.locator('.table-wrap.orders table')).toHaveCount(0)
  await expect(page.locator('.compact-workspace-help')).toContainText('纬狮')
  await expect(page.locator('.provider-card a[href="/logistics/china/export/weship"]')).toBeVisible()
  await expect(page.locator('.provider-card a[href="/logistics/china/export/4px"]')).toHaveCount(0)
  await expect(page.getByTestId('domestic-native-batch-toggle')).toHaveCount(0)
  await expect(page.locator('input[type="file"]')).toHaveCount(0)
  await expect(weshipGrid.locator('.domestic-grid-cell-actions').first()).toBeVisible()
  await weshipGrid.locator('.ag-selection-checkbox').first().click()
  await expect(weshipGrid.getByTestId('grid-batch-footer')).toBeVisible()
  await expect(weshipGrid.getByTestId('grid-batch-footer')
    .getByRole('combobox',{name:'批量操作'})).toBeVisible()
  await page.getByRole('button',{name:'返回原表'}).click()
  await expect(page).toHaveURL(/view=today-weship/)
  await expect(page).toHaveURL(/global_grid=0/)
  const weshipNative=page.locator('.table-wrap.orders')
  await expect(weshipNative).toContainText('007-1111-c99985')
  await expect(weshipNative).not.toContainText('007-1111-c99986')
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toHaveCount(0)
  await expect(page.locator('.provider-card a[href="/logistics/china/export/weship"]')).toBeVisible()
  console.log('R4_5_B_WESHIP_AND_SPEEDA_DUAL_GRID_PROVIDER_ISOLATION_FALLBACK_PASS')
})


test('待分仓 Global Grid reuses domestic selection and requires a second batch confirmation',async ({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().authorized)
  await page.goto('/logistics/wait-stock',{waitUntil:'domcontentloaded'})
  const grid=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(grid).toBeVisible({timeout:20000})
  await expect(page.locator('.allocation-table')).toHaveCount(0)
  await expect(page.getByTestId('allocation-global-grid-toggle')).toHaveText('返回原版表格')
  await expect(grid.getByText('007-1110-c99990',{exact:true}).first()).toBeVisible()
  const firstRow=grid.locator('.ag-row[row-index="0"]')
  await firstRow.locator('.ag-selection-checkbox').first().click()
  const footer=grid.getByTestId('grid-batch-footer')
  await expect(footer).toBeVisible()
  await expect(footer).toContainText('已选 1 条')
  await footer.getByRole('combobox',{name:'批量操作'}).selectOption('warehouse:FACTORY')
  await footer.getByRole('button',{name:'应用'}).click()
  const dialog=page.getByTestId('allocation-batch-confirm')
  await expect(dialog).toBeVisible()
  await expect(dialog).toContainText('批量分仓确认')
  await expect(dialog).toContainText('可提交 1 条')
  await dialog.getByRole('button',{name:'返回'}).click()
  await expect(dialog).toHaveCount(0)

  // The first Apply only opens the review; an actual write requires
  // the second, explicit operator confirmation, on disposable SQLite only.
  await footer.getByRole('button',{name:'应用'}).click()
  const responsePromise=page.waitForResponse(response=>response.url().includes('/logistics/wait-stock/batch-allocation')
    &&response.request().method()==='POST')
  await dialog.getByTestId('allocation-batch-confirm-apply').click()
  const response=await responsePromise
  expect(response.status()).toBe(200)
  const result=await response.json()
  expect(result.results).toHaveLength(1)
  expect(result.results[0].order_id).toBeGreaterThan(0)
  expect(typeof result.results[0].ok).toBe('boolean')
  await expect(dialog).toHaveCount(0)
  console.log('WAIT_STOCK_BATCH_CHROME_CONFIRM_PER_ORDER_PASS',JSON.stringify({
    results:result.results.map((row:{ok:boolean;message:string})=>({ok:row.ok,message:row.message})),
  }))
})

test('R4-6 exceptions Grid has factual reasons and view-only actions with native fallback',async({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().logisticsOperator)
  await page.goto('/logistics/china?view=exceptions',{waitUntil:'domcontentloaded'})
  const grid=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(grid).toBeVisible({timeout:18000})
  await expect(grid.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:18000})
  await expect(grid.getByText('007-1111-c99984',{exact:true})).toBeVisible()
  await expect(grid.getByText('007-1111-c99983',{exact:true})).toBeVisible()
  await expect(grid.getByText('007-1111-c99985',{exact:true})).toHaveCount(0)
  await expect(grid.getByRole('columnheader',{name:'异常类型'})).toBeVisible()
  await expect(grid.getByRole('columnheader',{name:'异常原因'})).toBeVisible()
  await expect(grid.getByText('发货异常',{exact:true}).first()).toBeVisible()
  await expect(grid.getByText('承运商反馈包裹延误',{exact:true})).toBeVisible()
  await expect(grid.getByText('进度冲突',{exact:true}).first()).toBeVisible()
  await expect(grid.getByText('物流已发货，但缺少发货时间',{exact:true})).toBeVisible()
  const actions=grid.locator('.global-cell-actions')
  await expect(actions.first().getByRole('link',{name:'查看订单'})).toBeVisible()
  // Latest #740 KOL detail routing may point to /orders/{id}, while
  // customer rows still use /customers/orders/{id}. Both must resolve.
  await expect(actions.first().getByRole('link',{name:'查看订单'}))
    .toHaveAttribute('href', /\/(?:customers\/orders|orders)\/\d+/)
  await expect(grid.getByRole('button',{name:'原操作'})).toHaveCount(0)
  await expect(grid.getByRole('button',{name:'确认发货'})).toHaveCount(0)
  await expect(page.getByTestId('domestic-native-batch-toggle')).toHaveCount(0)
  await expect(page.locator('input[type="file"]')).toHaveCount(0)
  await grid.locator('.ag-selection-checkbox').first().click()
  await expect(grid.getByTestId('grid-batch-footer')).toBeVisible()
  await page.getByRole('button',{name:'返回原表'}).click()
  await expect(page).toHaveURL(/view=exceptions/)
  await expect(page).toHaveURL(/global_grid=0/)
  const original=page.locator('.table-wrap.orders')
  await expect(original).toContainText('007-1111-c99984')
  await expect(original).toContainText('007-1111-c99983')
  await expect(original).not.toContainText('007-1111-c99985')
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toHaveCount(0)
  // R4-7A only: 4PX history now defaults Grid. Weship cumulative list
  // remains native until an independent Owner-approved R4-7B release.
  await page.getByRole('navigation',{name:'国内发货业务视图'})
    .getByRole('button',{name:/速达订单/}).click()
  await expect(page).toHaveURL(/view=4px/)
  await expect(page).toHaveURL(/global_grid=1/)
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toBeVisible()
  await page.getByRole('navigation',{name:'国内发货业务视图'})
    .getByRole('button',{name:/纬狮订单/}).click()
  await expect(page).toHaveURL(/view=weship/)
  await expect(page).toHaveURL(/global_grid=0/)
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toHaveCount(0)
  console.log('R4_6_EXCEPTION_REASON_VIEW_ONLY_FALLBACK_PASS')
})

test('R4-7A 速达历史 Global Grid all-state scope and direct authorized inline shipping, native fallback',async({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().logisticsOperator)
  await page.goto('/logistics/china?view=4px',{waitUntil:'domcontentloaded'})
  const grid=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(grid).toBeVisible({timeout:18000})
  await expect(grid.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:18000})
  await expect(grid.getByText('007-1111-c99986',{exact:true})).toBeVisible()
  // Original 4PX carrier filter includes exceptions and unreviewed rows;
  // it does not constrain shipped_at or order creation date.
  await expect(grid.getByText('007-1111-c99984',{exact:true})).toBeVisible()
  await expect(grid.getByText('007-1111-c99982',{exact:true})).toBeVisible()
  await expect(grid.getByText('007-1111-c99985',{exact:true})).toHaveCount(0)
  await expect(grid.locator('.domestic-grid-cell-actions')).toHaveCount(0)
  await expect(grid.getByRole('button',{name:'原操作'})).toHaveCount(0)
  await expect(page.locator('.domestic-grid-legacy-tools')).toHaveCount(0)
  await expect(page.getByTestId('domestic-native-batch-toggle')).toHaveCount(0)
  await expect(page.locator('input[type="file"]')).toHaveCount(0)
  // G-P0: the historical view defaults to zero pinned-right columns.
  // For this operation coverage explicitly opt back into 1 pinned column.
  await grid.getByRole('button',{name:'⚙ 列设置'}).click()
  await grid.getByRole('button',{name:'恢复默认'}).click()
  const rightPin=grid.locator('.oms-pin-row label').filter({hasText:'右侧固定'}).locator('input')
  await expect(rightPin).toHaveValue('0')
  // Explicitly put Action at the far right. It is now user-reorderable,
  // rather than forcibly moved back regardless of chosen column order.
  await grid.locator('.oms-column-row').filter({hasText:'操作'})
    .dragTo(grid.locator('.oms-column-row').last())
  await rightPin.fill('1')
  await rightPin.press('Tab')
  await grid.getByRole('button',{name:'关闭列设置'}).click()
  // G-P1: compact menu is only presentation; it delegates to the same
  // already-guarded pending review, carrier and shipping API callbacks.
  const inline=grid.locator('.ag-cell[col-id="actions"].ag-cell-first-right-pinned .domestic-pending-actions button[aria-label="操作"]').first()
  await expect(inline).toBeVisible()
  await inline.click()
  const menu=page.getByRole('group',{name:'订单操作'})
  await expect(menu.getByRole('button',{name:'填写物流'})).toBeVisible()
  await menu.getByRole('button',{name:'填写物流'}).click()
  const popup=page.getByTestId('domestic-pending-popover')
  await expect(popup).toBeVisible()
  await expect(popup.getByRole('button',{name:'确认发货'})).toBeDisabled()
  await popup.getByRole('button',{name:'取消'}).click()
  await expect(popup).toHaveCount(0)
  await grid.locator('.ag-selection-checkbox').first().click()
  await expect(grid.getByTestId('grid-batch-footer')).toBeVisible()
  await expect(grid.getByTestId('grid-batch-footer').getByRole('combobox',{name:'批量操作'})).toBeVisible()
  await page.getByRole('button',{name:'返回原表'}).click()
  await expect(page).toHaveURL(/view=4px/)
  await expect(page).toHaveURL(/global_grid=0/)
  const legacy=page.locator('.table-wrap.orders')
  await expect(legacy).toContainText('007-1111-c99986')
  await expect(legacy).toContainText('007-1111-c99984')
  await expect(legacy).toContainText('007-1111-c99982')
  await expect(legacy).not.toContainText('007-1111-c99985')
  // Do not silently enable R4-7B via provider tab switch.
  await page.getByRole('navigation',{name:'国内发货业务视图'})
    .getByRole('button',{name:/纬狮订单/}).click()
  await expect(page).toHaveURL(/view=weship/)
  await expect(page).toHaveURL(/global_grid=0/)
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toHaveCount(0)
  console.log('R4_7A_SPEEDA_HISTORY_DIRECT_INLINE_ACTION_AND_NATIVE_FALLBACK_PASS')
})


test('G-P0/G-P2 operator can unpin and hide Action, legacy width migrates and layout survives reload',async({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().logisticsOperator)
  await page.goto('/logistics/china?view=4px',{waitUntil:'domcontentloaded'})
  const grid=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(grid.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:18000})
  await grid.getByRole('button',{name:'⚙ 列设置'}).click()
  await grid.getByRole('button',{name:'恢复默认'}).click()
  const pins=grid.locator('.oms-pin-row label').filter({hasText:'右侧固定'}).locator('input')
  await expect(pins).toHaveValue('0')
  const actionLine=grid.locator('.oms-column-row').filter({hasText:'操作'})
  const checkbox=actionLine.locator('input[type="checkbox"]')
  await expect(checkbox).toBeEnabled()
  await expect(checkbox).toBeChecked() // 恢复默认 must re-show the operation column
  await actionLine.dragTo(grid.locator('.oms-column-row').last())
  await pins.fill('1')
  await pins.press('Tab')
  await expect(pins).toHaveValue('1')
  const actionCell=grid.locator('.ag-cell[col-id="actions"].ag-cell-first-right-pinned').first()
  await expect(actionCell).toBeVisible()
  let width=await actionCell.evaluate(node=>node.getBoundingClientRect().width)
  expect(width).toBeLessThanOrEqual(130)

  // Emulate legacy layout saved by the prior locked 350-390px operation column.
  const key=await page.evaluate(()=>Object.keys(localStorage).find(k=>k.endsWith(':grid.logistics.domestic.4px.global.v1')))
  expect(key).toBeTruthy()
  await page.evaluate(storageKey=>{
    const saved=JSON.parse(localStorage.getItem(storageKey!)??'{}')
    const actions=saved.columns?.find((column:{colId:string})=>column.colId==='actions')
    if(actions)actions.width=360
    saved.pinnedRight=1
    localStorage.setItem(storageKey!,JSON.stringify(saved))
  },key)
  await page.reload({waitUntil:'domcontentloaded'})
  const reopened=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(reopened.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:18000})
  const corrected=reopened.locator('.ag-cell[col-id="actions"].ag-cell-first-right-pinned').first()
  await expect(corrected).toBeVisible()
  width=await corrected.evaluate(node=>node.getBoundingClientRect().width)
  expect(width).toBeLessThanOrEqual(130)
  await reopened.getByRole('button',{name:'⚙ 列设置'}).click()
  const newPins=reopened.locator('.oms-pin-row label').filter({hasText:'右侧固定'}).locator('input')
  await newPins.fill('0')
  await newPins.press('Tab')
  await expect(newPins).toHaveValue('0')
  const actionOption=reopened.locator('.oms-column-row').filter({hasText:'操作'}).locator('input[type="checkbox"]')
  await actionOption.uncheck()
  await expect(actionOption).not.toBeChecked()
  await expect(reopened.locator('.ag-cell[col-id="actions"].ag-cell-first-right-pinned')).toHaveCount(0)
  await reopened.getByRole('button',{name:'关闭列设置'}).click()
  await page.reload({waitUntil:'domcontentloaded'})
  const afterReload=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(afterReload.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:18000})
  await afterReload.getByRole('button',{name:'⚙ 列设置'}).click()
  await expect(afterReload.locator('.oms-pin-row label').filter({hasText:'右侧固定'}).locator('input')).toHaveValue('0')
  await expect(afterReload.locator('.oms-column-row').filter({hasText:'操作'}).locator('input[type="checkbox"]')).not.toBeChecked()
  // Reset synthetic account preference after verification: future tests should
  // not inherit a hidden action column.
  await afterReload.getByRole('button',{name:'恢复默认'}).click()
  await expect(afterReload.locator('.oms-pin-row label').filter({hasText:'右侧固定'}).locator('input')).toHaveValue('0')
  console.log('G_PIN_0_HIDE_OPERATION_WIDTH_MIGRATION_AND_PERSISTENCE_PASS')
})

test('G-P3 sync all orders permits right pinned zero and optional actions without losing progress state',async({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().authorized)
  await page.goto('/sync/orders',{waitUntil:'domcontentloaded'})
  const grid=page.locator('[data-testid="erp-global-grid-v1"]')
  await expect(grid).toBeVisible({timeout:18000})
  await expect(grid.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:18000})
  // Owner G third-table feedback: direct action links, not extra dropdown clicks.
  // Do not actually sync any orders during read-only browser tests.
  const actionCells=grid.locator('.ag-cell[col-id="actions"]')
  await expect(actionCells.first().getByRole('link',{name:'查看'})).toBeVisible()
  await expect(actionCells.getByRole('button',{name:'操作'})).toHaveCount(0)
  await grid.getByRole('button',{name:'⚙ 列设置'}).click()
  const right=grid.locator('.oms-pin-row label').filter({hasText:'右侧固定'}).locator('input')
  await right.fill('0')
  await right.press('Tab')
  await expect(right).toHaveValue('0')
  await expect(grid.locator('.oms-column-row').filter({hasText:'操作'}).locator('input')).toBeEnabled()
  await expect(grid.locator('.oms-column-row').filter({hasText:'同步进度'}).locator('input')).toBeEnabled()
  await expect(grid.locator('.ag-cell[col-id="actions"].ag-cell-first-right-pinned')).toHaveCount(0)
  await grid.getByRole('button',{name:'关闭列设置'}).click()
  await page.reload({waitUntil:'domcontentloaded'})
  await expect(grid.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:18000})
  await grid.getByRole('button',{name:'⚙ 列设置'}).click()
  await expect(grid.locator('.oms-pin-row label').filter({hasText:'右侧固定'}).locator('input')).toHaveValue('0')
  await grid.getByRole('button',{name:'恢复默认'}).click()
  console.log('G_SYNC_RIGHT_ZERO_NO_LOCK_AND_PROGRESS_UNCHANGED_PASS')
})

test('G overseas action column may be unpinned and hidden with account layout persistence',async({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().authorized)
  await page.goto('/logistics/oversea?view=all',{waitUntil:'domcontentloaded'})
  const grid=page.locator('[data-testid="oversea-global-grid-workbench"] [data-testid="erp-global-grid-v1"]')
  await expect(grid).toBeVisible({timeout:18000})
  await expect(grid.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:18000})
  await grid.getByRole('button',{name:'⚙ 列设置'}).click()
  const pins=grid.locator('.oms-pin-row label').filter({hasText:'右侧固定'}).locator('input')
  const action=grid.locator('.oms-column-row').filter({hasText:'操作'}).locator('input[type="checkbox"]')
  await expect(action).toBeChecked()
  await expect(action).toBeEnabled()
  await pins.fill('0')
  await pins.press('Tab')
  await expect(pins).toHaveValue('0')
  await action.uncheck()
  await expect(action).not.toBeChecked()
  await grid.getByRole('button',{name:'关闭列设置'}).click()
  await page.reload({waitUntil:'domcontentloaded'})
  await expect(grid.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:18000})
  await grid.getByRole('button',{name:'⚙ 列设置'}).click()
  await expect(grid.locator('.oms-pin-row label').filter({hasText:'右侧固定'}).locator('input')).toHaveValue('0')
  await expect(grid.locator('.oms-column-row').filter({hasText:'操作'}).locator('input[type="checkbox"]')).not.toBeChecked()
  // Restore this isolated test user's default to avoid leaking visibility preferences.
  await grid.getByRole('button',{name:'恢复默认'}).click()
  await expect(grid.locator('.oms-column-row').filter({hasText:'操作'}).locator('input[type="checkbox"]')).toBeChecked()
  console.log('G_OVERSEA_ACTION_CAN_UNPIN_HIDE_PERSIST_AND_RESTORE_PASS')
})


test('overseas bulk confirm ships only after preview and explicit acknowledgement, never executes in Chrome UAT',async({page})=>{
  await page.setViewportSize({width:1440,height:900})
  await loginThroughUi(page,syntheticUsers().logisticsOperator)
  let previewRequests=0
  let applyRequests=0
  await page.route('**/logistics/oversea/bulk-ship/preview',async(route)=>{
    previewRequests++
    const payload=route.request().postDataJSON() as {order_ids:number[]}
    expect(payload.order_ids).toHaveLength(1)
    await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({
      token:'a'.repeat(48),expires_in_seconds:600,total:1,eligible_count:1,blocked_count:0,
      warning:'真实出库经人工核实才能确认',
      results:[{order_id:payload.order_ids[0],order_no:'007-E2E-GG-BULK-01',
        warehouse:'US-NY',tracking_number:'9400111899223856928777',
        eligible:true,message:'预检通过'}],
    })})
  })
  await page.route('**/logistics/oversea/bulk-ship/apply',async(route)=>{
    applyRequests++
    await route.fulfill({status:409,contentType:'application/json',
      body:JSON.stringify({message:'Browser UAT forbids shipment writes'})})
  })
  await page.goto('/logistics/oversea?view=pending',{waitUntil:'domcontentloaded'})
  const grid=page.locator('[data-testid="oversea-global-grid-workbench"] [data-testid="erp-global-grid-v1"]')
  await expect(grid.locator('.oms-ag-grid')).toHaveCSS('visibility','visible',{timeout:18000})
  const row=grid.locator('.ag-row').filter({hasText:'007-E2E-GG-BULK-01'})
  await expect(row).toBeVisible()
  await row.locator('input[type="checkbox"]').first().check()
  const footer=grid.getByTestId('grid-batch-footer')
  await expect(footer).toContainText('已选 1 条')
  await footer.getByLabel('批量操作').selectOption('bulk_ship')
  await footer.getByRole('button',{name:'应用'}).click()
  await expect(page.getByTestId('oversea-bulk-ship-modal')).toBeVisible()
  const dialog=page.getByRole('dialog',{name:'海外仓批量确认发货'})
  await expect(dialog).toContainText('可确认 1 单')
  await expect(dialog.getByRole('button',{name:'确认发货 1 单'})).toBeDisabled()
  await dialog.locator('.oversea-bulk-ship-ack input').check()
  await expect(dialog.getByRole('button',{name:'确认发货 1 单'})).toBeEnabled()
  await dialog.getByRole('button',{name:'取消'}).click()
  await expect(page.getByTestId('oversea-bulk-ship-modal')).toHaveCount(0)
  expect(previewRequests).toBe(1)
  expect(applyRequests).toBe(0)
  console.log('OVERSEA_BATCH_SHIP_BROWSER_PREVIEW_CONFIRM_NO_APPLY_PASS')
})
