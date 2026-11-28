import type { ColumnState } from 'ag-grid-community'
export type GridFieldMeta = {id:string;label:string;category:string;defaultVisible:boolean;required:boolean}
export type FieldGroups=Readonly<Record<string,readonly string[]>>
type Field={colId?:string;field?:string;headerName?:string;hide?:boolean|null;initialHide?:boolean;lockVisible?:boolean}
export function existingFieldCatalog(columns:readonly Field[],groups:FieldGroups,requiredIds:readonly string[]=[]):GridFieldMeta[]{
 const seen=new Set<string>()
 return columns.flatMap(c=>{const id=c.colId??c.field;if(!id||seen.has(id))return [];seen.add(id);const required=requiredIds.includes(id)||c.lockVisible===true;return [{id,label:c.headerName??id,category:Object.entries(groups).find(([,ids])=>ids.includes(id))?.[0]??'其他字段',defaultVisible:required||!(c.hide??c.initialHide),required}]})
}
export function makeColumnDraft(state:readonly ColumnState[],catalog:readonly GridFieldMeta[],requiredIds:readonly string[]):ColumnState[]{
 const allowed=new Map(catalog.map(c=>[c.id,c]));const seen=new Set<string>();const out:ColumnState[]=[]
 for(const c of state){const field=allowed.get(c.colId);if(!field||seen.has(c.colId))continue;seen.add(c.colId);out.push({...c,hide:field.required||requiredIds.includes(c.colId)?false:!!c.hide})}
 for(const f of catalog)if(!seen.has(f.id))out.push({colId:f.id,hide:!f.defaultVisible})
 return out
}
export function changeDraftVisibility(state:ColumnState[],id:string,show:boolean,requiredIds:readonly string[]):ColumnState[]{
 return state.map(c=>c.colId===id?{...c,hide:requiredIds.includes(id)?false:!show}:c)
}
export function moveDraft(state:ColumnState[],source:string,target:string):ColumnState[]{
 const next=[...state],a=next.findIndex(c=>c.colId===source),b=next.findIndex(c=>c.colId===target)
 if(a<0||b<0||a===b)return next;const [m]=next.splice(a,1);if(m)next.splice(b,0,m);return next
}
