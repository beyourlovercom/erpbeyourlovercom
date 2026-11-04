import { describe, expect, it } from 'vitest'
import { allocationGuidance, type GuidanceOrder, type GuidanceWarehouse } from './allocationGuidance'

const warehouses:GuidanceWarehouse[] = [
  {id:1,code:'US-NY',name:'美东纽约仓',country_code:'US',can_auto_allocate:true},
  {id:2,code:'US-SM',name:'美国3世迈仓',country_code:'US',can_auto_allocate:true},
  {id:3,code:'US-LA',name:'美西MorePlus',country_code:'US',can_auto_allocate:true},
]
const stock = (warehouse_id:number, quantity_available:number|null, state='confirmed', auto=true) =>
  ({warehouse_id,quantity_available,inventory_state:state,can_auto_allocate:auto})
const singlePlan = (code:string, name:string) => ({
  plan_type:'single',is_split:false,label:`整单${name}发货`,
  segments:[{source_type:'warehouse',source_code:code,warehouse_name:name}],
})
const order = (overrides:Partial<GuidanceOrder> = {}):GuidanceOrder => ({
  country:'US',domestic_reject_reason:null,
  us_fulfillment_zone:{status:'matched',warehouse_priority:['US-NY','US-SM']},
  items:[{product_id:11,quantity:1,is_gift:false,availability:[stock(1,4),stock(2,3)]}],
  allocation_decision:{
    can_use_recommendation:false,recommended_plan:null,
    plans:[singlePlan('US-NY','美东纽约仓')],
    manual_reasons:['产品运输重量数据不足'],reason_code:'inventory_only_manual_warehouse_selection',
  },...overrides,
})
describe('evidence-aware allocation guidance V1.2',()=>{
  it('separates verified stock candidate from complete-cost recommendation',()=>{
    const result=allocationGuidance(order(),warehouses)
    expect(result.kind).toBe('candidate')
    expect(result.group).toBe('recommendation')
    expect(result.title).toContain('美东纽约仓')
    expect(result.detail).toContain('产品运输重量数据不足')
    expect(result.status).not.toContain('可确认分仓')
  })
  it('shows multiple verified, eligible warehouse plans as comparison, without ranking by missing cost',()=>{
    const candidate=order()
    candidate.allocation_decision.plans.push(singlePlan('US-SM','美国3世迈仓'))
    const result=allocationGuidance(candidate,warehouses)
    expect(result.kind).toBe('compare')
    expect(result.detail).toContain('美东纽约仓 / 美国3世迈仓')
  })
  it('only uses green ready state when backend has a complete recommendation',()=>{
    const candidate=order()
    candidate.allocation_decision.can_use_recommendation=true
    candidate.allocation_decision.recommended_plan=singlePlan('US-NY','美东纽约仓')
    expect(allocationGuidance(candidate,warehouses).kind).toBe('ready')
    expect(allocationGuidance(candidate,warehouses).status).toBe('可确认分仓')
  })
  it('does not promote unverified snapshot or known stock to an executable suggestion',()=>{
    const candidate=order({items:[{product_id:11,quantity:1,is_gift:false,availability:[stock(1,10,'pending',false)]}]})
    expect(allocationGuidance(candidate,warehouses).kind).toBe('stock_review')
    expect(allocationGuidance(candidate,warehouses).group).toBe('inventory')
  })
  it('reports verified priority warehouse shortfall, without claiming the whole country is out of stock',()=>{
    const candidate=order({items:[{product_id:11,quantity:2,is_gift:false,availability:[stock(1,1)]}]})
    candidate.allocation_decision.plans=[]
    const result=allocationGuidance(candidate,warehouses)
    expect(result.kind).toBe('stock_short')
    expect(result.title).toContain('美东纽约仓')
    expect(result.detail).toContain('查看其他仓库')
  })
  it('requires whole-order coverage, including repeated SKU quantities',()=>{
    const candidate=order({
      items:[
        {product_id:11,quantity:1,is_gift:false,availability:[stock(1,1)]},
        {product_id:11,quantity:1,is_gift:false,availability:[stock(1,1)]},
      ],
    })
    expect(allocationGuidance(candidate,warehouses).kind).toBe('stock_short')
  })
  it('keeps gift, address and rejected orders out of green recommendations',()=>{
    const base=order()
    expect(allocationGuidance({...base,items:[{...base.items[0],is_gift:true}]},warehouses).group).toBe('gift')
    expect(allocationGuidance({...base,us_fulfillment_zone:{status:'unmatched',warehouse_priority:[]}},warehouses).group).toBe('manual')
    expect(allocationGuidance({...base,domestic_reject_reason:'商务无货'},warehouses).title).toContain('商务驳回')
    expect(allocationGuidance(base,warehouses,true).kind).toBe('processed')
  })
})
