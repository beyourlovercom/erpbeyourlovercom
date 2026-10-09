import { expect, test } from '@playwright/test';
import { loginThroughUi, syntheticUsers } from '../support/auth-helpers';
import { installBrowserGuards } from '../support/browser-guards';

// A browser UAT with disposable SQLite and a synthetic administrator only.
// True Emma/manager authorization is covered by isolated PHP feature tests;
// this scenario is NOT a live Dev or Production role session.
test('M14 SKU picker and KOL logistics render without horizontal overflow', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    await page.setViewportSize({ width: 390, height: 844 });
    await loginThroughUi(page, syntheticUsers().authorized);

    await page.goto('/kol/orders/create');
    await expect(page.getByRole('heading', { name: '新建网红履约订单', level: 1 })).toBeVisible();

    // The SKU suggestion must come from the real local backend, not a mock.
    const combobox = page.getByPlaceholder('输入 SKU 或产品名称…');
    await combobox.fill('E2E-SKU-0001');
    await expect(page.locator('.sku-combobox-dropdown li').first()).toBeVisible();
    await page.locator('.sku-combobox-dropdown li').first().click();
    await expect(combobox).toHaveValue('E2E-SKU-0001');

    await page.getByRole('button', { name: '编辑订单号' }).click();
    await page.getByLabel('业务订单号').fill('E2E-PR440-MOBILE');
    const itemRow = page.locator('.ord-item-row').first();
    await itemRow.locator('.erp-form-field').filter({
        has: page.locator('.erp-form-label', { hasText: '数量' }),
    }).locator('input').fill('1');
    await itemRow.locator('.erp-form-field').filter({
        has: page.locator('.erp-form-label', { hasText: '单价' }),
    }).locator('input').fill('0');
    await page.getByRole('button', { name: '创建订单' }).click();

    await expect(page).toHaveURL(/\/kol\/orders\/\d+$/u);
    await page.goto('/kol/logistics');
    await expect(page.getByRole('heading', { name: '物流进度' })).toBeVisible();
    const card = page.locator('.shipment-card').filter({ hasText: 'E2E-PR440-MOBILE' });
    await expect(card).toHaveCount(1);
    await expect(card).toContainText('待发货');
    await expect(card).toContainText('待分配');
    await expect(page.getByText('E2E-CUSTOMER-PRIVATE')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /发货|导出|审核/u })).toHaveCount(0);

    const metrics = await page.evaluate(() => ({
        viewportWidth: window.innerWidth,
        documentWidth: document.documentElement.scrollWidth,
    }));
    expect(metrics.documentWidth).toBeLessThanOrEqual(metrics.viewportWidth + 1);

    await guard.assertClean();
});
