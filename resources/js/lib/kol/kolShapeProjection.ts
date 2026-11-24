import type {QuickOrderDraft} from './kolQuickOrderParser'

export const shapeFields=['recipient_name','country','province_state','city','address_line_1','address_line_2','postal_code','email','phone','recipient_customs_id'] as const
export type ShapeField=typeof shapeFields[number]
export const shapeKinds=['text','number','postal_like','phone_like','email_like','mixed','unknown_label','other'] as const
export type ShapeKind=typeof shapeKinds[number]
export type ShapeLine={index:number;label:ShapeField|null;kind:ShapeKind}
export type ShapeRequest={lines:ShapeLine[]}
export type ShapeAssignment={index:number;field:ShapeField}
const labels:Record<string,ShapeField>=Object.create(null)
for(const [field,aliases] of Object.entries({
 recipient_name:['name','full name','recipient name','nombre','nome','收件人','姓名'],
 country:['country','país','pais','国家'],
 province_state:['state','province','estado','provincia','州','省'],
 city:['city','ciudad','cidade','城市'],
 address_line_1:['address','address line','address line 1','street','street address','address street','dirección','endereço','地址'],
 address_line_2:['address line 2','apartment','apt','unit','suite','complemento','地址2'],
 postal_code:['post code','postal code','zip','zip code','zipcode','cep','código postal','邮编'],
 email:['email','e-mail','contact email','correo','邮箱'],
 phone:['phone','phone number','tel','telephone','telefone','teléfono','电话'],
 recipient_customs_id:['tax id','tax number','rfc','cpf','rut','cuit','清关税号'],
}))for(const alias of aliases)labels[alias]=field as ShapeField

function kindOf(value:string):ShapeKind{
 if(!value||/https?:\/\/|^@/i.test(value))return 'other'
 if(/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value))return 'email_like'
 if(/^\+?[\d() .-]+$/.test(value)){
  const digits=value.replace(/\D/g,'').length
  if(digits>=7&&digits<=18)return 'phone_like'
  if(digits>=3&&digits<=6)return 'postal_like'
  return 'number'
 }
 if(/[\p{L}]/u.test(value)&&/\d/.test(value))return 'mixed'
 if(/^[\p{L}\p{M} .'’-]+$/u.test(value))return 'text'
 return 'other'
}
export function projectKolShape(raw:string):{request:ShapeRequest;localValues:Map<number,string>}|null{
 if(raw.length>16000)return null
 const lines=raw.split(/\r?\n/).map(x=>x.trim()).filter(Boolean)
 if(!lines.length||lines.length>24)return null
 const localValues=new Map<number,string>()
 const safeLines:ShapeLine[]=lines.map((line,index)=>{
  const match=line.match(/^([^:：]{1,64})[:：]\s*(.*)$/u)
  const label=match?labels[match[1].trim().toLowerCase()]??null:null
  // Do not retain arbitrary labels in the transmitted shape, even if unknown.
  const value=match?match[2].trim():line
  const kind=match&&!label?'unknown_label':kindOf(value)
  localValues.set(index,value)
  return {index,label,kind}
 })
 return {request:{lines:safeLines},localValues}
}
export function validateShapeRequest(input:unknown):input is ShapeRequest{
 if(!input||typeof input!=='object'||Object.keys(input).join(',')!=='lines')return false
 const lines=(input as ShapeRequest).lines
 return Array.isArray(lines)&&lines.length>0&&lines.length<=24&&lines.every((line,i)=>line&&typeof line==='object'&&Object.keys(line).sort().join(',')==='index,kind,label'&&line.index===i&&(line.label===null||shapeFields.includes(line.label))&&shapeKinds.includes(line.kind))
}
// No raw strings are ever accepted back from the model. Rehydrate locally only,
// as review suggestions; never infer identity, tax number, or overwrite a local field.
export function recoverShapeSuggestions(raw:string,assignments:unknown,local:QuickOrderDraft):Partial<Record<ShapeField,string>>{
 const p=projectKolShape(raw)
 if(!p||!Array.isArray(assignments)||assignments.length>24)return {}
 const result:Partial<Record<ShapeField,string>>={}
 const seenFields=new Set<ShapeField>(),seenIndexes=new Set<number>()
 for(const entry of assignments){
  if(!entry||typeof entry!=='object'||Object.keys(entry).sort().join(',')!=='field,index'||!Number.isInteger(entry.index)||!shapeFields.includes(entry.field))return {}
  const {field,index}=entry as ShapeAssignment
  const line=p.request.lines[index],value=p.localValues.get(index)
  if(!line||!value||seenFields.has(field)||seenIndexes.has(index))return {}
  seenFields.add(field);seenIndexes.add(index)
  if(local[field]!==undefined||field==='recipient_customs_id'||line.kind==='other'||line.kind==='unknown_label')continue
  if(line.label&&line.label!==field)continue
  if(!line.label){
   // Untyped prose could be anything: don't classify it as a name, city, address,
   // or country merely because the surrounding pattern looks plausible.
   if(!((field==='email'&&line.kind==='email_like')||(field==='phone'&&line.kind==='phone_like')||(field==='postal_code'&&line.kind==='postal_like')))continue
  }
  // A conflict in explicitly labeled fields must be resolved by the operator.
  if(p.request.lines.filter(x=>x.label===field).length>1)continue
  if(field==='email'&&p.request.lines.filter(x=>x.kind==='email_like').length>1)continue
  if(field==='phone'&&p.request.lines.filter(x=>x.kind==='phone_like').length>1)continue
  if(field==='postal_code'&&p.request.lines.filter(x=>x.kind==='postal_like').length>1)continue
  result[field]=value
 }
 return result
}
