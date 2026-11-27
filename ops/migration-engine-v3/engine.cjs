#!/usr/bin/env node
'use strict';
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const sha=x=>crypto.createHash('sha256').update(x).digest('hex');
const posix=x=>x.split(path.sep).join('/');
const norm=s=>String(s||'').normalize('NFKC').replace(/[\s⏫↻＋+…：:]/g,'').toLowerCase();
const inside=(r,p)=>p===r||p.startsWith(r+path.sep);
const read=p=>JSON.parse(fs.readFileSync(p,'utf8').replace(/^\uFEFF/,''));
const write=(p,x)=>{fs.mkdirSync(path.dirname(path.resolve(p)),{recursive:true});fs.writeFileSync(p,JSON.stringify(x,null,2)+'\n')};
function dependency(name,base){try{return require(require.resolve(name,{paths:[base]}))}catch(e){if(name==='vue/compiler-sfc'){return require(require.resolve('@vue/compiler-sfc',{paths:[base]}))}throw e}}
function inventory(config,side,dep){
 const cfg=config[side],root=path.resolve(cfg.root),sfc=dependency('vue/compiler-sfc',dep),ts=dependency('typescript',dep),seen=new Set(),files=[],items=[],unresolved=[],external=new Set();
 const rel=p=>posix(path.relative(root,p));
 function add(file,kind,line,data){const record={file:rel(file),kind,line,...data};record.id=sha(JSON.stringify(record)).slice(0,24);items.push(record)}
 function resolve(file,s){
   let p;if(s.startsWith('.'))p=path.resolve(path.dirname(file),s);else{const key=Object.keys(cfg.aliases||{}).sort((a,b)=>b.length-a.length).find(k=>s.startsWith(k));if(!key){external.add(s);return}p=path.resolve(root,cfg.aliases[key],s.slice(key.length))}
   if(!inside(root,p)){unresolved.push({file:rel(file),import:s,reason:'outside-root'});return}
   const hit=[p,p+'.vue',p+'.ts',p+'.tsx',p+'.js',p+'.mjs',path.join(p,'index.ts'),path.join(p,'index.js'),path.join(p,'index.vue')].find(x=>fs.existsSync(x)&&fs.statSync(x).isFile());
   if(!hit){unresolved.push({file:rel(file),import:s,reason:'unresolved-local-import'});return}
   if(!/\.(vue|[cm]?[jt]sx?)$/.test(hit)){return}
   visit(hit);
 }
 function script(file,source,offset=0){const ast=ts.createSourceFile(file+'.ts',source,ts.ScriptTarget.Latest,true,ts.ScriptKind.TS);
   for(const d of ast.parseDiagnostics||[])unresolved.push({file:rel(file),reason:'script-parse',message:ts.flattenDiagnosticMessageText(d.messageText,' ')});
   const line=n=>ast.getLineAndCharacterOfPosition(n.getStart(ast)).line+1+offset;
   function walk(n){
    if(ts.isImportDeclaration(n)&&n.moduleSpecifier&&ts.isStringLiteral(n.moduleSpecifier)&&!n.importClause?.isTypeOnly)resolve(file,n.moduleSpecifier.text);
    if(ts.isExportDeclaration(n)&&n.moduleSpecifier&&ts.isStringLiteral(n.moduleSpecifier)&&!n.isTypeOnly)resolve(file,n.moduleSpecifier.text);
    if(ts.isCallExpression(n)&&n.expression.kind===ts.SyntaxKind.ImportKeyword){const a=n.arguments[0];if(a&&ts.isStringLiteral(a))resolve(file,a.text);else unresolved.push({file:rel(file),line:line(n),reason:'dynamic-import'});}
    if(ts.isCallExpression(n)){const e=n.expression.getText(ast);if(/(?:axios|router|fetcher|fetch|request|apiClient|http)\b/i.test(e)&&!e.startsWith('router.resolve')){const a=n.arguments[0];if(a){const v=a.getText(ast);if(/\/(?:logistics|orders|customers|api|ui|inventory|procurement)\b/.test(v))add(file,'route',line(n),{handler:e,expression:v});}}}
    if(ts.isPropertyAssignment(n)&&['colId','headerName','cellRenderer','cellRendererParams'].includes(n.name.getText(ast).replace(/['"]/g,''))&&ts.isObjectLiteralExpression(n.parent)){
      if(n.name.getText(ast).replace(/['"]/g,'')==='colId'){const props={};for(const p of n.parent.properties)if(ts.isPropertyAssignment(p)){const k=p.name.getText(ast).replace(/['"]/g,'');if(['colId','field','headerName','cellRenderer','cellRendererParams','hide','pinned'].includes(k))props[k]=p.initializer.getText(ast)}add(file,'column',line(n),{label:props.headerName||props.colId,...props})}
    }
    ts.forEachChild(n,walk);
   }walk(ast)
 }
 function template(file,ast){function node(n,parents=[]){if(!n||typeof n!=='object')return;if(n.type===1){
   const tag=n.tag,props=n.props||[],directives=props.filter(p=>p.type===7),attrs=Object.fromEntries(props.filter(p=>p.type===6).map(p=>[p.name,p.value?.content||'']));
   const bound=Object.fromEntries(directives.filter(p=>p.name==='bind').map(p=>[p.arg?.content||'*',p.exp?.content||'']));
   const events=directives.filter(p=>p.name==='on').map(p=>({event:p.arg?.content||'*',expression:p.exp?.content||'',modifiers:(p.modifiers||[]).map(m=>typeof m==='string'?m:m.content)}));
   const guards=directives.filter(p=>['if','else-if','else','for','show','slot'].includes(p.name)).map(p=>p.name+':'+(p.exp?.content||''));
   const conditional=[...parents,...guards];
   const label=(attrs['aria-label']||bound['aria-label']||attrs.title||bound.title||((n.children||[]).filter(c=>c.type===2||c.type===5).map(c=>c.type===2?c.content:'{{'+(c.content?.content||'')+'}}').join(' '))).trim().replace(/\s+/g,' ');
   const common={tag,label,testid:attrs['data-testid']||bound['data-testid']||'',events,guards:conditional,disabled:bound.disabled||attrs.disabled||'',model:directives.filter(p=>p.name==='model').map(p=>p.exp?.content||''),href:attrs.href||bound.href||''};
   if(/^(button|a|input|select|textarea|form)$/.test(tag)||events.some(e=>e.expression)||directives.some(p=>p.name==='model'))add(file,'control',n.loc.start.line,common);
   if(attrs.role==='dialog'||attrs.role==='menu'||/dialog|modal|popover/i.test(tag))add(file,'overlay',n.loc.start.line,common);
   if(/^[A-Z]/.test(tag)||tag==='component')add(file,'component',n.loc.start.line,{...common,bindings:bound});
   if(tag==='component'&&bound.is)unresolved.push({file:rel(file),line:n.loc.start.line,reason:'dynamic-component',expression:bound.is});
   for(const c of n.children||[])node(c,conditional);
 }else for(const c of n.children||[])node(c,parents);
 }node(ast)}
 function visit(p){p=path.resolve(p);if(seen.has(p))return;seen.add(p);if(!inside(root,p)||!fs.existsSync(p)){unresolved.push({file:rel(p),reason:'missing-entry'});return}
 if(seen.size>(cfg.maxFiles||400)){unresolved.push({file:rel(p),reason:'scan-limit'});return}
 const s=fs.readFileSync(p,'utf8');files.push({path:rel(p),sha256:sha(fs.readFileSync(p))});
 if(p.endsWith('.vue')){const out=sfc.parse(s,{filename:p});for(const e of out.errors||[])unresolved.push({file:rel(p),reason:'vue-parse',message:String(e)});const d=out.descriptor;
 if(d.template?.ast)template(p,d.template.ast);else if(d.template)unresolved.push({file:rel(p),reason:'template-no-ast'});
 for(const block of [d.script,d.scriptSetup])if(block)script(p,block.content,block.loc.start.line-1);
 }else script(p,s);
 }
 for(const p of cfg.entries)visit(path.resolve(root,p));
 files.sort((a,b)=>a.path.localeCompare(b.path));items.sort((a,b)=>a.file.localeCompare(b.file)||a.line-b.line||a.kind.localeCompare(b.kind));return {root,entries:cfg.entries,treeHash:sha(JSON.stringify(files)),files,items,unresolved,external:[...external].sort()};
}
function compare(config,a,b){
 const byLabel=new Map();for(const item of b.items){const k=item.kind+':'+norm(item.label);if(norm(item.label)){if(!byLabel.has(k))byLabel.set(k,[]);byLabel.get(k).push(item)}}
 const suggestions=a.items.map(s=>{const matches=(byLabel.get(s.kind+':'+norm(s.label))||[]).filter(t=>t.tag===s.tag||s.kind==='column');return {sourceId:s.id,source:s,classification:matches.length?'REVIEW_CANDIDATE':'NO_EXACT_MATCH',candidates:matches.map(t=>({id:t.id,file:t.file,line:t.line,label:t.label,guards:t.guards}))}});
 const focus=(config.focus||[]).map(f=>{const old=a.files.filter(x=>x.path.endsWith(f.sourceFile));const src=a.items.filter(x=>old.some(y=>y.path===x.file));const dest=b.items.filter(x=>f.targetFileSuffixes?.some(y=>x.file.endsWith(y)));return {...f,sourceFiles:old.map(x=>x.path),sourceItems:src.length,targetItems:dest.length,status:'OPEN_REQUIRES_SEMANTIC_AND_BROWSER_REVIEW'}});
 return {version:3,route:config.route,view:config.view,source:a,target:b,suggestions,focus};
}
function buildDigest(dir){
 const root=path.resolve(dir),files=[];
 function walk(p){for(const entry of fs.readdirSync(p,{withFileTypes:true})){const f=path.join(p,entry.name);if(entry.isSymbolicLink())throw Error('symlink in build tree');if(entry.isDirectory())walk(f);else if(entry.isFile())files.push({path:posix(path.relative(root,f)),sha256:sha(fs.readFileSync(f))});else throw Error('non-file in build tree')}}
 walk(root);files.sort((a,b)=>a.path.localeCompare(b.path));if(!files.length)throw Error('empty build tree');return sha(JSON.stringify(files));
}
function gate(report,manifest,base){
 const issues=[],bad=m=>issues.push(m),hash=/^[a-f0-9]{64}$/i;
 if(report.version!==3||manifest.version!==3)bad('schema-version');
 if(report.route!==manifest.route||report.view!==manifest.view)bad('scope-mismatch');
 if(!report.source.items.length||!report.target.items.length)bad('empty-inventory');
 if(report.source.treeHash!==manifest.sourceTreeHash||report.target.treeHash!==manifest.targetTreeHash)bad('source-target-drift');
 for(const side of ['source','target']){const s=report[side];for(const f of s.files){const p=path.resolve(s.root,f.path);if(!inside(path.resolve(s.root),p)||!fs.existsSync(p)||sha(fs.readFileSync(p))!==f.sha256)bad('live-file-drift:'+side+':'+f.path)}
 if(s.unresolved.length)bad('unresolved-'+side+':'+s.unresolved.length)}
 if(!hash.test(manifest.buildHash||'')||!manifest.buildFile)bad('missing-build');else{const p=path.resolve(base,manifest.buildFile);if(!fs.existsSync(p)||sha(fs.readFileSync(p))!==manifest.buildHash)bad('build-hash-mismatch')}
 if(!hash.test(manifest.buildTreeHash||'')||!manifest.buildDirectory)bad('missing-build-tree');else{try{if(buildDigest(path.resolve(base,manifest.buildDirectory))!==manifest.buildTreeHash)bad('build-tree-drift')}catch{bad('build-tree-unreadable')}}
 const checkEvidence=(e,kind,expected={})=>{try{if(!e||e.kind!==kind||!e.path||!hash.test(e.sha256||''))return false;const p=path.resolve(base,e.path);if(!fs.existsSync(p)||!fs.statSync(p).isFile()||sha(fs.readFileSync(p))!==e.sha256)return false;const v=read(p);
 return v.version===3&&v.kind===kind&&v.route===report.route&&v.view===report.view&&v.sourceTreeHash===report.source.treeHash&&v.targetTreeHash===report.target.treeHash&&v.buildHash===manifest.buildHash&&v.buildTreeHash===manifest.buildTreeHash&&v.result==='PASS'&&typeof v.provenance==='string'&&v.provenance.trim().length>0&&Object.entries(expected).every(([k,x])=>JSON.stringify(v[k])===JSON.stringify(x));}catch{return false}};
 const m=new Map(),targetIds=new Set(report.target.items.map(x=>x.id));for(const row of manifest.items||[]){if(m.has(row.sourceId))bad('duplicate-mapping:'+row.sourceId);m.set(row.sourceId,row)}
 for(const s of report.source.items){const row=m.get(s.id);if(!row){bad('unmapped:'+s.id);continue}
 if(row.status==='NOT_APPLICABLE'){if(!row.reason||!checkEvidence(row.approval,'owner-scope-exception',{sourceId:s.id,reason:row.reason}))bad('unapproved-exclusion:'+s.id);continue}
 if(row.status!=='VERIFIED'||!row.targetIds?.length||row.targetIds.some(t=>!targetIds.has(t))||!checkEvidence(row.evidence,'semantic-action-review',{sourceId:s.id,targetIds:row.targetIds}))bad('unverified-mapping:'+s.id)}
 const expected=new Set(report.source.items.map(x=>x.id));for(const k of m.keys())if(!expected.has(k))bad('stale-mapping:'+k);
 for(let i=1;i<=8;i++){const k='S0'+i,e=manifest.gates?.[k];if(e?.status!=='PASS'||!checkEvidence(e.evidence,'v2-gate-'+k))bad('gate-open:'+k)}
 const b=manifest.browser;if(!b||b.mode!=='REAL_DUAL_SITE'||!b.actorVerified||!b.sameRole||!b.comparableRecord||!b.verifiedWorkflow||b.sourceTreeHash!==report.source.treeHash||b.targetTreeHash!==report.target.treeHash||b.buildHash!==manifest.buildHash||!checkEvidence(b.evidence,'real-dual-site-browser',{mode:'REAL_DUAL_SITE',actorVerified:true,sameRole:true,comparableRecord:true,verifiedWorkflow:true}))bad('real-browser-evidence-open');
 if(!checkEvidence(manifest.ownerUat,'owner-final-uat'))bad('owner-uat-open');
 return {status:issues.length?'BLOCKED':'READY_FOR_SEPARATE_RELEASE_AUTHORIZATION',issueCount:issues.length,issues};
}
function main(args){const [mode,cfg,out,dep]=args;if(mode==='scan'){const config=read(cfg);const a=inventory(config,'source',dep),b=inventory(config,'target',dep),report=compare(config,a,b);write(out,report);console.log(JSON.stringify({sourceFiles:a.files.length,targetFiles:b.files.length,sourceItems:a.items.length,targetItems:b.items.length,sourceUnresolved:a.unresolved.length,targetUnresolved:b.unresolved.length,noExactMatch:report.suggestions.filter(x=>x.classification==='NO_EXACT_MATCH').length,output:out}));return}
 if(mode==='gate'){const report=read(cfg),manifest=read(out);const result=gate(report,manifest,path.dirname(path.resolve(out)));console.log(JSON.stringify({status:result.status,issueCount:result.issueCount,issues:result.issues.slice(0,20)}));if(dep)write(dep,result);process.exitCode=result.status==='BLOCKED'?2:0;return}
 throw Error('Usage: scan config.json report.json dependencyBase | gate report.json manifest.json [result.json]')}
module.exports={inventory,compare,gate,sha,write,buildDigest};
if(require.main===module){try{main(process.argv.slice(2))}catch(e){console.error(e.stack);process.exitCode=1}}
