import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Dashboard from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { post: vi.fn() },
  usePage: () => ({
    url: '/dashboard',
    props: {
      auth: { user: { id: 1, name: 'Owner', email: 'owner@example.test' } },
      shellNavigation: [{ key: 'dashboard', label: '仪表盘', path: '/dashboard', route: 'dashboard', placeholder: false, children: [] }],
    },
  }),
}))

const allVisible = { operations: true, inventory: true, sync: true, finance: true, procurement: true }
const noneVisible = { operations: false, inventory: false, sync: false, finance: false, procurement: false }
const operations = {
  wait_stock:{key:'wait_stock',label:'待分仓',url:'/logistics/wait-stock',count:120},
  pending:{key:'pending',label:'待发货',url:'/logistics/pending',count:663},
  awaiting:{key:'awaiting',label:'已发货',url:'/logistics/awaiting-tracking',count:0},
  transit:{key:'transit',label:'运输中',url:'/logistics/in-transit',count:1847},
  exception:{key:'exception',label:'异常单',url:'/logistics/exceptions',count:2},
  review:{key:'review',label:'复核单',url:'/logistics/review',count:1},
  completed:{key:'completed',label:'已完结',url:'/logistics/completed',count:40},
  cancelled:{key:'cancelled',label:'已取消',url:'/logistics/cancelled',count:549},
}
const inventoryRisk = { stock_alert_rows:38, out_of_stock_rows:21, low_stock_rows:17, quantity_available:5059, warehouse_data_gap_count:2, warehouse_total_count:7 }
const syncHealth = { pending:1, retry_due:1, retry_waiting:0, processing:0, failed:0, conflicts:0 }
const financeSnapshot = {
  date_from:'2026-09-14',date_to:'2026-09-20',eligible_orders:110,revenue_ready_orders:99,estimated_profit_orders:99,
  estimated_profit_percent:100,final_profit_orders:0,final_profit_percent:0,fx_excluded_orders:0,aggregate_orders:99,
  estimated_revenue_usd:'12000.00',estimated_cost_usd:'7000.00',estimated_profit_usd:'5000.00',
  projection_confidence:{high:20,moderate:40,low:30,very_low:9},
  trend:[
    {date:'2026-09-14',revenue_usd:'1000.00',estimated_cost_usd:'600.00',estimated_profit_usd:'400.00',aggregate_orders:10},
    {date:'2026-09-20',revenue_usd:'1500.00',estimated_cost_usd:'900.00',estimated_profit_usd:'600.00',aggregate_orders:12},
  ],
}
const baseProps = {
  visibility: allVisible,
  period: '7d' as const,
  dateFrom: '2026-09-14',
  dateTo: '2026-09-20',
  operations,
  inventoryRisk,
  syncHealth,
  financeSnapshot,
  procurementProgress: { active:0, counts:{pending:0,purchasing:0,ordered:0,completed:0,cancelled:0}, recent:[] },
}

describe('dashboard redesign', () => {
  it('renders canonical D3 live metrics and removes the legacy duplicate KPI contract', () => {
    const wrapper = mount(Dashboard, { props: baseProps })
    expect(wrapper.text()).toContain('经营与运营总览')
    expect(wrapper.text()).toContain('663')
    expect(wrapper.text()).toContain('1,847')
    expect(wrapper.text()).toContain('5,059')
    expect(wrapper.text()).toContain('$5,000.00')
    expect(wrapper.text()).not.toContain('待处理订单')
    expect(wrapper.find('[aria-label="ERP主导航"]').exists()).toBe(true)
  })

  it('makes operational and inventory risk cards drill into canonical views', () => {
    const wrapper = mount(Dashboard, { props: baseProps })
    expect(wrapper.find('a[href="/logistics/pending"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/logistics/wait-stock"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/inventory/products?stock_status=alert"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/inventory/products?stock_status=out_of_stock"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/inventory/products?stock_status=low_stock"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('不与“待发货”相加')
  })

  it('shows only sections allowed by the permission visibility contract', () => {
    const wrapper = mount(Dashboard, {
      props: { ...baseProps, visibility:{operations:true,inventory:false,sync:true,finance:false,procurement:false}, inventoryRisk:null, financeSnapshot:null },
    })
    expect(wrapper.find('#operations').exists()).toBe(true)
    expect(wrapper.find('#sync').exists()).toBe(true)
    expect(wrapper.find('#inventory').exists()).toBe(false)
    expect(wrapper.find('#finance').exists()).toBe(false)
    expect(wrapper.find('#procurement').exists()).toBe(false)
  })

  it('supports preset and custom period navigation and labels estimated finance explicitly', () => {
    const preset = mount(Dashboard, { props: { ...baseProps, period:'30d' } })
    expect(preset.find('a[href="/dashboard?period=30d"]').classes()).toContain('active')

    const custom = mount(Dashboard, { props: { ...baseProps, period:'custom', dateFrom:'2026-09-01', dateTo:'2026-09-20' } })
    expect(custom.find('.dashboard-custom-period').exists()).toBe(true)
    expect(custom.find('input[name="date_from"]').attributes('value')).toBe('2026-09-01')
    expect(custom.text()).toContain('预估 / 非最终')
    expect(custom.find('svg[aria-label="经营趋势图"]').exists()).toBe(true)
  })

  it('keeps zero-activity procurement compact and expands it only when active', () => {
    const quiet = mount(Dashboard, { props: baseProps })
    expect(quiet.text()).toContain('当前无活跃采购')
    expect(quiet.find('.procurement-recent').exists()).toBe(false)

    const active = mount(Dashboard, { props: {
      ...baseProps,
      procurementProgress:{
        active:2,
        counts:{pending:1,purchasing:0,ordered:1,completed:3,cancelled:1},
        recent:[{id:7,procurement_number:'PO-20260905-0007',status:'ordered',source_order_erp_id:'007-0908-c7',carrier:'顺丰速运',tracking_number:'SF-7',updated_at:null}],
      },
    } })
    expect(active.text()).toContain('活跃采购')
    expect(active.find('a[href="/purchase/orders/7"]').exists()).toBe(true)
  })

  it('renders a bounded empty state when dashboard permission has no visible module blocks', () => {
    const wrapper = mount(Dashboard, { props: { ...baseProps, visibility:noneVisible, operations:null, inventoryRisk:null, syncHealth:null, financeSnapshot:null, procurementProgress:null } })
    expect(wrapper.text()).toContain('当前账号暂无可展示的业务总览模块')
  })
})
