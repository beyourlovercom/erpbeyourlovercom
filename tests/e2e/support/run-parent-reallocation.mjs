import {createDisposableRun,cleanupDisposableRun,runManagedChild,resolveLocalPlaywrightCli} from './e2e-environment.mjs'
import {mkdir} from 'node:fs/promises'
import path from 'node:path'
let run
try {
  run=await createDisposableRun()
  const {env,runRoot}=run
  env.BYL_E2E_PARENT_REALLOCATION='1'
  const evidence=process.env.BYL_E2E_VISUAL_EVIDENCE_DIR
  if (!evidence || !path.isAbsolute(evidence)) throw new Error('Absolute evidence directory required')
  await mkdir(evidence,{recursive:true,mode:0o700})
  console.log('R5_LOOPBACK='+env.BYL_E2E_BASE_URL)
  for (const [cmd,args] of [
    ['php',['tests/e2e/support/provision-users.php','--runtime-preflight']],
    ['php',['artisan','migrate','--force']],
    ['php',['tests/e2e/support/provision-users.php']],
    ['php',['tests/e2e/support/provision-parent-reallocation.php']],
    [process.execPath,[resolveLocalPlaywrightCli(),'test','tests/e2e/global-grid/parent-reallocation.spec.ts','--project=chromium','--timeout=45000']],
    ['php',['tests/e2e/support/provision-parent-reallocation.php','--verify']],
  ]) await runManagedChild(cmd,args,{env,timeout:90000})
} finally {
  if(run){await cleanupDisposableRun(run.runRoot);console.log('R5_DISPOSABLE_CLEANUP_PASS')}
}
