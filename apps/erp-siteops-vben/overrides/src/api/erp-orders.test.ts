import { describe, expect, it, vi } from 'vitest';
import { getExistingErpOrders, normalizeExistingInertiaOrders, orderReadOnlyPath } from './erp-orders';

const inertia = {
  component: 'Orders/Index',
  props: {
    orders: {
      data: [{
        id: 7963, erp_order_id: '007-0910-40414',
        external_order_number:'C42203-007', internal_order_number:'ERP-INTERNAL-123',
        order_kind_label:'客户订单', order_date:'2026-11-14',
        order_status_label:'已分仓',
        workflow_status_projection:{code:'out_of_stock',zh:'缺货'},
        latest_order_note:'仅用于 UAT 的备注',
        shipment:{warehouse_name:'us-moreplus', tracking_number:'DEMO-TRACK-123'},
        items:[{sku:'SKU-REAL-A',quantity:1},{sku:'GIFT-WAREHOUSE',quantity:1}],
      }],
      total:1,current_page:1,last_page:1,per_page:50,
    }, filters: {search:'C42203-007'},
  },
};
describe('BYL ERP authoritative existing orders GET projection', () => {
  it('uses only GET /orders, never tries to override OMS canary', () => {
    expect(orderReadOnlyPath({search:'C42203-007',page:3,perPage:100}))
      .toBe('/orders?search=C42203-007&page=3&per_page=100');
    expect(orderReadOnlyPath({perPage:999})).not.toContain('per_page');
    expect(orderReadOnlyPath({view:'pending'})).toContain('view=pending');
    expect(orderReadOnlyPath({view:'stockout'})).not.toContain('view=');
    expect(orderReadOnlyPath({view:'arbitrary'})).not.toContain('view=');
    expect(orderReadOnlyPath({scope:'customers'})).toMatch(/^\/customers\/orders\?/);
    expect(orderReadOnlyPath({scope:'kol'})).toMatch(/^\/kol\/orders\?/);
    expect(orderReadOnlyPath({scope:'customers',view:'trash'})).not.toContain('view=');
    expect(orderReadOnlyPath({})).not.toContain('oms_grid');
  });
  it('preserves front office order number, status projection and shipping SKU without rewriting identity', () => {
    const page = normalizeExistingInertiaOrders(inertia);
    expect(page.rows[0]!.order_number).toBe('007-0910-40414');
    expect(page.rows[0]!.status).toBe('缺货');
    expect(page.rows[0]!.sku).toBe('SKU-REAL-A, GIFT-WAREHOUSE');
    expect(page.rows[0]!.tracking_number).toBe('DEMO-TRACK-123');
    expect(page.rows[0]!.warehouse).toBe('us-moreplus');
    expect(page.total).toBe(1);
  });
  it('supports current Laravel main Orders/Master field contract without inventing tracking or notes', () => {
    const page = normalizeExistingInertiaOrders({
      component:'Orders/Master', props:{
        orders:{data:[{id:7963,number:'007-0910-40414',kind:'customer',
          status:'pending_shipment',warehouse:'us-moreplus',skus:'SKU-A / GIFT-B',
          date:'2026-11-20'}],current_page:1,last_page:1,per_page:50,total:1},
        filters:{view:'all',search:''},
      },
    });
    expect(page.rows[0]).toMatchObject({order_number:'007-0910-40414',
      order_kind:'customer',status:'pending_shipment',sku:'SKU-A / GIFT-B',
      tracking_number:'',latest_note:''});
    expect(page.rows[0]!.warehouse).toBe('us-moreplus');
    expect(page.total).toBe(1);
    expect(() => normalizeExistingInertiaOrders({component:'Orders/Master',
      props:{orders:{data:[{id:10}]}}})).toThrow('ERP_MASTER_ROW_CONTRACT_INVALID');
  });
  it('rejects non-Orders Inertia pages and invalid collections instead of fake successful data', () => {
    expect(() => normalizeExistingInertiaOrders({ component:'Other',props:{} }))
      .toThrow('ERP_INERTIA_COMPONENT_MISMATCH');
    expect(() => normalizeExistingInertiaOrders({ component:'Orders/Index',props:{orders:{data:null}} }))
      .toThrow('ERP_ORDERS_PAYLOAD_INVALID');
  });
  it('requests Laravel session-authorized Inertia GET, no write headers', async () => {
    const fetcher = vi.fn().mockResolvedValue(new Response(JSON.stringify(inertia), {
      status:200,headers:{'content-type':'application/json'},
    }));
    await expect(getExistingErpOrders({search:'test'},fetcher)).resolves.toMatchObject({total:1});
    expect(fetcher).toHaveBeenCalledWith(expect.stringContaining('/orders?search=test'),expect.objectContaining({
      method:'GET',credentials:'same-origin',
      headers:expect.objectContaining({'X-Inertia':'true'}),
    }));
  });
  it('routes dedicated customer and KOL reads to original Laravel GET scopes', async () => {
    const fetcher=vi.fn().mockResolvedValue(new Response(JSON.stringify(inertia),{
      status:200,headers:{'content-type':'application/json'},
    }));
    await getExistingErpOrders({scope:'customers'},fetcher);
    await getExistingErpOrders({scope:'kol'},fetcher);
    expect(fetcher.mock.calls[0]?.[0]).toMatch(/^\/customers\/orders\?/);
    expect(fetcher.mock.calls[1]?.[0]).toMatch(/^\/kol\/orders\?/);
  });
  it('does not silently switch to fixtures on permission or session failures', async () => {
    const fail = vi.fn().mockResolvedValue(new Response('Forbidden',{status:403}));
    await expect(getExistingErpOrders({},fail)).rejects.toThrow('ERP_LOGIN_OR_PERMISSION_REQUIRED');
    const html = vi.fn().mockResolvedValue(new Response('<html>login</html>',{
      status:200,headers:{'content-type':'text/html'},
    }));
    await expect(getExistingErpOrders({},html)).rejects.toThrow('ERP_INERTIA_JSON_NOT_AVAILABLE');
  });
});