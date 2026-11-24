import type { ColDef } from 'ag-grid-community';
import type { LogisticsRow, LogisticsScope } from '#/api/erp-logistics';
import {logisticsQueryCapabilities,isLogisticsQueue} from '../../api/logistics-query-contract';
export {logisticsQueryCapabilities} from '../../api/logistics-query-contract';

type Field = Exclude<keyof LogisticsRow,'items'|'key'|'order_id'|'shipment_id'|'fulfillment_parent_id'>;
type Column = {key:Field;label:string;width:number};
export const logisticsFields:readonly Column[] = [
  {key:'order_number',label:'前台订单号',width:185},
  {key:'review_status',label:'审核状态',width:120},
  {key:'tracking',label:'物流单号',width:185},
  {key:'carrier',label:'物流商',width:128},
  {key:'service',label:'运输 / 服务类型',width:150},
  {key:'shipment_status',label:'发货状态',width:120},
  {key:'order_date',label:'下单日期',width:130},
  {key:'status',label:'订单状态',width:130},
  {key:'fulfillment_suffix',label:'A/B/C 子单',width:100},
  {key:'sku',label:'SKU',width:170},
  {key:'actual_sku',label:'实际 SKU',width:170},
  {key:'qty',label:'数量',width:86},
  {key:'warehouse_code',label:'发货仓',width:134},
  {key:'oms_status',label:'OMS 状态',width:120},
  {key:'oms_order_number',label:'OMS 单号',width:185},
  {key:'line',label:'#',width:80},
  {key:'paid_date',label:'成交日期',width:130},
  {key:'source_type',label:'订单来源',width:130},
  {key:'order_kind',label:'订单类型',width:120},
  {key:'warehouse_route',label:'所属分仓',width:160},
  {key:'warehouse_name',label:'实际仓库名称',width:150},
  {key:'material_full_name',label:'物料全称',width:310},
  {key:'has_liquid',label:'是否带液体',width:110},
  {key:'amount',label:'金额 / 币种',width:140},
  {key:'export_status',label:'导出状态',width:200},
  {key:'provider_reference',label:'物流商单号',width:185},
  {key:'country',label:'国家 / 地区',width:130},
  {key:'tracking_sync_status',label:'官网同步状态',width:140},
  {key:'tracking_sync_reason',label:'官网同步说明',width:240},
  {key:'tracking_sync_verified_at',label:'官网核验时间',width:190},
  {key:'shipped_at',label:'发货时间',width:190},
  {key:'reviewer_name',label:'审核人',width:120},
  {key:'reviewed_at',label:'审核时间（北京时间）',width:190},
  {key:'dispatch_status',label:'仓库处理状态',width:200},
  {key:'exception_type',label:'异常类型',width:140},
  {key:'procurement_number',label:'采购单号',width:160},
  {key:'procurement_status',label:'采购状态',width:130},
  {key:'original_number',label:'原始订单号',width:170},
  {key:'original_status',label:'原始状态',width:130},
  {key:'order_kind_label',label:'订单类型说明',width:140},
  {key:'us_zone',label:'美国分区',width:135},
  {key:'warehouse_priority',label:'优先仓',width:170},
  {key:'proposed_warehouse',label:'推荐仓库',width:190},
  {key:'proposed_cost',label:'预计成本',width:140},
  {key:'allocation_status',label:'分仓状态 / 系统建议',width:185},
  {key:'has_gift',label:'含礼品',width:110},
  {key:'owner_label',label:'负责人',width:135},
  {key:'rejection_state',label:'驳回状态',width:125},
  {key:'rejection_type',label:'驳回来源',width:135},
  {key:'rejected_at',label:'驳回时间',width:180},
  {key:'rejected_by',label:'驳回人员',width:125},


];
const base:Field[]=['order_number','order_date','status','sku','actual_sku','qty','warehouse_route','warehouse_code','country'];
export function logisticsPageNamespace(scope:LogisticsScope,view:string,warehouse:string):string {
  if(scope==='allocation')return 'grid.erp2.logistics.allocation.v1';
  if(isLogisticsQueue(scope))return `grid.erp2.logistics.queue.${scope}.v1`;
  if(!/^[a-z0-9-]+$/.test(view)|| (warehouse&&!/^[a-z0-9-]+$/.test(warehouse)))throw Error('INVALID_LOGISTICS_PAGE_ID');
  return scope==='domestic'?`grid.erp2.logistics.domestic.${view}.v1`:
    `grid.erp2.logistics.overseas.${warehouse||'all-warehouses'}.${view}.v1`;
}
export function logisticsDefaultFields(scope:LogisticsScope,view:string):Field[] {
  const lead:Field[]=isLogisticsQueue(scope)?['tracking','carrier','service','shipment_status','shipped_at']:scope==='allocation'?['allocation_status','proposed_warehouse']:view==='review'||view==='stockout'?['review_status']:
    ['pending','today','today-4px','today-weship','issued','shipped'].includes(view)?['tracking','carrier','service','shipment_status']:[];
  const tail:Field[]=scope==='overseas'?['fulfillment_suffix','review_status','oms_status','oms_order_number','tracking','carrier','service','shipment_status']:
    scope==='domestic'?['review_status','carrier','service','tracking','shipment_status']:[];
  const extra:Field[]=scope==='domestic'?['paid_date','has_liquid','export_status','provider_reference','tracking_sync_status']:
    scope==='overseas'?['dispatch_status','tracking_sync_status','shipped_at','reviewer_name','reviewed_at']:
    scope==='allocation'?['rejection_state','rejection_type','rejected_by','rejected_at','proposed_cost','us_zone','warehouse_priority','has_gift','owner_label','amount','source_type']:[];
  if(view==='exceptions')extra.unshift('exception_type');
  if(view==='outsource')extra.unshift('procurement_number','procurement_status');
  return [...new Set<Field>(['order_number',...lead,...base,...tail,...extra])];
}
export function logisticsOrderColumns(scope:LogisticsScope,view:string):ColDef<Record<string,unknown>>[] {
  const visible=logisticsDefaultFields(scope,view);
  const fields=[...visible,...logisticsFields.map(c=>c.key).filter(key=>!visible.includes(key))];
  const {sortKeys}=logisticsQueryCapabilities(scope,view);
  return [...fields.map(key=>{
    const c=logisticsFields.find(f=>f.key===key)!;
    return {colId:key,field:key,headerName:c.label,width:c.width,hide:!visible.includes(key),
      resizable:true,sortable:sortKeys.includes(key),pinned:key==='order_number'?'left' as const:undefined,
      lockPinned:key==='order_number',tooltipField:key,valueFormatter:(p:{value:unknown})=>p.value==null||p.value===''?'—':(key==='has_liquid'||key==='has_gift')?(p.value===true?'是':p.value===false?'否':'—'):String(p.value)};
  }),{colId:'action',headerName:'操作',width:108,pinned:'right',lockPinned:true,lockVisible:true,
    sortable:false,resizable:false,valueGetter:()=> '查看详情'}];
}
