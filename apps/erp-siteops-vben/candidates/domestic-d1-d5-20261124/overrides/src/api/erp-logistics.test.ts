import { afterEach, describe, expect, it, vi } from 'vitest';
import { getLogisticsPage, parseLogisticsPage, parseOriginalLogisticsInertiaPage, submitOriginalDomesticFlow, domesticShipmentReceipt, parseAllocationPlan, submitOriginalAllocation, readDomesticBarcode69, parseOriginalAllocationOrQueue,
  submitOriginalErpReview, submitOriginalErpShipment, originalShippingPath,
  previewOriginalDomesticTrackingReturn, applyOriginalDomesticTrackingReturn } from './erp-logistics';

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

describe('M2 original ERP shipping with fail-closed Dev adapter',()=>{
  it('uses only original domestic and warehouse-specific routes',()=>{
    expect(originalShippingPath({scope:'domestic',action:'tracking-draft',orderId:12,tracking:'TEST123'}))
      .toBe('/logistics/china/orders/12/tracking-draft');
    expect(originalShippingPath({scope:'overseas',action:'manual-ship',orderId:12,
      warehouse:'US-NY',tracking:'TEST123'}))
      .toBe('/logistics/oversea/orders/12/manual-ship');
    expect(originalShippingPath({scope:'overseas',action:'manual-ship',orderId:12,
      warehouse:'US-SM',tracking:'TEST123'}))
      .toBe('/logistics/oversea/us-3sm/orders/12/manual-ship');
    expect(originalShippingPath({scope:'overseas',action:'manual-ship',orderId:12,
      warehouse:'US-LA',tracking:'TEST123'}))
      .toBe('/logistics/oversea/us-moreplus/orders/12/manual-ship');
    expect(()=>originalShippingPath({scope:'overseas',action:'manual-ship',orderId:12,
      warehouse:'UNKNOWN',tracking:'TEST123'})).toThrow('ERP2_SHIPPING_WAREHOUSE_UNSUPPORTED');
  });
  it('blocks disabled writes and invalid carrier / Tracking before HTTP',async()=>{
    const fetcher=vi.fn();
    await expect(submitOriginalErpShipment({scope:'domestic',action:'quick-ship',
      orderId:12,tracking:'TEST123'},fetcher,'XSRF-TOKEN=abc')).rejects.toThrow('ERP2_M2_SHIPPING_WRITE_DISABLED');
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');
    vi.stubEnv('VITE_ERP_M2_SHIPPING_WRITE_ENABLED','1');
    await expect(submitOriginalErpShipment({scope:'domestic',action:'quick-ship',
      orderId:12,tracking:'contains spaces'},fetcher,'XSRF-TOKEN=abc'))
      .rejects.toThrow('ERP2_SHIPPING_TRACKING_INVALID');
    await expect(submitOriginalErpShipment({scope:'domestic',action:'carrier-service',
      orderId:12,carrier:'',service:''},fetcher,'XSRF-TOKEN=abc'))
      .rejects.toThrow('ERP2_SHIPPING_CARRIER_SERVICE_REQUIRED');
    expect(fetcher).not.toHaveBeenCalled();
  });
  it('submits a single warehouse manual POST and requires fresh state readback',async()=>{
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');
    vi.stubEnv('VITE_ERP_M2_SHIPPING_WRITE_ENABLED','1');
    const fetcher=vi.fn().mockResolvedValue(new Response(null,{status:302,
      headers:{location:'/logistics/oversea/us-moreplus?view=shipped'}}));
    await expect(submitOriginalErpShipment({scope:'overseas',action:'manual-ship',
      orderId:42,warehouse:'US-LA',tracking:'DEVTEST123',outboundReference:'DEV-UAT-REF-42'},
      fetcher,'XSRF-TOKEN=test%3Dabc')).resolves.toBe('readback-required');
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls[0]?.[0]).toBe('/logistics/oversea/us-moreplus/orders/42/manual-ship');
    expect(JSON.parse(fetcher.mock.calls[0]?.[1]?.body)).toMatchObject({
      tracking_number:'DEVTEST123',outbound_reference:'DEV-UAT-REF-42',
      confirmation:'CONFIRM_MOREPLUS_MANUAL_SHIPPED_42'});
    expect(fetcher.mock.calls[0]?.[1]?.headers['X-XSRF-TOKEN']).toBe('test=abc');
  });
  it('rejects HTTP failure without retrying or claiming shipment success',async()=>{
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');
    vi.stubEnv('VITE_ERP_M2_SHIPPING_WRITE_ENABLED','1');
    const fetcher=vi.fn().mockResolvedValue(new Response('Denied',{status:409}));
    await expect(submitOriginalErpShipment({scope:'domestic',action:'quick-ship',
      orderId:12,tracking:'UAT123'},fetcher,'XSRF-TOKEN=abc'))
      .rejects.toThrow('ERP2_SHIPPING_REJECTED_409');
    expect(fetcher).toHaveBeenCalledTimes(1);
  });
});

describe('P0 original domestic tracking XLSX PREVIEW only',()=>{
  it('sends an XLSX as multipart to the original Laravel preview and verifies counts',async()=>{
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');
    const fetcher=vi.fn().mockResolvedValue(new Response(JSON.stringify({
      message:'预览已生成；无业务数据写入',provider:'4px',
      apply_count:0,noop_count:0,blocked_count:1,
    }),{status:200,headers:{'content-type':'application/json'}}));
    const file=new File(['PK-test'],'test.xlsx',{
      type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
    const result=await previewOriginalDomesticTrackingReturn(file,fetcher,'XSRF-TOKEN=dev%3Dtest');
    expect(result).toEqual({message:'预览已生成；无业务数据写入',
      provider:'4px',apply_count:0,noop_count:0,blocked_count:1});
    expect(fetcher.mock.calls).toHaveLength(1);
    const [path,options]=fetcher.mock.calls[0]!;
    expect(path).toBe('/logistics/china/tracking-import/preview');
    expect(options.method).toBe('POST');
    expect(options.headers['X-XSRF-TOKEN']).toBe('dev=test');
    expect(options.headers).not.toHaveProperty('Content-Type');
    expect(options.body).toBeInstanceOf(FormData);
    expect(options.body.get('file')).toBeInstanceOf(File);
  });
  it('rejects bad extension, missing CSRF, non-Laravel and invalid preview schema without Apply',async()=>{
    const fetcher=vi.fn();
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');
    await expect(previewOriginalDomesticTrackingReturn(
      new File(['x'],'bad.txt'),fetcher,'XSRF-TOKEN=xxx'))
      .rejects.toThrow('ERP2_TRACKING_PREVIEW_FILE_INVALID');
    await expect(previewOriginalDomesticTrackingReturn(
      new File(['x'],'demo.xlsx'),fetcher,''))
      .rejects.toThrow('ERP2_TRACKING_PREVIEW_CSRF_REQUIRED');
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','synthetic');
    await expect(previewOriginalDomesticTrackingReturn(
      new File(['x'],'demo.xlsx'),fetcher,'XSRF-TOKEN=xxx'))
      .rejects.toThrow('ERP2_LARAVEL_SESSION_REQUIRED');
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');
    const wrong=vi.fn().mockResolvedValue(new Response(JSON.stringify({
      provider:'4px',apply_count:2,blocked_count:null,
    }),{status:200,headers:{'content-type':'application/json'}}));
    await expect(previewOriginalDomesticTrackingReturn(
      new File(['x'],'demo.xlsx'),wrong,'XSRF-TOKEN=xxx'))
      .rejects.toThrow('ERP2_TRACKING_PREVIEW_LARAVEL_SCHEMA_INVALID');
    expect(fetcher).not.toHaveBeenCalled();
  });
});
describe('P0 domestic Apply adapter',()=>{
  const confirmation={provider:'generic' as const,message:'preview',apply_count:1,noop_count:0,blocked_count:0,token:'a'.repeat(64),rows:[{order_number:'UAT1',tracking_number:'TEST1',status:'apply' as const,message:''}]};
  it('rejects disabled writes without HTTP',async()=>{
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');vi.stubEnv('VITE_ERP_P0_DOMESTIC_APPLY_ENABLED','0');
    const fetcher=vi.fn();await expect(applyOriginalDomesticTrackingReturn(confirmation,fetcher,'XSRF-TOKEN=abc')).rejects.toThrow('ERP2_TRACKING_APPLY_DISABLED');expect(fetcher).not.toHaveBeenCalled();
  });
  it('sends exactly one original provider Apply with session token and preserves handoff failures',async()=>{
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');vi.stubEnv('VITE_ERP_P0_DOMESTIC_APPLY_ENABLED','1');
    const fetcher=vi.fn().mockResolvedValue(new Response(JSON.stringify({applied_count:1,noop_count:0,blocked_count:0,failed_count:0,sync_pending_count:0,sync_handoff_failed_count:1,message:'test'}),{headers:{'content-type':'application/json'}}));
    const result=await applyOriginalDomesticTrackingReturn(confirmation,fetcher,'XSRF-TOKEN=abc');
    expect(result.sync_handoff_failed_count).toBe(1);expect(fetcher).toHaveBeenCalledTimes(1);expect(fetcher.mock.calls[0]?.[0]).toBe('/logistics/china/pending-batch/tracking-import/apply');expect(JSON.parse(fetcher.mock.calls[0]?.[1]?.body)).toEqual({token:confirmation.token});
  });
  it('does not retry a stale token response',async()=>{
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');vi.stubEnv('VITE_ERP_P0_DOMESTIC_APPLY_ENABLED','1');
    const fetcher=vi.fn().mockResolvedValue(new Response(null,{status:409}));await expect(applyOriginalDomesticTrackingReturn(confirmation,fetcher,'XSRF-TOKEN=abc')).rejects.toThrow('ERP2_TRACKING_APPLY_REJECTED_409');expect(fetcher).toHaveBeenCalledTimes(1);
  });
});

describe('Unified Grid parity fields and canonical query',()=>{
  it('maps original business fields without contact or free-text notes',()=>{
    const payload={component:'Logistics/Domestic/Index',props:{rows:{...pagination,data:[{
      order_id:6,erp_order_id:'UAT-6',paid_date:'2026-11-15',line:1,source_type:'woocommerce',
      workflow_status_projection:{code:'sending',zh:'待发货'},routing_warehouse:{code:'FACTORY',name:'中国工厂'},
      amount:'12.30',amount_currency:'USD',export_blockers:[],provider_reference:'TEST-REF',
      country:'US',tracking_sync_status:'pending',has_liquid:false,exception_type:'进度冲突',
      recipient_name:'NEVER_PROJECT',phone:'PRIVATE',note:'PRIVATE_NOTE',items:[
        {sku:'A',quantity:1,material_full_name:'Sample material',has_liquid:false}
      ]}]}}};
    const row=parseOriginalLogisticsInertiaPage('domestic',payload).rows[0];
    expect(row).toMatchObject({status:'待发货',warehouse_route:'中国工厂',amount:'12.30 USD',
      export_status:'可导出',material_full_name:'Sample material',has_liquid:false,country:'US',provider_reference:'TEST-REF'});
    expect(JSON.stringify(row)).not.toMatch(/NEVER_PROJECT|PRIVATE/);
  });
  it('preserves unknown liquid and export state and maps original overseas dispatch',()=>{
    const payload={component:'Logistics/Oversea/Show',props:{rows:{...pagination,data:[{
      order_id:7,erp_order_id:'UAT-7',warehouse_dispatch:{label:'待下发'},reviewer_name:'Mock reviewer',
      warehouse_name:'Mock warehouse',reviewed_at:'2026-11-20 08:00',shipped_at:'2026-11-20T00:00:00Z',
      items:[{sku:'B',quantity:1}]
    }]}}};
    const row=parseOriginalLogisticsInertiaPage('overseas',payload).rows[0];
    expect(row).toMatchObject({dispatch_status:'待下发',warehouse_name:'Mock warehouse',reviewed_at:'2026-11-20 08:00',export_status:''});
    expect(row?.has_liquid).toBeUndefined();
  });
  it('uses original filter aliases and only supported scope and sort',async()=>{
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');
    vi.stubEnv('VITE_ERP_M2_ORIGINAL_INERTIA_ENABLED','1');
    const urls:string[]=[];
    const fetcher=vi.fn().mockImplementation(async(url:string)=>{
      urls.push(url);
      if(url==='/dashboard')return new Response('<div data-page="{&quot;version&quot;:&quot;dev-v1&quot;}"></div>',{headers:{'content-type':'text/html'}});
      return new Response(JSON.stringify({component:url.startsWith('/logistics/china')?'Logistics/Domestic/Index':'Logistics/Oversea/Show',props:{rows:{...pagination,data:[]}}}),{headers:{'content-type':'application/json'}});
    });
    await getLogisticsPage({scope:'domestic',view:'all',columnFilters:{review_status:'approved',country:'US',sku:'ABC',warehouse_route:'FACTORY',tracking:'X',service:'Standard',shipment_status:'pending'}},fetcher);
    const domestic=new URL(urls.at(-1)!,'http://mock').searchParams;
    expect(domestic.get('filter_review')).toBe('approved');
    expect(domestic.get('filter_destination')).toBe('US');
    expect(domestic.get('filter_sku')).toBe('ABC');
    expect(domestic.get('filter_warehouse_route')).toBe('FACTORY');
    await getLogisticsPage({scope:'domestic',view:'review',columnFilters:{sku:'ABC'},sortCol:'shipped_at'},fetcher);
    expect(urls.at(-1)).not.toMatch(/filter_sku|sort_col/);
    await getLogisticsPage({scope:'overseas',view:'pending',sortCol:'shipped_at',sortDir:'asc',columnFilters:{sku:'ABC',tracking:'X'}},fetcher);
    expect(urls.at(-1)).toContain('sort_col=shipped_at');
    expect(urls.at(-1)).toContain('filter_tracking=X');
    expect(urls.at(-1)).not.toContain('filter_sku');
  });
});

describe('Original allocation and L2 queues',()=>{
  it('reads allocation recommendations and preserves privacy boundary',()=>{
    const payload={component:'Logistics/FulfillmentAllocation',props:{orders:{...pagination,data:[{
      id:1,erp_order_id:'MOCK-1',country:'US',order_date:'2026-11-20',recipient_name:'NEVER_PROJECT',
      items:[{sku:'A',quantity:1,is_gift:false,resolved_fulfillment_sku:'B'}],
      allocation_decision:{status_label:'系统推荐',estimated_cost:12,currency:'CNY',recommended_plan:{segments:[{warehouse_name:'Mock NY'}]}},
      us_fulfillment_zone:{status:'matched',zone_label:'East',warehouse_priority:['NY','CA']}
    }]}}};
    const row=parseOriginalAllocationOrQueue('allocation',payload).rows[0];
    expect(row).toMatchObject({proposed_warehouse:'Mock NY',proposed_cost:'12 CNY',actual_sku:'B',has_gift:false,us_zone:'East'});
    expect(JSON.stringify(row)).not.toContain('NEVER_PROJECT');
  });
  it('uses the original queue endpoint and canonical L2 sort parameters',async()=>{
    vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');vi.stubEnv('VITE_ERP_M2_ORIGINAL_INERTIA_ENABLED','1');
    const urls:string[]=[];
    const fetcher=vi.fn().mockImplementation(async(url:string)=>{
      urls.push(url);
      if(url==='/dashboard')return new Response('<div data-page="{&quot;version&quot;:&quot;dev-v1&quot;}"></div>',{headers:{'content-type':'text/html'}});
      return new Response(JSON.stringify({component:'Logistics/InTransit',props:{shipments:{...pagination,data:[{order_id:2,shipment_id:3,erp_order_id:'MOCK-2',tracking_number:'T',shipment_status:'shipped',items:[{sku:'B',quantity:1}]}]}}}),{headers:{'content-type':'application/json'}});
    });
    const page=await getLogisticsPage({scope:'transit',sortCol:'tracking',sortDir:'asc'},fetcher);
    expect(page.rows[0]).toMatchObject({key:'shipment-3',tracking:'T',shipment_status:'shipped'});
    expect(urls.at(-1)).toContain('/logistics/in-transit?');
    expect(urls.at(-1)).toContain('sort=tracking_number');
    expect(urls.at(-1)).toContain('direction=asc');
    expect(page.canOperate).toBe(false);
  });
});

describe('Domestic P0 original allocation and return contracts',()=>{
 const json=(data:unknown)=>new Response(JSON.stringify(data),{headers:{'content-type':'application/json'}});
 function enable(){vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');vi.stubEnv('VITE_ERP_P0_DOMESTIC_FLOW_ENABLED','1');}
 it('defaults to disabled',async()=>{const f=vi.fn();await expect(submitOriginalDomesticFlow({action:'allocate_factory',orderIds:[1]},f,'XSRF-TOKEN=x')).rejects.toThrow('DISABLED');expect(f).not.toHaveBeenCalled();});
 it('allocates using original factory batch and reports mixed results',async()=>{
  enable();const f=vi.fn().mockResolvedValue(json({succeeded:1,failed:1,results:[{order_id:1,ok:true,message:'OK'},{order_id:2,ok:false,message:'gift'}]}));
  const result=await submitOriginalDomesticFlow({action:'allocate_factory',orderIds:[1,2]},f,'XSRF-TOKEN=abc');
  expect(f.mock.calls[0]?.[0]).toBe('/logistics/wait-stock/batch-allocation');
  expect(JSON.parse(f.mock.calls[0]?.[1].body)).toEqual({order_ids:[1,2],action:'warehouse',warehouse_code:'FACTORY',confirmed:true});expect(result.failed_count).toBe(1);expect(f).toHaveBeenCalledTimes(1);
 });
 it('uses force-return endpoint without additional state restriction',async()=>{
  enable();const f=vi.fn().mockResolvedValue(json({success_count:1,failed_count:0,results:[{order_id:9,ok:true,message:'OK'}]}));
  await submitOriginalDomesticFlow({action:'return_allocation',orderIds:[9]},f,'XSRF-TOKEN=abc');
  expect(f.mock.calls[0]?.[0]).toBe('/logistics/china/batch-return-allocation');expect(JSON.parse(f.mock.calls[0]?.[1].body)).toEqual({order_ids:[9]});
 });
 it('rejects duplicate ids, missing CSRF, duplicate response rows',async()=>{
  enable();const f=vi.fn();await expect(submitOriginalDomesticFlow({action:'return_allocation',orderIds:[1,1]},f,'XSRF-TOKEN=abc')).rejects.toThrow('IDS_INVALID');
  await expect(submitOriginalDomesticFlow({action:'return_allocation',orderIds:[1]},f,'')).rejects.toThrow('CSRF_REQUIRED');expect(f).not.toHaveBeenCalled();
  f.mockResolvedValue(json({success_count:2,failed_count:0,results:[{order_id:1,ok:true},{order_id:1,ok:true}]}));
  await expect(submitOriginalDomesticFlow({action:'return_allocation',orderIds:[1,2]},f,'XSRF-TOKEN=abc')).rejects.toThrow('RESULT_INVALID');
 });
 it('does not retry denied operations',async()=>{enable();const f=vi.fn().mockResolvedValue(new Response('',{status:403}));await expect(submitOriginalDomesticFlow({action:'return_allocation',orderIds:[1]},f,'XSRF-TOKEN=abc')).rejects.toThrow('FAILED_403');expect(f).toHaveBeenCalledTimes(1);});
});

describe('Domestic shipment readback',()=>{
  const row={...parseLogisticsPage('domestic',{data:[order],meta:pagination}).rows[0]!,carrier:'4PX',service:'Standard',tracking:'MOCK-T',shipment_status:'pending'};
  const expected={carrier:'4PX',service:'Standard',tracking:'MOCK-T'};
  it('draft never claims shipment or Woo completion',()=>{
    expect(domesticShipmentReceipt('tracking-draft',row,expected)).toContain('未发货');
    expect(()=>domesticShipmentReceipt('tracking-draft',{...row,shipment_status:'shipped'},expected)).toThrow('UNCONFIRMED');
  });
  it('requires matching current tracking and distinguishes sync outcomes',()=>{
    const shipped={...row,shipment_status:'shipped'};
    expect(()=>domesticShipmentReceipt('quick-ship',row,expected)).toThrow('UNCONFIRMED');
    expect(()=>domesticShipmentReceipt('quick-ship',shipped,{...expected,tracking:'WRONG'})).toThrow('UNCONFIRMED');
    expect(domesticShipmentReceipt('quick-ship',{...shipped,tracking_sync_status:'pending'},expected)).toContain('待同步');
    expect(domesticShipmentReceipt('quick-ship',{...shipped,tracking_sync_status:'conflict',tracking_sync_reason:'Mock conflict'},expected)).toContain('冲突');
    expect(domesticShipmentReceipt('quick-ship',{...shipped,tracking_sync_status:'completed'},expected)).toContain('已验证');
  });
});

describe('Original manual allocation and barcode readback',()=>{
 const json=(data:unknown,status=200)=>new Response(JSON.stringify(data),{status,headers:{'content-type':'application/json'}});
 const plan=parseAllocationPlan({allocation_decision:{can_use_recommendation:true,recommended_plan_key:'mock-key',recommended_plan:{segments:[{warehouse_name:'Factory'}]}},items:[{id:11,sku:'gift',is_gift:true,quantity:1,gift_options_by_warehouse:{FACTORY:[{mapping_id:22,fulfillment_sku:'MOCK-GIFT',quantity_available:0}]}},{id:12,sku:'MOCK-SKU',quantity:1}],carrier_routes:{}});
 const assignments=[{order_item_id:11,source_code:'FACTORY',gift_mapping_id:22,carrier:null,service:null},{order_item_id:12,source_code:'FACTORY',gift_mapping_id:null,carrier:null,service:null}];
 const input={orderId:1,mode:'manual' as const,plan,warehouses:[{code:'FACTORY',name:'Factory'}],assignments};
 function enable(){vi.stubEnv('VITE_ERP_IDENTITY_MODE','laravel');vi.stubEnv('VITE_ERP_P0_DOMESTIC_FLOW_ENABLED','1');}
 it('preflight then submit once then reads matching saved gift assignments',async()=>{
  enable();const f=vi.fn().mockResolvedValueOnce(json({can_submit:true})).mockResolvedValueOnce(json({status:'submitted'})).mockResolvedValueOnce(json({status:'submitted',decision_source:'manual',assignments}));
  await expect(submitOriginalAllocation(input,f,'XSRF-TOKEN=mock')).resolves.toMatchObject({status:'submitted'});
  expect(f.mock.calls.map(c=>c[0].split('/').pop())).toEqual(['preflight','manual','status']);
 });
 it('rejects gift from wrong warehouse before request',async()=>{
  enable();const f=vi.fn();await expect(submitOriginalAllocation({...input,assignments:[{...assignments[0]!,gift_mapping_id:99},assignments[1]!]},f,'XSRF-TOKEN=mock')).rejects.toThrow('真实 SKU');expect(f).not.toHaveBeenCalled();
 });
 it('preflight failure prevents mutation',async()=>{
  enable();const f=vi.fn().mockResolvedValueOnce(json({can_submit:false,issues:[{reason:'mock blocked'}]}));
  await expect(submitOriginalAllocation(input,f,'XSRF-TOKEN=mock')).rejects.toThrow('mock blocked');expect(f).toHaveBeenCalledTimes(1);
 });
 it('recovers committed response loss by status only and never repeats POST',async()=>{
  enable();const f=vi.fn().mockResolvedValueOnce(json({can_submit:true})).mockRejectedValueOnce(Error('lost')).mockResolvedValueOnce(json({status:'submitted',decision_source:'manual',assignments}));
  await expect(submitOriginalAllocation(input,f,'XSRF-TOKEN=mock')).resolves.toMatchObject({status:'submitted'});expect(f).toHaveBeenCalledTimes(3);
 });
 it('rejects unrelated submitted allocation',async()=>{
  enable();const f=vi.fn().mockResolvedValueOnce(json({can_submit:true})).mockResolvedValueOnce(json({status:'submitted'})).mockResolvedValueOnce(json({status:'submitted',decision_source:'manual',assignments:[{...assignments[0]!,gift_mapping_id:99},assignments[1]]}));
  await expect(submitOriginalAllocation(input,f,'XSRF-TOKEN=mock')).rejects.toThrow('未确认');
 });
 it('recommended plan submits exact key and verifies source',async()=>{
  enable();const f=vi.fn().mockResolvedValueOnce(json({status:'submitted'})).mockResolvedValueOnce(json({status:'submitted',decision_source:'system_recommendation',selected_plan_key:'mock-key'}));
  await submitOriginalAllocation({...input,mode:'recommended'},f,'XSRF-TOKEN=mock');expect(JSON.parse(f.mock.calls[0]![1].body).plan_key).toBe('mock-key');expect(f).toHaveBeenCalledTimes(2);
 });
 it('preserves barcode leading zeros and rejects mismatched order',async()=>{
  const f=vi.fn().mockResolvedValueOnce(json({order_id:1,items:[{sku:'MOCK',quantity:1,barcode_69:'0691234567890'}]})).mockResolvedValueOnce(json({order_id:2,items:[]}));
  await expect(readDomesticBarcode69(1,f)).resolves.toEqual([{sku:'MOCK',quantity:1,barcode:'0691234567890'}]);
  await expect(readDomesticBarcode69(1,f)).rejects.toThrow('CONTRACT_INVALID');
 });
});
