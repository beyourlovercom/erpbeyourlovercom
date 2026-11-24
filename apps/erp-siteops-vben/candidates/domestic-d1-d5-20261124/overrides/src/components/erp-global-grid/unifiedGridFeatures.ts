export const gridFeatureKeys = ['search','density','maximize','copy','selection','filter','sort','pageSize'] as const;
export type GridFeature = typeof gridFeatureKeys[number];
export type GridFeatures = Partial<Record<GridFeature, boolean>>;
export const gridFeatureLabels: Record<GridFeature,string> = {
  search:'全库搜索',density:'密度切换',maximize:'最大化',copy:'单元格文字选择 / 复制',selection:'批量选择',
  filter:'列筛选',sort:'列排序',pageSize:'每页条数设置',
};
export function sanitizeGridFeatures(raw:unknown): GridFeatures {
  const result:GridFeatures={};
  if(raw && typeof raw==='object') for(const key of gridFeatureKeys){
    const value=(raw as Record<string,unknown>)[key];
    if(typeof value==='boolean')result[key]=value;
  }
  return result;
}
export function gridFeatureEnabled(key:GridFeature, capabilities:GridFeatures, preference:GridFeatures):boolean {
  return capabilities[key]===true && preference[key]!==false;
}
