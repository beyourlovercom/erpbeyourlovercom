import { expect, test, type APIResponse } from '@playwright/test';
import { authenticateUnauthorizedThroughFortify, loginThroughUi, syntheticUsers } from '../support/auth-helpers';
import { installBrowserGuards } from '../support/browser-guards';

const SENSITIVE_ERROR_MARKERS = [
    /SQLSTATE/iu,
    /(?:Exception|Error)\s+in\s+\/[^\s]+/iu,
    /\/Users\//u,
    /\/var\/www\//u,
    /APP_KEY/iu,
    /DB_PASSWORD/iu,
    /stack trace/iu,
];

async function expectSafeErrorResponse(response: APIResponse, status: 403 | 404): Promise<void> {
    expect(response.status()).toBe(status);
    const text = await response.text();

    for (const marker of SENSITIVE_ERROR_MARKERS) {
        expect(text).not.toMatch(marker);
    }
}

test('super admin can access the permission-protected dashboard', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);

    await loginThroughUi(page, syntheticUsers().authorized);

    await expect(page).toHaveURL(/\/dashboard$/u);
    await expect(page.getByRole('navigation', { name: 'ERP主导航' })).toBeVisible();
    await guard.assertClean();
});

test('active unauthorized user authenticates but receives real 403 responses', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);
    const loginStatus = await authenticateUnauthorizedThroughFortify(context, syntheticUsers().unauthorized);

    expect(loginStatus).toBe(302);
    await expectSafeErrorResponse(await context.request.get('/dashboard'), 403);
    await expectSafeErrorResponse(await context.request.get('/settings'), 403);
    await expect(page.getByRole('navigation', { name: 'ERP主导航' })).toHaveCount(0);
    await guard.assertClean();
});

test('an unknown route returns a safe real 404 response', async ({ context, page }) => {
    const guard = await installBrowserGuards(context, page);

    await loginThroughUi(page, syntheticUsers().authorized);
    await expectSafeErrorResponse(await context.request.get('/__e2e-not-found__'), 404);
    await guard.assertClean();
});
