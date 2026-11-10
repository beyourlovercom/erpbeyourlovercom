import { describe, expect, it } from 'vitest'
import { resolveCsrfHeaders } from './csrfHeaders'

describe('Laravel CSRF for long-lived Inertia ERP sessions', () => {
  it('uses the latest XSRF cookie instead of a stale head meta token', () => {
    expect(resolveCsrfHeaders('erp-session=opaque; XSRF-TOKEN=latest%3Dtoken', 'stale'))
      .toEqual({'X-XSRF-TOKEN':'latest=token'})
  })
  it('falls back to meta when no XSRF cookie is present', () => {
    expect(resolveCsrfHeaders('erp-session=opaque', 'safe-meta'))
      .toEqual({'X-CSRF-TOKEN':'safe-meta'})
  })
  it('does not send malformed cookie values or empty tokens', () => {
    expect(resolveCsrfHeaders('XSRF-TOKEN=%E0%A4%A', 'fallback'))
      .toEqual({'X-CSRF-TOKEN':'fallback'})
    expect(resolveCsrfHeaders('', '')).toEqual({})
  })
})
