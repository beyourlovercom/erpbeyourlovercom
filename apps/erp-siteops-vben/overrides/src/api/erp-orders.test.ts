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
    expect(orderReadOnlyPath({view:'stockout'})).toContain('view=stockout');
    expect(orderReadOnlyPath({view:'arbitrary'})).not.toContain('view=');
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
  it('does not silently switch to fixtures on permission or session failures', async () => {
    const fail = vi.fn().mockResolvedValue(new Response('Forbidden',{status:403}));
    await expect(getExistingErpOrders({},fail)).rejects.toThrow('ERP_LOGIN_OR_PERMISSION_REQUIRED');
    const html = vi.fn().mockResolvedValue(new Response('<html>login</html>',{
      status:200,headers:{'content-type':'text/html'},
    }));
    await expect(getExistingErpOrders({},html)).rejects.toThrow('ERP_INERTIA_JSON_NOT_AVAILABLE');
  });
});