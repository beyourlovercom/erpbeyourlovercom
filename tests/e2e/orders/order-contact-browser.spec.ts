import { expect, test } from '@playwright/test'
import { syntheticUsers } from '../support/auth-helpers'
import { installBrowserGuards } from '../support/browser-guards'

test('KOL contact selection, create, detail and list work in isolated browser', async ({ context, page }) => {
  const guard = await installBrowserGuards(context, page)
  page.on('requestfailed', request => console.log('R4_REQUEST_FAILURE', new URL(request.url()).pathname, request.failure()?.errorText))
  await context.request.get('/login')
  const login = await context.request.post('/login', {
    form: syntheticUsers().kolEmma,
    headers: { Origin: process.env.BYL_E2E_BASE_URL ?? '', 'Sec-Fetch-Site': 'same-origin' },
    maxRedirects: 0,
  })
  expect(login.status()).toBe(302)
  await page.goto('/kol/orders/create')
  const contact = page.locator('#contact_user_id')
  await expect(contact).toBeVisible()
  await expect(contact).toHaveAttribute('required', '')
  await expect(contact.locator('option')).toHaveCount(4)
  await expect(contact).not.toContainText('E2E Logistics Operator')
  await expect(contact).not.toContainText('E2E Authorized')
  await Promise.all([
    page.waitForResponse(r=>r.url().includes('/kol/orders/kol-search') && r.ok()),
    page.getByTestId('kol-identity-select').focus(),
  ])
  await page.getByTestId('kol-identity-select').fill('Synthetic Contact Creator')
  await page.getByRole('option').filter({hasText:'Synthetic Contact Creator'}).click()
  await expect(contact.locator('option:checked')).toHaveText('E2E KOL Emma')
  await contact.selectOption({ label:'E2E KOL Manager' })
  await page.locator('.sku-combobox-input').first().fill('E2E-SKU-0001')
  await page.getByRole('option').filter({hasText:'E2E-SKU-0001'}).first().click()
  await page.getByTestId('kol-smart-address-confirm').check()
  await page.getByRole('button',{name:'创建订单',exact:true}).click()
  await expect(page).toHaveURL(/\/kol\/orders\/\d+$/)
  await expect(page.getByText('订单联络人',{exact:true})).toBeVisible()
  const summary = page.getByText('订单联络人',{exact:true}).locator('..')
  await expect(summary).toContainText('E2E KOL Manager')
  await summary.getByRole('button',{name:'更换',exact:true}).click()
  const manual = page.getByLabel('订单联络人',{exact:true})
  await expect(manual).toContainText('E2E Logistics Operator')
  await manual.selectOption({label:'E2E Logistics Operator'})
  await summary.getByRole('button',{name:'保存',exact:true}).click()
  await expect(summary).toContainText('E2E Logistics Operator')
  await expect(summary.getByRole('button',{name:'更换',exact:true})).toBeVisible()
  await page.waitForLoadState('networkidle')
  await page.goto('/kol/orders?grid_contact=E2E%20Logistics%20Operator')
  await expect(page.getByText('订单联络人',{exact:true}).first()).toBeVisible()
  await expect(page.locator('tbody')).toContainText('E2E Logistics Operator')
  await page.waitForLoadState('networkidle')
  await guard.assertClean()
  console.log('ORDER_CONTACT_R4_BROWSER_PASS')
})
