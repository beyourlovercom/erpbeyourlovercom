/**
 * For Laravel cookie-session writes, the XSRF cookie reflects the latest
 * session token. The initial <meta> token may become stale after an Inertia
 * login/session regeneration while the same document remains mounted.
 *
 * Never persist tokens. Prefer the current cookie and fall back to the meta
 * value only if there is no usable XSRF cookie.
 */
export function resolveCsrfHeaders(cookies: string, initialMetaToken = ''): Record<string, string> {
  const encoded = cookies.split(';').map(part => part.trim())
    .find(part => part.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
  if (encoded) {
    try {
      const token = decodeURIComponent(encoded)
      if (token) return {'X-XSRF-TOKEN': token}
    } catch { /* invalid cookie encoding: fall back to meta */ }
  }
  return initialMetaToken ? {'X-CSRF-TOKEN': initialMetaToken} : {}
}

export function currentCsrfHeaders(): Record<string, string> {
  const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
  return resolveCsrfHeaders(document.cookie, meta)
}
