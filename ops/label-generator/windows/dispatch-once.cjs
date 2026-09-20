'use strict'

const fs = require('fs')
const os = require('os')
const path = require('path')
const { execFileSync } = require('child_process')

function arg(name, fallback = null) {
  const index = process.argv.indexOf(name)
  return index >= 0 ? process.argv[index + 1] : fallback
}

async function post(base, token, workerId, endpoint, body = null) {
  const response = await fetch(base + endpoint, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      'X-BYL-Worker-ID': workerId,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    body: body === null ? undefined : JSON.stringify(body),
  })

  const text = await response.text()
  let json = null
  if (text !== '') {
    try { json = JSON.parse(text) } catch { /* keep raw text below */ }
  }

  if (!response.ok) {
    const detail = json?.message || text || `HTTP ${response.status}`
    throw new Error(`POST ${endpoint} failed: ${response.status} ${detail}`)
  }

  return json
}

async function main() {
  const api = String(arg('--api', '')).replace(/\/$/, '')
  const runtime = arg('--runtime', __dirname)
  const outputRoot = arg('--output-root')
  const workerId = arg('--worker', process.env.COMPUTERNAME || 'company-win-label-01')
  const token = arg('--token', process.env.BYL_BLUE_LABEL_WORKER_TOKEN || '')

  if (!api || !outputRoot || !token) {
    throw new Error('usage: dispatch-once.cjs --api URL --runtime DIR --output-root DIR [--worker ID] [--token TOKEN]')
  }
  if (!/^[A-Za-z0-9._-]{3,128}$/.test(workerId)) throw new Error('Invalid worker id')

  const claim = await post(api, token, workerId, '/claim')
  if (!claim?.job) {
    process.stdout.write('BLUE_LABEL_DISPATCH_IDLE\n')
    return
  }

  const job = claim.job
  const reference = String(job.reference)
  const generator = path.join(runtime, 'generate-blue-labels.cjs')
  const jobDir = path.join(outputRoot, reference)
  const jobJson = path.join(os.tmpdir(), `${reference}.json`)
  let started = false

  try {
    await post(api, token, workerId, `/jobs/${encodeURIComponent(reference)}/start`)
    started = true

    fs.writeFileSync(jobJson, JSON.stringify(job.payload, null, 2) + '\n', 'utf8')
    await post(api, token, workerId, `/jobs/${encodeURIComponent(reference)}/heartbeat`)

    const runtimeOutput = execFileSync(process.execPath, [
      generator,
      '--job', jobJson,
      '--output', jobDir,
    ], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })

    const manifestPath = path.join(jobDir, 'manifest.json')
    if (!fs.existsSync(manifestPath)) throw new Error(`manifest.json not found: ${manifestPath}`)
    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'))

    await post(api, token, workerId, `/jobs/${encodeURIComponent(reference)}/heartbeat`)
    const completed = await post(api, token, workerId, `/jobs/${encodeURIComponent(reference)}/complete`, {
      manifest,
      output_root: jobDir,
    })

    process.stdout.write(
      `BLUE_LABEL_DISPATCH_OK reference=${reference} status=${completed.job.status} output=${jobDir}\n${runtimeOutput.trim()}\n`,
    )
  } catch (error) {
    if (started) {
      try {
        await post(api, token, workerId, `/jobs/${encodeURIComponent(reference)}/fail`, {
          error_code: 'dispatch_failure',
          error_message: String(error?.message || error).slice(0, 4000),
        })
      } catch (reportError) {
        process.stderr.write(`BLUE_LABEL_FAIL_REPORT_ERROR ${reportError.message}\n`)
      }
    }
    throw error
  } finally {
    fs.rmSync(jobJson, { force: true })
  }
}

main().catch((error) => {
  process.stderr.write(`BLUE_LABEL_DISPATCH_ERROR ${error.stack || error.message || error}\n`)
  process.exit(1)
})
