import { execFileSync } from 'node:child_process'
import path from 'node:path'
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


test('R6 isolated Dev: canonical /orders shows XLSX import and previews without creating an order', async ({ context, page }) => {
  // A successful XLSX attachment download aborts its navigation request in Chrome.
  const guard = await installBrowserGuards(context, page, { allowedNavigationAbortPaths: ['/orders/oms/import/template'] })
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginThroughUi(page, syntheticUsers().authorized)

  await page.goto('/orders/master?view=normal', { waitUntil: 'domcontentloaded' })
  await expect(page).toHaveURL(/\/orders\?view=normal/)
  await page.goto('/orders', { waitUntil: 'domcontentloaded' })
  await expect(page.locator('.orders-master')).toBeVisible({ timeout: 20000 })
  await expect(page.getByText('全部可查看记录 4 单')).toBeVisible()
  await expect(page.getByRole('button', { name: '导入', exact: true })).toBeVisible({ timeout: 20000 })
  await expect(page.locator('.orders-master').getByRole('link', { name: '归档清单' })).toBeVisible()

  await page.getByRole('button', { name: '导入', exact: true }).click()
  const modal = page.getByRole('dialog', { name: '导入订单' })
  await expect(modal).toBeVisible()
  await expect(modal.getByRole('button', { name: '上传并预检' })).toBeDisabled()
  await expect(modal.locator('button.oms-import-commit')).toBeDisabled()
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    modal.getByRole('link', { name: '下载导入模板 XLSX' }).click(),
  ])
  expect(download.suggestedFilename()).toMatch(/\.xlsx$/)

  // Disposable synthetic XLSX only: preflight never calls the create/confirm endpoint.
  const runRoot = process.env.BYL_E2E_RUN_ROOT
  if (!runRoot || process.env.BYL_E2E_DISPOSABLE !== 'true' || process.env.DB_CONNECTION !== 'sqlite') {
    throw new Error('Refusing import preview outside disposable SQLite Dev')
  }
  const file = path.join(runRoot, 'artifacts', 'R6-ORDER-MASTER-PREVIEW-ONLY.xlsx')
  const script = String.raw`
    require getcwd().'/vendor/autoload.php';
    $columns = \App\Modules\Orders\Application\OrderOmsBatchImport::FIELDS;
    $row = array_fill_keys($columns, '');
    $row['external_order_number'] = 'E2E-MASTER-PREVIEW-ONLY-20261119';
    $row['order_date'] = '2026-11-09';
    $row['recipient_name'] = 'Dev Preview Only';
    $row['phone'] = '5551234567';
    $row['country'] = 'US';
    $row['province_state'] = 'CA';
    $row['city'] = 'Los Angeles';
    $row['address_line_1'] = '123 Synthetic Street';
    $row['postal_code'] = '90001';
    $row['currency'] = 'USD';
    $row['sku'] = 'E2E-SKU-0001';
    $row['quantity'] = '2';
    $row['unit_price'] = '19.50';
    $writer = new \App\Modules\Logistics\Application\OverseaFiles\ProviderXlsxWriter;
    file_put_contents($argv[1], $writer->write('Synthetic Preview', $columns, [array_values($row)]));
  `
  execFileSync('php', ['-r', script, file], { cwd: process.cwd(), env: process.env, stdio: 'pipe' })

  let confirms = 0
  page.on('request', request => {
    if (request.url().includes('/orders/oms/import/confirm')) confirms++
  })
  await modal.locator('#oms-import-type').selectOption('manual_other')
  await modal.locator('#oms-grid-import-file').setInputFiles(file)
  await modal.getByRole('button', { name: '上传并预检' }).click()
  await expect(modal.locator('.oms-import-status')).toContainText('预检通过', { timeout: 15000 })
  await expect(modal.locator('.oms-preview-table')).toContainText('E2E-MASTER-PREVIEW-ONLY-20261119')
  await expect(modal.locator('button.oms-import-commit')).toBeEnabled()
  expect(confirms).toBe(0)
  await modal.getByRole('button', { name: '关闭', exact: true }).click()
  await expect(page.getByText('全部可查看记录 4 单')).toBeVisible()
  expect(confirms).toBe(0)
  await guard.assertClean()
  console.log('ORDER_MASTER_R6_DEV_PREVIEW_PASS=canonical,redirect,toolbar,template,xlsx,preview,no-confirm')
})
