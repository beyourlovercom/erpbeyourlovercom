'use strict';
/**
 * ERP2 isolated identity-only relay. The ORIGINAL ERP Laravel session is
 * verified server-to-server; its cookies are never sent to the Dev browser.
 *
 * This bridge NEVER accesses production orders, customers, tracking or other
 * business routes. Staff login remains explicitly gated until the original
 * ERP current effective-permission projection is verified end-to-end.
 */
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');

const ORIGINAL_ERP = 'https://erp.beyourlover.com';
const HOST = '127.0.0.1';
const PORT = 18788;
const MAX_BODY = 8192;
const MAX_REMOTE = 2_000_000;
const TTL = 60 * 24 * 60 * 60_000; // Dev session ceiling: 60 days, not an upstream guarantee.
const COOKIE_MAX_AGE = Math.floor(TTL / 1000);
const COOKIE = 'erp2_identity_dev';
const STATIC = {'.html':'text/html; charset=utf-8','.js':'text/javascript; charset=utf-8',
  '.css':'text/css; charset=utf-8','.json':'application/json','.svg':'image/svg+xml',
  '.png':'image/png','.woff':'font/woff','.woff2':'font/woff2','.ico':'image/x-icon'};

function makeId() { return crypto.randomBytes(32).toString('base64url'); }
function cookieHeader(jar) { return [...jar].map(([k,v]) => `${k}=${v}`).join('; '); }
function collectCookies(headers,jar) {
  for (const header of headers.getSetCookie()) {
    const [pair] = header.split(';');
    const at = pair.indexOf('=');
    if (at > 0 && /^[\w-]+$/.test(pair.slice(0,at))) jar.set(pair.slice(0,at),pair.slice(at+1));
  }
}
function reply(res,status,value,extra={}) {
  res.writeHead(status,{'cache-control':'private, no-store, max-age=0',
    'x-content-type-options':'nosniff','x-frame-options':'DENY',
    'content-type':'application/json; charset=utf-8',...extra});
  res.end(JSON.stringify(value));
}
function cookieValue(req) {
  const raw = String(req.headers.cookie || '').split(';').map(s=>s.trim());
  const found=raw.find(s=>s.startsWith(COOKIE+'='));
  return found ? found.slice(COOKIE.length+1) : '';
}
function localCookie(value,maxAge=COOKIE_MAX_AGE) {
  return `${COOKIE}=${value}; HttpOnly; SameSite=Strict; Path=/; Max-Age=${maxAge}`;
}
function csrfFrom(html) {
  const tag=html.match(/<meta\b[^>]*\bname=["']csrf-token["'][^>]*>/i)?.[0];
  return tag?.match(/\bcontent=["']([^"']+)["']/i)?.[1];
}
async function requestBody(req) {
  const chunks=[];let count=0;
  for await(const chunk of req){count+=chunk.length;if(count>MAX_BODY)throw Error('BODY_TOO_LARGE');chunks.push(chunk);}
  return Buffer.concat(chunks).toString('utf8');
}
async function remoteText(response) {
  const length=Number(response.headers.get('content-length')||0);
  if(length>MAX_REMOTE)throw Error('REMOTE_RESPONSE_TOO_LARGE');
  const text=await response.text();
  if(text.length>MAX_REMOTE)throw Error('REMOTE_RESPONSE_TOO_LARGE');
  return text;
}
function profileFromHtml(html){
  const value=html.match(/<script[^>]*data-page=["']app["'][^>]*>([\s\S]*?)<\/script>/i)?.[1];
  if(!value)throw Error('ORIGINAL_ERP_PROFILE_INERTIA_REQUIRED');
  const parsed=JSON.parse(value),user=parsed.props?.auth?.user;
  if(parsed.component!=='Identity/Account/Profile'||!Number.isSafeInteger(user?.id)||
    user.id<=0||typeof user.email!=='string'||typeof user.name!=='string')
    throw Error('ORIGINAL_ERP_PROFILE_IDENTITY_INVALID');
  return {id:user.id,name:user.name,email:user.email};
}
function checkIdentity(input,actual) {
  const user=input?.user;
  if(input?.source!=='ERP_ORIGINAL_LARAVEL_PRODUCTION_SESSION_V1'||
    user?.id!==actual.id||user?.email?.toLowerCase()!==actual.email.toLowerCase()||
    !['role','custom'].includes(input.permissionMode)||
    !Array.isArray(input.roleKeys)||!input.roleKeys.every(x=>typeof x==='string')||
    !Array.isArray(input.effectivePermissionKeys)||
    !input.effectivePermissionKeys.every(x=>typeof x==='string')||
    !Array.isArray(input.navigation)) throw Error('CANONICAL_RIGHTS_NOT_VERIFIED');
  return input;
}

// A Windows-user DPAPI sealed key keeps Laravel cookie jars private across Dev restarts.
function windowsDevSessionStorage() {
  if(process.platform!=='win32')throw Error('WINDOWS_DEV_ONLY');
  const directory=path.join(process.env.LOCALAPPDATA||'', 'BYL', 'ERP2-Identity-Dev');
  if(!path.isAbsolute(directory))throw Error('LOCALAPPDATA_REQUIRED');
  fs.mkdirSync(directory,{recursive:true,mode:0o700});
  const keyFile=path.join(directory,'session-key.dpapi');
  const escaped=keyFile.replace(/'/g,"''");
  const script=`$p='${escaped}'; if (Test-Path -LiteralPath $p) {
    $s=ConvertTo-SecureString ([IO.File]::ReadAllText($p));
    $b=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($s);
    try { $v=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($b) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }
  } else {
    $bytes=New-Object byte[] 32;
    $rng=[Security.Cryptography.RandomNumberGenerator]::Create();
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    $v=[Convert]::ToBase64String($bytes);
    $s=ConvertTo-SecureString -String $v -AsPlainText -Force;
    [IO.File]::WriteAllText($p,(ConvertFrom-SecureString $s))
  }; [Console]::Out.Write($v)`;
  const command=Buffer.from(script,'utf16le').toString('base64');
  const key=Buffer.from(execFileSync('powershell.exe',
    ['-NoProfile','-NonInteractive','-EncodedCommand',command],
    {encoding:'utf8',timeout:15_000,windowsHide:true}).trim(),'base64');
  if(key.length!==32)throw Error('ERP2_SESSION_KEY_INVALID');
  return {filePath:path.join(directory,'session-state.enc'),key};
}

function createBridge({upstream=ORIGINAL_ERP,distDir,sessionStorage,
  allowedUpstreams=[ORIGINAL_ERP],enableCredentials=false,host=HOST,port=PORT}) {
  if(!allowedUpstreams.includes(upstream))throw Error('UNAPPROVED_ORIGINAL_ERP');
  const origin=new URL(upstream);
  if(origin.pathname!=='/'||origin.search||origin.hash||origin.username||origin.password)
    throw Error('UNSAFE_ORIGINAL_ERP_ORIGIN');
  const preauth=new Map(), sessions=new Map();
  const dist=path.resolve(distDir);
  if(sessionStorage && (!path.isAbsolute(sessionStorage.filePath) ||
      !Buffer.isBuffer(sessionStorage.key) || sessionStorage.key.length!==32))
    throw Error('INVALID_ENCRYPTED_SESSION_STORAGE');
  function saveSessions() {
    if(!sessionStorage)return;
    const valid=[...sessions].filter(([,s])=>s.expires>Date.now())
      .map(([id,s])=>[id,{expires:s.expires,jar:[...s.jar]}]);
    const iv=crypto.randomBytes(12);
    const cipher=crypto.createCipheriv('aes-256-gcm',sessionStorage.key,iv);
    const data=Buffer.concat([cipher.update(JSON.stringify({version:1,valid}),'utf8'),cipher.final()]);
    const payload=Buffer.concat([iv,cipher.getAuthTag(),data]);
    const temp=sessionStorage.filePath+'.pending';
    fs.writeFileSync(temp,payload,{mode:0o600});
    fs.renameSync(temp,sessionStorage.filePath);
  }
  if(sessionStorage && fs.existsSync(sessionStorage.filePath)) {
    // Corrupt or foreign-user DPAPI stores fail closed; never silently reset auth.
    const payload=fs.readFileSync(sessionStorage.filePath);
    if(payload.length<29)throw Error('INVALID_SESSION_STORE');
    const decipher=crypto.createDecipheriv('aes-256-gcm',sessionStorage.key,payload.subarray(0,12));
    decipher.setAuthTag(payload.subarray(12,28));
    const parsed=JSON.parse(Buffer.concat([decipher.update(payload.subarray(28)),decipher.final()]).toString());
    if(parsed.version!==1||!Array.isArray(parsed.valid))throw Error('INVALID_SESSION_STORE');
    for(const [id,s] of parsed.valid) {
      if(typeof id!=='string'||!/^[A-Za-z0-9_-]{43}$/.test(id)||
         !Number.isSafeInteger(s?.expires)||!Array.isArray(s.jar)||
         !s.jar.every(p=>Array.isArray(p)&&p.length===2&&
           typeof p[0]==='string'&&typeof p[1]==='string'))throw Error('INVALID_SESSION_STORE');
      if(s.expires>Date.now())sessions.set(id,{expires:s.expires,jar:new Map(s.jar)});
    }
  }

  async function original(route,jar,options={}) {
    // Strict static routes: no caller-supplied URL, no production order API.
    if(!['/login','/account/profile','/_erp2/identity','/logout'].includes(route))
      throw Error('PRODUCTION_IDENTITY_ONLY');
    const response=await fetch(new URL(route,origin),{
      method:options.method||'GET',redirect:'manual',signal:AbortSignal.timeout(15_000),
      headers:{Accept:options.accept||'text/html','Cookie':cookieHeader(jar),
        ...(options.method==='POST'?{'Content-Type':'application/x-www-form-urlencoded',
          'X-CSRF-TOKEN':options.csrf}:{} )},body:options.body,
    });
    collectCookies(response.headers,jar);
    return response;
  }
  async function verified(jar) {
    const p=await original('/account/profile',jar);
    if([401,403].includes(p.status) ||
      (p.status===302 && /\/login(?:[?#]|$)/i.test(p.headers.get('location')||'')))
      throw Error('ORIGINAL_ERP_SESSION_EXPIRED');
    if(p.status!==200)throw Error('ORIGINAL_ERP_PROFILE_UPSTREAM_UNAVAILABLE');
    const html=await remoteText(p);
    const actual=profileFromHtml(html);
    const identityRes=await original('/_erp2/identity',jar,{accept:'application/json'});
    if([401,403].includes(identityRes.status) ||
      (identityRes.status===302 &&
        /\/login(?:[?#]|$)/i.test(identityRes.headers.get('location')||'')))
      throw Error('ORIGINAL_ERP_SESSION_EXPIRED');
    if(identityRes.status!==200||!String(identityRes.headers.get('content-type')).includes('application/json'))
      throw Error('ORIGINAL_ERP_PERMISSION_ENDPOINT_REQUIRED');
    const identity=checkIdentity(JSON.parse(await remoteText(identityRes)),actual);
    return {identity,csrf:csrfFrom(html)};
  }
  function current(req) {
    const id=cookieValue(req),state=sessions.get(id);
    if(!state||state.expires<=Date.now()){if(sessions.delete(id))saveSessions();return undefined;}
    return {id,state};
  }
  return http.createServer(async(req,res)=>{
    try {
      const url=new URL(req.url||'/',`http://${host}:${port}`),route=url.pathname;
      if(route==='/api/health'&&req.method==='GET')
        return reply(res,200,{mode:'ORIGINAL_ERP_IDENTITY_ONLY',productionOrders:false,
          identityEnabled:enableCredentials,storage:sessionStorage?'dpapi-aes256gcm':'memory-only'});
      if(['/orders','/customers/orders'].includes(route)||route.startsWith('/api/'))
        return reply(res,405,{code:'PRODUCTION_BUSINESS_ACCESS_FORBIDDEN'});
      if(route==='/login'&&req.method==='GET') {
        if(!enableCredentials)return reply(res,503,{code:'AUTHORITY_ENDPOINT_NOT_PROVISIONED'});
        const jar=new Map(),page=await original('/login',jar);
        if(page.status!==200)throw Error('ORIGINAL_LOGIN_UNAVAILABLE');
        const token=csrfFrom(await remoteText(page));
        if(!token)throw Error('ORIGINAL_CSRF_MISSING');
        const id=makeId();preauth.set(id,{jar,token,expires:Date.now()+2*60_000});
        res.writeHead(200,{'content-type':'text/html','cache-control':'no-store',
          'set-cookie':localCookie(id,120)});
        return res.end(`<meta name="csrf-token" content="${token.replace(/[^A-Za-z0-9+/=_-]/g,'')}">`);
      }
      if(route==='/login'&&req.method==='POST'){
        if(!enableCredentials)return reply(res,503,{code:'AUTHORITY_ENDPOINT_NOT_PROVISIONED'});
        if(req.headers.origin&&req.headers.origin!==`http://${host}:${port}`)
          return reply(res,403,{code:'CROSS_ORIGIN_LOGIN_FORBIDDEN'});
        if(!String(req.headers['content-type']||'').startsWith('application/x-www-form-urlencoded'))
          return reply(res,415,{code:'FORM_REQUIRED'});
        const id=cookieValue(req),state=preauth.get(id);preauth.delete(id);
        if(!state||state.expires<Date.now()||req.headers['x-csrf-token']!==state.token)
          return reply(res,401,{code:'INVALID_PREAUTH'});
        const fields=new URLSearchParams(await requestBody(req));
        if(!fields.get('email')||!fields.get('password'))return reply(res,401,{code:'CREDENTIALS_REQUIRED'});
        const login=await original('/login',state.jar,{method:'POST',csrf:state.token,
          body:new URLSearchParams({email:fields.get('email'),password:fields.get('password'),remember:'1'}).toString()});
        if(login.status!==302)return reply(res,401,{code:'ORIGINAL_LOGIN_REJECTED'});
        const loginLocation=login.headers.get('location')||'';
        if(/\/login(?:[?#]|$)/i.test(loginLocation))
          return reply(res,401,{code:'ORIGINAL_LOGIN_REJECTED'});
        // Authentication and live permissions are independently verified.
        let identity;
        try {({identity}=await verified(state.jar));}
        catch {return reply(res,503,{code:'CURRENT_ORIGINAL_PERMISSIONS_REQUIRED'});}
        if(identity.user.email.toLowerCase()!==fields.get('email').toLowerCase()&&
          identity.user.name.toLowerCase()!==fields.get('email').toLowerCase())
          return reply(res,403,{code:'PRINCIPAL_MISMATCH'});
        const next=makeId();sessions.set(next,{jar:state.jar,expires:Date.now()+TTL});
        saveSessions();
        return reply(res,200,{authenticated:true},{'set-cookie':localCookie(next)});
      }
      if(route==='/_erp2/identity'&&req.method==='GET'){
        const active=current(req);if(!active)return reply(res,401,{code:'LOGIN_REQUIRED'});
        try{
          const {identity}=await verified(active.state.jar);
          saveSessions(); // Laravel may rotate remembered-cookie or session state.
          return reply(res,200,identity);
        }catch(error){
          if(error?.message==='ORIGINAL_ERP_SESSION_EXPIRED'){
            sessions.delete(active.id);saveSessions();
            return reply(res,401,{code:'IDENTITY_REVALIDATION_FAILED'},
              {'set-cookie':localCookie('',0)});
          }
          // Timeout, 5xx, malformed upstream response: deny this check but
          // preserve the opaque cookie for recovery when upstream returns.
          return reply(res,503,{code:'IDENTITY_REVALIDATION_TEMPORARILY_UNAVAILABLE'});
        }
      }
      if(route==='/dashboard'&&req.method==='GET'){
        const active=current(req);if(!active)return reply(res,401,{code:'LOGIN_REQUIRED'});
        const {csrf}=await verified(active.state.jar);
        if(!csrf)throw Error('LOGOUT_CSRF_UNAVAILABLE');
        res.writeHead(200,{'content-type':'text/html','cache-control':'no-store'});
        return res.end(`<meta name="csrf-token" content="${csrf.replace(/[^A-Za-z0-9+/=_-]/g,'')}">`);
      }
      if(route==='/logout'&&req.method==='POST'){
        const active=current(req);if(!active)return reply(res,401,{code:'LOGIN_REQUIRED'});
        if(req.headers.origin&&req.headers.origin!==`http://${host}:${port}`)
          return reply(res,403,{code:'CROSS_ORIGIN_LOGOUT_FORBIDDEN'});
        const page=await original('/account/profile',active.state.jar);
        const csrf=page.status===200?csrfFrom(await remoteText(page)):undefined;
        if(!csrf||req.headers['x-csrf-token']!==csrf)
          return reply(res,403,{code:'LOGOUT_CSRF_INVALID'});
        sessions.delete(active.id);saveSessions();
        await original('/logout',active.state.jar,{method:'POST',csrf,body:''});
        return reply(res,200,{authenticated:false},{'set-cookie':localCookie('',0)});
      }
      if(!['GET','HEAD'].includes(req.method))return reply(res,405,{code:'BUSINESS_WRITES_FORBIDDEN'});
      if(route.includes('\\')||route.includes('\0'))return reply(res,404,{code:'NOT_FOUND'});
      let relative;try{relative=decodeURIComponent(route);}catch{return reply(res,400,{code:'INVALID_PATH'});}
      const candidate=path.resolve(dist,'.'+relative);
      if(candidate!==dist&&!candidate.startsWith(dist+path.sep))return reply(res,403,{code:'FORBIDDEN'});
      let file=candidate;try{if(!fs.statSync(file).isFile())file=path.join(dist,'index.html');}
      catch{file=path.join(dist,'index.html');}
      if(!fs.existsSync(file))return reply(res,404,{code:'DEV_BUILD_MISSING'});
      res.writeHead(200,{'content-type':STATIC[path.extname(file)]||'application/octet-stream',
        'cache-control':file.endsWith('.html')?'no-store':'public, max-age=3600'});
      return req.method==='HEAD'?res.end():fs.createReadStream(file).pipe(res);
    } catch {
      // Do not log submitted passwords, upstream cookies, identities or PII.
      return reply(res,502,{code:'ORIGINAL_IDENTITY_UPSTREAM_UNAVAILABLE'});
    }
  });
}

if(require.main===module){
  const ready=process.env.BYL_ERP2_ORIGINAL_IDENTITY_READY==='1';
  const distDir=path.join(__dirname,'p0-canonical-uat-dist-20261116');
  createBridge({distDir,enableCredentials:ready,
    sessionStorage:ready?windowsDevSessionStorage():undefined}).listen(PORT,HOST,()=>{
    process.stdout.write(`ERP2 identity-only bridge http://${HOST}:${PORT}, login ${ready?'READY':'HOLD'}\n`);
  });
}
module.exports={createBridge,windowsDevSessionStorage,ORIGINAL_ERP};
