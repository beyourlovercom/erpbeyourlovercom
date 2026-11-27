'use strict';
const fs=require('node:fs'),path=require('node:path'),{sha,write,buildDigest}=require('./engine.cjs');
const [reportFile,buildDirectory,manifestFile]=process.argv.slice(2);
if(!reportFile||!buildDirectory||!manifestFile){console.error('Usage: init-manifest.cjs report.json build-directory new-manifest.json');process.exit(1)}
if(fs.existsSync(manifestFile)){console.error('Refusing to overwrite an existing evidence manifest');process.exit(1)}
const report=JSON.parse(fs.readFileSync(reportFile,'utf8').replace(/^\uFEFF/,''));
if(report.version!==3||!report.source?.items?.length||!report.target?.items?.length)throw Error('Invalid scan report');
const root=path.resolve(buildDirectory),entry=path.join(root,'index.html');
const suggestion=new Map(report.suggestions.map(x=>[x.sourceId,x]));
const manifest={version:3,route:report.route,view:report.view,sourceTreeHash:report.source.treeHash,targetTreeHash:report.target.treeHash,buildFile:entry,buildHash:sha(fs.readFileSync(entry)),buildDirectory:root,buildTreeHash:buildDigest(root),items:report.source.items.map(s=>({sourceId:s.id,sourceFile:s.file,sourceLine:s.line,sourceKind:s.kind,sourceLabel:s.label||s.expression||s.tag||'',status:'OPEN',targetIds:[],suggestedTargetIds:(suggestion.get(s.id)?.candidates||[]).map(t=>t.id),evidence:null})),gates:Object.fromEntries(Array.from({length:8},(_,i)=>['S0'+(i+1),{status:'OPEN'}])),browser:{mode:'NOT_VERIFIED'},ownerUat:null};
write(manifestFile,manifest);console.log(JSON.stringify({status:'OPEN',items:manifest.items.length,manifest:manifestFile}));
