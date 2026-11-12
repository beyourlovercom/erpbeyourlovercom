#!/usr/bin/env node
/**
 * Mandatory postbuild gate for Owner-approved BYL ERP V4 multi-tab feature.
 * This runs automatically after `npm run build`, so builds that accidentally
 * remove all-staff Workspace Tabs cannot be used as production assets.
 */
import { readFileSync, readdirSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

export function verifyWorkspaceTabsChunk(body, chunkName = 'AuthenticatedLayout') {
  for (const signal of ['erp_v4_tabs', 'byl:workspace:canary:user:']) {
    if (!body.includes(signal)) {
      throw new Error(`ERP_WORKSPACE_TABS_BUILD_GATE_FAIL: missing ${signal} in ${chunkName}; 30-tab all-staff rollout was disabled`)
    }
  }
  return chunkName
}

export function verifyWorkspaceTabsBuild(buildDir) {
  const assetsDir = resolve(buildDir, 'assets')
  const files = readdirSync(assetsDir).filter(name => /^AuthenticatedLayout-[\w-]+\.js$/.test(name))
  if (files.length !== 1) {
    throw new Error(`ERP_WORKSPACE_TABS_BUILD_GATE_FAIL: expected exactly one AuthenticatedLayout JS chunk, found ${files.length}`)
  }
  const body = readFileSync(resolve(assetsDir, files[0]), 'utf8')
  verifyWorkspaceTabsChunk(body, files[0])
  // Pin these two frozen workspace limits as well as the rollout flag.
  const rules = readFileSync(resolve(process.cwd(), 'resources/js/lib/workspaceTabs.ts'), 'utf8')
  for (const [name, value] of [['WORKSPACE_TAB_LIMIT', 30], ['WORKSPACE_PIN_LIMIT', 3]]) {
    if (!new RegExp(`export const ${name}\\s*=\\s*${value}\\b`).test(rules)) {
      throw new Error(`ERP_WORKSPACE_TABS_BUILD_GATE_FAIL: ${name} must remain ${value}`)
    }
  }
  return files[0]
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    const bundle = verifyWorkspaceTabsBuild(resolve(process.argv[2] || 'public/build'))
    process.stdout.write(`ERP_WORKSPACE_TABS_BUILD_PASS: all-staff 30-tab bundle ${bundle}\n`)
  } catch (error) {
    process.stderr.write(`${error instanceof Error ? error.message : error}\n`)
    process.exitCode = 1
  }
}
