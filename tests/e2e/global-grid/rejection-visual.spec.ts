import { expect, test } from '@playwright/test'
import { mkdir } from 'node:fs/promises'
import path from 'node:path'
import { loginThroughUi, syntheticUsers } from '../support/auth-helpers'
import { installBrowserGuards } from '../support/browser-guards'

// Tests keep disposable screenshots in their isolated run by default.
// Manual UAT may opt into a machine-local, absolute evidence directory.
const externalScreenshots = process.env.BYL_E2E_VISUAL_EVIDENCE_DIR
if (externalScreenshots && !path.isAbsolute(externalScreenshots)) {
  throw new Error('BYL_E2E_VISUAL_EVIDENCE_DIR must be absolute')
}
const screenshots = externalScreenshots ?? path.join(process.env.BYL_E2E_RUN_ROOT ?? '', 'artifacts', 'rejection-visual')
async function saveScreenshot(page: import('@playwright/test').Page, name: string) {
  await mkdir(screenshots, { recursive: true })
  await page.screenshot({ path: path.join(screenshots, name), fullPage: true, animations: 'disabled' })
}

test('R3 desktop browser visual acceptance: global grid, cross-page rejected queue, reasons and banners', async ({page,context}) => {
  await page.setViewportSize({width:1440,height:900})
  const guard = await installBrowserGuards(context,page)
  await loginThroughUi(page,syntheticUsers().authorized)
  await page.goto('/logistics/wait-stock?per_page=20',{waitUntil:'domcontentloaded'})
  const rejectedTab=page.getByTestId('allocation-rejected-tab')
  await expect(rejectedTab).toBeVisible()
  await expect(rejectedTab).toContainText('驳回待处理 2')
  await expect(page.getByTestId('allocation-rejection-alert')).toBeVisible()
  await expect(page.getByTestId('allocation-rejection-alert')).toContainText('2 个订单被驳回')
  const grid=page.getByTestId('erp-global-grid-v1')
  await expect(grid).toBeVisible()
  await expect(page.getByRole('grid')).toBeVisible()
  await expect(page.locator('.allocation-table')).toHaveCount(0)
  // Both rejected items have dates older than 23 normal orders, and are absent from page 1.
  await expect(grid.getByText('007-1110-c99990',{exact:true})).toHaveCount(0)
  await expect(grid.getByText('007-1110-c99992',{exact:true})).toHaveCount(0)
  await saveScreenshot(page,'01-desktop-all-alert.png')

  await rejectedTab.click()
  await expect(page).toHaveURL(/rejected=1/)
  await expect(rejectedTab).toHaveClass(/active/)
  await expect(grid.getByText('007-1110-c99990',{exact:true})).toBeVisible()
  await expect(grid.getByText('007-1110-c99992',{exact:true})).toBeVisible()
  await expect(grid.getByText('海外仓驳回：仓库地址信息不完整（模拟）',{exact:true})).toBeVisible()
  await expect(grid.getByText('国内商务驳回：收件地址需核对（模拟）',{exact:true})).toBeVisible()
  await expect(grid.getByText('已驳回',{exact:true}).first()).toBeVisible()
  await saveScreenshot(page,'02-desktop-rejected-filter.png')
  const yellowCells=page.locator('.oms-ag-grid .ag-cell.allocation-rejected-cell')
  await expect(yellowCells.first()).toBeVisible()
  const background=await yellowCells.first().evaluate(node=>getComputedStyle(node).backgroundColor)
  expect(background).toBe('rgb(255, 245, 214)')
  await expect(grid.getByText('驳回原因',{exact:true}).first()).toBeVisible()
  await expect(grid.getByText('驳回状态',{exact:true}).first()).toBeVisible()
  const rightCell=page.locator('.ag-cell.ag-cell-first-right-pinned').first()
  await expect(rightCell).toBeVisible()
  const pinnedWidth=(await rightCell.boundingBox())?.width ?? 0
  expect(pinnedWidth).toBeGreaterThan(0)
  expect(pinnedWidth).toBeLessThanOrEqual(180)
  await saveScreenshot(page,'02-desktop-rejected-filter.png')

  await grid.getByRole('button',{name:'⚙ 列设置'}).click()
  await expect(grid.getByText('驳回人员',{exact:true}).first()).toBeVisible()
  await expect(grid.getByText('驳回时间',{exact:true}).first()).toBeVisible()
  await grid.getByRole('button',{name:'关闭列设置'}).click()

  const alert=page.getByTestId('allocation-rejection-alert')
  await alert.getByRole('button',{name:'知道了，不再重复提醒'}).click()
  await expect(alert).toHaveCount(0)
  await page.reload()
  await expect(page.getByTestId('allocation-rejected-tab')).toContainText('2')
  await expect(page.getByTestId('allocation-rejection-alert')).toHaveCount(0)
  await expect(grid.getByText('007-1110-c99992',{exact:true})).toBeVisible()
  await page.getByTestId('allocation-global-grid-toggle').click()
  await expect(page.locator('.allocation-table')).toBeVisible()
  await expect(page.locator('.domestic-rejection').first()).toBeVisible()
  await saveScreenshot(page,'03-desktop-legacy-fallback.png')
  await page.getByTestId('allocation-global-grid-toggle').click()
  await expect(grid).toBeVisible()
  await guard.assertClean()
  console.log('REJECTION_DESKTOP_VISUAL_PASS',JSON.stringify({background,pinnedWidth,filteredOrders:2}))
})

test('R3 mobile browser visual acceptance: warning tab, reason and accessible actions',async ({page,context})=>{
  await page.setViewportSize({width:390,height:844})
  const guard=await installBrowserGuards(context,page)
  await loginThroughUi(page,syntheticUsers().authorized)
  await page.goto('/logistics/wait-stock?rejected=1&per_page=20',{waitUntil:'domcontentloaded'})
  const tab=page.getByTestId('allocation-rejected-tab')
  await expect(tab).toBeVisible()
  await expect(tab).toContainText('2')
  await expect(page.getByTestId('erp-global-grid-v1')).toBeVisible()
  await expect(page.getByRole('grid')).toBeVisible()
  await expect(page.getByTestId('allocation-rejection-alert')).toBeVisible()
  await saveScreenshot(page,'04-mobile-rejected-filter.png')
  await expect(page.getByText('007-1110-c99992',{exact:true}).first()).toBeVisible()
  await page.getByRole('button',{name:'查看方案'}).first().click()
  await expect(page.getByText('已驳回：国内商务驳回：收件地址需核对（模拟）')).toBeVisible()
  await saveScreenshot(page,'05-mobile-rejection-detail.png')
  const pageWidth=await page.evaluate(()=>({viewport:innerWidth,document:document.documentElement.scrollWidth}))
  expect(pageWidth.document).toBeLessThanOrEqual(pageWidth.viewport+24)
  await guard.assertClean()
  console.log('REJECTION_MOBILE_VISUAL_PASS',JSON.stringify(pageWidth))
})
