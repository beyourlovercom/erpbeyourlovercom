'use strict';
// ERP2 private Dev ingress: BasicAuth + 007windows-built static assets served at the Mac edge.
// Original Laravel business traffic remains on the isolated 007windows-to-Dev gateway.
// Never route this app to ERP1 Production or accept public access without the Dev password.
const http=require('node:http');
const fs=require('node:fs');
const path=require('node:path');
const crypto=require('node:crypto');
const host='127.0.0.1',port=18815,target='100.99.21.57',targetPort=18810;
const domain='erp2.beyourlover.com';
const staticRoot='/Users/a/ai/erp2-dev-ingress/static-vben-20261123';
const auth=JSON.parse(fs.readFileSync(__dirname+'/auth.json','utf8'));
const expected=Buffer.from(auth.sha256,'hex');
const mimes={'.html':'text/html; charset=utf-8','.js':'text/javascript; charset=utf-8',
 '.css':'text/css; charset=utf-8','.json':'application/json; charset=utf-8',
 '.svg':'image/svg+xml','.ico':'image/x-icon','.png':'image/png',
 '.jpg':'image/jpeg','.jpeg':'image/jpeg','.webp':'image/webp',
 '.woff':'font/woff','.woff2':'font/woff2','.ttf':'font/ttf'};
const noindex='noindex, nofollow, noarchive';
function permitted(header){
 if(!header?.startsWith('Basic '))return false;
 let decoded;try{decoded=Buffer.from(header.slice(6),'base64').toString('utf8');}
 catch{return false;}
 const actual=crypto.createHash('sha256').update(decoded).digest();
 return actual.length===expected.length&&crypto.timingSafeEqual(actual,expected);
}
function fail(res,code,msg){
 const extra=code===401?{'www-authenticate':'Basic realm="ERP2 Development Preview", charset="UTF-8"'}:{};
 res.writeHead(code,{'content-type':'text/plain; charset=utf-8','cache-control':'no-store, private',
 'x-content-type-options':'nosniff','x-robots-tag':noindex,...extra});
 res.end(msg);
}
function serveStatic(req,res){
 if(req.method!=='GET'&&req.method!=='HEAD')return false;
 let pathname;
 try{pathname=decodeURIComponent(new URL(req.url,'http://erp2.local').pathname);}
 catch{fail(res,400,'Invalid URL');return true;}
 if(pathname.includes('\\')||pathname.includes('\0')){fail(res,400,'Invalid asset path');return true;}
 const logical=pathname==='/'?'/index.html':pathname;
 const file=path.resolve(staticRoot,'.'+logical);
 if(!file.startsWith(staticRoot+path.sep)){fail(res,403,'Asset path denied');return true;}
 let stat;
 try{stat=fs.statSync(file);}catch{return false;}
 if(!stat.isFile())return false;
 // Only frontend asset types; do not expose archives, env or staging artifacts.
 if(!Object.hasOwn(mimes,path.extname(file).toLowerCase())){
  fail(res,404,'Dev asset unavailable');return true;
 }
 const hashAsset=/-[A-Za-z0-9_-]{8,}\.[A-Za-z0-9]+$/.test(path.basename(file));
 res.writeHead(200,{'content-type':mimes[path.extname(file).toLowerCase()]||'application/octet-stream',
  'content-length':stat.size,'x-robots-tag':noindex,'x-content-type-options':'nosniff',
  'x-erp2-static-mirror':'007windows-build-20261123',
  'cache-control':hashAsset?'private, max-age=86400, immutable':'no-store, private'});
 if(req.method==='HEAD')return res.end();
 fs.createReadStream(file).pipe(res);
 return true;
}
const server=http.createServer((req,res)=>{
 if(req.url==='/__erp2_ingress_health'&&req.method==='GET'){
   return fail(res,200,'ERP2 authenticated edge active (007windows static mirror)');
 }
 if(!permitted(req.headers.authorization))return fail(res,401,'ERP2 Dev access credential required');
 // The Tailnet sync bundle is not part of the public Dev website.
 const packagePath=(req.url||'').split('?')[0];
 if(packagePath==='/erp2-static-mirror-20261123.zip' || packagePath==='/erp2-static-mirror.sha256')
  return fail(res,404,'Dev package is Tailnet-only');
 // Static assets are built exclusively on 007windows and mirrored as one checked archive,
 // avoiding 100+ chained Cloudflare->Mac->Windows roundtrips on page boot.
 if(serveStatic(req,res))return;
 const headers={...req.headers,host:target+':'+targetPort};
 delete headers.authorization;delete headers['proxy-authorization'];delete headers['proxy-connection'];
 const req2=http.request({hostname:target,port:targetPort,method:req.method,path:req.url,
   headers,timeout:20000},up=>{
   const h={...up.headers};
   if(typeof h.location==='string'){
     for(const from of ['http://'+target+':'+targetPort,'http://127.0.0.1:18810']){
       if(h.location===from){h.location='https://'+domain+'/';break;}
       if(h.location.startsWith(from+'/')){h.location='https://'+domain+h.location.slice(from.length);break;}
     }
   }
   h['x-robots-tag']=noindex;h['cache-control']='no-store, private';
   res.writeHead(up.statusCode||502,h);up.pipe(res);
 });
 req2.on('timeout',()=>req2.destroy(Error('DEV_ORIGIN_TIMEOUT')));
 req2.on('error',e=>{if(!res.headersSent)fail(res,502,'ERP2 Windows Dev origin unavailable');else res.destroy(e)});
 req.pipe(req2);
});
server.listen(port,host,()=>console.log('ERP2_DEV_AUTH_STATIC_EDGE_READY http://'+host+':'+port));
