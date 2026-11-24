import {createDisposableRun,cleanupDisposableRun,runManagedChild,resolveLocalPlaywrightCli,assertRuntimeEnvironment} from './e2e-environment.mjs'
const run=await createDisposableRun()
try {
  const {env,runRoot}=run
  await runManagedChild('npm',['run','build'],{env})
  await runManagedChild('php',['tests/e2e/support/provision-users.php','--runtime-preflight'],{env})
  await runManagedChild('php',['artisan','migrate','--force'],{env})
  assertRuntimeEnvironment(env,runRoot,{allowPopulatedDatabase:true})
  await runManagedChild('php',['tests/e2e/support/provision-users.php'],{env})
  await runManagedChild(process.execPath,[resolveLocalPlaywrightCli(),'test','tests/e2e/orders/country-standard-browser.spec.ts','--project=chromium','--timeout=90000'],{env,timeout:180000})
} finally { await cleanupDisposableRun(run.runRoot); console.log('COUNTRY_E2E_CLEANUP_PASS') }
