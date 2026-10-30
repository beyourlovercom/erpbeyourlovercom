// Offline Chromium geometry test for the Owner-approved left navigation.
const fs=require('node:fs'),path=require('node:path'),{chromium}=require('playwright');
const root=path.resolve(__dirname,'../..');
const read=f=>fs.readFileSync(path.join(root,f),'utf8');
const scoped=f=>read(f).split('<style scoped>')[1]?.split('</style>')[0]||'';
(async()=>{
 const browser=await chromium.launch({headless:true});
 let failures=0;
 try {
  for(const name of ['Create','Edit']) {
   const css=read('resources/css/shell.css')+'\n'+scoped('resources/js/Pages/Products/'+name+'.vue')+'\n'+scoped('resources/js/Components/Products/ProductSectionRail.vue')+'\n.uat-sidebar{position:fixed;left:0;top:0;width:260px;height:100vh}.uat-shell{margin-left:260px;min-height:100vh}';
   for(const width of [1920,1600,1440,1366,1280,1152]){
    const page=await browser.newPage({viewport:{width,height:900}});
    await page.setContent('<div class="uat-sidebar"></div><main class="uat-shell"><div class="erp-form-page product-editor-page"><div class="product-editor-layout"><aside class="product-section-nav-rail"><nav class="product-section-rail"><span class="product-section-caption">快捷定位</span><button class="product-section-item">基本信息</button><button class="product-section-top">↑ 返回顶部</button></nav></aside><div class="erp-form-content"><div class="erp-form-header-row">产品</div><article class="erp-form-card" style="min-height:1800px">产品参数</article></div></div></div></main>');
    await page.addStyleTag({content:css});
    const v=await page.evaluate(()=>{const r=document.querySelector('.product-section-nav-rail').getBoundingClientRect(),m=document.querySelector('.erp-form-content').getBoundingClientRect();return {left:r.left,gap:m.left-r.right,width:m.width}});
    await page.evaluate(()=>scrollTo(0,330));
    await page.waitForTimeout(70);
    const sticky=await page.locator('.product-section-nav-rail').evaluate(el=>el.getBoundingClientRect().top);
    const ok=v.left>=268&&Math.abs(v.gap-5)<=1&&v.width>=690&&Math.abs(sticky-108)<=1;
    if(!ok)failures++;
    console.log(ok?'PASS':'FAIL',name,width,JSON.stringify({railLeft:Math.round(v.left),gap:Math.round(v.gap),contentWidth:Math.round(v.width),stickyTop:Math.round(sticky)}));
    await page.close();
   }
  }
 }finally{await browser.close();}
 if(failures){console.error('FAILURES:',failures);process.exitCode=1}
})().catch(e=>{console.error(e);process.exitCode=1});
