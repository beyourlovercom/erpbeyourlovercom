'use strict';
// Apply the only missing Vben v5.8 dependency delta to a verified isolated ERP Dev copy.
// The vendor ZIP is integrity-checked by bootstrap; NEVER patch the shared SiteOps base.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const INTEGRITY = Object.freeze({
  charts: 'sha512-6VtzUYBKN5mFY24/Eye3RUn1AAq7s+/B4hEu83ZOK/Pjqx26tHq2MoxiXlBE8F7oQO0VpjV5rZZaw6wxSpMNJg==',
  community: 'sha512-0JRaIwE82TuOcx+upA3OnTyhDAIUDqrMxLoBib6yVON0SyfICdpOnwD4QdiT0A1lgrC8SkzLBFRst73bTREF6Q==',
  vue3: 'sha512-dTuGy9t2ff2gs4uuuA1qvsDoxqMN4moC9G/S2xdyhg4cJ8jH+sxbPwD6+YmvVEQeHrtcyO/3kK2KcXiZB1S9fg==',
  stack: 'sha512-KbX/b6cM0GIJ4cz6W3jk8YoDG/XpqOC4WurYl8CDuQtER2d4rihjPI4tOLN4LqS0yfVspZ31ED1273kfG+6m0w==',
});

function exactlyOnce(text, from, to, label) {
  const first = text.indexOf(from);
  if (first < 0 || text.indexOf(from, first + from.length) >= 0) {
    throw new Error('Vendor delta anchor changed: ' + label);
  }
  return text.slice(0, first) + to + text.slice(first + from.length);
}
function sha(text) { return crypto.createHash('sha256').update(text).digest('hex'); }
function patchPinnedAgGrid(vbenRoot) {
  const root = path.resolve(vbenRoot);
  const canonical = root.replace(/\\/g, '/').toLowerCase();
  if (!canonical.startsWith('d:/ai/erp-siteops/')) {
    throw new Error('Only isolated D:/ai/erp-siteops/ worktrees are writable');
  }
  const packageFile = path.join(root, 'apps', 'web-antd', 'package.json');
  const lockFile = path.join(root, 'pnpm-lock.yaml');
  const originalPackage = fs.readFileSync(packageFile, 'utf8');
  const originalLock = fs.readFileSync(lockFile, 'utf8');
  const pkgEol = originalPackage.includes('\r\n') ? '\r\n' : '\n';
  const lockEol = originalLock.includes('\r\n') ? '\r\n' : '\n';
  let pkg = originalPackage.replace(/\r\n/g, '\n');
  let lock = originalLock.replace(/\r\n/g, '\n');

  const parsed = JSON.parse(pkg);
  const hasPackage = parsed.dependencies?.['ag-grid-community'] === '36.3.0'
    && parsed.dependencies?.['ag-grid-vue3'] === '36.3.0';
  const hasLock = lock.includes('  ag-grid-community@36.3.0:')
    && lock.includes('  ag-grid-vue3@36.3.0:');
  if (hasPackage && hasLock) return {state:'ALREADY_PINNED', packageSha:sha(originalPackage), lockSha:sha(originalLock)};
  if (hasPackage || hasLock || parsed.dependencies?.['ag-grid-community'] || parsed.dependencies?.['ag-grid-vue3']) {
    throw new Error('Partial or conflicting AG Grid dependency state; fail closed');
  }
  if (!lock.includes('      version: 8.3.0') || !lock.includes('version: 15.0.0(vue@3.5.41(typescript@6.0.3))')) {
    throw new Error('Expected Vben v5.8 vendor lock not found');
  }

  pkg = exactlyOnce(pkg,
    '    "@vueuse/core": "catalog:",\n    "ant-design-vue":',
    '    "@vueuse/core": "catalog:",\n    "ag-grid-community": "36.3.0",\n    "ag-grid-vue3": "36.3.0",\n    "ant-design-vue":', 'web-antd package.json');

  lock = exactlyOnce(lock,
    "      '@vueuse/core':\n        specifier: 'catalog:'\n        version: 15.0.0(vue@3.5.41(typescript@6.0.3))\n      ant-design-vue:",
    "      '@vueuse/core':\n        specifier: 'catalog:'\n        version: 15.0.0(vue@3.5.41(typescript@6.0.3))\n      ag-grid-community:\n        specifier: 36.3.0\n        version: 36.3.0\n      ag-grid-vue3:\n        specifier: 36.3.0\n        version: 36.3.0(vue@3.5.41(typescript@6.0.3))\n      ant-design-vue:", 'pnpm importer');

  const packageBlocks = [
    '  ag-charts-types@14.3.0:',
    '    resolution: {integrity: ' + INTEGRITY.charts + '}',
    '',
    '  ag-grid-community@36.3.0:',
    '    resolution: {integrity: ' + INTEGRITY.community + '}',
    '',
    '  ag-grid-vue3@36.3.0:',
    '    resolution: {integrity: ' + INTEGRITY.vue3 + '}',
    '    peerDependencies:',
    '      vue: 3.5.41',
    '',
    '  ag-stack@36.3.0:',
    '    resolution: {integrity: ' + INTEGRITY.stack + '}',
    '',
  ].join('\n') + '\n';
  lock = exactlyOnce(lock, '  agent-base@6.0.2:\n',
    packageBlocks + '  agent-base@6.0.2:\n', 'pnpm packages');

  const snapshotBlocks = [
    '  ag-charts-types@14.3.0: {}',
    '',
    '  ag-grid-community@36.3.0:',
    '    dependencies:',
    '      ag-charts-types: 14.3.0',
    '      ag-stack: 36.3.0',
    '',
    '  ag-grid-vue3@36.3.0(vue@3.5.41(typescript@6.0.3)):',
    '    dependencies:',
    '      ag-grid-community: 36.3.0',
    '      vue: 3.5.41(typescript@6.0.3)',
    '',
    '  ag-stack@36.3.0: {}',
    '',
  ].join('\n') + '\n';
  lock = exactlyOnce(lock, '  agent-base@6.0.2(supports-color@10.2.2):\n',
    snapshotBlocks + '  agent-base@6.0.2(supports-color@10.2.2):\n', 'pnpm snapshots');

  const nextPackage = pkg.replace(/\n/g, pkgEol);
  const nextLock = lock.replace(/\n/g, lockEol);
  // No dependency resolution or network; both exact files use upstream lock ordering.
  fs.writeFileSync(lockFile, nextLock, 'utf8');
  fs.writeFileSync(packageFile, nextPackage, 'utf8');
  return {state:'PINNED', packageSha:sha(nextPackage), lockSha:sha(nextLock)};
}
if (require.main === module) {
  try {
    const root = process.argv[2];
    if (!root) throw new Error('Usage: node patch-pinned-ag-grid.cjs <isolated-vben-root>');
    console.log(JSON.stringify(patchPinnedAgGrid(root)));
  } catch (e) {
    console.error('ERP_VENDOR_PATCH_FAIL: ' + e.message);
    process.exitCode = 1;
  }
}
module.exports = {patchPinnedAgGrid};
