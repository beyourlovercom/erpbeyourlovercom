import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { expect, test } from '@playwright/test'
import { authenticateUnauthorizedThroughFortify, loginThroughUi, syntheticUsers } from '../support/auth-helpers'
import { installBrowserGuards } from '../support/browser-guards'

const cases = [
  { type: 'website', label: '网站订单', original: 'E2E-WEB-0091', path: '/customers/orders/' },
  { type: 'kol', label: '网红订单', original: 'E2E-KOL-0092', path: '/kol/orders/' },
  { type: 'manual_other', label: '外采订单', original: 'E2E-PO-0093', path: '/customers/orders/' },
] as const

function syntheticXlsx(original: string): string {
  const root = process.env.BYL_E2E_RUN_ROOT
  if (!root || process.env.BYL_E2E_DISPOSABLE !== 'true' || process.env.DB_CONNECTION !== 'sqlite') {
    throw new Error('Disposable SQLite UAT required; refusing real order creation')
  }
  const file = path.join(root, 'artifacts', original + '.xlsx')
  const php = String.raw`
    require getcwd().'/vendor/autoload.php';
    $columns = \App\Modules\Orders\Application\OrderOmsBatchImport::FIELDS;
    $row = array_fill_keys($columns, '');
    $row['external_order_number'] = $argv[2];
    $row['order_date'] = '2026-11-09';
    $row['recipient_name'] = 'E2E Browser Customer';
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
    file_put_contents($argv[1], $writer->write('E2E Synthetic Orders', $columns, [array_values($row)]));
  `
  execFileSync('php', ['-r', php, file, original], {
    cwd: process.cwd(), env: process.env, stdio: 'pipe',
  })
  return file
}

test('authenticated Dev browser imports three source types and reads source identity in existing detail view', async ({ context, page }) => {
  const guard = await installBrowserGuards(context, page)
  await page.setViewportSize({ width: 1440, height: 900 })
  await loginThroughUi(page, syntheticUsers().authorized)
  await page.goto('/orders?oms_grid=1', { waitUntil: 'domcontentloaded' })
  await expect(page.getByRole('button', { name: '导入', exact: true })).toBeVisible({ timeout: 20000 })

  for (const [index, item] of cases.entries()) {
    await page.getByRole('button', { name: '导入', exact: true }).click()
    const modal = page.getByRole('dialog', { name: '导入订单' })
    await expect(modal).toBeVisible()
    await expect(modal.getByText('批量导入订单')).toBeVisible()
    await expect(modal.getByRole('link', { name: '下载导入模板 XLSX' })).toBeVisible()
    const preflight = modal.getByRole('button', { name: '上传并预检' })
    await expect(preflight).toBeDisabled()
    await expect(modal.locator('button.oms-import-commit')).toBeDisabled()

    const file = syntheticXlsx(item.original)
    await modal.locator('#oms-import-type').selectOption(item.type)
    await modal.locator('#oms-grid-import-file').setInputFiles(file)
    await preflight.click()
    await expect(modal.locator('.oms-import-status')).toContainText('预检通过', { timeout: 15000 })
    await expect(modal.locator('.oms-preview-table')).toContainText(item.original)
    await expect(modal.locator('button.oms-import-commit')).toBeEnabled()
    if (index === 0) {
      // A changed type must invalidate an already-issued preview token.
      await modal.locator('#oms-import-type').selectOption('kol')
      await expect(modal.locator('button.oms-import-commit')).toBeDisabled()
      await expect(modal.locator('.oms-import-results')).toHaveCount(0)
      await modal.locator('#oms-import-type').selectOption(item.type)
      await preflight.click()
      await expect(modal.locator('button.oms-import-commit')).toBeEnabled()
    }

    page.once('dialog', dialog => dialog.accept())
    const pending = page.waitForResponse(r => r.url().endsWith('/orders/oms/import/confirm') && r.request().method() === 'POST')
    await modal.locator('button.oms-import-commit').click()
    const response = await pending
    expect(response.status()).toBe(200)
    const result = await response.json() as { created: number; orders: Array<{ id: number; erp_order_id: string }> }
    expect(result.created).toBe(1)
    expect(result.orders).toHaveLength(1)
    expect(result.orders[0].erp_order_id).not.toBe(item.original)
    await expect(modal.getByText('批量创建完成：1 个订单。')).toBeVisible()
    await modal.getByRole('button', { name: '关闭', exact: true }).click()

    await page.goto(item.path + result.orders[0].id, { waitUntil: 'domcontentloaded' })
    const identity = page.locator('dl[aria-label="订单信息摘要"]')
    await expect(identity).toBeVisible({ timeout: 15000 })
    await expect(identity).toContainText('原始订单号')
    await expect(identity).toContainText(item.original)
    await expect(identity).toContainText(item.label)
    await expect(page.locator('.ord-identity-strip')).toContainText(item.label)
    // The existing source badge and detail source field must no longer show
    // the server's English label for imported orders.
    const sourceField = page.locator('.ord-summary-field').filter({ has: page.locator('dt:text-is("来源")') })
    await expect(sourceField.locator('dd')).toHaveText(item.label)
    console.log('OMS_BROWSER_UAT_DETAIL_PASS=' + item.type + ':' + item.original + ':' + result.orders[0].id)

    // Repeated XLSX after a committed batch must not generate another order.
    if (index === 0) {
      await page.goto('/orders?oms_grid=1', { waitUntil: 'domcontentloaded' })
      await page.getByRole('button', { name: '导入', exact: true }).click()
      const repeated = page.getByRole('dialog', { name: '导入订单' })
      await repeated.locator('#oms-import-type').selectOption(item.type)
      await repeated.locator('#oms-grid-import-file').setInputFiles(file)
      await repeated.getByRole('button', { name: '上传并预检' }).click()
      await expect(repeated.locator('.oms-import-status')).toContainText('预检发现')
      await expect(repeated.locator('button.oms-import-commit')).toBeDisabled()
      await repeated.getByRole('button', { name: '关闭', exact: true }).click()
      console.log('OMS_BROWSER_UAT_DUPLICATE_BLOCK_PASS=true')
    }
    await page.goto('/orders?oms_grid=1', { waitUntil: 'domcontentloaded' })
  }
  for (const item of cases) {
    const row = page.locator('.ag-row').filter({ has: page.getByRole('link', { name: item.original }) })
    await expect(row.locator('[col-id="source"]')).toHaveText(item.label)
  }
  await guard.assertClean()
})

test('browser session without order permissions cannot access OMS import endpoints', async ({ context }) => {
  expect(await authenticateUnauthorizedThroughFortify(context, syntheticUsers().unauthorized)).toBe(302)
  const template = await context.request.get('/orders/oms/import/template', { maxRedirects: 0 })
  expect(template.status()).toBe(403)
  const csrf = (await context.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN')
  expect(csrf).toBeDefined()
  const preview = await context.request.post('/orders/oms/import/preview', {
    data: {}, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(csrf!.value) }, maxRedirects: 0,
  })
  expect(preview.status()).toBe(403)
  console.log('OMS_BROWSER_UAT_AUTHORIZATION_PASS=true')
})
