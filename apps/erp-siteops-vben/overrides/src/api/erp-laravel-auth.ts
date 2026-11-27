import { getCanonicalErpIdentity, type CanonicalErpIdentity } from './erp-identity';

/**
 * ERP2 canonical Dev login: the original Laravel web session is the only
 * authority. No local JWT, role selector, employee replica or password store.
 * The credentials are submitted only to the same-origin /login route.
 */
async function csrfFrom(path: '/login' | '/dashboard', fetcher: typeof fetch): Promise<string> {
  const response = await fetcher(path, {
    method: 'GET', credentials: 'same-origin', cache: 'no-store',
    redirect: 'follow', headers: { Accept: 'text/html' },
  });
  if (!response.ok) throw new Error('ERP2_LARAVEL_CSRF_PAGE_UNAVAILABLE');
  const html = await response.text();
  const tag = html.match(/<meta\b[^>]*\bname=["']csrf-token["'][^>]*>/i)?.[0];
  const token = tag?.match(/\bcontent=["']([^"']+)["']/i)?.[1];
  if (!token || token.length > 512) throw new Error('ERP2_LARAVEL_CSRF_MISSING');
  return token;
}

export async function loginCanonicalErp(
  principal: string, password: string, fetcher: typeof fetch = fetch,
): Promise<CanonicalErpIdentity> {
  if (!principal.trim() || !password) throw new Error('ERP2_LARAVEL_CREDENTIALS_REQUIRED');
  const token = await csrfFrom('/login', fetcher);
  const response = await fetcher('/login', {
    method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'follow',
    headers: {
      Accept: 'text/html', 'Content-Type': 'application/x-www-form-urlencoded',
      'X-CSRF-TOKEN': token,
    },
    body: new URLSearchParams({ email: principal.trim(), password }),
  });
  // Wrong password can also return 200 after a Laravel redirect back to /login.
  // Never interpret the HTTP status or a client-supplied role as proof of login.
  if (!response.ok) throw new Error('ERP2_LARAVEL_LOGIN_FAILED');
  return getCanonicalErpIdentity(fetcher);
}

export async function logoutCanonicalErp(fetcher: typeof fetch = fetch): Promise<void> {
  const token = await csrfFrom('/dashboard', fetcher);
  const response = await fetcher('/logout', {
    method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'follow',
    headers: { Accept: 'text/html', 'X-CSRF-TOKEN': token },
  });
  if (!response.ok) throw new Error('ERP2_LARAVEL_LOGOUT_FAILED');
  // A successful HTTP redirect does not prove the server invalidated the session.
  try {
    await getCanonicalErpIdentity(fetcher);
  } catch {
    return;
  }
  throw new Error('ERP2_LARAVEL_LOGOUT_SESSION_STILL_ACTIVE');
}
