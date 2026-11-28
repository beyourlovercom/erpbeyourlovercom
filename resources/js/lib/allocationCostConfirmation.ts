import axios from 'axios'
export type CostGroup = {warehouse_code:string;warehouse_name:string;carrier:string|null;service:string|null;status:string;reason:string|null;amount_cny:number|null;native_totals:Record<string,number>|null;measurement:{weight_kg:number;dimensions_cm:number[]|null;basis:string}|null;rate?:{service?:string;version_number?:number;version_status?:string;extra_item_usd?:number|null}|null}
export type AllocationCost = {confirmation_token?:string;confirmation_status?:string;confirmed_by?:number;confirmed_at?:string;requires_confirmation:boolean;known_subtotal_cny:number|null;total_cny:number|null;known_group_count:number;unknown_group_count:number;partial_group_count:number;groups:CostGroup[];factory_default_reference?:{amount_cny:number|null;native_totals:Record<string,number>|null;service:string|null}|null}
export async function fetchAllocationCost(orderId:number,payload:Record<string,unknown>,signal?:AbortSignal):Promise<AllocationCost>{
  const {data}=await axios.post<AllocationCost>('/logistics/orders/'+orderId+'/fulfillment-allocation/cost-preview',payload,{signal,headers:{Accept:'application/json'}})
  if(!Array.isArray(data?.groups)||typeof data.confirmation_token!=='string')throw new Error('费用预估返回无效')
  return data
}
export function costMoney(amount:number|null|undefined){return amount==null?'—':'¥'+Number(amount).toFixed(2)}
export function costNative(t:Record<string,number>|null|undefined){return Object.entries(t??{}).map(([c,v])=>c+' '+Number(v).toFixed(2)).join(' + ')}
