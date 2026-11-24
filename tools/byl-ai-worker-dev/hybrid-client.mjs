import http from 'node:http';
import {readFileSync} from 'node:fs';
import {createPrivateKey,sign,randomBytes} from 'node:crypto';
import {fileURLToPath} from 'node:url';
import {parseKolQuickOrder} from '../../resources/js/lib/kol/kolQuickOrderParser.ts';
const fixtures=JSON.parse(readFileSync(new URL('fixtures.json',import.meta.url),'utf8'));
const allowed=new Set(fixtures.map(f=>f.text));
const fields=['username','platform','followers','recipient_name','country','province_state','city','address_line_1','address_line_2','postal_code','email','phone','product_hint','coupon_code','tax_id'];
function valid(data){return data&&typeof data==='object'&&Object.keys(data).length===fields.length&&fields.every(k=>Object.hasOwn(data,k)&&(data[k]===null||(k==='followers'?Number.isSafeInteger(data[k])&&data[k]>=0:typeof data[k]==='string'&&data[k].length<=1000)))}
export async function hybridDraft(raw,options={}){
 const local=parseKolQuickOrder(raw);
 // Dev-only allowlist. No real text, key material or credentials leave the client.
 if(!allowed.has(raw))return {source:'local_rules',reason:'synthetic_only',draft:local};
 try{
  const key=createPrivateKey(readFileSync(process.env.BYL_AI_KEY_PATH || new URL('client-key.pem',import.meta.url)));
  const path='/v1/kol/parse',body=Buffer.from(JSON.stringify({text:raw})),ts=String(Date.now()),nonce=randomBytes(16).toString('hex');
  const msg=Buffer.concat([Buffer.from('POST\n'+path+'\n'+ts+'\n'+nonce+'\n'),body]);
  const testPort=process.env.BYL_AI_TEST_LOOPBACK_PORT;
  if(testPort&&(!/^\d{4,5}$/.test(testPort)||Number(testPort)>65535))throw new Error('invalid_loopback_test_config');
  const data=await new Promise((resolve,reject)=>{
   const req=http.request({host:testPort?'127.0.0.1':'100.94.52.109',port:options.offlineTest?1:testPort?Number(testPort):18765,path,method:'POST',headers:{'content-type':'application/json','content-length':body.length,'x-byl-ts':ts,'x-byl-nonce':nonce,'x-byl-signature':sign(null,msg,key).toString('base64')}},res=>{
    const chunks=[];let size=0;res.on('data',c=>{size+=c.length;if(size>32768)req.destroy(new Error('response_size'));else chunks.push(c)});
    res.on('end',()=>{try{if(res.statusCode!==200)throw new Error('http_'+res.statusCode);resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')).data)}catch(e){reject(e)}});
    res.on('error',reject);
   });
   const timeout=setTimeout(()=>req.destroy(new Error('timeout')),options.offlineTest?1000:50000);
   req.on('close',()=>clearTimeout(timeout));req.on('error',reject);req.end(body);
  });
  if(!valid(data))throw new Error('invalid_schema');
  // Suggestions only: never overwrite deterministic values or silently accept an identity.
  return {source:'hybrid_review',draft:local,ai_suggestions:data};
 }catch{return {source:'local_rules',reason:'worker_unavailable',draft:local}}
}
if(process.argv[1]===fileURLToPath(import.meta.url)&&process.argv.includes('--self-test')){
 const assert=(await import('node:assert/strict')).default;
 const local=await hybridDraft('Country: Mexico\nEmail: fake@example.com');assert.equal(local.reason,'synthetic_only');
 const offline=await hybridDraft(fixtures[0].text,{offlineTest:true});assert.equal(offline.reason,'worker_unavailable');assert.ok(offline.draft);
 console.log('SYNTHETIC_ONLY_PASS OFFLINE_FALLBACK_PASS');
 const live=await hybridDraft(fixtures[0].text);assert.equal(live.source,'hybrid_review');assert.equal(live.ai_suggestions.username,'demo_es');assert.equal(live.ai_suggestions.tax_id,null);console.log('LIVE_HYBRID_REVIEW_PASS');
}

if(process.argv[1]===fileURLToPath(import.meta.url)&&process.argv[2]==='--fixture'){
 const fixture=fixtures.find(f=>f.id===process.argv[3]);
 if(!fixture){console.log(JSON.stringify({source:'local_rules',reason:'synthetic_only'}));process.exitCode=1;}
 else {const result=await hybridDraft(fixture.text);console.log(JSON.stringify(result));}
}
