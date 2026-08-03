import { expect, test } from '@playwright/test';
import { expectGenericLoginFailure, loginThroughUi, syntheticUsers } from '../support/auth-helpers';
import { installBrowserGuards } from '../support/browser-guards';

test('guest dashboard access redirects to the real login page', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);

    await page.goto('/dashboard');

    await expect(page).toHaveURL(/\/login$/u);
    await expect(page.getByRole('button', { name: '登录系统' })).toBeVisible();
    await guard.assertClean();
});

test('active super admin signs in through Fortify and reaches dashboard', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);

    await loginThroughUi(page, syntheticUsers().authorized);

    await expect(page.getByRole('heading', { name: 'BYL ERP 应用工作台' })).toBeVisible();
    await guard.assertClean();
});

test('wrong password returns the generic login error', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    const user = syntheticUsers().authorized;

    await expectGenericLoginFailure(page, { ...user, password: `${user.password}-wrong` });

    await expect(page.getByText(/inactive|停用|不存在|unknown/iu)).toHaveCount(0);
    await guard.assertClean();
});

test('unknown account returns the same generic login error', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);

    await expectGenericLoginFailure(page, syntheticUsers().missing);

    await expect(page.getByText(/inactive|停用|不存在|unknown/iu)).toHaveCount(0);
    await guard.assertClean();
});

test('authenticated user is redirected away from login', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);

    await loginThroughUi(page, syntheticUsers().authorized);
    await page.goto('/login');

    await expect(page).toHaveURL(/\/dashboard$/u);
    await guard.assertClean();
});

test('login exposes labels, keyboard order, visible focus and alert semantics', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    const user = syntheticUsers().authorized;

    await page.goto('/login');
    const email = page.getByLabel('邮箱');
    const password = page.locator('input[name="password"]');
    const passwordToggle = page.getByRole('button', { name: '显示密码' });
    const submit = page.getByRole('button', { name: '登录系统' });

    await page.keyboard.press('Tab');
    await expect(email).toBeFocused();
    await expect(email.locator('..')).toHaveCSS('outline-style', 'solid');
    await page.keyboard.press('Tab');
    await expect(password).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(passwordToggle).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(submit).toBeFocused();

    await email.fill(user.email);
    await password.fill(`${user.password}-wrong`);
    await submit.click();
    await expect(page.getByRole('alert')).toContainText('邮箱或密码不正确，请重试。');
    await guard.assertClean();
});
