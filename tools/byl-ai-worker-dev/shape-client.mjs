import http from 'node:http';
import {readFileSync} from 'node:fs';
import {createPrivateKey,sign,randomBytes} from 'node:crypto';
import {validShapeRequest,validShapeAssignments} from './shape-contract.mjs';
export async function requestShape(p){
 const fallback={source:'local_rules',reason:'worker_unavailable'};
 if(!validShapeRequest(p))return {source:'local_rules',reason:'invalid_shape'};
 try{
  const key=createPrivateKey(readFileSync(process.env.BYL_AI_KEY_PATH || new URL('client-key.pem',import.meta.url)));
  const path='/v1/kol/shape',body=Buffer.from(JSON.stringify(p)),ts=String(Date.now()),nonce=randomBytes(16).toString('hex');
  const msg=Buffer.concat([Buffer.from('POST\n'+path+'\n'+ts+'\n'+nonce+'\n'),body]);
  const data=await new Promise((resolve,reject)=>{
   const req=http.request({host:'100.94.52.109',port:18765,path,method:'POST',headers:{'content-type':'application/json','content-length':body.length,'x-byl-ts':ts,'x-byl-nonce':nonce,'x-byl-signature':sign(null,msg,key).toString('base64')}},res=>{
    const chunks=[];let size=0;res.on('data',c=>{size+=c.length;if(size>32768)req.destroy(new Error('response_size'));else chunks.push(c)});
    res.on('end',()=>{try{if(res.statusCode!==200)throw new Error('http');resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')).data)}catch(e){reject(e)}});
    res.on('error',reject);
   });
   const timer=setTimeout(()=>req.destroy(new Error('timeout')),50000);
   req.on('close',()=>clearTimeout(timer));req.on('error',reject);req.end(body);
  });
  if(!data||Object.keys(data).join(',')!=='assignments'||!validShapeAssignments(p,data.assignments))return fallback;
  return {source:'shape_review',assignments:data.assignments};
 }catch{return fallback}
}
let raw='';for await(const c of process.stdin){raw+=c;if(raw.length>8192){console.log(JSON.stringify({source:'local_rules',reason:'invalid_shape'}));process.exit(0)}}
try{console.log(JSON.stringify(await requestShape(JSON.parse(raw))))}catch{console.log(JSON.stringify({source:'local_rules',reason:'invalid_shape'}))}
