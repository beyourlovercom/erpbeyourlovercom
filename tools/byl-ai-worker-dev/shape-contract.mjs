export const fields=['recipient_name','country','province_state','city','address_line_1','address_line_2','postal_code','email','phone','recipient_customs_id'];
export const suggestionFields=fields.filter(f=>f!=='recipient_customs_id');
const kinds=['text','number','postal_like','phone_like','email_like','mixed','unknown_label','other'];
const keys=(o,expected)=>o!==null&&typeof o==='object'&&!Array.isArray(o)&&Object.keys(o).sort().join(',')===expected;
export function validShapeRequest(p){
 return keys(p,'lines,missing')&&Array.isArray(p.lines)&&p.lines.length>0&&p.lines.length<=24&&
 p.lines.every((l,i)=>keys(l,'index,kind,label')&&l.index===i&&(l.label===null||fields.includes(l.label))&&kinds.includes(l.kind))&&
 Array.isArray(p.missing)&&p.missing.length>0&&p.missing.length<=suggestionFields.length&&new Set(p.missing).size===p.missing.length&&p.missing.every(f=>suggestionFields.includes(f));
}
export function validShapeAssignments(p,a){
 if(!validShapeRequest(p)||!Array.isArray(a)||a.length>24)return false;
 const seenIndex=new Set(),seenField=new Set();
 for(const x of a){
  if(!keys(x,'field,index')||!Number.isInteger(x.index)||!p.missing.includes(x.field)||seenIndex.has(x.index)||seenField.has(x.field))return false;
  const l=p.lines[x.index];if(!l||['other','unknown_label'].includes(l.kind))return false;
  if(l.label!==null?l.label!==x.field:!((x.field==='email'&&l.kind==='email_like')||(x.field==='phone'&&l.kind==='phone_like')||(x.field==='postal_code'&&l.kind==='postal_like')))return false;
  if(p.lines.filter(v=>v.label===x.field).length>1)return false;
  const kind={email:'email_like',phone:'phone_like',postal_code:'postal_like'}[x.field];
  if(kind&&p.lines.filter(v=>v.kind===kind).length>1)return false;
  seenIndex.add(x.index);seenField.add(x.field);
 }
 return true;
}
export const schema={type:'object',properties:{assignments:{type:'array',items:{type:'object',properties:{index:{type:'integer'},field:{type:'string',enum:suggestionFields}},required:['index','field'],additionalProperties:false}}},required:['assignments'],additionalProperties:false};
export const prompt='Only structural enums and ordinal positions are provided. Suggest only missing fields supported by explicit labels or an unambiguous unlabeled email_like, phone_like or postal_like line. Never guess a name, country, city, province or address from untyped text. Never propose tax or identity. Skip repeated or ambiguous candidates, unknown_label and other. Use no tools. Return only schema JSON.';
