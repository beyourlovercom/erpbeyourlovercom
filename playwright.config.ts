import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';
import {
    assertExternalRunRoot,
    assertLoopbackBaseUrl,
    assertRuntimeEnvironment,
} from './tests/e2e/support/e2e-environment.mjs';

const runRoot = assertExternalRunRoot(process.env.BYL_E2E_RUN_ROOT, { requireEmpty: false });
const baseURL = assertLoopbackBaseUrl(process.env.BYL_E2E_BASE_URL);

assertRuntimeEnvironment(process.env, runRoot, { allowPopulatedDatabase: true });

const port = new URL(baseURL).port;

export default defineConfig({
    testDir: './tests/e2e',
    testMatch: [
        'foundation/**/*.spec.ts',
        'auth/**/*.spec.ts',
        'authorization/**/*.spec.ts',
        'shell/**/*.spec.ts',
    ],
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: true,
    outputDir: path.join(runRoot, 'artifacts'),
    reporter: [
        ['line'],
        ['html', { open: 'never', outputFolder: path.join(runRoot, 'report') }],
    ],
    use: {
        baseURL,
        screenshot: 'off',
        trace: 'off',
        video: 'off',
        storageState: undefined,
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                browserName: 'chromium',
            },
        },
    ],
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${port}`,
        url: `${baseURL}/login`,
        reuseExistingServer: false,
        timeout: 60_000,
        stdout: 'pipe',
        stderr: 'pipe',
    },
});
