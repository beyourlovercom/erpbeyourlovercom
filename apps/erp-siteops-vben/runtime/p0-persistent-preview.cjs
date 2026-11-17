'use strict';
/**
 * P0-A..D UAT on 007windows only; previous port 18789 remains untouched.
 * Never proxies Production orders or mutates Production permissions.
 */
const {createBridge,windowsDevSessionStorage}=require('./p0-production-identity-bridge.cjs');
const port=18790;
const distDir='D:\\AI\\erp-siteops\\p0-persistent-uat-dist-20261117';
const sessionStorage=windowsDevSessionStorage();
createBridge({distDir,sessionStorage,enableCredentials:true,port,host:'127.0.0.1'})
  .listen(port,'127.0.0.1',()=>{
    process.stdout.write('ERP2 P0 UAT ready at http://127.0.0.1:'+port+'\n');
  });
