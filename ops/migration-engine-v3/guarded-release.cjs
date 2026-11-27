'use strict';
// This wrapper is an additional parity gate, not production authorization.
// Only an already approved release script may be handed to it; it never supplies release approval.
const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const {sha}=require('./engine.cjs');
const [config,manifest,dependencyBase,receipt,...tail]=process.argv.slice(2);
if(!config||!manifest||!dependencyBase||!receipt||tail[0]!=='--'||tail.length<2){console.error('Usage: guarded-release.cjs config manifest dependencyBase receipt -- approved-release-script.js [args]');process.exit(2)}
const gate=spawnSync(process.execPath,[path.join(__dirname,'preflight.cjs'),config,manifest,dependencyBase,receipt],{stdio:'inherit'});
if(gate.error||gate.status!==0){console.error('V3 BLOCKED: release command NOT STARTED');process.exit(2)}
try{
 const m=JSON.parse(fs.readFileSync(manifest,'utf8').replace(/^\uFEFF/,'')),script=path.resolve(tail[1]),args=tail.slice(2);
 // Exact script and args must have been separately approved under existing release rules.
 if(!m.releaseScript||m.releaseScript.path!==script||m.releaseScript.sha256!==sha(fs.readFileSync(script))||JSON.stringify(m.releaseScript.args)!==JSON.stringify(args)||!m.releaseScript.authorizationRef){throw Error('release script, args or existing authorization reference mismatch')}
 if(!/\.[cm]?js$/i.test(script))throw Error('approved Node release script required');
 const child=spawnSync(process.execPath,[script,...args],{stdio:'inherit'});
 if(child.error)throw child.error;process.exitCode=child.status??2;
}catch(e){console.error('V3 BLOCKED: '+e.message);process.exitCode=2}
