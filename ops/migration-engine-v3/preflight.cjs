'use strict';
const fs=require('node:fs'),path=require('node:path'),{inventory,compare,gate,sha,write}=require('./engine.cjs');
const read=p=>JSON.parse(fs.readFileSync(p,'utf8').replace(/^\uFEFF/,''));
const [configFile,manifestFile,dependencyBase,resultFile]=process.argv.slice(2);
if(!configFile||!manifestFile||!dependencyBase||!resultFile){console.error('Usage: preflight.cjs config.json manifest.json dependencyBase result.json');process.exit(2)}
try{
 const config=read(configFile),manifest=read(manifestFile),report=compare(config,inventory(config,'source',dependencyBase),inventory(config,'target',dependencyBase));
 const result=gate(report,manifest,path.dirname(path.resolve(manifestFile)));
 write(resultFile,{...result,route:config.route,view:config.view,sourceTreeHash:report.source.treeHash,targetTreeHash:report.target.treeHash,buildHash:manifest.buildHash,checkedAt:new Date().toISOString()});
 console.log(JSON.stringify({status:result.status,issueCount:result.issueCount,issues:result.issues.slice(0,5)}));
 process.exitCode=result.status==='BLOCKED'?2:0;
}catch(e){console.error('V3 PREFLIGHT BLOCKED: '+e.message);process.exitCode=2}
