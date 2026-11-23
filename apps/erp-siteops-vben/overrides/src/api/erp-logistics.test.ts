import { afterEach, describe, expect, it, vi } from 'vitest';
import { getLogisticsPage, parseLogisticsPage, parseOriginalLogisticsInertiaPage,
  submitOriginalErpReview } from './erp-logistics';

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
describe('M2 original Laravel workbench and review contracts',()=>{
  const inertia={
    component:'Logistics/Domestic/Index',
    props:{view:'review',canReview:true,canOperate:false,
      rows:{data:[{order_id:6,erp_order_id:'007-1115-00006',paid_date:'2026-11-15',
        review_status:'pending',shipment_status:'pending',carrier:'4px',
        tracking_number:null,recipient_name:'NEVER_PROJECT',phone:'PRIVATE',
        items:[{sku:'S-1',source_sku:'S-1',final_sku:'S-FINAL',quantity:2}]}],
        current_page:1,per_page:20,total:1,last_page:1}},
  };
  it('accepts the original domestic page without leaking customer contact fields',()=>{
    const page=parseOriginalLogisticsInertiaPage('domestic',inertia);
    expect(page).toMatchObject({total:1,canReview:true,canOperate:false});
    expect(page.rows[0]).toMatchObject({order_number:'007-1115-00006',
      warehouse_code:'FACTORY',actual_sku:'S-FINAL',qty:2,review_status:'pending'});
    expect(JSON.stringify(page)).not.toContain('NEVER_PROJECT');
    expect(JSON.stringify(page)).not.toContain('PRIVATE');
  });
  it('fetches every original menu view through Laravel Inertia, not a new status engine',async()=>{
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');
    vi.stubEnv('VITE_ERP_M2_ORIGINAL_INERTIA_ENABLED','1');
    const oversea={...inertia,component:'Logistics/Oversea/Show',
      props:{...inertia.props,canOperate:true}};
    const fetcher=vi.fn().mockImplementation(async (url:string)=>
      url==='/dashboard'
        ?new Response('<div data-page="{&quot;version&quot;:&quot;dev-v1&quot;}"></div>',{
          status:200,headers:{'content-type':'text/html'}})
        :new Response(JSON.stringify(url.startsWith('/logistics/oversea')?oversea:inertia),{
          status:200,headers:{'content-type':'application/json'}}));
    await getLogisticsPage({scope:'domestic',view:'review',page:1},fetcher);
    expect(fetcher.mock.calls[0]?.[0]).toBe('/dashboard');
    expect(fetcher.mock.calls[1]?.[0]).toBe('/logistics/china?page=1&per_page=20&view=review');
    expect(fetcher.mock.calls[1]?.[1]?.headers['X-Inertia']).toBe('true');
    expect(fetcher.mock.calls[1]?.[1]?.headers['X-Inertia-Version']).toBe('dev-v1');
    await getLogisticsPage({scope:'overseas',view:'pending',warehouse:'us-sm'},fetcher);
    expect(fetcher.mock.calls[2]?.[0]).toContain('/logistics/oversea/us-3sm?');
    await expect(getLogisticsPage({scope:'overseas',warehouse:'production.example'},fetcher))
      .rejects.toThrow('ERP2_LOGISTICS_WAREHOUSE_INVALID');
  });
  it('refreshes the original Laravel asset version once after an Inertia 409',async()=>{
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');
    vi.stubEnv('VITE_ERP_M2_ORIGINAL_INERTIA_ENABLED','1');
    let boot=0,requestCount=0;
    const fetcher=vi.fn().mockImplementation(async (url:string)=>{
      if(url==='/dashboard'){
        ++boot;
        return new Response('<div data-page="{&quot;version&quot;:&quot;dev-v'+boot+'&quot;}"></div>',{
          status:200,headers:{'content-type':'text/html'}});
      }
      if(++requestCount===1)return new Response('',{status:409});
      return new Response(JSON.stringify(inertia),{
        status:200,headers:{'content-type':'application/json'}});
    });
    await expect(getLogisticsPage({scope:'domestic',view:'review'},fetcher))
      .resolves.toMatchObject({total:1});
    expect(boot).toBe(2);
    expect(requestCount).toBe(2);
    expect(fetcher.mock.calls[1]?.[1]?.headers['X-Inertia-Version']).toBe('dev-v1');
    expect(fetcher.mock.calls[3]?.[1]?.headers['X-Inertia-Version']).toBe('dev-v2');
  });
  it('submits a single original-review decision with session CSRF and validates row result',async()=>{
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');
    vi.stubEnv('VITE_ERP_M2_REVIEW_WRITE_ENABLED','1');
    const fetcher=vi.fn().mockResolvedValue(new Response(JSON.stringify({
      results:[{order_id:10,ok:true,message:'已处理'}],success_count:1,failed_count:0,
    }),{status:200,headers:{'content-type':'application/json'}}));
    const response=await submitOriginalErpReview({scope:'overseas',orderIds:[10],
      action:'approve'},fetcher,'XSRF-TOKEN=abc%3D123');
    expect(response.success_count).toBe(1);
    expect(fetcher.mock.calls[0]?.[0]).toBe('/logistics/oversea/batch-review');
    expect(fetcher.mock.calls[0]?.[1]?.headers['X-XSRF-TOKEN']).toBe('abc=123');
    expect(fetcher.mock.calls[0]?.[1]?.body).toContain('"order_ids":[10]');
    expect(fetcher).toHaveBeenCalledTimes(1);
  });
  it('blocks disabled writes, bad reject reasons, missing CSRF and row-level failures',async()=>{
    const fetcher=vi.fn();
    await expect(submitOriginalErpReview({scope:'domestic',orderIds:[6],action:'approve'},fetcher))
      .rejects.toThrow('ERP2_M2_REVIEW_WRITE_DISABLED');
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');
    vi.stubEnv('VITE_ERP_M2_REVIEW_WRITE_ENABLED','1');
    await expect(submitOriginalErpReview({scope:'domestic',orderIds:[6],
      action:'reject',reason:'x'},fetcher,'XSRF-TOKEN=abc'))
      .rejects.toThrow('ERP2_REVIEW_REJECTION_REASON_INVALID');
    await expect(submitOriginalErpReview({scope:'domestic',orderIds:[6],
      action:'approve'},fetcher,'')).rejects.toThrow('ERP2_REVIEW_CSRF_REQUIRED');
    expect(fetcher).not.toHaveBeenCalled();
    fetcher.mockResolvedValue(new Response(JSON.stringify({
      results:[{order_id:6,ok:false,message:'无操作权限'}],success_count:0,failed_count:1,
    }),{status:200,headers:{'content-type':'application/json'}}));
    const denied=await submitOriginalErpReview({scope:'domestic',orderIds:[6],
      action:'approve'},fetcher,'XSRF-TOKEN=abc');
    expect(denied.failed_count).toBe(1);
    expect(denied.results[0]?.message).toBe('无操作权限');
  });
});
