import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Show from './Show.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { props: ['title'], template: '<div data-head="product360" />' },
  Link: { props: ['href', 'method', 'as'], template: '<a :href="href"><slot /></a>' },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { props: ['title', 'breadcrumbs'], template: '<main><slot /></main>' },
}))
vi.mock('../../Components/Products/PackagingEditor.vue', () => ({
  default: { props:['product','packagingProfile','presets','embedded'], template:'<section data-testid="embedded-packaging-editor">旧包装方案字段复用 · 包装方式 · 主示意图 · 真实包装案例图</section>' },
}))
vi.mock('../../Components/DataGrid/ColumnSettingsHeader.vue', () => ({
  default: { props: ['columns'], template: '<button type="button" aria-label="列设置">列设置</button>' },
}))
vi.mock('../../Composables/useAccountGridPreferences', () => ({
  useAccountGridPreferences: ({ definitions }: { definitions: any[] }) => ({
    visibleColumns: definitions, columns: definitions,
    toggle: vi.fn(), startDrag: vi.fn(), drop: vi.fn(), reset: vi.fn(), syncState: {},
  }),
}))

const fixture = () => ({
  product: {
    id: 123, name: 'UAT Product 360', sku: 'P360-123',
    product_type: 'simple', source_type: 'woocommerce', status: 'active',
    image_url: null, woo_url: 'https://www.beyourlover.com/uat-product',
    weight_kg: '1.25', shipping_weight_kg: '1.52',
    length_cm: '18', width_cm: '12', height_cm: '9',
    weight_evidence_source_type: 'supplier_document',
    weight_evidence_source_reference: 'FAC-W-12',
    shipping_weight_evidence_source_type: 'manual_measurement',
    shipping_weight_evidence_source_reference: 'SHP-57',
    standard_production_cost: '15.00', standard_packaging_cost: '4.00',
    standard_purchase_cost: '20.00', cost_currency: 'RMB',
    country_of_origin: 'CN', hs_code: '123456', cost_note: 'old cost note',
    sale_price: '49.00', regular_price: '69.00', woocommerce_product_id: 12345,
  },
  externalReferences: [
    { provider: 'woocommerce', connection_key: 'beyourlover.com', reference: '12345', reference_type: 'product' },
    { provider: 'woocommerce', connection_key: 'beyourlover.com', reference: '56789', reference_type: 'variation' },
  ],
  skuAliases: [{ alias_sku: 'P360-ALT', source_type: 'manual', source_reference: 'WMS' }],
  sales: {
    units_30d: 3, order_count_30d: 2, units_90d: 10, order_count_90d: 8,
    lifetime_units: 21, lifetime_order_count: 19,
    revenue_by_currency: [{ currency: 'USD', revenue: 49 }],
    recent_orders: [{ id: 19, erp_order_id: 'ERP-19', order_date: '2026-09-25', status: 'shipped', quantity: 1, revenue: 49, currency: 'USD' }],
  },
  kolShipping: {
    units_30d: 2, shipment_count_30d: 1, units_90d: 4,
    shipment_count_90d: 2, lifetime_units: 7, lifetime_shipment_count: 5,
  },
  inventory: [{ warehouse_id: 1, warehouse_name: 'US-LA', warehouse_slug: 'us-la',
    inventory_state: 'confirmed', quantity_on_hand: 17, quantity_reserved: 2,
    quantity_available: 15, expected_restock_qty: null }],
  shipments: [{ id: 5, order_id: 19, erp_order_id: 'ERP-19', carrier: 'UPS',
    tracking_number: 'TRACK-360', shipped_at: '2026-09-26', status: 'shipped' }],
  collaborations: [{ id: 16, title: 'Review UAT', status: 'open', kol_id: 4, kol_name: 'KOL-A' }],
  audit: [{ action: 'product.updated', action_label: '产品编辑', actor_label: 'Auditor A',
    actor_name: 'Auditor A', source: 'web', created_at: '2026-09-26', reason: 'test' }],
  canOperate: true, canSync: true, hasWooProductReference: true,
  woo: { provider_status: 'publish', categories: ['Accessories'], description: 'Canonical Woo summary',
    short_description: 'Woo Short', variation_count: 1, provider_created_at: null,
    provider_modified_at: null, last_synced_at: '2026-09-20',
    connection_key: 'beyourlover.com' },
  wooSyncHistory: [{ status: 'completed', result_code: 'success', error_message: null,
    completed_at: '2026-09-20', run_id: 3 }],
  media: [],
})
const make = (canOperate = true) => mount(Show, { props: { ...fixture(), canOperate } })

async function navigate(wrapper: ReturnType<typeof make>, main: string, sub?: string) {
  const tabs = wrapper.findAll('[data-product360-tab]')
  const parent = tabs.find(t => t.text() === main)
  expect(parent, 'main tab exists: ' + main).toBeDefined()
  await parent!.trigger('click')
  if (sub) {
    const options = wrapper.findAll('.product360-subtabs button')
    const child = options.find(t => t.text() === sub)
    expect(child, 'sub tab exists: ' + main + '/' + sub).toBeDefined()
    await child!.trigger('click')
  }
}

describe('Product 360 V2 P0 — old nine-tabs no-loss', () => {
  it('retains five reachable first-level tabs and all legacy hero action links', () => {
    const w = make()
    expect(w.findAll('[data-product360-tab]').map(t => t.text())).toEqual([
      '概览', '产品参数', '销售与合作', '库存与物流', '关联与记录',
    ])
    for (const link of ['/products/list', '/products/123/edit',
                        'https://www.beyourlover.com/uat-product']) {
      expect(w.findAll('a').some(a => a.attributes('href') === link)).toBe(true)
    }
    expect(w.text()).toContain('刷新 Woo')
  })

  it('embeds the full canonical packaging editor in Product Parameters Tab without a second-page link', async () => {
    const w = make()
    expect(w.findAll('a').some(a => a.attributes('href') === '/products/123/packaging')).toBe(false)
    await navigate(w,'产品参数','包装与配件')
    expect(w.find('[data-testid=embedded-packaging-editor]').exists()).toBe(true)
    expect(w.text()).toContain('旧包装方案字段复用')
    const readOnly = make(false)
    await navigate(readOnly,'产品参数','包装与配件')
    expect(readOnly.find('[data-testid=embedded-packaging-editor]').exists()).toBe(false)
  })

  it('supports arrow-key navigation for main and sub tabs', async () => {
    const w = make()
    await w.find('[data-product360-tab="概览"]').trigger('keydown.right')
    expect(w.find('[data-product360-tab="产品参数"]').attributes('aria-selected')).toBe('true')
    expect(w.find('[data-product360-subtab="参数总览"]').attributes('aria-selected')).toBe('true')
    await w.find('[data-product360-subtab="参数总览"]').trigger('keydown.right')
    expect(w.find('[data-product360-subtab="工厂填报"]').attributes('aria-selected')).toBe('true')
    await w.find('[data-product360-subtab="工厂填报"]').trigger('keydown.left')
    expect(w.find('[data-product360-subtab="参数总览"]').attributes('aria-selected')).toBe('true')
  })

  it('preserves old 概览 sales/KOL comparison, inventory/weight and Woo summary', () => {
    const w = make()
    expect(w.text()).toContain('销售 / 网红发货 对比')
    expect(w.text()).toContain('网红发货摘要')
    expect(w.text()).toContain('US-LA')
    expect(w.text()).toContain('Woo Short')
    expect(w.text()).toContain('1.25 kg')
  })

  it('preserves old 销售 currency revenue, order list, order links and column settings', async () => {
    const w = make()
    await navigate(w, '销售与合作', '销售记录')
    expect(w.text()).toContain('收入（按原始币种）')
    expect(w.text()).toContain('ERP-19')
    expect(w.find('a[href="/customers/orders/19"]').exists()).toBe(true)
    expect(w.find('button[aria-label="列设置"]').exists()).toBe(true)
  })

  it('preserves old 库存 values and unknown restock without converting to zero', async () => {
    const w = make()
    await navigate(w, '库存与物流', '库存明细')
    expect(w.text()).toContain('US-LA')
    expect(w.text()).toContain('15')
    expect(w.text()).toContain('未知')
  })

  it('preserves old 物流 evidence, shipments/tracking and column settings', async () => {
    const w = make()
    await navigate(w, '库存与物流', '物流记录')
    expect(w.text()).toContain('供应商文件')
    expect(w.text()).toContain('FAC-W-12')
    expect(w.text()).toContain('UPS')
    expect(w.text()).toContain('TRACK-360')
    expect(w.find('button[aria-label="列设置"]').exists()).toBe(true)
  })

  it('preserves original 成本与海关 fields in 产品参数', async () => {
    const w = make()
    await navigate(w, '产品参数', '成本与海关')
    for (const token of ['生产成本','包材成本','采购参考成本','HS Code','123456','old cost note']) {
      expect(w.text()).toContain(token)
    }
  })

  it('preserves both original Woo 信息 and SKU 别名 panels simultaneously', async () => {
    const w = make()
    await navigate(w, '产品参数', '渠道与 SKU')
    for (const token of ['Woo 信息','Canonical SKU','P360-ALT','P360-123','Variation 56789','Canonical Woo summary','Legacy Woo ID']) {
      expect(w.text()).toContain(token)
    }
  })

  it('preserves original 业务关联 order/shipment/KOL and column settings', async () => {
    const w = make()
    await navigate(w, '关联与记录', '业务关联')
    expect(w.text()).toContain('ERP-19')
    expect(w.text()).toContain('KOL-A')
    expect(w.find('button[aria-label="列设置"]').exists()).toBe(true)
  })

  it('preserves original 审计 actor/action/source/time', async () => {
    const w = make()
    await navigate(w, '关联与记录', '操作记录')
    expect(w.text()).toContain('Auditor A')
    expect(w.text()).toContain('产品编辑')
    expect(w.text()).toContain('2026')
  })

  it('adds parameter view without fabricating material, colorbox or courier weight', async () => {
    const w = make()
    await navigate(w, '产品参数', '参数总览')
    expect(w.text()).toContain('产品材质：待填写')
    expect(w.text()).toContain('彩盒毛重：待填写')
    expect(w.text()).toContain('快递毛重：待填写')
    expect(w.text()).toContain('18 × 12 × 9 cm')
  })

  it('preserves weight evidence & packaging links while factory write UI is gated', async () => {
    const w = make()
    await navigate(w, '产品参数', '工厂填报')
    expect(w.find('a[href="/products/weight-evidence"]').exists()).toBe(true)
    expect(w.find('form').exists()).toBe(false)
    await navigate(w, '产品参数', '包装与配件')
    expect(w.find('[data-testid=embedded-packaging-editor]').exists()).toBe(true)
    expect(w.find('a[href="/products/123/packaging"]').exists()).toBe(false)
  })

  it('keeps privileged edit and packaging modification actions hidden for view-only role', async () => {
    const w = make(false)
    expect(w.find('a[href="/products/123/edit"]').exists()).toBe(false)
    await navigate(w, '产品参数', '包装与配件')
    expect(w.find('a[href="/products/123/packaging"]').exists()).toBe(false)
  })

  it('keeps KOL shipping totals and collaboration records under 销售与合作', async () => {
    const w = make()
    await navigate(w, '销售与合作', 'KOL 合作')
    expect(w.text()).toContain('KOL-A')
    expect(w.text()).toContain('Review UAT')
    expect(w.text()).toContain('近 30 天 KOL 发货')
  })
})
