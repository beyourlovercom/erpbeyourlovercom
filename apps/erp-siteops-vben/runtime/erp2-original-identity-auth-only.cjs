'use strict';
// ERP2 R2 auth-only UAT gateway. Loopback only. No Production business endpoints.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { randomBytes } = require('node:crypto');
const HOST = '127.0.0.1';
const PROD = 'https://erp.beyourlover.com';
const COOKIE = 'ERP2_R2_HANDLE';
const MAX_AGE_MS = 15 * 60 * 1000;
const MIME = {'.html':'text/html; charset=utf-8','.js':'text/javascript; charset=utf-8',
  '.css':'text/css; charset=utf-8','.json':'application/json; charset=utf-8',
  '.ico':'image/x-icon','.svg':'image/svg+xml','.png':'image/png',
  '.woff':'font/woff','.woff2':'font/woff2'};
function send(res, status, object, extra={}) {
  res.writeHead(status, {'content-type':'application/json; charset=utf-8',
    'cache-control':'no-store, private','x-content-type-options':'nosniff',...extra});
  res.end(JSON.stringify(object));
}
function getHandle(header='') {
  const match=header.match(/(?:^|;\s*)ERP2_R2_HANDLE=([A-Za-z0-9_-]{32,64})(?:;|$)/);
  return match?.[1];
}
function collectSetCookies(headers, jar) {
  const values=typeof headers.getSetCookie==='function'?headers.getSetCookie():[];
  for(const raw of values) {
    const match=raw.match(/^([^=;]+)=([^;]*)/);
    if(match && ['laravel_session','XSRF-TOKEN'].includes(match[1]))
      jar[match[1]]=match[2];
  }
}
function sessionCookie(handle, age=900) {
  return COOKIE+'='+handle+'; HttpOnly; Path=/; SameSite=Strict; Max-Age='+age;
}
async function readPostBody(req) {
  const parts=[];let bytes=0;
  for await(const chunk of req) {
    bytes+=chunk.length;
    if(bytes>8192)throw Error('AUTH_BODY_TOO_LARGE');
    parts.push(chunk);
  }
  return Buffer.concat(parts);
}
function createServer({fetchImpl=fetch,distDir=process.env.ERP2_R2_VBEN_DIST,now=Date.now}={}) {
  if(!distDir || !path.isAbsolute(distDir))throw Error('ERP2_R2_ABSOLUTE_DIST_REQUIRED');
  const sessions=new Map();
  async function upstream(target, req, session, method='GET', body) {
    const headers={Accept:target==='/_erp2/identity'?'application/json':'text/html',
      'X-Requested-With':'XMLHttpRequest'};
    if(Object.keys(session.jar).length)
      headers.Cookie=Object.entries(session.jar).map(([k,v])=>k+'='+v).join('; ');
    if(body)headers['Content-Type']='application/x-www-form-urlencoded';
    // Only /login accepts browser CSRF. Logout uses the server-held Laravel XSRF cookie.
    if(target==='/login'&&req.headers['x-csrf-token'])
      headers['X-CSRF-TOKEN']=req.headers['x-csrf-token'];
    if(target==='/logout' && session.jar['XSRF-TOKEN'])
      headers['X-XSRF-TOKEN']=decodeURIComponent(session.jar['XSRF-TOKEN']);
    const result=await fetchImpl(PROD+target,{method,headers,body,redirect:'manual',
      signal:AbortSignal.timeout(12000)});
    collectSetCookies(result.headers,session.jar);
    return result;
  }
  function staticFile(req,res,url) {
    if(!['GET','HEAD'].includes(req.method))return send(res,405,{error:'R2_STATIC_READ_ONLY'});
    let pathname;
    try{pathname=decodeURIComponent(url.pathname);}catch{return send(res,400,{error:'BAD_PATH'});}
    let file=path.resolve(distDir,'.'+pathname);
    if(file!==distDir&&!file.startsWith(distDir+path.sep))
      return send(res,403,{error:'BAD_PATH'});
    try{if(!fs.statSync(file).isFile())file=path.join(distDir,'index.html');}
    catch{file=path.join(distDir,'index.html');}
    if(!fs.existsSync(file))return send(res,503,{error:'R2_VBEN_DIST_NOT_FOUND'});
    res.writeHead(200,{'content-type':MIME[path.extname(file)]||'application/octet-stream',
      'x-content-type-options':'nosniff','cache-control':file.endsWith('index.html')?'no-store':'public,max-age=300'});
    if(req.method==='HEAD')return res.end();
    fs.createReadStream(file).pipe(res);
  }
  return http.createServer(async(req,res)=>{
    const url=new URL(req.url||'/', 'http://'+HOST);
    if(url.pathname==='/api/health'&&req.method==='GET')
      return send(res,200,{mode:'ERP2_R2_ORIGINAL_IDENTITY_ONLY',
        production_business_connected:false,synthetic_data_connected:false});
    // Business paths NEVER proxy to the original ERP or the synthetic Mac backend.
    if(url.pathname==='/dashboard' && req.method==='GET' && !url.search) {
      // Frontend logout reads CSRF from this path. Keep it synthetic and never
      // fetch the real Production dashboard or its potentially sensitive HTML.
      res.writeHead(200,{'content-type':'text/html; charset=utf-8',
        'cache-control':'no-store, private','x-content-type-options':'nosniff'});
      return res.end('<meta name="csrf-token" content="R2_LOCAL_LOGOUT_ONLY">');
    }
    if(url.pathname.startsWith('/api/') || url.pathname==='/dashboard')
      return send(res,403,{error:'R2_AUTH_ONLY_NO_BUSINESS_DATA'});
    if(!['/login','/logout','/_erp2/identity'].includes(url.pathname))
      return staticFile(req,res,url);
    if((url.pathname==='/login'&&!['GET','POST'].includes(req.method))||
       (url.pathname==='/logout'&&req.method!=='POST')||
       (url.pathname==='/_erp2/identity'&&req.method!=='GET')||
       url.search)return send(res,405,{error:'R2_AUTH_METHOD_OR_QUERY_DENIED'});
    try {
      const handle=getHandle(req.headers.cookie||'');
      let session=handle?sessions.get(handle):undefined;
      if(session && now()-session.touched>MAX_AGE_MS) {
        sessions.delete(handle);session=undefined;
      }
      if(!session && url.pathname==='/login'&&req.method==='GET') {
        for(const [id,value] of sessions)
          if(now()-value.touched>MAX_AGE_MS)sessions.delete(id);
        if(sessions.size>=24)return send(res,429,{error:'R2_AUTH_SESSION_CAP'});
        const id=randomBytes(24).toString('base64url');
        session={id,jar:{},touched:now()};
        sessions.set(id,session);
      }
      if(!session)return send(res,401,{error:'R2_LOGIN_SESSION_REQUIRED'});
      session.touched=now();
      if(url.pathname==='/login'&&req.method==='GET') {
        const result=await upstream('/login',req,session);
        if(result.status!==200)return send(res,503,{error:'ORIGINAL_ERP_LOGIN_UNAVAILABLE'});
        const html=await result.text();
        if(!html.includes('csrf-token'))return send(res,503,{error:'ORIGINAL_ERP_CSRF_MISSING'});
        res.writeHead(200,{'content-type':'text/html; charset=utf-8',
          'cache-control':'no-store, private','set-cookie':sessionCookie(session.id)});
        return res.end(html);
      }
      if(url.pathname==='/login') {
        if(!req.headers['x-csrf-token'])return send(res,419,{error:'R2_CSRF_REQUIRED'});
        const result=await upstream('/login',req,session,'POST',await readPostBody(req));
        if(result.status!==302&&result.status!==200)
          return send(res,502,{error:'ORIGINAL_ERP_LOGIN_FAILED'});
        // NEVER follow /dashboard or return any Production dashboard HTML.
        return send(res,200,{next:'verify_original_identity'});
      }
      if(url.pathname==='/logout') {
        if(!session.jar['XSRF-TOKEN'])return send(res,419,{error:'R2_XSRF_REQUIRED'});
        const result=await upstream('/logout',req,session,'POST',Buffer.alloc(0));
        if(result.status!==302&&result.status!==200)
          return send(res,502,{error:'ORIGINAL_ERP_LOGOUT_FAILED'});
        const still=await upstream('/_erp2/identity',req,session);
        if(still.status===200)return send(res,502,{error:'ORIGINAL_ERP_LOGOUT_NOT_CONFIRMED'});
        if(![302,401,403].includes(still.status))
          return send(res,502,{error:'ORIGINAL_ERP_LOGOUT_UNVERIFIED'});
        sessions.delete(session.id);
        return send(res,200,{logout:true},{'set-cookie':sessionCookie(session.id,0)});
      }
      const identity=await upstream('/_erp2/identity',req,session);
      if([302,401,403].includes(identity.status))
        return send(res,401,{error:'R2_ORIGINAL_SESSION_REQUIRED'});
      if(identity.status!==200 || !(identity.headers.get('content-type')||'').includes('application/json'))
        return send(res,503,{error:'R2_ORIGINAL_IDENTITY_UNAVAILABLE'});
      const data=await identity.json();
      if(data.source!=='ERP_ORIGINAL_LARAVEL_PRODUCTION_SESSION_V1'||
         !Number.isSafeInteger(data.user?.id)||!Array.isArray(data.navigation)||
         !Array.isArray(data.effectivePermissionKeys))
        return send(res,502,{error:'R2_ORIGINAL_IDENTITY_INVALID'});
      return send(res,200,data);
    } catch(error) {
      console.error('[ERP2 R2 auth-only]',String(error?.message||error));
      return send(res,502,{error:'R2_ORIGINAL_AUTH_UNAVAILABLE'});
    }
  });
}
if(require.main===module) {
  const port=Number(process.argv[3]||process.env.ERP2_R2_DEV_PORT||'18797');
  if(!Number.isInteger(port)||port<1024||port>65535)throw Error('INVALID_PORT');
  createServer({distDir:process.argv[2]||process.env.ERP2_R2_VBEN_DIST})
    .listen(port,HOST,()=>console.log('ERP2_R2_AUTH_ONLY_READY '+HOST+':'+port));
}
module.exports={createServer};
