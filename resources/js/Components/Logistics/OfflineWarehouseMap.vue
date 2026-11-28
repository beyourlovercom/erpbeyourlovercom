<script setup lang="ts">
import {computed,ref,watch} from 'vue';
import geography from '../../lib/offline-geography.json';
import {locate,project,warehouseRows,resolveCountry,type Address,type Geography,type GeoResult,type Warehouse,type Item} from '../../lib/offlineWarehouseMap';
const props=defineProps<{address?:Address|null;warehouses:Warehouse[];items:Item[];loading?:boolean;error?:string;verifiedLocations?:Record<string,GeoResult>}>();
const base=geography as Geography;
const cityLoaders=import.meta.glob('../../lib/offline-cities/*.json',{import:'default'});
const cityData=ref<Geography['cities']>({});
const g=computed<Geography>(()=>({...base,cities:{...base.cities,...cityData.value}}));
watch(()=>resolveCountry(props.address?.country,base),async(code,_old,onCleanup)=>{let active=true;onCleanup(()=>{active=false});cityData.value={};if(!code)return;try{const value=await cityLoaders['../../lib/offline-cities/'+code+'.json']?.()||{};if(active)cityData.value=value as Geography['cities'];}catch{/* country fallback */}},{immediate:true});
const recipient=computed(()=>locate(props.address,g.value));
const rows=computed(()=>warehouseRows(recipient.value,props.warehouses,props.items,g.value,props.verifiedLocations));
const mapped=computed(()=>rows.value.filter(w=>w.point));
const selected=ref(''),mode=ref<'world'|'region'|'nearest'>('world');
const view=ref({x:0,y:0,w:1000,h:500}),drag=ref<{x:number;y:number;vx:number;vy:number}|null>(null);
const map=ref<SVGSVGElement|null>(null);
const colors={ok:'#078966',short:'#8799aa',unknown:'#c49342',factory:'#4379bb'};
const labels={ok:'库存参考足够',short:'库存不足',unknown:'库存未知',factory:'中国工厂'};
const precision=(p:GeoResult|null)=>p?.precision==='city'?'城市级':p?.precision==='region'?'区域级':'国家级';
const nearest=computed(()=>rows.value.find(w=>w.km!==null));
function fit(points:{x:number;y:number}[],min=100){
 if(!points.length){view.value={x:0,y:0,w:1000,h:500};return}
 const xs=points.map(p=>p.x),ys=points.map(p=>p.y);
 const width=Math.min(1000,Math.max(min,Math.max(...xs)-Math.min(...xs)+min,(Math.max(...ys)-Math.min(...ys)+min/2)*2));
 const height=width/2;view.value={x:(Math.min(...xs)+Math.max(...xs)-width)/2,y:(Math.min(...ys)+Math.max(...ys)-height)/2,w:width,h:height};
}
function setMode(m:typeof mode.value){
 mode.value=m;if(m==='world'||!recipient.value){view.value={x:0,y:0,w:1000,h:500};return}
 const a=project(recipient.value);
 if(m==='region'){fit([a],260);return}
 const w=rows.value.find(r=>r.code===selected.value&&r.point)||nearest.value;
 fit(w?.point?[a,project(w.point)]:[a],100);
}
function zoom(f:number){const v=view.value,w=Math.min(1000,Math.max(50,v.w*f)),h=w/2;view.value={x:v.x+(v.w-w)/2,y:v.y+(v.h-h)/2,w,h}}
function pointerDown(e:PointerEvent){if(e.button!==0)return;const s=map.value;if(!s)return;s.setPointerCapture(e.pointerId);drag.value={x:e.clientX,y:e.clientY,vx:view.value.x,vy:view.value.y}}
function pointerMove(e:PointerEvent){const d=drag.value,s=map.value;if(!d||!s)return;const scale=view.value.w/s.getBoundingClientRect().width;view.value={...view.value,x:d.vx-(e.clientX-d.x)*scale,y:d.vy-(e.clientY-d.y)*scale}}
function pick(code:string){selected.value=code;setMode('nearest')}
watch(()=>[props.address?.country,props.address?.city,props.address?.province_state],()=>{selected.value='';setMode('world')});
const vb=computed(()=>[view.value.x,view.value.y,view.value.w,view.value.h].join(' '));
const markerSize=computed(()=>view.value.w/180);
</script>
<template>
<section class="overflow-hidden rounded-lg border border-slate-200 text-xs" data-testid="offline-warehouse-map">
 <header class="flex flex-wrap items-center justify-between gap-2 bg-slate-50 p-3"><strong class="text-sm">仓库地理参考 <span class="ml-1 font-normal text-slate-500">离线地图</span></strong>
 <div class="flex gap-1"><button v-for="m in [{id:'world',label:'全球'},{id:'region',label:'收件区域'},{id:'nearest',label:'最近仓'}] as const" :key="m.id" type="button" :aria-pressed="mode===m.id" class="rounded border px-2 py-1" :class="mode===m.id?'bg-white font-semibold text-emerald-700':''" @click="setMode(m.id)">{{m.label}}</button></div></header>
 <div v-if="loading" class="p-3">正在读取收件区域…</div><div v-else-if="error" class="p-3 text-amber-700">{{error}}</div>
 <div v-else class="offline-map-grid">
  <div class="offline-map-canvas relative">
   <div class="absolute left-2 top-2 z-10 grid gap-1"><button type="button" aria-label="放大地图" class="rounded border bg-white px-2 py-1" @click="zoom(.75)">+</button><button type="button" aria-label="缩小地图" class="rounded border bg-white px-2 py-1" @click="zoom(1.33)">−</button><button type="button" aria-label="重置地图" class="rounded border bg-white px-2 py-1" @click="setMode('world')">⌂</button></div>
   <svg ref="map" :viewBox="vb" class="offline-map-svg" aria-label="全球离线地图" @wheel.prevent="zoom($event.deltaY>0?1.2:.83)" @pointerdown="pointerDown" @pointermove="pointerMove" @pointerup="drag=null" @pointercancel="drag=null">
    <g fill="#d6e1dc" stroke="#f7faf9" stroke-width=".5" vector-effect="non-scaling-stroke"><path v-for="(d,i) in base.paths" :key="i" :d="d" fill-rule="evenodd"/></g>
    <g v-if="recipient"><line v-for="w in mapped.filter(r=>r.code===selected)" :key="w.code" :x1="project(recipient).x" :y1="project(recipient).y" :x2="project(w.point!).x" :y2="project(w.point!).y" stroke="#087f68" stroke-dasharray="4 3" vector-effect="non-scaling-stroke"/></g>
    <g v-for="w in mapped" :key="w.code" :transform="'translate('+project(w.point!).x+' '+project(w.point!).y+')'" @pointerdown.stop @click.stop="pick(w.code)"><circle :r="markerSize" :fill="colors[w.stock]" stroke="white" :stroke-width="markerSize/4"/><text :x="markerSize+2" :y="-markerSize/2" :font-size="markerSize*1.8" fill="#243e55" paint-order="stroke" stroke="#fff" :stroke-width="markerSize*.22">{{w.name}}</text><title>{{w.name}} · {{precision(w.point)}} · {{w.km??'未知'}} km</title></g>
    <g v-if="recipient" :transform="'translate('+project(recipient).x+' '+project(recipient).y+')'"><circle :r="markerSize*1.3" fill="#e05649" stroke="white" :stroke-width="markerSize/3"/><text :x="markerSize*1.6" :y="markerSize*1.5" :font-size="markerSize*2" fill="#c63428" paint-order="stroke" stroke="white" :stroke-width="markerSize*.3">收件地 · {{recipient.label}}</text></g>
   </svg>
   <p class="offline-map-attribution absolute bottom-1 left-2 px-1 text-slate-500">Natural Earth · GeoNames（有本地匹配时） · 直线距离估算</p>
  </div>
  <div class="offline-map-distances border-l border-slate-200"><p class="offline-map-distance-title text-slate-500">参考距离 · 从近到远</p><div class="offline-map-distance-list space-y-1 p-2" tabindex="0" aria-label="仓库参考距离列表"><button v-for="w in rows" :key="w.code" type="button" :disabled="!w.point" class="flex w-full items-center justify-between gap-1 rounded px-1 py-1 text-left hover:bg-emerald-50" :class="selected===w.code?'bg-emerald-100':''" @click="pick(w.code)"><span><span class="mr-1 inline-block h-2 w-2 rounded-full" :style="{background:colors[w.stock]}"/>{{w.name}}<small class="block pl-3 text-slate-500">{{w.point?precision(w.point):'位置未知'}} · {{labels[w.stock]}}</small></span><strong class="whitespace-nowrap">{{w.km===null?'—':(w.approximate?'约 ':'')+w.km.toLocaleString()+' km'}}</strong></button></div></div>
 </div>
 <footer class="offline-map-footer border-t px-2 py-1 text-slate-500"><p v-if="!recipient&&!loading&&!error" class="text-amber-700">无法识别国家，暂不计算距离。</p><p v-else-if="recipient">收件区域：{{recipient.label}} · {{precision(recipient)}}（估算）</p><p>直线距离仅供参考，不代表实际仓址、运费或可发货性。</p></footer>
</section>
</template>

<style scoped>
.offline-map-grid{display:grid;grid-template-columns:minmax(0,1fr);align-items:start}
.offline-map-canvas{position:relative;height:256px;min-height:0;background:#ebf2f5;overflow:hidden}
.offline-map-svg{height:100%;width:100%;display:block;cursor:grab;touch-action:none;user-select:none}
.offline-map-svg:active{cursor:grabbing}
.offline-map-attribution{font-size:10px;background:rgba(255,255,255,.82)}
.offline-map-distances{display:flex;flex-direction:column;min-height:0;min-width:0;height:160px;overflow:hidden}
.offline-map-distance-title{flex:none;padding:6px 8px 0}
.offline-map-distance-list{flex:1;min-height:0;overflow-y:auto;overscroll-behavior:contain}
.offline-map-footer{font-size:10px;line-height:1.35}
@media(min-width:1280px){.offline-map-grid{grid-template-columns:minmax(0,1fr) 180px}.offline-map-distances{height:256px}}
</style>
