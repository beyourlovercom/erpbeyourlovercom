import { describe, expect, it, vi } from 'vitest';
import { loginCanonicalErp, logoutCanonicalErp } from './erp-laravel-auth';

const canonical = {
  source: 'ERP_CANONICAL_LARAVEL_DEV_SESSION_V1',
  user: { id: 18, name: 'Vita', email: 'vita@beyourlover.com' },
  permissionMode: 'role', roleKeys: ['role_preset_operations'],
  effectivePermissionKeys: ['orders.view'],
  navigation: [{ key: 'orders', label: '订单', path: '/orders', children: [] }],
};
const html = '<html><head><meta name="csrf-token" content="dev-csrf"></head></html>';
const htmlResponse = () => new Response(html, { status: 200, headers: { 'content-type': 'text/html' } });
const identityResponse = (status = 200) => new Response(
  status === 200 ? JSON.stringify(canonical) : '',
  { status, headers: { 'content-type': 'application/json' } },
);

describe('ERP2 canonical Dev web session', () => {
  it('submits employee credentials to same-origin Laravel, then proves identity', async () => {
    const fetcher = vi.fn()
      .mockResolvedValueOnce(htmlResponse())
      .mockResolvedValueOnce(htmlResponse())
      .mockResolvedValueOnce(identityResponse());
    await expect(loginCanonicalErp('vita@beyourlover.com', 'temporary', fetcher))
      .resolves.toMatchObject({ user: { id: 18 }, roleKeys: ['role_preset_operations'] });
    const [path, request] = fetcher.mock.calls[1]!;
    expect(path).toBe('/login');
    expect(request).toMatchObject({ method: 'POST', credentials: 'same-origin' });
    expect(request.body.get('email')).toBe('vita@beyourlover.com');
    expect(request.body.get('password')).toBe('temporary');
    expect(request.headers['X-CSRF-TOKEN']).toBe('dev-csrf');
    expect(fetcher.mock.calls[2]?.[0]).toBe('/_erp2/identity');
  });

  it('rejects a wrong-password login redirect without a canonical session', async () => {
    const fetcher = vi.fn()
      .mockResolvedValueOnce(htmlResponse())
      .mockResolvedValueOnce(htmlResponse())
      .mockResolvedValueOnce(identityResponse(401));
    await expect(loginCanonicalErp('vita@beyourlover.com', 'wrong', fetcher))
      .rejects.toThrow('ERP2_CANONICAL_SESSION_REQUIRED');
  });

  it('rejects absent CSRF and never posts credentials', async () => {
    const fetcher = vi.fn().mockResolvedValueOnce(new Response('<html/>', { status: 200 }));
    await expect(loginCanonicalErp('vita@beyourlover.com', 'temporary', fetcher))
      .rejects.toThrow('ERP2_LARAVEL_CSRF_MISSING');
    expect(fetcher).toHaveBeenCalledTimes(1);
  });

  it('logs out with Laravel CSRF and verifies server-side revocation', async () => {
    const fetcher = vi.fn()
      .mockResolvedValueOnce(htmlResponse())
      .mockResolvedValueOnce(htmlResponse())
      .mockResolvedValueOnce(identityResponse(401));
    await expect(logoutCanonicalErp(fetcher)).resolves.toBeUndefined();
    expect(fetcher.mock.calls[0]?.[0]).toBe('/dashboard');
    expect(fetcher.mock.calls[1]?.[0]).toBe('/logout');
    expect(fetcher.mock.calls[1]?.[1].method).toBe('POST');
  });

  it('does not call logout complete if Laravel still accepts the session', async () => {
    const fetcher = vi.fn()
      .mockResolvedValueOnce(htmlResponse())
      .mockResolvedValueOnce(htmlResponse())
      .mockResolvedValueOnce(identityResponse());
    await expect(logoutCanonicalErp(fetcher))
      .rejects.toThrow('ERP2_LARAVEL_LOGOUT_SESSION_STILL_ACTIVE');
  });
});
