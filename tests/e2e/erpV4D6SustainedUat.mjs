// Run ONLY in an isolated localhost Dev/UAT with synthetic users.
// No outbound shipment APIs, Production database, or real employee accounts.
import fs from 'node:fs'
import { chromium } from 'playwright'

const base = 'http://127.0.0.1:56989'
const users = JSON.parse(fs.readFileSync('.uat-credentials.json', 'utf8'))
if (users.length !== 5 || users.some(u => !u.email.endsWith('@example.test'))) throw Error('Synthetic users only')
const durationMs = 150_000
const started = Date.now()
const browser = await chromium.launch({ headless: true, args: ['--no-first-run', '--enable-precise-memory-info'] })
const sessions = [], errors = [], latencies = [], samples = []
const methods = {}
async function login(user) {
  const context = await browser.newContext({ viewport: { width: 1366, height: 850 } })
  const page = await context.newPage()
  page.on('pageerror', e => errors.push(['js', user.id, e.message.slice(0, 150)]))
  page.on('response', r => { if (r.status() >= 500) errors.push(['http', user.id, r.status()]) })
  page.on('request', r => { methods[r.method()] = (methods[r.method()] || 0) + 1 })
  await page.goto(base + '/login')
  await page.locator('input[name=email]').fill(user.email)
  await page.locator('input[name=password]').fill(user.password)
  await page.locator('input[name=password]').press('Enter')
  await page.waitForURL(u => !u.pathname.startsWith('/login'), { timeout: 15000 })
  await page.goto(base + '/dashboard?erp_v4_tabs=1')
  await page.locator('nav.erp-workspace-tabs').waitFor({ timeout: 12000 })
  const cdp = await context.newCDPSession(page)
  await cdp.send('Performance.enable')
  return { user, context, page, cdp }
}
async function navigate(session, n) {
  const path = '/__uat/r3-grid/' + n
  const before = Date.now()
  await session.page.evaluate(path => window.__d5Router.visit(path), path)
  await session.page.waitForURL(base + path, { timeout: 18000 })
  await session.page.waitForFunction(() => document.querySelectorAll('.ag-row').length > 0, { timeout: 15000 })
  const elapsed = Date.now() - before
  latencies.push(elapsed)
  return elapsed
}
async function sample(label) {
  let totalHeap = 0, totalNodes = 0
  const users = []
  for (const session of sessions) {
    await session.cdp.send('HeapProfiler.enable')
    await session.cdp.send('HeapProfiler.collectGarbage')
    const m = Object.fromEntries((await session.cdp.send('Performance.getMetrics')).metrics.map(x => [x.name, x.value]))
    const tabs = await session.page.locator('.erp-workspace-tab').count()
    const heap = +(m.JSHeapUsedSize / 1048576).toFixed(2)
    totalHeap += heap; totalNodes += m.Nodes
    users.push({ id: session.user.id, heapMiB: heap, nodes: m.Nodes, tabs })
  }
  const value = { label, elapsedSeconds: (Date.now() - started) / 1000, heapMiB: +totalHeap.toFixed(2), nodes: totalNodes, users, errors: errors.length }
  samples.push(value)
  console.log('D6_SAMPLE', JSON.stringify(value))
}
try {
  for (const user of users) sessions.push(await login(user))
  console.log('D6_SYNTHETIC_USERS_READY', sessions.length)
  await sample('start')
  let round = 0, next = Date.now() + 30_000
  while (Date.now() - started < durationMs) {
    const results = await Promise.allSettled(sessions.map((s, i) => navigate(s, ((round + i) % 31) + 1)))
    const good = results.filter(r => r.status === 'fulfilled').map(r => r.value)
    for (const r of results) if (r.status === 'rejected') errors.push(['navigation', String(r.reason).slice(0, 180)])
    round++
    if (round === 1 || round % 10 === 0)
      console.log('D6_ROUND', JSON.stringify({ round, passed: good.length, longestMs: Math.max(0, ...good), errors: errors.length }))
    if (Date.now() >= next) { await sample('round-' + round); next += 30_000 }
    await new Promise(resolve => setTimeout(resolve, 2500))
  }
  await sample('final')
  const sorted = latencies.slice().sort((a, b) => a - b)
  const result = { elapsedSeconds: (Date.now() - started) / 1000, rounds: round,
    requests: latencies.length, medianMs: sorted[Math.floor(.5 * (sorted.length - 1))],
    p95Ms: sorted[Math.floor(.95 * (sorted.length - 1))], maxMs: sorted.at(-1),
    errors, postRequests: methods.POST || 0, sampleCount: samples.length,
    firstHeapMiB: samples[0]?.heapMiB, lastHeapMiB: samples.at(-1)?.heapMiB }
  console.log('D6_FINAL', JSON.stringify(result))
  if (errors.length || latencies.length < 60 || samples.length < 3) process.exitCode = 1
  else console.log('D6_ISOLATED_FIVE_SESSION_STRESS_PASS')
} catch (error) {
  console.log('D6_FAILURE', String(error).slice(0, 1000))
  process.exitCode = 1
} finally { await browser.close() }
