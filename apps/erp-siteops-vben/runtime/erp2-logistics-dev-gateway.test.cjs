'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const gateway = path.join(__dirname, 'erp2-logistics-dev-gateway.cjs');
function refused(upstream, dist = '/tmp/erp2-logistics-dev-test') {
  const env = { ...process.env, ERP2_LOGISTICS_VBEN_DIST: dist };
  delete env.ERP2_LOGISTICS_DEV_UPSTREAM;
  if (upstream !== undefined) env.ERP2_LOGISTICS_DEV_UPSTREAM = upstream;
  return spawnSync(process.execPath, [gateway], {env, encoding: 'utf8', timeout: 3000});
}
test('gateway refuses missing explicit Dev upstream', () => {
  const r = refused(undefined);
  assert.equal(r.status, 1);
  assert.match(r.stderr, /ERP2_DEV_UPSTREAM_REQUIRED/);
});
test('gateway refuses Production and arbitrary Internet upstreams', () => {
  for (const host of ['https://erp.beyourlover.com', 'http://example.com', 'http://192.168.1.7']) {
    const r = refused(host);
    assert.equal(r.status, 1);
    assert.match(r.stderr, /ERP2_DEV_UPSTREAM_MUST_BE_LOCAL_OR_TAILNET/);
  }
});
test('gateway rejects non-absolute build paths', () => {
  const r = refused('http://127.0.0.1:18811', './dist');
  assert.equal(r.status, 1);
  assert.match(r.stderr, /ERP2_DEV_PORT_OR_ABSOLUTE_DIST_REQUIRED/);
});
