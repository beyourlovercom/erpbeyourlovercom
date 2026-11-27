import {expect,test} from '@playwright/test'
import {loginThroughUi,syntheticUsers} from '../support/auth-helpers'
import {installBrowserGuards} from '../support/browser-guards'
import path from 'node:path'

test.skip(process.env.BYL_E2E_ALLOCATION_RATE!=='1','Requires isolated rate-preview fixture')
test('real ERP allocation dialog shows source-based estimate and missing data without writes',async({page,context})=>{
  const guard=await installBrowserGuards(context,page)
  await page.setViewportSize({width:1586,height:928})
  await loginThroughUi(page,syntheticUsers().logisticsOperator)
  let businessWrites=0
  page.on('request',r=>{if(['POST','PUT','PATCH','DELETE'].includes(r.method())&&/\/logistics\//.test(r.url()))businessWrites++})
  await page.goto('/logistics/wait-stock?global_grid=1&search=C9900021-007')
  await expect(page.getByText('C9900021-007',{exact:true}).first()).toBeVisible()
  await page.getByRole('button',{name:'查看方案',exact:true}).click()
  const panel=page.getByTestId('allocation-rate-preview')
  await expect(panel).toContainText('2件 · 1 kg · 20 × 15 × 10 cm')
  const de=panel.locator('[data-warehouse="DE"]')
  await expect(de).toContainText('DHL 本地')
  await expect(de).toContainText('未审核')
  await expect(de).toContainText('额外件操作参考 USD 1.00')
  // November seasonal fee and fuel, using real 2025 original German workbook.
  await expect(de).toContainText('EUR 4.13')
  await expect(de).toContainText('¥33.04')
  await de.getByText('计费明细',{exact:true}).click()
  await expect(de).toContainText('DE-DHL （本地）')
  await expect(de).toContainText('B5:C5')
  await expect(panel).toContainText('不参与自动分仓排名')
  await expect(panel.locator('[data-warehouse="FACTORY"]')).toContainText('无对应仓库报价')
  if(process.env.BYL_E2E_VISUAL_EVIDENCE_DIR)await page.screenshot({path:path.join(process.env.BYL_E2E_VISUAL_EVIDENCE_DIR,'allocation-rate-desktop.png')})
  await page.setViewportSize({width:390,height:844})
  await expect(panel).toBeVisible()
  expect(await panel.evaluate(el=>el.getBoundingClientRect().width<=window.innerWidth)).toBe(true)
  if(process.env.BYL_E2E_VISUAL_EVIDENCE_DIR)await page.screenshot({path:path.join(process.env.BYL_E2E_VISUAL_EVIDENCE_DIR,'allocation-rate-mobile.png')})
  await page.setViewportSize({width:1586,height:928})
  await page.goto('/logistics/wait-stock?global_grid=1&search=C9900022-007')
  await page.getByRole('button',{name:'查看方案',exact:true}).click()
  await expect(panel).toContainText('缺少已审核的物流包装重量/尺寸')
  await expect(panel.locator('.rate-row')).toHaveCount(0)
  expect(businessWrites).toBe(0)
  await guard.assertClean()
  console.log('ALLOCATION_RATE_BROWSER_PASS: real source estimate, source cells, missing evidence, responsive, zero writes')
})
