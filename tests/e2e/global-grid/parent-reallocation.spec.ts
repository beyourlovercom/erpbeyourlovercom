import { expect, test } from '@playwright/test'
import { loginThroughUi, syntheticUsers } from '../support/auth-helpers'
import { installBrowserGuards } from '../support/browser-guards'
import path from 'node:path'

test.skip(process.env.BYL_E2E_PARENT_REALLOCATION !== '1', 'Requires the dedicated disposable reallocation fixture')

test('R5 real browser: cancel reset, confirm archive, reopen fresh allocation, submit new split', async ({page,context}) => {
  const guard = await installBrowserGuards(context,page)
  // Keep the workflow regression independent of platform-specific overlay scrollbars
  // on the one-row allocation grid. All clicks still use real pointer actionability.
  await page.setViewportSize({width:2880,height:928})
  await loginThroughUi(page,syntheticUsers().logisticsOperator)
  await page.goto('/logistics/wait-stock?global_grid=1&search=C9900001-007')
  const screenshot = async (name:string) => page.screenshot({path:path.join(process.env.BYL_E2E_VISUAL_EVIDENCE_DIR!,name),animations:'disabled'})
  await expect(page.getByText('C9900001-007',{exact:true}).first()).toBeVisible()
  await page.getByRole('button',{name:'查看方案',exact:true}).click()
  await expect(page.getByRole('heading',{name:'履约方案详情'})).toBeVisible()
  await expect(page.getByTestId('reallocation-open')).toBeVisible()
  await screenshot('01-original-plan.png')
  let resetRequests=0
  page.on('request',request=>{if(request.method()==='POST'&&request.url().endsWith('/fulfillment-allocation/reset'))resetRequests++})
  await page.getByTestId('reallocation-open').click()
  await expect(page.getByTestId('reallocation-confirm')).toContainText('警告：此操作将归档所有相关子订单！')
  await screenshot('02-confirm-warning.png')
  await page.getByTestId('reallocation-confirm').getByRole('button',{name:'取消',exact:true}).click()
  expect(resetRequests).toBe(0)
  await page.getByTestId('reallocation-open').click()
  const resetResponse = page.waitForResponse(r=>r.url().endsWith('/fulfillment-allocation/reset')&&r.request().method()==='POST')
  await page.getByTestId('reallocation-confirm-submit').click()
  const reset = await resetResponse
  expect(reset.status()).toBe(200)
  expect(await reset.json()).toMatchObject({status:'reset',archived_count:2})
  await expect(page.locator('.primary-allocation')).toBeVisible()
  await expect(page.getByTestId('reallocation-open')).toHaveCount(0)
  const rows=page.locator('.manual-table-wrap tbody tr')
  await expect(rows).toHaveCount(2)
  await rows.nth(0).locator('td:nth-child(5) select').selectOption('FACTORY')
  await rows.nth(1).locator('td:nth-child(5) select').selectOption('US-NY')
  await expect(page.locator('.group-preview')).toContainText('C9900001-007A-2')
  await expect(page.locator('.group-preview')).toContainText('C9900001-007B-2')
  await expect(page.getByTestId('selected-allocation-cost')).toContainText('暂无法估算')
  const submit = page.getByRole('button',{name:'费用未知 · 继续分仓',exact:true})
  await expect(submit).toBeEnabled()
  await screenshot('03-fresh-plan.png')
  const manualResponse=page.waitForResponse(r=>r.url().endsWith('/fulfillment-allocation/manual')&&r.request().method()==='POST')
  await submit.click()
  const manual=await manualResponse
  expect(manual.status()).toBe(200)
  await expect(page.locator('.plan-modal')).toHaveCount(0)
  expect(resetRequests).toBe(1)
  await screenshot('04-after-submit.png')
  await guard.assertClean()
  console.log('R5_BROWSER_PASS: cancel, exact warning, reset, reopen, A-2/B-2 preview and submit')
})
