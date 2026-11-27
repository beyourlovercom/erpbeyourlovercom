export type Point={lat:number;lon:number};
export type GeoResult=Point&{label:string;country:string;precision:'city'|'country'|'region'};
export type Country=Point&{name:string;aliases:string[]};
export type City=Point&{name:string;admin:string;id:string};
export type Geography={paths:string[];countries:Record<string,Country>;cities:Record<string,City[]>};
export type Address={country?:string;city?:string;province_state?:string;postal_code?:string};
export type Warehouse={code:string;name:string;id?:number};
export type Item={sku?:string;productId?:number|null;is_gift?:boolean;quantity:number;availability?:{warehouseId:number;available:number|null}[]};
export const normalize=(s:unknown)=>String(s??'').normalize('NFKD').replace(/\p{Diacritic}/gu,'').toLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').trim();
export function resolveCountry(raw:unknown,g:Geography){
 const n=normalize(raw),iso=n.toUpperCase();if(!n)return '';
 if(g.countries[iso])return iso;
 const aliases:Record<string,string>={'uk':'GB','united states of america':'US','usa':'US','u s a':'US','中国':'CN','美国':'US','英国':'GB','德国':'DE','加拿大':'CA','意大利':'IT'};
 if(aliases[n]&&g.countries[aliases[n]])return aliases[n];
 const hits=Object.entries(g.countries).filter(([,c])=>c.aliases.includes(n));return hits.length===1?hits[0]![0]:'';
}
export function locate(a:Address|null|undefined,g:Geography):GeoResult|null{
 const country=resolveCountry(a?.country,g);if(!country)return null;
 const c=g.countries[country]!;
 const city=normalize(a?.city);const hits=city?(g.cities[country+'|'+city]||[]):[];
 const region=normalize(a?.province_state);const regionHits=region?hits.filter(v=>normalize(v.admin)===region):[];const matches=regionHits.length?regionHits:hits;
 if(matches.length===1){const v=matches[0]!;return {lat:v.lat,lon:v.lon,label:v.name+', '+c.name,country,precision:'city'}}
 return {lat:c.lat,lon:c.lon,label:c.name,country,precision:'country'};
}
// Official warehouse codes. City references follow ERP master names; no street-level claims.
// Unverified sites remain at country reference; they must not be represented as actual warehouse coordinates.
const known:Record<string,Address>={'US-NY':{country:'US',city:'New York City',province_state:'NY'},'US-LA':{country:'US',city:'Los Angeles',province_state:'CA'},'US-SM':{country:'US',city:'Pomona',province_state:'CA'},'US-AMZ':{country:'US'},DE:{country:'DE'},CA:{country:'CA'},FACTORY:{country:'CN'}};
export function warehousePoint(w:Warehouse,g:Geography,verified:Record<string,GeoResult>={}):GeoResult|null{
 if(verified[w.code.toUpperCase()])return verified[w.code.toUpperCase()]!;
 const code=w.code.toUpperCase(),v=known[code];const p=v?locate(v,g):null;
 if(p&&p.precision==='city'&&['US-NY','US-LA'].includes(code))return {...p,precision:'region'};
 return p;
}
export function distanceKm(a:Point,b:Point){
 const r=Math.PI/180,dlat=(b.lat-a.lat)*r,dlon=(b.lon-a.lon)*r;
 const x=Math.sin(dlat/2)**2+Math.cos(a.lat*r)*Math.cos(b.lat*r)*Math.sin(dlon/2)**2;
 return 6371.0088*2*Math.asin(Math.sqrt(Math.min(1,Math.max(0,x))));
}
export function stockState(w:Warehouse,items:Item[]):'factory'|'ok'|'short'|'unknown'{
 if(w.code.toUpperCase()==='FACTORY')return 'factory';
 if(!items.length||!w.id)return 'unknown';
 const grouped=new Map<string,{required:number;available:number|null;unknown:boolean}>();
 for(const [idx,i] of items.entries()){const key=i.productId?'id:'+i.productId:i.sku?'sku:'+i.sku.trim().toUpperCase():'line:'+idx;const available=i.availability?.find(a=>a.warehouseId===w.id)?.available;const v=grouped.get(key)||{required:0,available:null,unknown:false};v.required+=i.quantity;if(typeof available==='number'&&Number.isFinite(available))v.available=v.available===null?available:Math.min(v.available,available);else v.unknown=true;if(i.is_gift)v.unknown=true;grouped.set(key,v);}
 const checks=[...grouped.values()];if(checks.some(x=>x.available!==null&&x.available<x.required))return 'short';
 return checks.every(x=>!x.unknown&&x.available!==null&&x.available>=x.required)?'ok':'unknown';
}
export const project=(p:Point)=>({x:(p.lon+180)*1000/360,y:(90-p.lat)*500/180});
export function warehouseRows(a:GeoResult|null,warehouses:Warehouse[],items:Item[],g:Geography,verified:Record<string,GeoResult>={}){
 return warehouses.filter(w=>w.code.toUpperCase()!=='007').map(w=>{
  const point=warehousePoint(w,g,verified);return {...w,point,stock:stockState(w,items),km:a&&point?Math.round(distanceKm(a,point)):null,approximate:!a||!point||a.precision!=='city'||point.precision!=='city'};
 }).sort((x,y)=>(x.km??Infinity)-(y.km??Infinity));
}
