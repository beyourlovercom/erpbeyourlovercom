'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const {inventory,compare,gate,sha,buildDigest}=require('./engine.cjs');
const dep=process.env.ERP2_VUE_DEPENDENCY_BASE||process.cwd();
function setup(t){const dir=fs.mkdtempSync(path.join(os.tmpdir(),'erp2-v3-test-'));t.after(()=>fs.rmSync(dir,{recursive:true,force:true}));for(const s of ['source','target'])fs.mkdirSync(path.join(dir,s));
 const put=(p,v)=>{fs.writeFileSync(path.join(dir,p),v);return path.join(dir,p)};
 put('source/Page.vue','<script setup lang="ts">import Child from "./Child.vue"</script><template><Child v-if="ready" /></template>');
 put('source/Child.vue','<template><button v-if="allowed" @click="save">Save</button><button @click="archive">Archive</button></template>');
 put('target/Page.vue','<template><button v-if="allowed" @click="save">Save</button></template>');
 fs.mkdirSync(path.join(dir,'dist'));put('dist/index.html','candidate-build');
 const config={route:'/fixture',view:'pending',source:{root:path.join(dir,'source'),entries:['Page.vue']},target:{root:path.join(dir,'target'),entries:['Page.vue']}};
 const report=compare(config,inventory(config,'source',dep),inventory(config,'target',dep));
 const manifest={version:3,route:config.route,view:config.view,sourceTreeHash:report.source.treeHash,targetTreeHash:report.target.treeHash,buildFile:'dist/index.html',buildHash:sha('candidate-build'),buildDirectory:'dist',buildTreeHash:buildDigest(path.join(dir,'dist')),items:[],gates:{},browser:{mode:'MOCK'},ownerUat:null};
 let counter=0;
 const evidence=(kind,extra={})=>{const name='evidence-'+(++counter)+'.json';const body=JSON.stringify({version:3,kind,route:manifest.route,view:manifest.view,sourceTreeHash:manifest.sourceTreeHash,targetTreeHash:manifest.targetTreeHash,buildHash:manifest.buildHash,buildTreeHash:manifest.buildTreeHash,result:'PASS',provenance:'SYNTHETIC UNIT TEST ONLY',...extra});put(name,body);return {kind,path:name,sha256:sha(body)}};
 const good=()=>{const m=structuredClone(manifest);m.items=report.source.items.map(x=>{const targetIds=[report.target.items[0].id];return {sourceId:x.id,status:'VERIFIED',targetIds,evidence:evidence('semantic-action-review',{sourceId:x.id,targetIds})}});for(let i=1;i<=8;i++)m.gates['S0'+i]={status:'PASS',evidence:evidence('v2-gate-S0'+i)};const verified={mode:'REAL_DUAL_SITE',actorVerified:true,sameRole:true,comparableRecord:true,verifiedWorkflow:true};m.browser={...verified,sourceTreeHash:report.source.treeHash,targetTreeHash:report.target.treeHash,buildHash:m.buildHash,evidence:evidence('real-dual-site-browser',verified)};m.ownerUat=evidence('owner-final-uat');return m};
 return {dir,put,config,report,manifest,good};
}
test('recurses into imported Vue and preserves event and guard',t=>{const x=setup(t);const a=x.report.source.items.find(i=>i.label==='Save');assert.equal(x.report.source.files.length,2);assert.equal(a.events[0].expression,'save');assert.ok(a.guards.includes('if:allowed'))});
test('missing Archive reported even when Save matches',t=>{const x=setup(t);assert.equal(x.report.suggestions.find(i=>i.source.label==='Archive').classification,'NO_EXACT_MATCH');assert.equal(x.report.suggestions.find(i=>i.source.label==='Save').classification,'REVIEW_CANDIDATE')});
test('empty manifest and mocked browser block',t=>{const x=setup(t);const g=gate(x.report,x.manifest,x.dir);assert.equal(g.status,'BLOCKED');assert.ok(g.issues.includes('real-browser-evidence-open'));assert.ok(g.issues.some(i=>i.startsWith('unmapped:')))});
test('source and candidate build drift block even with synthetic full manifest',t=>{const x=setup(t),m=x.good();x.put('source/Child.vue','<template><button>Changed</button></template>');x.put('dist/index.html','changed');const g=gate(x.report,m,x.dir);assert.equal(g.status,'BLOCKED');assert.ok(g.issues.some(i=>i.startsWith('live-file-drift:source:')));assert.ok(g.issues.includes('build-hash-mismatch'))});
test('unapproved exclusions and wrong target ids block',t=>{const x=setup(t),m=x.good();m.items[0]={sourceId:m.items[0].sourceId,status:'NOT_APPLICABLE',reason:'not proven'};m.items[1].targetIds=['fake'];const g=gate(x.report,m,x.dir);assert.ok(g.issues.some(i=>i.startsWith('unapproved-exclusion:')));assert.ok(g.issues.some(i=>i.startsWith('unverified-mapping:')))});
test('evidence alteration, stale identity and missing owner approval block',t=>{const x=setup(t),m=x.good();m.browser.sameRole=false;m.ownerUat=null;x.put(m.gates.S01.evidence.path,'changed');const g=gate(x.report,m,x.dir);assert.ok(g.issues.includes('real-browser-evidence-open'));assert.ok(g.issues.includes('owner-uat-open'));assert.ok(g.issues.includes('gate-open:S01'))});
test('positive control is synthetic schema validation only, not business parity',t=>{const x=setup(t);assert.equal(gate(x.report,x.good(),x.dir).status,'READY_FOR_SEPARATE_RELEASE_AUTHORIZATION')});

test('an unrelated evidence file and changed hashed build asset block',t=>{const x=setup(t),m=x.good();m.items[1].evidence=m.items[0].evidence;x.put('dist/extra.js','new asset');const g=gate(x.report,m,x.dir);assert.ok(g.issues.some(i=>i.startsWith('unverified-mapping:')));assert.ok(g.issues.includes('build-tree-drift'))});
