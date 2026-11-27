const {chromium}=require('playwright');const assert=require('node:assert/strict');const path=require('path');
(async()=>{
 const {createServer}=await import('vite');const {default:vue}=await import('@vitejs/plugin-vue');
 const server=await createServer({configFile:false,root:__dirname,plugins:[vue()],resolve:{alias:{'#':__dirname}},define:Object.fromEntries(['VITE_ERP_M2_ORIGINAL_INERTIA_ENABLED','VITE_ERP_M2_REVIEW_WRITE_ENABLED','VITE_ERP_M2_SHIPPING_WRITE_ENABLED','VITE_ERP_P0_DOMESTIC_FLOW_ENABLED','VITE_ERP_P0_DOMESTIC_APPLY_ENABLED'].map(k=>['import.meta.env.'+k,JSON.stringify('1')]).concat([['import.meta.env.VITE_ERP_IDENTITY_MODE',JSON.stringify('laravel')]])),server:{host:'127.0.0.1',port:0,strictPort:true},logLevel:'error'});
 await server.listen();const url='http://127.0.0.1:'+server.httpServer.address().port;
 const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true,args:['--disable-background-networking']});
 const c=await browser.newContext({viewport:{width:1600,height:1000},acceptDownloads:true});
 await c.addCookies([{name:'XSRF-TOKEN',value:'mock-xsrf',url}]);const p=await c.newPage();p.setDefaultTimeout(8000);const errors=[],posts=[];p.on('pageerror',e=>errors.push(String(e)));p.on('dialog',d=>d.accept());
 let state='allocation',carrier='4PX',service='Standard',tracking='',preview=false,shipCount=0,giftMode=false,savedAssignments=[];
 const row=()=>({id:101,order_id:101,erp_order_id:'MOCK-CN-101',order_date:'2026-11-24',routing_warehouse:{code:'FACTORY',name:'Factory'},workflow_status_projection:{zh:state==='shipped'?'已发货':'待发货'},review_status:state==='review'?'pending':'approved',shipment_status:state==='shipped'?'shipped':'pending',carrier,service,tracking_number:tracking,tracking_sync_status:state==='shipped'?'pending':'',items:giftMode&&state==='allocation'?[{id:11,sku:'gift',is_gift:true,quantity:1,gift_options_by_warehouse:{FACTORY:[{mapping_id:22,fulfillment_sku:'MOCK-GIFT',quantity_available:0}]}},{id:12,sku:'MOCK-SKU',quantity:1}]:[{id:12,sku:'MOCK-SKU',quantity:1}],carrier_routes:{'US-NY':{carrier:'USPS',service:'Priority'}}});
 const pager=data=>({data,current_page:1,per_page:20,last_page:1,total:data.length});
 const token='a'.repeat(64);
 await c.route('**/*',async r=>{
 const req=r.request(),u=new URL(req.url()),method=req.method(),pathname=u.pathname;
 if(u.origin!==url)return r.abort();
 if(pathname.startsWith('/ui/preferences/'))return r.fulfill({json:{value:null}});
 if(pathname==='/logistics/wait-stock'&&method==='GET')return r.fulfill({json:{component:'Logistics/FulfillmentAllocation',props:{canOperate:true,warehouses:[{code:'FACTORY',name:'Factory'},{code:'US-NY',name:'NY'}],orders:pager(state==='allocation'?[row()]:[])}}});
 if(pathname==='/logistics/china'&&method==='GET'){const v=u.searchParams.get('view'),data= v==='all'&&state!=='allocation'||v==='review'&&state==='review'||v==='pending'&&state==='pending'?[row()]:[];
 return r.fulfill({json:{component:'Logistics/Domestic/Index',props:{canOperate:true,canReview:true,rows:pager(data),carrierOptions:[{value:'4PX',label:'4PX',default_service_value:'Standard',services:[{value:'Standard',label:'Standard'}]},{value:'Weship',label:'Weship',default_service_value:'Standard',services:[{value:'Standard',label:'Standard'}]}],trackingImportProvider:preview?'generic':null,trackingImportPreview:preview?{token,apply_count:1,noop_count:0,blocked_count:0,rows:[{order_number:'MOCK-CN-101',tracking_number:'MOCK-RETURN',status:'apply',message:'OK'}]}:null}}});}
 if(pathname.endsWith('/fulfillment-allocation/status')&&method==='GET')return r.fulfill({json:{status:'submitted',decision_source:'manual',assignments:savedAssignments}});
 if(pathname.endsWith('/barcode-69')&&method==='GET')return r.fulfill({json:{order_id:101,items:[{sku:'MOCK-SKU',quantity:1,barcode_69:'0691234567890'}]}});
 if(pathname.startsWith('/logistics/')&&method==='POST'){
  assert.equal(req.headers()['x-xsrf-token'],'mock-xsrf');posts.push(pathname);
  if(pathname.endsWith('/tracking-import/preview')){preview=true;return r.fulfill({json:{provider:'generic',message:'MOCK',apply_count:1,noop_count:0,blocked_count:0}});}
  const body=req.postDataJSON();
  if(pathname.endsWith('/fulfillment-allocation/preflight')){assert.equal(body.assignments[0].gift_mapping_id,22);assert.equal(body.assignments[1].source_code,'US-NY');return r.fulfill({json:{can_submit:true}});}
  if(pathname.endsWith('/fulfillment-allocation/manual')){savedAssignments=body.assignments;state='review';return r.fulfill({json:{status:'submitted'}});}
  if(pathname==='/logistics/wait-stock/batch-allocation'){assert.equal(state,'allocation');assert.deepEqual(body,{order_ids:[101],action:'warehouse',warehouse_code:'FACTORY',confirmed:true});state='review';return r.fulfill({json:{succeeded:1,failed:0,results:[{order_id:101,ok:true,message:'OK'}]}});}
  if(pathname.endsWith('/batch-review')){assert.equal(body.action,'approve');state='pending';return r.fulfill({json:{success_count:1,failed_count:0,results:[{order_id:101,ok:true,message:'OK'}]}});}
  if(pathname.endsWith('/batch-return-allocation')){state='allocation';return r.fulfill({json:{success_count:1,failed_count:0,results:[{order_id:101,ok:true,message:'OK'}]}});}
  if(pathname.endsWith('/carrier-service')){carrier=body.carrier;service=body.service;return r.fulfill({status:302,headers:{location:'/logistics/china?view=pending'}});}
  if(pathname.endsWith('/tracking-draft')){tracking=body.tracking_number;return r.fulfill({status:302,headers:{location:'/logistics/china?view=pending'}});}
  if(pathname.endsWith('/quick-ship')){tracking=body.tracking_number;state='shipped';shipCount++;return r.fulfill({status:302,headers:{location:'/logistics/china?view=pending'}});}
  if(pathname.endsWith('/tracking-import/apply')){assert.equal(body.token,token);assert.ok(preview);preview=false;tracking='MOCK-RETURN';state='shipped';return r.fulfill({json:{applied_count:1,noop_count:0,blocked_count:0,failed_count:0,sync_pending_count:1,sync_handoff_failed_count:0,message:'OK'}});}
  throw Error('unexpected POST '+pathname);
 }
 if(pathname.startsWith('/logistics/')&&method==='GET')return r.fulfill({status:200,headers:{'content-type':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','content-disposition':'attachment; filename="mock.xlsx"'},body:'MOCK DOWNLOAD'});
 return r.continue();
 });
 const wait=async()=>{await p.waitForFunction(()=>!document.querySelector('.oms-grid-layout-loading')&&!document.querySelector('[aria-busy="true"]'));};
 const rowVisible=async()=>{await p.locator('.ag-cell[col-id="order_number"]').getByText('MOCK-CN-101').waitFor();};
 const select=async()=>{await rowVisible();const checkbox=p.locator('.ag-row .ag-selection-checkbox input').first(); console.log(JSON.stringify(await checkbox.evaluate(e=>{const rect=e.getBoundingClientRect(),h=document.elementFromPoint(rect.x+rect.width/2,rect.y+rect.height/2);return {checkbox:rect.toJSON(),hit:h?.className,parents:[...document.querySelectorAll('.ag-grid-scrolling-rows,.ag-body-horizontal-scroll,.ag-body-viewport,.oms-ag-grid,.oms-workspace')].map(n=>({class:n.className,rect:n.getBoundingClientRect().toJSON(),height:getComputedStyle(n).height,minHeight:getComputedStyle(n).minHeight}))}})));await checkbox.check();};
 const detail=async()=>{await rowVisible();await p.locator('.ag-cell[col-id="action"]').first().click();await p.getByRole('heading',{name:'MOCK-CN-101'}).waitFor();};
 const nav=async(v)=>{await p.getByRole('button',{name:v,exact:true}).click();await wait();};
 const allocate=async()=>{await select();await p.getByTestId('p0-batch-factory').click();await p.getByText(/成功 1 单，失败 0 单/).waitFor();await wait();};
 const approve=async()=>{await detail();await p.getByRole('button',{name:'审核通过',exact:true}).click();await p.getByText(/审核通过成功/).waitFor();await wait();};
 try {
 await p.goto(url);await allocate();console.log('PASS D1 allocation');
 await p.getByTestId('domestic').click();await approve();await nav('待发货');await select();await p.getByTestId('p0-batch-return-allocation').click();await p.getByText(/成功 1 单，失败 0 单/).waitFor();assert.equal(state,'allocation');console.log('PASS D1 approval and force return');
 giftMode=true;await p.getByTestId('allocation').click();await detail();await p.getByLabel('分仓 gift',{exact:true}).selectOption('FACTORY');await p.getByLabel('礼品 gift',{exact:true}).selectOption('22');await p.getByLabel('分仓 MOCK-SKU',{exact:true}).selectOption('US-NY');await p.getByTestId('p0-manual-allocate').click();await p.getByText('分仓已通过原 ERP 回读确认').waitFor();assert.equal(state,'review');console.log('PASS manual split and gift mapping');giftMode=false;await p.getByTestId('domestic').click();await approve();await nav('待发货');
 await detail();await p.getByRole('button',{name:'查看欧洲包裹69条码'}).click();await p.getByText(/0691234567890/).waitFor();console.log('PASS barcode leading zeros');await p.getByLabel('国内物流商').selectOption('Weship');await p.getByRole('button',{name:'保存物流商 / 服务（未发货）',exact:true}).click();await p.getByText(/物流商\/服务已保存（未发货）/).waitFor();await wait();await detail();await p.getByLabel('发货 Tracking').fill('MOCK-TRACK');await p.getByRole('button',{name:'仅保存 Tracking 草稿'}).click();await p.getByText(/Tracking 草稿已保存（未发货）/).waitFor();assert.equal(state,'pending');console.log('PASS D2 carrier and draft readback');
 await detail();await p.getByRole('button',{name:'确认实际发货…'}).click();await p.getByText(/ERP 发货确认已落库；官网待同步/).waitFor();assert.equal(shipCount,1);console.log('PASS D2/D4 ship and pending sync readback');
 state='pending';tracking='';await p.getByRole('button',{name:'↻ 刷新数据'}).click();await rowVisible();await select();
 const href=await p.getByTestId('p0-export-domestic-selected').getAttribute('href');assert.equal(new URL(href,url).searchParams.get('order_ids[]'),'101');
 const downloadPromise=p.waitForEvent('download');await p.getByTestId('p0-export-domestic-selected').click();const download=await downloadPromise;assert.equal(download.suggestedFilename(),'mock.xlsx');console.log('PASS D3 selected export');
 await p.getByTestId('p0-domestic-tracking-return-file').setInputFiles({name:'mock.xlsx',mimeType:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',buffer:Buffer.from('MOCK')});await p.getByTestId('p0-domestic-tracking-preview').click();
 await p.getByText('MOCK-RETURN',{exact:true}).waitFor();await p.getByTestId('p0-domestic-tracking-apply').click();await p.getByText(/发货登记 1 单；重复 0；阻断 0；失败 0；官网待同步 1/).waitFor();assert.equal(state,'shipped');console.log('PASS D3 preview, confirmed apply and sync counts');
 assert.equal(errors.length,0,errors.join(';'));console.log('PASS no browser errors; POSTs='+posts.length);
 }finally{await browser.close();await server.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
