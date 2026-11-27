'use strict';
/**
 * ERP2 P0 only: loopback, same-origin gateway to the original Laravel DEV web
 * session. No simulated Owner token, user/role selection or business writes.
 *
 * The CLI pins the Mac mini isolated Laravel worktree. It will NOT connect to
 * Production, nor accept an arbitrary upstream URL from a browser request.
 */
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');

const PINNED_DEV = 'http://100.94.52.109:18931';
const HOST = '127.0.0.1';
const PORT = 18787;
const MAX_BODY = 8192;
const MAX_RESPONSE = 6_000_000;
const DEV_PATHS = new Set(['/login', '/logout', '/dashboard', '/_erp2/identity', '/orders', '/customers/orders']);
const EXT_TYPES = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8', '.svg': 'image/svg+xml', '.json': 'application/json',
  '.png': 'image/png', '.ico': 'image/x-icon', '.woff': 'font/woff',
  '.woff2': 'font/woff2', '.webp': 'image/webp',
};

function reject(res, code, reason) {
  res.writeHead(code, {'content-type':'text/plain; charset=utf-8','cache-control':'no-store',
    'x-content-type-options':'nosniff','x-frame-options':'DENY'});
  res.end(reason);
}

async function limitedBody(req) {
  let length = 0;
  const chunks = [];
  for await (const chunk of req) {
    length += chunk.length;
    if (length > MAX_BODY) throw new Error('BODY_TOO_LARGE');
    chunks.push(chunk);
  }
  return Buffer.concat(chunks);
}

async function limitedResponse(response) {
  const advertised = Number(response.headers.get('content-length') || 0);
  if (advertised > MAX_RESPONSE) throw new Error('DEV_RESPONSE_TOO_LARGE');
  const chunks = [];
  let length = 0;
  if (response.body) {
    for await (const chunk of response.body) {
      length += chunk.length;
      if (length > MAX_RESPONSE) {
        await response.body.cancel().catch(() => {});
        throw new Error('DEV_RESPONSE_TOO_LARGE');
      }
      chunks.push(chunk);
    }
  }
  return Buffer.concat(chunks);
}

function safeRedirect(location, upstream) {
  if (!location) return undefined;
  try {
    const target = new URL(location, upstream);
    if (target.origin !== upstream.origin) return undefined;
    return target.pathname + target.search + target.hash;
  } catch { return undefined; }
}

function safeFile(distDir, pathname) {
  let raw;
  try { raw = decodeURIComponent(pathname); }
  catch { return; }
  if (raw.includes('\0') || raw.includes('\\')) return;
  const candidate = path.resolve(distDir, '.' + raw);
  if (candidate !== distDir && !candidate.startsWith(distDir + path.sep)) return;
  try { if (fs.statSync(candidate).isFile()) return candidate; }
  catch { /* React/Vue hash navigation can use the SPA root. */ }
  return path.join(distDir, 'index.html');
}

function createServer({
  upstream, distDir, host = HOST, port = PORT, allowedUpstreams = [PINNED_DEV],
  loginEnabled = false,
}) {
  if (!allowedUpstreams.includes(upstream)) throw new Error('UNAPPROVED_LARAVEL_DEV_ORIGIN');
  const base = new URL(upstream);
  if (base.pathname !== '/' || base.search || base.hash || base.username || base.password)
    throw new Error('INVALID_LARAVEL_DEV_ORIGIN');
  const absoluteDist = path.resolve(distDir);
  return http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url || '/', `http://${host}:${port}`);
      if (DEV_PATHS.has(url.pathname)) {
        const method = req.method || 'GET';
        const loginWrite = url.pathname === '/login' || url.pathname === '/logout';
        if (!['GET', 'HEAD'].includes(method) && !(loginWrite && method === 'POST'))
          return reject(res, 405, 'ERP business writes are disabled');
        if (method === 'POST' && url.pathname === '/login' && !loginEnabled)
          return reject(res, 503, 'Genuine Laravel Dev staff authority not provisioned');
        if (method === 'POST') {
          const origin = req.headers.origin;
          if (origin && origin !== `http://${host}:${port}`) return reject(res, 403, 'Cross-origin login denied');
          if (url.pathname === '/login' && !String(req.headers['content-type'] || '').startsWith('application/x-www-form-urlencoded'))
            return reject(res, 415, 'Login form encoding required');
        }
        const body = method === 'POST' ? await limitedBody(req) : undefined;
        const headers = {'accept':String(req.headers.accept || 'text/html'),
          'cookie':String(req.headers.cookie || ''),
          'x-requested-with':String(req.headers['x-requested-with'] || ''),
          'x-inertia':String(req.headers['x-inertia'] || ''),
          ...(method === 'POST' ? {'content-type':String(req.headers['content-type'] || ''),
            'x-csrf-token':String(req.headers['x-csrf-token'] || '')} : {}),
        };
        const target = new URL(url.pathname + url.search, base);
        const upstreamResponse = await fetch(target, {method, headers, body,
          redirect:'manual', signal:AbortSignal.timeout(30_000)});
        const data = await limitedResponse(upstreamResponse);
        const out = {'cache-control':'private, no-store, max-age=0',
          'x-content-type-options':'nosniff','x-frame-options':'DENY',
          'referrer-policy':'no-referrer',
          'content-type':upstreamResponse.headers.get('content-type') || 'text/plain',
        };
        const location = safeRedirect(upstreamResponse.headers.get('location'), base);
        if (location) out.location = location;
        else if (upstreamResponse.headers.has('location'))
          return reject(res, 502, 'Unsafe Laravel redirect refused');
        const cookies = upstreamResponse.headers.getSetCookie();
        if (cookies.length) out['set-cookie'] = cookies.map(c => c.replace(/;\s*Domain=[^;]*/gi,''));
        res.writeHead(upstreamResponse.status, out);
        return res.end(method === 'HEAD' ? undefined : data);
      }
      if (!['GET','HEAD'].includes(req.method)) return reject(res,405,'Business writes disabled');
      if (url.pathname === '/api/health') {
        res.writeHead(200, {'content-type':'application/json', 'cache-control':'no-store'});
        return res.end(JSON.stringify({source:'ERP2_CANONICAL_DEV_ONLY',
          productionConnected:false, businessWrites:false, auth:'laravel-web-session'}));
      }
      if (url.pathname.startsWith('/api/')) return reject(res,404,'Synthetic UAT API disabled');
      const file = safeFile(absoluteDist, url.pathname);
      if (!file || !fs.existsSync(file)) return reject(res,404,'Dev frontend not built');
      res.writeHead(200, {'content-type': EXT_TYPES[path.extname(file)] || 'application/octet-stream',
        'x-content-type-options':'nosniff', 'x-frame-options':'DENY',
        'cache-control':file.endsWith('.html') ? 'no-store' : 'public, max-age=3600'});
      if (req.method === 'HEAD') return res.end();
      return fs.createReadStream(file).pipe(res);
    } catch {
      // No credentials or customer rows appear in logs or error text.
      return reject(res,502,'Canonical Dev upstream unavailable or invalid');
    }
  });
}

if (require.main === module) {
  const distDir = path.join(__dirname,'p0-canonical-uat-dist-20261116');
  const loginEnabled = process.env.BYL_ERP2_STAFF_DEV_AUTH_READY === '1';
  createServer({upstream:PINNED_DEV, distDir, loginEnabled}).listen(PORT,HOST,() => {
    process.stdout.write(`ERP2 canonical Dev preview http://${HOST}:${PORT} (business writes OFF, staff login ${loginEnabled ? 'enabled' : 'HOLD'})\n`);
  });
}
module.exports = {createServer, PINNED_DEV};
