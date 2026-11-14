'use strict';
// Independent localhost-only BYL ERP SiteOps UAT gateway; no real ERP providers or writes.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const ROOT = __dirname;
const DIST = path.join(ROOT, 'frontend/apps/web-antd/dist');
const SECRET_FILE = path.join(ROOT, 'private/local-admin-key.txt');
const HOST = '127.0.0.1', PORT = 18786;
const sessions = new Map(), failed = new Map();
if (!fs.existsSync(SECRET_FILE)) {
  fs.mkdirSync(path.dirname(SECRET_FILE), { recursive: true });
  fs.writeFileSync(SECRET_FILE, crypto.randomBytes(24).toString('hex') + '\n', { mode: 0o600, flag: 'wx' });
}
function constantEq(a, b) {
  const x = Buffer.from(String(a || '')), y = Buffer.from(String(b || ''));
  return x.length > 0 && x.length === y.length && crypto.timingSafeEqual(x, y);
}
function respond(res, status, value) {
  res.writeHead(status, {
    'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store',
    'x-content-type-options': 'nosniff', 'x-frame-options': 'DENY',
    'referrer-policy': 'no-referrer',
  });
  res.end(JSON.stringify({ code: status === 200 ? 0 : status, data: status === 200 ? value : null,
    ...(status !== 200 ? { message: String(value) } : {}) }));
}
async function readBody(req) {
  let input = '';
  for await (const chunk of req) {
    input += chunk;
    if (input.length > 16_384) throw new Error('Body too large');
  }
  return JSON.parse(input || '{}');
}
function actor(req) {
  const key = String(req.headers.authorization || '').replace(/^Bearer /i, '').trim();
  const until = sessions.get(key) || 0;
  if (until <= Date.now()) { sessions.delete(key); return false; }
  return true;
}
const types = { '.html':'text/html; charset=utf-8', '.js':'text/javascript; charset=utf-8',
  '.css':'text/css; charset=utf-8', '.json':'application/json', '.svg':'image/svg+xml',
  '.png':'image/png', '.ico':'image/x-icon', '.woff':'font/woff', '.woff2':'font/woff2' };
function serveStatic(req, res, url) {
  if (!['GET', 'HEAD'].includes(req.method)) return respond(res, 405, 'Read-only UAT');
  let requested;
  try { requested = decodeURIComponent(url.pathname); }
  catch { return respond(res, 400, 'Bad path'); }
  const target = path.resolve(DIST, '.' + requested);
  if (target !== DIST && !target.startsWith(DIST + path.sep)) return respond(res, 403, 'Forbidden');
  let file = target;
  try { if (!fs.statSync(file).isFile()) file = path.join(DIST, 'index.html'); }
  catch { file = path.join(DIST, 'index.html'); }
  if (!fs.existsSync(file)) return respond(res, 503, 'Frontend build not installed');
  const ext = path.extname(file).toLowerCase();
  res.writeHead(200, { 'content-type': types[ext] || 'application/octet-stream',
    'x-content-type-options':'nosniff','x-frame-options':'DENY',
    'cache-control': ext === '.html' ? 'no-store' : 'public,max-age=3600' });
  if (req.method === 'HEAD') return res.end();
  fs.createReadStream(file).pipe(res);
}
async function readOnlyDevOrders(res, url) {
  const tokenFile=path.join(ROOT,'private/dev-bridge-token.txt');
  if(!fs.existsSync(tokenFile)) return respond(res,503,'Dev bridge disabled');
  const token=fs.readFileSync(tokenFile,'utf8').trim();
  if(!/^[a-f0-9]{64}$/i.test(token)) return respond(res,503,'Dev bridge invalid config');
  const params=new URLSearchParams();
  const search=(url.searchParams.get('search')||'').trim();
  if(search.length>90) return respond(res,400,'Search too long');
  if(search) params.set('search',search);
  const page=Math.max(1,Math.min(999,Number.parseInt(url.searchParams.get('page')||'1',10)||1));
  const perPage=Number.parseInt(url.searchParams.get('per_page')||'50',10);
  const view=url.searchParams.get('view')||'all';
  if(![20,50,100].includes(perPage)) return respond(res,400,'Invalid page size');
  if(!['all','pending','exception','in_transit','shipped','return','loss','overweight','stockout'].includes(view))
    return respond(res,400,'Invalid view');
  params.set('page',String(page));params.set('per_page',String(perPage));params.set('view',view);
  let remote;
  try {
    const urlFile=path.join(ROOT,'private/dev-bridge-address.txt');
    const base=fs.readFileSync(urlFile,'utf8').trim();
    const target=new URL(base);
    const octets=target.hostname.split('.').map(Number);
    if(target.protocol!=='http:'||target.port!=='18796'||octets.length!==4||
      octets[0]!==100||octets[1]<64||octets[1]>127||
      target.username||target.password||target.pathname!=='/'||target.search||target.hash)
      return respond(res,503,'Dev bridge not configured for private tailnet');
    const upstream=await fetch(base+'r2/orders?'+params.toString(),{
      method:'GET',headers:{'x-byl-r2-token':token},redirect:'manual',signal:AbortSignal.timeout(12000),
    });
    if(!upstream.ok)return respond(res,502,'Mac Dev bridge rejected request');
    remote=await upstream.json();
  } catch {return respond(res,503,'Mac Dev read-only bridge unavailable');}
  if(remote.source!=='MAC_LOCAL_DEV_SQLITE'||remote.scope!=='DEV_UAT_OWNER_ONLY'||
    remote.inertia?.component!=='Orders/Index'||!Array.isArray(remote.inertia?.props?.orders?.data))
    return respond(res,502,'Invalid Dev bridge contract');
  return respond(res,200,{source:remote.source,scope:remote.scope,
    real_dev_rows:true,inertia:remote.inertia});
}
const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url || '/', 'http://' + HOST + ':' + PORT);
    const route = url.pathname;
    if (route === '/api/health' && req.method === 'GET')
      return respond(res, 200, { status:'ok', app:'BYL ERP SiteOps UAT', mode:'synthetic-offline-readonly', version:'0.1.0', production_connected:false, write_enabled:false });
    if (route === '/api/auth/login' && req.method === 'POST') {
      const key = req.socket.remoteAddress || 'local';
      const state = failed.get(key) || { n:0, until:0 };
      if (state.until > Date.now()) return respond(res, 429, 'Too many attempts');
      const body = await readBody(req);
      if (body.username !== 'owner' || !constantEq(body.password, fs.readFileSync(SECRET_FILE,'utf8').trim())) {
        state.n++;
        if (state.n >= 5) { state.n = 0; state.until = Date.now() + 300000; }
        failed.set(key, state);
        return respond(res, 401, 'Invalid credentials');
      }
      failed.delete(key);
      const token = crypto.randomBytes(32).toString('hex');
      sessions.set(token, Date.now() + 12 * 3600000);
      return respond(res, 200, { accessToken: token });
    }
    if (route === '/api/auth/logout' && req.method === 'POST') {
      sessions.delete(String(req.headers.authorization || '').replace(/^Bearer /i, '').trim());
      return respond(res, 200, true);
    }
    if (route.startsWith('/api/')) {
      if (!actor(req)) return respond(res, 401, 'Login required');
      if (req.method !== 'GET') return respond(res, 405, 'All business mutations disabled');
      if (route === '/api/user/info')
        return respond(res, 200, { id:'local-owner', username:'owner', realName:'BYL ERP UAT Owner',
          roles:['owner'], homePath:'/erp/overview', avatar:'' });
      if (route === '/api/auth/codes') return respond(res, 200, ['erp:read']);
      if (route === '/api/menu/all') return respond(res, 200, []);
      if (route === '/api/erp/dev/orders') return readOnlyDevOrders(res, url);
      if (route === '/api/erp/status') return respond(res, 200, {
        source:'Mac mini local Dev SQLite', erp_api_connected:true, production_writes:false });
      return respond(res, 404, 'ERP API not connected in R0');
    }
    return serveStatic(req, res, url);
  } catch (error) {
    return respond(res, 500, 'Local UAT gateway error');
  }
});
server.listen(PORT, HOST, () => console.log('BYL ERP SiteOps UAT listening on http://' + HOST + ':' + PORT));