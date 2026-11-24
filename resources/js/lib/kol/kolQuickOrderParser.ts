export type QuickOrderDraft = {
  recipient_name?: string
  country?: string
  province_state?: string
  city?: string
  address_line_1?: string
  address_line_2?: string
  postal_code?: string
  email?: string
  phone?: string
  recipient_customs_id?: string
  coupon_code?: string
  product_hint?: string
  username_hint?: string
  social_accounts: { platform: string; handle: string; profile_url: string; followers?: number }[]
  unparsed: string[]
}
type Field=keyof Omit<QuickOrderDraft,'social_accounts'|'unparsed'>
const groups: Record<Field,string[]> = {
 recipient_name:['full name','recipient name','name','nombre','nome','nombre completo','nome completo','收件人','姓名'],
 country:['country','país','pais','国家'],
 province_state:['state','province','estado','provincia','州','省'],
 city:['city','ciudad','cidade','城市'],
 address_line_1:['address line 1','address line','address street','street address','street','address','dirección','direccion','endereço','endereco','地址'],
 address_line_2:['address line 2','address 2','complemento','地址2'],
 postal_code:['post code','postal code','zip code','zipcode','zip','código postal','codigo postal','cep','邮编'],
 email:['contact email','e-mail address','email address','email','e-mail','correo','correo electrónico','邮箱'],
 phone:['contact phone','telephone number','telephone','phone number','phone','teléfono','telefono','telefone','tel','电话'],
 recipient_customs_id:['recipient tax id','customs id','tax number','tax id','rfc','cpf','rut','cuit','清关税号'],
 coupon_code:['coupon code','discount code','code','código','codigo','cupom','折扣码'],
 product_hint:['product','sku','producto','produto','产品'],
 username_hint:['username','handle','usuario','用户名'],
}
const labels=new Map<string,Field>()
for(const [key,values] of Object.entries(groups))for(const label of values)labels.set(label,key as Field)
const escaped=[...labels.keys()].sort((a,b)=>b.length-a.length).map(s=>s.replace(/[.*+?^$${}()|[\]\\]/g,'\\$&')).join('|')
const labeled=new RegExp('^('+escaped+')(?:\\s*[:：]\\s*|\\s+)(.*)$','iu')
const inline=new RegExp('(?:^|;\\s*|\\s+)('+escaped+')\\s*[:：]','giu')
function splitInline(line:string):string[]{
 const matches=[...line.matchAll(inline)]
 if(matches.length<2)return [line]
 const starts=matches.map(m=>(m.index??0)+m[0].indexOf(m[1]))
 const parts=starts.map((start,i)=>line.slice(start,starts[i+1]??line.length).trim().replace(/;\s*$/,''))
 const prefix=line.slice(0,starts[0]).trim()
 if(prefix)parts.unshift(prefix)
 return parts
}
const platforms:Record<string,string>={'x.com':'twitter','twitter.com':'twitter','instagram.com':'instagram','tiktok.com':'tiktok','youtube.com':'youtube'}
export function parseKolQuickOrder(raw:string):QuickOrderDraft {
 const draft:QuickOrderDraft={social_accounts:[],unparsed:[]}
 const conflicts=new Set<Field>();const original=new Map<Field,string>()
 let prev:Field|undefined
 let pendingLabel:Field|undefined
 const loose:string[]=[]
 function set(key:Field,value:string,line:string){
  value=value.trim();if(!value)return
  if(key==='username_hint')value=value.replace(/^@/,'')
  if(conflicts.has(key)){draft.unparsed.push(line);return}
  if(draft[key]!==undefined&&draft[key]!==value){
   draft.unparsed.push(original.get(key)??String(draft[key]),line);delete draft[key];conflicts.add(key);return
  }
  draft[key]=value;original.set(key,line)
 }
 for(const part of raw.split(/\r?\n/).flatMap(line=>/^https?:\/\//i.test(line.trim())?[line]:splitInline(line))){
  const line=part.trim();if(!line)continue
  const social=line.match(/^(https?:\/\/\S+)(?:\s+([\d,.]+)\s*([kKmM])?(?:\s+(?:followers|subscribers|seguidores))?)?$/i)
  if(social){
   try{
    const url=new URL(social[1]);const platform=platforms[url.hostname.toLowerCase().replace(/^www\./,'')]
    const path=url.pathname.split('/').filter(Boolean);const handle=path.length===1?path[0].replace(/^@/,''):''
    if(platform&&/^[A-Za-z0-9_.-]{1,100}$/.test(handle)&&!['home','explore','search','share','watch','reel','p','shorts'].includes(handle.toLowerCase())){
     const n=social[2]; const value=n&&/^\d+(?:\.\d+)?$|^\d{1,3}(?:,\d{3})+$/.test(n)?Number(n.replace(/,/g,'')):NaN
     const scale=social[3]?.toLowerCase()==='m'?1e6:social[3]?.toLowerCase()==='k'?1e3:1;const followers=Math.round(value*scale)
     draft.social_accounts.push({platform,handle,profile_url:url.origin+url.pathname,...(Number.isSafeInteger(followers)&&followers>=0?{followers}:{})})
     prev=undefined;pendingLabel=undefined;continue
    }
   }catch{}
   draft.unparsed.push(line);prev=undefined;continue
  }
  if(/^[A-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)+$/i.test(line)){set('email',line,line);prev=undefined;pendingLabel=undefined;continue}
  if(/^@[A-Za-z0-9_.-]{1,100}$/.test(line)){set('username_hint',line,line);prev=undefined;pendingLabel=undefined;continue}
  const exactLabel=labels.get(line.replace(/[:：]$/,'').trim().toLowerCase())
  if(exactLabel){pendingLabel=exactLabel;prev=undefined;continue}
  const match=line.match(labeled);const key=match?labels.get(match[1].toLowerCase()):undefined
  if(match&&key){pendingLabel=undefined;set(key,match[2],line);prev=key;continue}
  if(pendingLabel){const label=pendingLabel;pendingLabel=undefined;set(label,line,line);prev=label;continue}
  if(prev==='address_line_1'&&/^(?:apartment|apt\.?|unit|suite|floor|flat|depto\.?|apartamento)\s+\S+/i.test(line)){set('address_line_2',line,line);prev='address_line_2';continue}
  if(!/[:：]/.test(line)&&prev==='phone')loose.push(line);else draft.unparsed.push(line)
  prev=undefined
 }
 // Legacy template: one unlabeled product hint after the phone; never a selected SKU.
 if(loose.length===1&&draft.coupon_code&&!draft.product_hint&&!conflicts.has('product_hint'))draft.product_hint=loose[0]
 else draft.unparsed.push(...loose)
 return draft
}
