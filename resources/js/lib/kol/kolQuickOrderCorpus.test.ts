import { describe,it,expect } from 'vitest'
import {parseKolQuickOrder as parse} from './kolQuickOrderParser'
import {performance} from 'node:perf_hooks'
type Case={id:string,raw:string,expected:Record<string,unknown>}
const cases:Case[]=[]
for(let i=0;i<10;i++){
 const id=String(i).padStart(2,'0'), user='demo_'+id, name='Demo '+id, mail=user+'@example.com', zip='001'+id, phone='000555'+id
 const standard={recipient_name:name,country:'Mexico',postal_code:zip,email:mail,phone}
 const social=(platform:string,handle:string,profile_url:string,followers?:number)=>[{platform,handle,profile_url,...(followers===undefined?{}:{followers})}]
 cases.push(
 {id:'standard-'+id,raw:'https://x.com/'+user+' 29.4K\nFull Name: '+name+'\nCountry: Mexico\nPost Code: '+zip+'\nEmail: '+mail+'\nPhone: '+phone+'\nProduct: Demo Product\nCode: DEMO20',expected:{...standard,social_accounts:social('twitter',user,'https://x.com/'+user,29400),product_hint:'Demo Product',coupon_code:'DEMO20'}},
 {id:'spanish-'+id,raw:'Nombre: '+name+'\nPaís: Mexico\nCódigo postal: '+zip+'\nCorreo: '+mail+'\nTeléfono: '+phone,expected:standard},
 {id:'portuguese-'+id,raw:'Nome: '+name+'\nPaís: Brazil\nCEP: '+zip+'\nE-mail: '+mail+'\nTelefone: '+phone,expected:{...standard,country:'Brazil'}},
 {id:'no-colon-'+id,raw:'Full Name '+name+'\nCountry Mexico\nPost Code '+zip+'\nEmail '+mail+'\nPhone '+phone,expected:standard},
 {id:'inline-'+id,raw:'Full Name: '+name+'; Country: Mexico; Post Code: '+zip+'; Email: '+mail+'; Phone: '+phone,expected:standard},
 {id:'address-'+id,raw:'Address Line: '+id+' Demo Street\nApartment 5\nCity: Demo City',expected:{address_line_1:id+' Demo Street',address_line_2:'Apartment 5',city:'Demo City',product_hint:undefined}},
 {id:'followers-'+id,raw:'https://instagram.com/'+user+' 12K followers',expected:{social_accounts:social('instagram',user,'https://instagram.com/'+user,12000)}},
 {id:'youtube-'+id,raw:'https://www.youtube.com/@'+user+' 1.2K subscribers',expected:{social_accounts:social('youtube',user,'https://www.youtube.com/@'+user,1200)}},
 {id:'conflict-'+id,raw:'Email: '+mail+'\nEmail: second@example.com\nCountry: Mexico',expected:{email:undefined,country:'Mexico',recipient_customs_id:undefined}},
 {id:'unknown-'+id,raw:'@'+user+'\nFull Name: '+name+'\nUnrecognized note',expected:{recipient_name:name,username_hint:user,product_hint:undefined,recipient_customs_id:undefined,social_accounts:[]}}
 )
}
describe('A7 synthetic parser regression — not representative real data',()=>{
 it('reports 100 cases, field accuracy and latency',()=>{
  let good=0,total=0,passed=0;const failures:string[]=[];const ms:number[]=[]
  for(const c of cases){const t=performance.now();const draft=parse(c.raw) as unknown as Record<string,unknown>;ms.push(performance.now()-t);let correct=true;for(const [k,v] of Object.entries(c.expected)){total++;if(JSON.stringify(draft[k])===JSON.stringify(v))good++;else{correct=false;failures.push(c.id+':'+k)}}if(correct)passed++}
  ms.sort((a,b)=>a-b);console.log('A7_SYNTHETIC '+JSON.stringify({cases:cases.length,passed,fields_correct:good,fields_total:total,p95_ms:ms[Math.ceil(ms.length*.95)-1],failure_examples:failures.slice(0,30)}))
  expect(passed).toBe(cases.length)
 })
})
