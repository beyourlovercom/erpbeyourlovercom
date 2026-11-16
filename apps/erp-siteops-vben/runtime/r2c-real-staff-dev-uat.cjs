'use strict';
/**
 * R2-C original-ERP real-employee Dev session PREFLIGHT. Read-only business requests.
 * This is not an ERP1-versus-ERP2 permission parity test.
 * This does NOT create accounts, impersonate users or obtain passwords.
 * Each employee must first establish a legitimate Laravel Dev session
 * and put its Cookie header in an untracked, locally protected file.
 *
 * Usage: BYL_R2C_DEV_STAFF_MANIFEST=/private/path/staff-sessions.json node r2c-real-staff-dev-uat.cjs
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const STAFF = ['Vita', 'Liang', 'Dede', 'Emma', 'Guts', 'Hiko'];
const ALLOWED_DEV = new Set(['http://127.0.0.1:18811', 'http://100.94.52.109:18811']);
const manifestPath = process.env.BYL_R2C_DEV_STAFF_MANIFEST;

function deny(code) {
  throw new Error(code);
}
function privateText(filename) {
  if (!path.isAbsolute(filename)) deny('SESSION_PATH_MUST_BE_ABSOLUTE');
  const info = fs.statSync(filename);
  if (!info.isFile()) deny('SESSION_NOT_A_FILE');
  if (process.platform !== 'win32' && (info.mode & 0o077) !== 0)
    deny('SESSION_FILE_PERMISSIONS_MUST_BE_0600');
  return fs.readFileSync(filename, 'utf8').trim();
}
function parseInertia(html) {
  const match = html.match(/<script[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/);
  if (!match) deny('INERTIA_DATA_PAGE_MISSING');
  return JSON.parse(match[1]);
}
async function readDev(base, uri, cookie) {
  assert.ok(uri.startsWith('/') && !uri.startsWith('//') && !uri.includes('://'));
  const response = await fetch(base + uri, {
    method: 'GET',
    redirect: 'manual',
    headers: { Cookie: cookie, Accept: 'text/html, application/xhtml+xml' },
    signal: AbortSignal.timeout(40000),
  });
  if (response.status === 302 || response.status === 401)
    deny('DEV_SESSION_EXPIRED_OR_UNAUTHENTICATED');
  if (response.status !== 200)
    deny('DEV_READ_STATUS_' + response.status);
  const html = await response.text();
  if (html.length > 6_000_000) deny('DEV_RESPONSE_TOO_LARGE');
  return parseInertia(html);
}
function getOrderNumber(order) {
  return [
    order.external_order_number, order.internal_order_number,
    order.erp_order_id,
  ].filter(x => typeof x === 'string' && x.length > 0);
}
async function checkSearch(base, cookie, number, shouldExist) {
  const page = await readDev(base, '/orders?search=' + encodeURIComponent(number) + '&per_page=50', cookie);
  const orders = page.props?.orders;
  if (!Array.isArray(orders?.data)) deny('ORDERS_SEARCH_CONTRACT_MISSING');
  const exists = orders.data.some(o => getOrderNumber(o).includes(number));
  assert.equal(exists, shouldExist, shouldExist ? 'OWN_SAMPLE_NOT_VISIBLE' : 'OTHER_SAMPLE_VISIBLE');
}
function checkManifest(manifest) {
  if (!ALLOWED_DEV.has(manifest.baseUrl)) deny('NON_ISOLATED_DEV_ORIGIN');
  if (!Array.isArray(manifest.staff) || manifest.staff.length !== STAFF.length)
    deny('EXACT_SIX_REAL_STAFF_REQUIRED');
  const names = manifest.staff.map(x => x.employee);
  assert.deepEqual([...names].sort(), [...STAFF].sort(), 'STAFF_IDENTITY_MATRIX_INCOMPLETE');
  const emails = new Set();
  const userIds = new Set();
  const cookieFiles = new Set();
  for (const item of manifest.staff) {
    if (typeof item.email !== 'string' || !item.email.includes('@') ||
        /(?:@local\.(?:invalid|test)|\.(?:test|invalid|example|localhost))$/i.test(item.email))
      deny('SYNTHETIC_OR_MISSING_IDENTITY');
    if (emails.has(item.email.toLowerCase())) deny('DUPLICATE_REAL_IDENTITY');
    emails.add(item.email.toLowerCase());
    if (!Number.isSafeInteger(item.erpUserId) || item.erpUserId < 1)
      deny('CANONICAL_ERP_USER_ID_REQUIRED');
    if (userIds.has(item.erpUserId)) deny('DUPLICATE_ERP_USER_ID');
    userIds.add(item.erpUserId);
    if (typeof item.cookieFile !== 'string') deny('DEV_SESSION_FILE_REQUIRED');
    if (cookieFiles.has(item.cookieFile)) deny('SHARED_DEV_SESSION_FILE');
    cookieFiles.add(item.cookieFile);
    if (!Array.isArray(item.requiredMenuKeys) || item.requiredMenuKeys.length === 0)
      deny('EXPECTED_ALLOWED_MENU_KEYS_REQUIRED');
    if (!Array.isArray(item.forbiddenMenuKeys))
      deny('EXPECTED_FORBIDDEN_MENU_KEYS_REQUIRED');
    if (typeof item.visibleOrderNumber !== 'string' || !item.visibleOrderNumber)
      deny('POSITIVE_REAL_ORDER_FIXTURE_REQUIRED');
    if (['Emma', 'Guts', 'Hiko'].includes(item.employee) &&
      (typeof item.forbiddenOrderNumber !== 'string' || !item.forbiddenOrderNumber))
      deny('KOL_NEGATIVE_REAL_ORDER_FIXTURE_REQUIRED');
  }
}
async function main() {
  if (!manifestPath) deny('REAL_STAFF_DEV_SESSIONS_NOT_PROVISIONED');
  const manifest = JSON.parse(privateText(manifestPath));
  checkManifest(manifest);
  // Process sequentially: current Laravel Dev uses a bounded single-thread test server.
  for (const item of manifest.staff) {
    const cookie = privateText(item.cookieFile);
    if (!cookie.includes('laravel_session=')) deny('LARAVEL_DEV_SESSION_COOKIE_REQUIRED');
    const page = await readDev(manifest.baseUrl, '/orders?per_page=20', cookie);
    const actualUserId = Number(page.props?.auth?.user?.id);
    assert.equal(actualUserId, item.erpUserId, 'CANONICAL_ERP_USER_ID_MISMATCH');
    const actualEmail = page.props?.auth?.user?.email;
    assert.equal(String(actualEmail ?? '').toLowerCase(), item.email.toLowerCase(), 'EMPLOYEE_IDENTITY_MISMATCH');
    const nav = page.props?.shellNavigation;
    if (!Array.isArray(nav)) deny('AUTH_NAVIGATION_MISSING');
    const keys = new Set(nav.flatMap(g => [g.key, ...(g.children ?? []).map(c => c.key)]));
    for (const key of item.requiredMenuKeys) assert.ok(keys.has(key), 'REQUIRED_MENU_MISSING:' + key);
    for (const key of item.forbiddenMenuKeys) assert.ok(!keys.has(key), 'FORBIDDEN_MENU_PRESENT:' + key);
    await checkSearch(manifest.baseUrl, cookie, item.visibleOrderNumber, true);
    if (item.forbiddenOrderNumber)
      await checkSearch(manifest.baseUrl, cookie, item.forbiddenOrderNumber, false);
    console.log('LEGACY_STAFF_DEV_PREFLIGHT_PASS', item.employee, 'identityBound=true',
      'menus=' + nav.length, 'visibleOrder=yes',
      'negativeScope=' + (item.forbiddenOrderNumber ? 'yes' : 'n/a'));
  }
  console.log('R2C_LEGACY_STAFF_PREFLIGHT_PASS verifiedEmployees=6 readOnlyBusinessRequests=true erp2Parity=NOT_TESTED');
}
main().catch(error => {
  console.error('R2C_LEGACY_STAFF_PREFLIGHT_HOLD', error.message);
  process.exitCode = 2;
});
