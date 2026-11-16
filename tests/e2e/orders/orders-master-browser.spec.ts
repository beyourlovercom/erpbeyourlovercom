import { expect, test } from '@playwright/test'
import { loginThroughUi, syntheticUsers } from '../support/auth-helpers'
import { installBrowserGuards } from '../support/browser-guards'

test('R5 isolated browser: compact all-order master searches canonical orders on server', async ({ context, page }) => {
  const guard = await installBrowserGuards(context, page)
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginThroughUi(page, syntheticUsers().authorized)

  await page.goto('/orders/master', { waitUntil: 'domcontentloaded' })
  await expect(page.locator('.orders-master')).toBeVisible({ timeout: 20000 })
  await expect(page.locator('.orders-master h2', { hasText: '订单总表' })).toBeVisible()
  await expect(page.locator('[data-testid="erp-global-grid-v1"]')).toBeVisible({ timeout: 20000 })
  await expect(page.getByText('全部可查看记录 4 单')).toBeVisible()
  await expect(page.getByText('007erp-1009-c999', { exact: true }).first()).toBeVisible()
  await expect(page.getByText('E2E-KOL-EMMA', { exact: true }).first()).toBeVisible()

  // Owner removed the extra top tab bar; only the quick-filter strip remains.
  await expect(page.locator('.orders-master .quick-filters')).toBeVisible()
  await expect(page.locator('.orders-master .master-toolbar')).toHaveCount(1)
  await expect(page.locator('.orders-master .archive-tabs')).toHaveCount(0)
  await expect(page.locator('.orders-master .top-tabs')).toHaveCount(0)

  await page.getByRole('combobox', { name: '订单类型' }).selectOption('kol_fulfillment')
  await expect(page).toHaveURL(/kind=kol_fulfillment/)
  await expect(page.getByText('E2E-KOL-EMMA', { exact: true }).first()).toBeVisible()
  await expect(page.getByText('007erp-1009-c999', { exact: true })).toHaveCount(0)

  await page.getByRole('combobox', { name: '订单类型' }).selectOption('all')
  const search = page.getByRole('textbox', { name: '搜索所有订单' })
  await search.fill('E2E-CUSTOMER-PRIVATE')
  await expect(page).toHaveURL(/search=E2E-CUSTOMER-PRIVATE/, { timeout: 15000 })
  await expect(page.getByText('007erp-1009-c999', { exact: true }).first()).toBeVisible()
  await expect(page.getByText('E2E-KOL-EMMA', { exact: true })).toHaveCount(0)

  await search.fill('NO-SUCH-ORDER-999999')
  await expect(page).toHaveURL(/search=NO-SUCH-ORDER-999999/)
  await expect(page.getByText('007erp-1009-c999', { exact: true })).toHaveCount(0)
  await page.getByRole('button', { name: '清空搜索' }).click()
  await expect(page.getByText('007erp-1009-c999', { exact: true }).first()).toBeVisible()

  await page.goto('/orders/archive', { waitUntil: 'domcontentloaded' })
  await expect(page.getByRole('textbox', { name: '搜索全部归档订单' })).toBeVisible()
  await expect(page.getByText('搜索覆盖全部分页')).toBeVisible()
  await guard.assertClean()
  console.log('ORDER_MASTER_R5_BROWSER_PASS=compact,search,kind,empty,clear,archive-search')
})
