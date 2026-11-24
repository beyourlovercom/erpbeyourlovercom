import type { LogisticsScope } from './erp-logistics';
export const originalQueueRoutes={
  awaiting:'/logistics/awaiting-tracking',transit:'/logistics/in-transit',exceptions:'/logistics/exceptions',
} as const;
export type LogisticsQueue=keyof typeof originalQueueRoutes;
export const isLogisticsQueue=(scope:string):scope is LogisticsQueue=>Object.prototype.hasOwnProperty.call(originalQueueRoutes,scope);

export const domesticFilterMap = {
  status:'status',warehouse_route:'warehouse_route',review_status:'review',sku:'sku',carrier:'carrier',
  service:'service',shipment_status:'shipment_status',tracking:'tracking',country:'destination',
} as const;
export const overseasFilterMap={carrier:'carrier',tracking:'tracking'} as const;
export type LogisticsFilterKey=keyof typeof domesticFilterMap;
export type LogisticsSortKey='tracking'|'shipped_at'|'line'|'carrier';
export function logisticsQueryCapabilities(scope:LogisticsScope,view:string) {
  const filterMap:Partial<Record<LogisticsFilterKey,string>>=scope==='overseas'?overseasFilterMap:
    scope==='domestic'&&view==='all'?domesticFilterMap:{};
  return {filterMap,filterKeys:Object.keys(filterMap),sortKeys:scope==='overseas'?['tracking','shipped_at','line']:isLogisticsQueue(scope)?['tracking','shipped_at','carrier']:[],selection:scope==='domestic'||scope==='overseas'};
}
