import { test } from 'node:test'
import assert from 'node:assert/strict'
import { verifyWorkspaceTabsChunk, verifyWorkspaceTabsBuild } from './verify-workspace-tabs-build.mjs'

test('Owner approved all-staff workspace tabs retain their toggle and per-user preference', () => {
  const bundle = 'byl:workspace:canary:user: ... erp_v4_tabs'
  assert.equal(verifyWorkspaceTabsChunk(bundle), 'AuthenticatedLayout')
})
test('rollout compiled away by missing build-time flags is a hard failure', () => {
  for (const badBundle of ['', 'byl:workspace:canary:user:', 'erp_v4_tabs']) {
    assert.throws(() => verifyWorkspaceTabsChunk(badBundle), /ERP_WORKSPACE_TABS_BUILD_GATE_FAIL/)
  }
})
test('actual production bundle contains the all-staff tab toggle and 30/3 frozen limits', () => {
  assert.match(verifyWorkspaceTabsBuild('public/build'), /^AuthenticatedLayout-/)
})
