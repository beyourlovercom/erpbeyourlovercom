/**
 * Read-only, evidence-aware allocation advice for the list view.
 * This never grants automatic-allocation permission or bypasses server submission gates.
 */
type Stock = { warehouse_id: number; inventory_state: string; can_auto_allocate: boolean; quantity_available: number|null }
type Item = { product_id: number|null; quantity: number; is_gift: boolean; availability: Stock[] }
type Segment = { source_type: string; source_code: string; warehouse_name: string }
type Plan = { plan_type: string; is_split: boolean; label: string; segments: Segment[] }
export type GuidanceOrder = {
  country: string|null; domestic_reject_reason?: string|null
  us_fulfillment_zone?: { status: string; warehouse_priority: string[] }|null
  items: Item[]
  allocation_decision: {
    can_use_recommendation: boolean; recommended_plan: Plan|null
    plans: Plan[]; manual_reasons: string[]; reason_code: string
  }
}
export type GuidanceWarehouse = {
  id: number; code: string; name: string; country_code: string|null; can_auto_allocate: boolean
}
export type GuidanceKind = 'ready'|'candidate'|'compare'|'stock_review'|'stock_short'|'manual'|'gift'|'processed'
export type GuidanceGroup = 'recommendation'|'inventory'|'manual'|'gift'|'processed'
export type AllocationGuidance = {
  kind: GuidanceKind; group: GuidanceGroup; title: string; detail: string; status: string
}
const canon = (code:string) => { const value = code.trim().toUpperCase(); return value === 'US-SJ' ? 'US-SM' : value }
const response = (kind:GuidanceKind, group:GuidanceGroup, title:string, detail:string, status:string):AllocationGuidance =>
  ({kind,group,title,detail,status})

function requirementsFor(items:Item[]):Map<number,number>|null {
  const requirements = new Map<number,number>()
  for (const item of items) {
    if (!item.product_id || item.quantity <= 0 || !Number.isFinite(item.quantity)) return null
    requirements.set(item.product_id, (requirements.get(item.product_id) ?? 0) + item.quantity)
  }
  return requirements.size ? requirements : null
}
function warehouseCoverage(order:GuidanceOrder, warehouse:GuidanceWarehouse, requirements:Map<number,number>) {
  const stocks = [...requirements].map(([productId, required]) => {
    const rows = order.items.filter(item => item.product_id === productId)
    const all = rows.map(item => item.availability.find(stock => stock.warehouse_id === warehouse.id))
    const known = all.length === rows.length && all.every(stock => stock?.quantity_available != null)
    const quantity = known ? Math.min(...all.map(stock => Number(stock!.quantity_available))) : null
    const verified = known && all.every(stock =>
      stock?.inventory_state === 'confirmed' && stock.can_auto_allocate === true)
    return { required, quantity, verified }
  })
  return {
    known: stocks.every(stock => stock.quantity !== null),
    enough: stocks.every(stock => stock.quantity !== null && stock.quantity >= stock.required),
    verified: warehouse.can_auto_allocate && stocks.every(stock => stock.verified),
  }
}

/** A geographic priority is never proof of stock; incomplete costs never become a green recommendation. */
export function allocationGuidance(
  order:GuidanceOrder, warehouses:GuidanceWarehouse[], processed = false,
):AllocationGuidance {
  if (processed) return response('processed','processed','已分仓','已保存 Vita 的最终分仓决定','已处理')
  if (order.domestic_reject_reason) return response('manual','manual','商务驳回待重分仓',order.domestic_reject_reason,'人工处理')
  if (order.items.some(item => item.is_gift)) return response('gift','gift','礼品 SKU 待确认','请在方案中选择实际礼品与可履约仓库','礼品待确认')
  if (!order.country || !requirementsFor(order.items))
    return response('manual','manual','地址或 SKU 信息异常','国家、商品绑定或数量不完整，请打开方案核实','人工处理')
  if (order.country.toUpperCase() === 'US' && order.us_fulfillment_zone?.status !== 'matched')
    return response('manual','manual','美国地址区域待确认','ZIP Code 与州无法可靠确定分仓区域','人工处理')
  const decision = order.allocation_decision
  if (decision.can_use_recommendation && decision.recommended_plan) {
    return response('ready','recommendation',`建议分配：${decision.recommended_plan.label}`,
      '已形成完整的成本可比履约方案 · 仍需 Vita 确认','可确认分仓')
  }
  const reasons = decision.manual_reasons ?? []
  const reason = reasons[0] ?? '成本或路由证据未齐'
  const requirements = requirementsFor(order.items)!
  const inCountry = warehouses.filter(w => w.code !== 'FACTORY' && w.code !== 'US-AMZ' &&
    w.country_code?.toUpperCase() === order.country?.toUpperCase())
  const coverage = inCountry.map(w => ({warehouse:w, ...warehouseCoverage(order,w,requirements)}))
  const confirmed = coverage.filter(row => row.enough && row.verified)
  const byCode = new Map(confirmed.map(row => [canon(row.warehouse.code), row.warehouse]))
  const eligiblePlans = (decision.plans ?? []).filter(plan =>
    plan.plan_type === 'single' && !plan.is_split && plan.segments.length === 1 &&
    plan.segments[0].source_type === 'warehouse' &&
    byCode.has(canon(plan.segments[0].source_code)))
  const codes = [...new Set(eligiblePlans.map(plan => canon(plan.segments[0].source_code)))]
  const names = codes.map(code => byCode.get(code)!.name)
  if (codes.length > 1)
    return response('compare','recommendation','多仓库存可履约',
      `${names.join(' / ')}；成本尚不可比（${reason}）`,'需人工比较')
  if (codes.length === 1)
    return response('candidate','recommendation',`库存可履约：${names[0]}`,
      `已验证库存与路由，待核对成本：${reason}`,'待确认成本')
  const unverified = coverage.filter(row => row.enough && !row.verified)
  if (unverified.length)
    return response('stock_review','inventory',`库存待核实：${unverified[0].warehouse.name}`,
      '库存数量可覆盖订单，但快照或自动分仓资格尚未确认','库存待核实')
  const priority = (order.us_fulfillment_zone?.warehouse_priority ?? []).map(canon)
  const priorityShort = coverage.find(row =>
    priority.includes(canon(row.warehouse.code)) && row.known && row.verified && !row.enough)
  if (priorityShort)
    return response('stock_short','manual',`优先仓库存不足：${priorityShort.warehouse.name}`,
      '当前已验证库存无法覆盖全部 SKU；请查看其他仓库或工厂备选','需换仓核实')
  const missingOrUnknown = coverage.some(row => !row.known || !row.verified)
  if (missingOrUnknown) {
    const priorityWarehouse = coverage.find(row => priority.includes(canon(row.warehouse.code)))
      ?? coverage[0]
    const title = priorityWarehouse
      ? `区域优先：${priorityWarehouse.warehouse.name} · 库存待核`
      : '库存数据待核实'
    return response('stock_review','inventory',title,
      `区域优先不代表有货；尚无可靠的整单库存证据。需核实：${reason}`,'待核实')
  }
  return response('manual','manual','无可靠整单库存候选',
    `本地区仓库存无法覆盖全部 SKU；${reason}`,'人工处理')
}
