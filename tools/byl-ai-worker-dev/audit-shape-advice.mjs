import {parseKolQuickOrder} from '../../resources/js/lib/kol/kolQuickOrderParser.ts'
import {projectKolShape,validateShapeRequest,recoverShapeSuggestions,shapeFields} from '../../resources/js/lib/kol/kolShapeProjection.ts'
import {spawnSync} from 'node:child_process'
import {mkdtempSync,writeFileSync,readFileSync,rmSync} from 'node:fs'
import {tmpdir} from 'node:os'
import {join} from 'node:path'
let input='';for await(const c of process.stdin)input+=c
const rows=JSON.parse(input)
if(!Array.isArray(rows)||rows.length!==100)throw new Error('expected_100_local_records')
const norm=x=>String(x??'').normalize('NFKD').toLowerCase().replace(/[^\p{L}\p{N}]/gu,'')
const templates=new Map(), cases=[]
const supported=shapeFields.filter(f=>f!=='recipient_customs_id')
for(const r of rows){
 const local=parseKolQuickOrder(r.raw), p=projectKolShape(r.raw)
 const rec={r,local,p,key:null};cases.push(rec)
 if(!p||!validateShapeRequest(p.request))continue
 // Local candidate gate: only formats with at least one missing and safe recoverable field.
 const candidates=p.request.lines.flatMap(l=>{
  const f=l.label||(l.kind==='phone_like'?'phone':l.kind==='postal_like'?'postal_code':l.kind==='email_like'?'email':null)
  return f&&supported.includes(f)&&local[f]===undefined?[{index:l.index,field:f}]:[]
 })
 if(!Object.keys(recoverShapeSuggestions(r.raw,candidates,local)).length)continue
 const missing=supported.filter(f=>local[f]===undefined)
 const payload={lines:p.request.lines,missing}
 const key=JSON.stringify(payload);rec.key=key
 if(!templates.has(key))templates.set(key,{id:templates.size, ...payload})
}
const all=[...templates.values()]
const advice=new Map(), timings=[]
let cloudFailed=0
const dir=mkdtempSync(join(tmpdir(),'byl-shape-bench-'))
try{
 const schema={type:'object',properties:{templates:{type:'array',items:{type:'object',properties:{id:{type:'integer'},assignments:{type:'array',items:{type:'object',properties:{index:{type:'integer'},field:{type:'string',enum:supported}},required:['index','field'],additionalProperties:false}}},required:['id','assignments'],additionalProperties:false}}},required:['templates'],additionalProperties:false}
 writeFileSync(join(dir,'schema.json'),JSON.stringify(schema),{mode:0o600})
 const prompt='You receive only synthetic structural enums and ordinal positions, with no raw field values. For each template propose only missing fields supported by explicit labels or an unambiguous unlabeled email_like, phone_like or postal_like line. Never guess a name, country, city, province or address from untyped text. Skip ambiguous/repeated candidates, unknown_label and other. No tools. Return only schema JSON.'
 for(let start=0;start<all.length;start+=12){
  const batch=all.slice(start,start+12)
  // Revalidate the exact outgoing representation. No source value, identifier,
  // original row number, filename, hash, or arbitrary label can enter this payload.
  if(!batch.every(t=>Number.isInteger(t.id)&&validateShapeRequest({lines:t.lines})&&t.missing.every(f=>supported.includes(f))))throw new Error('unsafe_projection')
  const env={...process.env};delete env.OPENAI_API_KEY;delete env.CODEX_API_KEY
  const out=join(dir,'result.json');rmSync(out,{force:true})
  const started=Date.now()
  const result=spawnSync('/opt/homebrew/bin/codex',['exec','--ignore-user-config','--ephemeral','--sandbox','read-only','--skip-git-repo-check','-c','approval_policy="never"','--output-schema',join(dir,'schema.json'),'--output-last-message',out,prompt],{cwd:dir,env,input:JSON.stringify({templates:batch}),encoding:'utf8',timeout:55000,maxBuffer:1024*1024})
  timings.push(Date.now()-started)
  try{
   if(result.status!==0)throw new Error('worker_failed')
   const v=JSON.parse(readFileSync(out,'utf8'))
   if(!Array.isArray(v.templates))throw new Error('invalid')
   const ids=new Set()
   for(const item of v.templates){
    if(!item||!batch.some(t=>t.id===item.id)||ids.has(item.id)||!Array.isArray(item.assignments))throw new Error('invalid')
    ids.add(item.id)
   }
   if(ids.size!==batch.length)throw new Error('incomplete')
   for(const item of v.templates)advice.set(item.id,item.assignments)
  }catch{cloudFailed+=batch.length}
 }
}finally{rmSync(dir,{recursive:true,force:true})}
let baseline=0,total=0,fullBase=0,fullWith=0,reviewCorrect=0,reviewDifferent=0,reviewNoReference=0,reviewRows=0
const byField={}
for(const c of cases){
 const t=c.key?templates.get(c.key):null
 const suggestions=t&&advice.has(t.id)?recoverShapeSuggestions(c.r.raw,advice.get(t.id),c.local):{}
 if(Object.keys(suggestions).length)reviewRows++
 let allBase=true,allWith=true
 for(const [field,value] of Object.entries(c.r.expected)){
  total++;const good=norm(c.local[field])===norm(value)
  if(good)baseline++;else allBase=false
  const goodAfter=good||(c.local[field]===undefined&&suggestions[field]!==undefined&&norm(suggestions[field])===norm(value))
  if(!goodAfter)allWith=false
  if(!byField[field])byField[field]={reference:0,baseline:0,additionalCorrect:0}
  byField[field].reference++;if(good)byField[field].baseline++;else if(goodAfter)byField[field].additionalCorrect++
 }
 for(const [field,value] of Object.entries(suggestions)){
  if(!Object.hasOwn(c.r.expected,field))reviewNoReference++
  else if(norm(value)===norm(c.r.expected[field]))reviewCorrect++
  else reviewDifferent++
 }
 if(allBase)fullBase++;if(allWith)fullWith++
}
console.log(JSON.stringify({heldout:rows.length,templates:all.length,cloudTemplates:advice.size,cloudFailed,timings_ms:timings,referenceFields:total,localMatches:baseline,localComplete:fullBase,reviewRows,reviewCorrect,reviewDifferent,reviewNoReference,possibleMatchesAfterReview:baseline+reviewCorrect,possibleCompleteAfterReview:fullWith,byField}))
