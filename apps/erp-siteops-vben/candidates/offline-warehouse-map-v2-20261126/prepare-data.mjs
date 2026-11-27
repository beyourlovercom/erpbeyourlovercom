import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=path.dirname(fileURLToPath(import.meta.url));
const data=JSON.parse(fs.readFileSync(path.join(root,'data/countries.geojson'),'utf8'));
const point=(lon,lat)=>[(lon+180)*1000/360,(90-lat)*500/180].map(x=>Number(x.toFixed(2)));
const paths=[],countries={};
const norm=s=>String(s??'').normalize('NFKD').replace(/\p{Diacritic}/gu,'').toLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').trim();
for(const f of data.features){
 const p=f.properties,g=f.geometry;
 const polygons=g.type==='Polygon'?[g.coordinates]:g.type==='MultiPolygon'?g.coordinates:[];
 const d=polygons.map(poly=>poly.map(r=>'M'+r.map(c=>point(...c).join(',')).join('L')+'Z').join('')).join('');
 if(d)paths.push(d);
 const code=[p.ISO_A2_EH,p.ISO_A2,p.WB_A2].find(v=>/^[A-Z]{2}$/.test(v));
 if(!code||!Number.isFinite(p.LABEL_X)||!Number.isFinite(p.LABEL_Y))continue;
 countries[code]={name:p.ADMIN||p.NAME,lat:p.LABEL_Y,lon:p.LABEL_X,aliases:[p.NAME,p.ADMIN,p.NAME_LONG,p.FORMAL_EN,p.NAME_ZH,p.NAME_ZHT,p.ISO_A3,p.ISO_A3_EH].map(norm).filter(Boolean)};
}
const cities={};
const file=path.join(root,'data/cities15000.txt');
if(fs.existsSync(file)){
 for(const line of fs.readFileSync(file,'utf8').split(/\r?\n/)){
  const a=line.split('\t'),code=a[8],lat=Number(a[4]),lon=Number(a[5]);
  if(!countries[code]||!Number.isFinite(lat)||!Number.isFinite(lon)||!a[1])continue;
  for(const name of new Set([a[1],a[2],...a[3].split(',')].map(norm).filter(s=>s.length>1))){
   const k=code+'|'+name, entry={name:a[1],lat,lon,admin:a[10],id:a[0]};
   const rows=cities[k]??(cities[k]=[]);
   if(!rows.some(r=>r.id===entry.id))rows.push(entry);
  }
 }
}
const out={paths,countries,cities:{},source:{boundaries:'Natural Earth v5.1.2 public domain',cities:fs.existsSync(file)?'GeoNames cities15000 CC BY 4.0':'not loaded'}};
fs.writeFileSync(path.join(root,'src/lib/offline-geography.json'),JSON.stringify(out));
const byCountry={};for(const [k,v] of Object.entries(cities)){const code=k.slice(0,2);(byCountry[code]??={})[k]=v;}
const dir=path.join(root,'src/lib/offline-cities');fs.mkdirSync(dir,{recursive:true});for(const [code,values] of Object.entries(byCountry))fs.writeFileSync(path.join(dir,code+'.json'),JSON.stringify(values));
const warehouseCityKeys=['US|new york city','US|los angeles','US|pomona'];out.cities=Object.fromEntries(warehouseCityKeys.filter(k=>cities[k]).map(k=>[k,cities[k]]));fs.writeFileSync(path.join(root,'src/lib/offline-geography.json'),JSON.stringify(out));
console.log(JSON.stringify({paths:paths.length,countries:Object.keys(countries).length,cityAliases:Object.keys(cities).length,cityFiles:Object.keys(byCountry).length}));
