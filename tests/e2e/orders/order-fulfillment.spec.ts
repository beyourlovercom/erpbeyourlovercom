import { expect, test } from '@playwright/test';
import { loginThroughUi, syntheticUsers } from '../support/auth-helpers';
import { installBrowserGuards } from '../support/browser-guards';

async function shipViaPrompt(page: import('@playwright/test').Page, carrier: string, tracking: string): Promise<void> {
    const answers = [carrier, tracking];
    page.on('dialog', async (dialog) => {
        await dialog.accept(answers.shift() ?? '');
    });
    await page.getByRole('button', { name: '标记发货' }).click();
}

test('customer manual order flows through logistics to shipped with the business number preserved', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    await loginThroughUi(page, syntheticUsers().authorized);

    await page.goto('/customers/orders/create');
    await expect(page.getByRole('heading', { name: '新建订单' })).toBeVisible();
    await page.getByLabel('业务订单号').fill('E2E-CUS-0001');
    await page.getByLabel('姓名').fill('E2E Customer Recipient');
    await page.getByLabel('电话').fill('+1 555 0100');
    await page.getByLabel('国家').fill('US');
    await page.getByLabel('省/州').fill('CA');
    await page.getByLabel('城市').fill('Los Angeles');
    await page.getByLabel('地址', { exact: true }).fill('100 E2E Street');
    await page.getByPlaceholder('商品摘要').fill('E2E Product');
    await page.getByPlaceholder('数量').fill('1');
    await page.getByPlaceholder('单价').fill('9.99');
    await page.getByRole('button', { name: '保存订单' }).click();

    await expect(page).toHaveURL(/\/customers\/orders\/\d+$/u);
    await expect(page.getByRole('heading', { name: 'E2E-CUS-0001' })).toBeVisible();

    await page.goto('/customers/orders');
    await expect(page.getByRole('link', { name: 'E2E-CUS-0001' })).toBeVisible();

    await page.goto('/logistics/pending');
    const row = page.getByRole('row', { name: /E2E-CUS-0001/u });
    await expect(row).toBeVisible();
    await expect(row.getByText('客户订单')).toBeVisible();
    await row.getByRole('button', { name: '复制完整信息' }).click();
    await expect(row.getByText('已复制')).toBeVisible();

    await shipViaPrompt(page, 'E2E Carrier', 'E2E-TRACK-0001');
    await expect(page.getByRole('row', { name: /E2E-CUS-0001/u })).toHaveCount(0);

    await guard.assertClean();
});

test('KOL fulfillment manual order defaults to kol_fulfillment and shares the same logistics queue', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    await loginThroughUi(page, syntheticUsers().authorized);

    await page.goto('/kol/orders/create');
    await expect(page.locator('select').first()).toHaveValue('kol_fulfillment');
    await page.getByLabel('业务订单号').fill('E2E-KOL-0001');
    await page.getByLabel('姓名').fill('E2E KOL Recipient');
    await page.getByLabel('电话').fill('+1 555 0101');
    await page.getByLabel('国家').fill('US');
    await page.getByLabel('省/州').fill('CA');
    await page.getByLabel('城市').fill('Los Angeles');
    await page.getByLabel('地址', { exact: true }).fill('200 E2E Street');
    await page.getByPlaceholder('商品摘要').fill('E2E KOL Product');
    await page.getByPlaceholder('数量').fill('1');
    await page.getByPlaceholder('单价').fill('19.99');
    await page.getByRole('button', { name: '保存订单' }).click();

    await expect(page).toHaveURL(/\/kol\/orders\/\d+$/u);
    await expect(page.getByRole('heading', { name: 'E2E-KOL-0001' })).toBeVisible();

    await page.goto('/kol/orders');
    await expect(page.getByRole('link', { name: 'E2E-KOL-0001' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'E2E-CUS-0001' })).toHaveCount(0);

    await page.goto('/logistics/pending');
    const row = page.getByRole('row', { name: /E2E-KOL-0001/u });
    await expect(row).toBeVisible();
    await expect(row.getByText('网红履约订单')).toBeVisible();

    await shipViaPrompt(page, 'E2E Carrier', 'E2E-TRACK-0002');
    await expect(page.getByRole('row', { name: /E2E-KOL-0001/u })).toHaveCount(0);

    await guard.assertClean();
});

test('direct URL manipulation cannot cross-leak an order into the wrong kind surface', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    await loginThroughUi(page, syntheticUsers().authorized);

    await page.goto('/customers/orders/create');
    await page.getByLabel('业务订单号').fill('E2E-LEAK-CUS');
    await page.getByLabel('姓名').fill('Leak Test Customer');
    await page.getByLabel('电话').fill('+1 555 0102');
    await page.getByLabel('国家').fill('US');
    await page.getByLabel('省/州').fill('CA');
    await page.getByLabel('城市').fill('Los Angeles');
    await page.getByLabel('地址', { exact: true }).fill('300 E2E Street');
    await page.getByPlaceholder('商品摘要').fill('Leak Product');
    await page.getByPlaceholder('数量').fill('1');
    await page.getByPlaceholder('单价').fill('5.00');
    await page.getByRole('button', { name: '保存订单' }).click();
    await expect(page).toHaveURL(/\/customers\/orders\/(\d+)$/u);
    const customerOrderId = new URL(page.url()).pathname.split('/').pop();

    const crossLeakResponse = await context.request.get(`/kol/orders/${customerOrderId}`);
    expect(crossLeakResponse.status()).toBe(404);

    await guard.assertClean();
});

test('logistics copy falls back to a manual-selection path when the async Clipboard API is unavailable', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    await page.addInitScript(() => {
        Object.defineProperty(window.navigator, 'clipboard', {
            configurable: true,
            value: {
                writeText: () => Promise.reject(new Error('Clipboard API unavailable in this synthetic run.')),
            },
        });
    });
    await loginThroughUi(page, syntheticUsers().authorized);

    await page.goto('/customers/orders/create');
    await page.getByLabel('业务订单号').fill('E2E-FALLBACK-0001');
    await page.getByLabel('姓名').fill('Fallback Recipient');
    await page.getByLabel('电话').fill('+1 555 0103');
    await page.getByLabel('国家').fill('US');
    await page.getByLabel('省/州').fill('CA');
    await page.getByLabel('城市').fill('Los Angeles');
    await page.getByLabel('地址', { exact: true }).fill('400 E2E Street');
    await page.getByPlaceholder('商品摘要').fill('Fallback Product');
    await page.getByPlaceholder('数量').fill('1');
    await page.getByPlaceholder('单价').fill('7.00');
    await page.getByRole('button', { name: '保存订单' }).click();
    await expect(page).toHaveURL(/\/customers\/orders\/\d+$/u);

    await page.goto('/logistics/pending');
    const row = page.getByRole('row', { name: /E2E-FALLBACK-0001/u });
    await row.getByRole('button', { name: '复制完整信息' }).click();
    await expect(row.getByText('已复制')).toBeVisible();

    await guard.assertClean();
});
