'use strict';
// Versioned overlay installer. Run from the ERP Git repository; never fetch secrets.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const args = process.argv.slice(2);
const apply = args.includes('--apply-overlay');
const packaged = path.resolve(__dirname, '..');
const base = process.env.ERP_SITEOPS_VBEN_BASE || 'D:/ai/siteops-vben/vue-vben-admin-main';
const destination = process.env.ERP_SITEOPS_WORKDIR || 'D:/ai/erp-siteops/frontend';
const archive = 'D:/ai/siteops-vben/vben-upstream-main.zip';
const known = 'DF1E977A85EB0D55E9A75D19E270180BFF68E8E7FCD15759D69919223E44141B';
if (!fs.existsSync(packaged)) throw new Error('Versioned overlay directory missing');
if (!fs.existsSync(base)) throw new Error('Approved SiteOps V9.1 local base missing');
if (fs.existsSync(archive)) {
  const sha = crypto.createHash('sha256').update(fs.readFileSync(archive)).digest('hex').toUpperCase();
  if (sha !== known) throw new Error('Pinned Vben archive checksum mismatch');
}
const overrides = path.join(packaged, 'overrides');
const files = [];
function collect(dir, rel='') {
  for (const entry of fs.readdirSync(dir, { withFileTypes:true })) {
    const next = path.join(rel, entry.name);
    if (entry.isDirectory()) collect(path.join(dir, entry.name), next);
    else if (entry.isFile()) files.push(next);
  }
}
collect(overrides);
console.log('ERP SiteOps overlay files: ' + files.length);
if (!apply) {
  console.log('DRY_RUN: use --apply-overlay after reviewing the source and target');
  process.exit(0);
}
if (!fs.existsSync(destination)) throw new Error('Destination not provisioned; do not auto-create or overwrite a clean Vben base');
const approved = path.resolve(destination).toLowerCase() === path.resolve('D:/ai/erp-siteops/frontend').toLowerCase();
if (!approved) throw new Error('Only the isolated D:/ai/erp-siteops/frontend target is allowed');
for (const rel of files) {
  const source = path.join(overrides, rel);
  const target = path.join(destination, 'apps/web-antd', rel);
  fs.mkdirSync(path.dirname(target), {recursive:true});
  fs.copyFileSync(source,target);
}
for (const name of ['build.cmd', 'install-offline.cmd', 'test-orders.cmd', 'gateway.cjs', 'r2-smoke.cjs', 'r2-browser.cjs']) {
  const source = path.join(packaged, 'runtime', name);
  const target = path.join(path.dirname(destination), name);
  fs.copyFileSync(source, target);
}
console.log('Overlay applied to isolated local frontend; no credentials or production API accessed');