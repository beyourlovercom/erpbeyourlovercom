import { afterEach, describe, expect, it, vi } from 'vitest';
import { getLogisticsPage, parseLogisticsPage } from './erp-logistics';

const pagination = {current_page:1,per_page:20,total:1,last_page:1};
const order = {id:11,order_number:'UAT-CN-101',order_date:'2026-11-19',
  status:'pending_shipment',warehouse_code:'FACTORY',carrier:null,tracking:null,
  items:[{sku:'SRC-1',resolved_sku:'FINAL-1',final_sku:null,quantity:2}]};
const child = {shipment_id:20,order_id:12,order_number:'UAT-OVERSEA-200-A',
  fulfillment_suffix:'A',fulfillment_parent_id:10,warehouse_code:'US-SM',
  shipment_status:'pending',review_status:'pending',oms:null,
  items:[{sku:'SRC-A',source_sku:'ORIG-A',resolved_sku:'REAL-A',quantity:1}]};
afterEach(()=>vi.unstubAllEnvs());
describe('ERP2 three isolated logistics read projections',()=>{
  it('retains the frontend order number and correct resolved/real SKU',()=>{
    const p=parseLogisticsPage('domestic',{data:[order],meta:pagination});
    expect(p.rows[0]).toMatchObject({order_number:'UAT-CN-101',
      sku:'SRC-1',actual_sku:'FINAL-1',qty:2,warehouse_code:'FACTORY'});
    expect(p.rows[0]?.shipment_id).toBeNull();
  });
  it('preserves A/B/C suffix and shipment identity instead of flattening to an order',()=>{
    const p=parseLogisticsPage('overseas',{data:[child],meta:pagination});
    expect(p.rows[0]).toMatchObject({key:'shipment-20',
      fulfillment_suffix:'A',fulfillment_parent_id:10,actual_sku:'REAL-A'});
  });
  it('rejects malformed server pages and rows; never falls back to synthetic UI',()=>{
    expect(()=>parseLogisticsPage('domestic',{data:[],meta:{total:0}}))
      .toThrow('ERP2_LOGISTICS_PAGE_CONTRACT_INVALID');
    expect(()=>parseLogisticsPage('overseas',{data:[{}],meta:pagination}))
      .toThrow('ERP2_LOGISTICS_ROW_CONTRACT_INVALID');
  });

  it('uses the existing Laravel Session, exact GET endpoint, and allowlisted query',async()=>{
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');
    const fetcher=vi.fn().mockResolvedValue(new Response(JSON.stringify({data:[child],meta:pagination}),{
      status:200,headers:{'content-type':'application/json'},
    }));
    const p=await getLogisticsPage({scope:'overseas',search:'SRC-A',view:'review',warehouse:'us-sm'},fetcher);
    expect(p.total).toBe(1);
    expect(fetcher).toHaveBeenCalledWith(
      expect.stringMatching(/^\/api\/erp2\/v1\/logistics\/overseas\?/),
      expect.objectContaining({method:'GET',credentials:'same-origin',cache:'no-store'}));
    expect(fetcher.mock.calls[0]?.[0]).toContain('warehouse=us-sm');
  });
  it('blocks the local fake JWT mode, non-JSON responses and forbidden access',async()=>{
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','not-laravel');
    await expect(getLogisticsPage({scope:'allocation'},vi.fn())).rejects.toThrow('ERP2_LARAVEL_SESSION_REQUIRED');
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');
    const denied=vi.fn().mockResolvedValue(new Response('Forbidden',{status:403}));
    await expect(getLogisticsPage({scope:'allocation'},denied)).rejects.toThrow('ERP2_LOGISTICS_LOGIN_OR_PERMISSION_REQUIRED');
    const html=vi.fn().mockResolvedValue(new Response('<html>login</html>',{
      status:200,headers:{'content-type':'text/html'}}));
    await expect(getLogisticsPage({scope:'domestic'},html)).rejects.toThrow('ERP2_LOGISTICS_GET_FAILED_200');
  });
});
