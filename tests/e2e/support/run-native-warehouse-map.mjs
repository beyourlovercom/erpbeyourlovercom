import {createDisposableRun,cleanupDisposableRun,runManagedChild,resolveLocalPlaywrightCli} from './e2e-environment.mjs'
import {mkdir} from 'node:fs/promises'
import path from 'node:path'
let run
try {
  run=await createDisposableRun()
  const {env}=run
  env.BYL_E2E_NATIVE_MAP='1'
  const evidence=process.env.BYL_E2E_VISUAL_EVIDENCE_DIR
  if(evidence) {
    if(!path.isAbsolute(evidence)) throw new Error('Absolute evidence directory required')
    await mkdir(evidence,{recursive:true,mode:0o700})
    env.BYL_E2E_VISUAL_EVIDENCE_DIR=evidence
  }
  for(const [cmd,args] of [
    ['php',['tests/e2e/support/provision-users.php','--runtime-preflight']],
    ['php',['artisan','migrate','--force']],
    ['php',['tests/e2e/support/provision-users.php']],
    ['php',['tests/e2e/support/provision-parent-reallocation.php']],
    [process.execPath,[resolveLocalPlaywrightCli(),'test','tests/e2e/global-grid/native-warehouse-map.spec.ts','--project=chromium','--timeout=45000']],
  ]) await runManagedChild(cmd,args,{env,timeout:90000})
} finally {
  if(run){await cleanupDisposableRun(run.runRoot);console.log('NATIVE_MAP_DISPOSABLE_CLEANUP_PASS')}
}
