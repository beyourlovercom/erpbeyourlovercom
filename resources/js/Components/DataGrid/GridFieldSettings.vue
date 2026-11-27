<script setup lang="ts">
import {computed,ref,onMounted,onBeforeUnmount,nextTick} from 'vue';
import type {ColumnState} from 'ag-grid-community';
import {changeDraftVisibility,makeColumnDraft,moveDraft,type GridFieldMeta} from './gridFieldCatalog';
const props=defineProps<{catalog:GridFieldMeta[];state:ColumnState[];requiredIds:string[];left:number;right:number;defaultLeft:number;defaultRight:number;defaultWidths?:Record<string,number|undefined>;defaultOrder?:string[]}>();
const emit=defineEmits<{(e:'cancel'):void;(e:'save',value:{columns:ColumnState[];left:number;right:number}):void}>();
const draft=ref(makeColumnDraft(props.state,props.catalog,props.requiredIds));
const left=ref(props.left),right=ref(props.right),query=ref('');
const fields=computed(()=>new Map(props.catalog.map(f=>[f.id,f])));
const groups=computed(()=>{const out=new Map<string,GridFieldMeta[]>();const q=query.value.trim().toLowerCase();for(const f of props.catalog){if(!draft.value.some(c=>c.colId===f.id)||!(f.label+' '+f.id+' '+f.category).toLowerCase().includes(q))continue;const a=out.get(f.category)||[];a.push(f);out.set(f.category,a);}return [...out.entries()];});
const selected=computed(()=>draft.value.filter(c=>!c.hide));
const checked=(id:string)=>draft.value.some(c=>c.colId===id&&!c.hide);
function toggle(id:string,show:boolean){draft.value=changeDraftVisibility(draft.value,id,show,props.requiredIds);}
function toggleGroup(group:GridFieldMeta[],show:boolean){for(const f of group)toggle(f.id,show);}
const dialog=ref<HTMLElement>();let returnFocus:HTMLElement|null=null;
function onKey(e:KeyboardEvent){if(e.key==='Escape'){e.preventDefault();e.stopPropagation();emit('cancel');return}if(e.key!=='Tab'||!dialog.value)return;const els=Array.from(dialog.value.querySelectorAll<HTMLElement>('button:not(:disabled),input:not(:disabled)'));if(!els.length)return;const a=els[0],b=els[els.length-1];if(e.shiftKey&&document.activeElement===a){e.preventDefault();b.focus()}else if(!e.shiftKey&&document.activeElement===b){e.preventDefault();a.focus()}}
onMounted(()=>{returnFocus=document.activeElement as HTMLElement;document.addEventListener('keydown',onKey,true);nextTick(()=>dialog.value?.querySelector('input')?.focus())});onBeforeUnmount(()=>{document.removeEventListener('keydown',onKey,true);returnFocus?.focus()});
let dragged='';
function start(e:DragEvent,id:string){dragged=id;e.dataTransfer?.setData('text/plain',id);if(e.dataTransfer)e.dataTransfer.effectAllowed='move';}
function drop(e:DragEvent,target:string){e.preventDefault();const from=dragged||e.dataTransfer?.getData('text/plain');dragged='';if(from)draft.value=moveDraft(draft.value,from,target);}
function move(id:string,delta:number){const a=selected.value.findIndex(c=>c.colId===id),target=selected.value[a+delta];if(target)draft.value=moveDraft(draft.value,id,target.colId);}
function reset(){draft.value=(props.defaultOrder??props.catalog.map(f=>f.id)).map(id=>props.catalog.find(f=>f.id===id)).filter((f):f is GridFieldMeta=>!!f).map(f=>({colId:f.id,hide:props.requiredIds.includes(f.id)?false:!f.defaultVisible,width:props.defaultWidths?.[f.id]}));left.value=props.defaultLeft;right.value=props.defaultRight;}
function save(){emit('save',{columns:draft.value,left:Number(left.value)||0,right:Number(right.value)||0});}
</script>
<template>
 <Teleport to="body"><div class="grid-v2-backdrop" @click.self="emit('cancel')">
 <section ref="dialog" class="grid-v2-dialog" role="dialog" aria-modal="true" aria-label="分类字段设置" data-testid="grid-v2-settings">
  <header><div><strong>表格列设置</strong><small>选择业务字段，拖动调整显示顺序</small></div><button type="button" aria-label="关闭" @click="emit('cancel')">×</button></header>
  <div class="grid-v2-body">
   <section class="grid-v2-panel"><h3>可选字段</h3><input v-model="query" class="grid-v2-search" placeholder="搜索字段名称或分类" aria-label="搜索字段"/>
    <div class="grid-v2-scroll"><div v-for="[category,items] in groups" :key="category" class="grid-v2-group"><div class="grid-v2-group-head"><strong>{{category}}</strong><span><button type="button" @click="toggleGroup(items,true)">全选</button><button type="button" @click="toggleGroup(items,false)">取消</button></span></div>
    <label v-for="f in items" :key="f.id" class="grid-v2-field"><input type="checkbox" :checked="checked(f.id)" :disabled="requiredIds.includes(f.id)" @change="toggle(f.id,($event.target as HTMLInputElement).checked)"/>{{f.label}}<small v-if="requiredIds.includes(f.id)">必选</small></label></div><p v-if="!groups.length">没有匹配字段</p></div>
   </section>
   <section class="grid-v2-panel"><h3>已选字段（{{selected.length}}）</h3><small>拖动排序，或使用上下箭头</small><div class="grid-v2-scroll">
    <div v-for="c in selected" :key="c.colId" class="grid-v2-selected" draggable="true" @dragstart="start($event,c.colId)" @dragover.prevent @drop="drop($event,c.colId)"><span class="grid-v2-grip">⋮⋮</span><span class="grid-v2-name">{{fields.get(c.colId)?.label}}</span><button type="button" aria-label="上移" @click="move(c.colId,-1)">↑</button><button type="button" aria-label="下移" @click="move(c.colId,1)">↓</button><button type="button" :disabled="requiredIds.includes(c.colId)" aria-label="移除字段" @click="toggle(c.colId,false)">×</button></div>
   </div><div class="grid-v2-pins"><label>左固定 <input v-model.number="left" type="number" min="0" :max="Math.max(0,selected.length-1)"/></label><label>右固定 <input v-model.number="right" type="number" min="0" :max="Math.max(0,selected.length-1)"/></label></div></section>
  </div>
  <footer><button type="button" @click="reset">恢复默认</button><div><button type="button" @click="emit('cancel')">取消</button><button class="grid-v2-primary" type="button" @click="save">保存设置</button></div></footer>
 </section></div></Teleport>
</template>
<style scoped>

.grid-v2-backdrop{position:fixed;inset:0;z-index:10010;background:rgba(15,23,42,.62);display:flex;align-items:center;justify-content:center;padding:20px}
.grid-v2-dialog{color-scheme:light;width:min(1000px,96vw);height:min(740px,92vh);background:#ffffff;color:#172033;display:flex;flex-direction:column;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 24px 80px rgba(15,23,42,.35);overflow:hidden;font-size:14px;line-height:1.5}
.grid-v2-dialog header,.grid-v2-dialog footer{display:flex;align-items:center;justify-content:space-between;padding:16px 22px;background:#fff;border-bottom:1px solid #dce2eb;flex-shrink:0}
.grid-v2-dialog header strong{font-size:20px;font-weight:700}.grid-v2-dialog header small{display:block;color:#475569;margin-top:4px;font-size:13px}
.grid-v2-body{min-height:0;flex:1;display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:16px 20px;background:#f8fafc}
.grid-v2-panel{background:#fff;color:#172033;border:1px solid #cbd5e1;border-radius:8px;min-height:0;min-width:0;padding:14px;display:flex;flex-direction:column;gap:10px}
.grid-v2-panel h3{font-size:16px;font-weight:700;margin:0}.grid-v2-panel>small{color:#475569;font-size:12px}
.grid-v2-search{width:100%;border:1px solid #94a3b8;border-radius:6px;padding:9px 11px;background:#fff;color:#172033;font-size:14px}.grid-v2-search::placeholder{color:#64748b}
.grid-v2-scroll{flex:1;min-height:0;overflow:auto;scrollbar-gutter:stable}
.grid-v2-group{margin-bottom:12px}.grid-v2-group-head{display:flex;justify-content:space-between;align-items:center;background:#e9eef9;color:#243653;padding:7px 9px;border-radius:5px;font-size:14px}
.grid-v2-field{display:flex;align-items:center;gap:10px;min-height:36px;padding:6px 9px;color:#172033;cursor:pointer}.grid-v2-field:hover{background:#f1f5f9}.grid-v2-field input{width:16px;height:16px;accent-color:#4f46e5;flex-shrink:0}.grid-v2-field small{margin-left:auto;color:#475569;font-size:12px}
.grid-v2-selected{display:flex;gap:8px;align-items:center;min-height:38px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:5px;margin-bottom:6px;background:#fff;color:#172033}.grid-v2-selected:hover{background:#f1f5f9}.grid-v2-grip{cursor:grab;color:#475569;font-weight:700}.grid-v2-name{flex:1;min-width:0;overflow-wrap:anywhere}
.grid-v2-pins{display:flex;gap:12px;color:#172033;font-size:13px}.grid-v2-pins input{width:60px;border:1px solid #94a3b8;border-radius:4px;padding:5px;background:#fff;color:#172033}
.grid-v2-dialog button{border:1px solid #94a3b8;border-radius:6px;padding:5px 10px;background:#fff;color:#243653;font-size:13px;cursor:pointer}.grid-v2-dialog button:hover:not(:disabled){background:#edf2f7}.grid-v2-dialog button:disabled{opacity:.5;cursor:not-allowed}.grid-v2-dialog button:focus-visible,.grid-v2-dialog input:focus-visible{outline:2px solid #4f46e5;outline-offset:2px}
.grid-v2-dialog footer{border-bottom:0;border-top:1px solid #cbd5e1}.grid-v2-dialog footer div{display:flex;gap:10px}.grid-v2-dialog footer button{padding:8px 16px;font-size:14px}.grid-v2-dialog .grid-v2-primary{background:#4f46e5;color:#fff;border-color:#4f46e5;font-weight:600}.grid-v2-dialog .grid-v2-primary:hover{background:#4338ca}
@media(max-width:640px){.grid-v2-backdrop{padding:6px}.grid-v2-dialog{width:100%;height:96vh}.grid-v2-body{gap:6px;padding:6px}.grid-v2-panel{padding:6px;gap:6px}.grid-v2-dialog header,.grid-v2-dialog footer{padding:10px}.grid-v2-dialog button{padding:4px 6px}.grid-v2-pins{gap:5px;flex-wrap:wrap}.grid-v2-group-head{flex-wrap:wrap}}

</style>


