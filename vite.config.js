import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { verifyWorkspaceTabsChunk } from './ops/production/verify-workspace-tabs-build.mjs';

// Owner-frozen ERP V4 all-staff rollout. These values MUST be deterministic
// in every production bundle, even when the inherited .env lacks Vite flags.
// End-user emergency opt-out (?erp_v4_tabs=0) remains available in the UI.
const approvedWorkspaceTabDefinitions = Object.freeze({
    'import.meta.env.VITE_ERP_WORKSPACE_TABS': JSON.stringify('1'),
    'import.meta.env.VITE_ERP_WORKSPACE_CANARY_IDS': JSON.stringify('*'),
    'import.meta.env.VITE_ERP_WORKSPACE_DEFAULT_ON': JSON.stringify('1'),
});

export default defineConfig(({ command }) => ({
    ...(command === 'build' ? { define: approvedWorkspaceTabDefinitions } : {}),
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/foundation.css',
                'resources/css/shell.css',
                'resources/js/app.ts',
            ],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
        {
            name: 'byl-erp-workspace-tabs-production-gate',
            apply: 'build',
            generateBundle(_options, bundle) {
                const chunks = Object.values(bundle).filter(item =>
                    item.type === 'chunk' && /^assets\/AuthenticatedLayout-[\w-]+\.js$/.test(item.fileName));
                if (chunks.length !== 1) throw new Error(
                    `ERP_WORKSPACE_TABS_BUILD_GATE_FAIL: expected one tab layout chunk, found ${chunks.length}`);
                verifyWorkspaceTabsChunk(chunks[0].code, chunks[0].fileName);
            },
        },
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
}));
