import type { ErpDevMenuNode } from './erp-orders';

/** ERP2 P0: session-derived identity from the existing Laravel ERP, never Vben role fixtures. */
export interface CanonicalErpIdentity {
  source: 'ERP_CANONICAL_LARAVEL_DEV_SESSION_V1' | 'ERP_ORIGINAL_LARAVEL_PRODUCTION_SESSION_V1';
  user: { id: number; name: string; email: string };
  permissionMode: 'role' | 'custom';
  roleKeys: string[];
  effectivePermissionKeys: string[];
  navigation: ErpDevMenuNode[];
}
/** A session's effective authority may change without its user ID changing. */
export function canonicalAuthorityFingerprint(identity: CanonicalErpIdentity): string {
  return JSON.stringify({
    source: identity.source,
    userId: identity.user.id,
    email: identity.user.email.toLowerCase(),
    mode: identity.permissionMode,
    roleKeys: [...identity.roleKeys].sort(),
    effectivePermissionKeys: [...identity.effectivePermissionKeys].sort(),
    navigation: identity.navigation.map((entry) => ({
      key: entry.key,
      path: entry.path,
      children: (entry.children ?? []).map((child) => ({key:child.key,path:child.path})),
    })),
  });
}
type ObjectValue = Record<string, unknown>;
const obj = (value: unknown): ObjectValue =>
  value && typeof value === 'object' && !Array.isArray(value) ? value as ObjectValue : {};
const strings = (value: unknown): value is string[] =>
  Array.isArray(value) && value.every((item) => typeof item === 'string');

export function parseCanonicalErpIdentity(input: unknown): CanonicalErpIdentity {
  const payload = obj(input), user = obj(payload.user);
  if (!['ERP_CANONICAL_LARAVEL_DEV_SESSION_V1',
        'ERP_ORIGINAL_LARAVEL_PRODUCTION_SESSION_V1'].includes(String(payload.source)) ||
      !Number.isSafeInteger(user.id) || Number(user.id) <= 0 ||
      typeof user.name !== 'string' || typeof user.email !== 'string' ||
      !['role', 'custom'].includes(String(payload.permissionMode)) ||
      !strings(payload.roleKeys) || !strings(payload.effectivePermissionKeys) ||
      !Array.isArray(payload.navigation)) {
    throw new Error('ERP2_CANONICAL_IDENTITY_INVALID');
  }
  const navigation = payload.navigation.map((raw): ErpDevMenuNode => {
    const entry = obj(raw);
    if (typeof entry.key !== 'string' || typeof entry.label !== 'string' ||
        typeof entry.path !== 'string' || !Array.isArray(entry.children)) {
      throw new Error('ERP2_CANONICAL_NAVIGATION_INVALID');
    }
    const children = entry.children.map((rawChild): ErpDevMenuNode => {
      const child = obj(rawChild);
      if (typeof child.key !== 'string' || typeof child.label !== 'string' ||
          typeof child.path !== 'string') throw new Error('ERP2_CANONICAL_NAVIGATION_INVALID');
      return {key:child.key,label:child.label,path:child.path};
    });
    return {key:entry.key,label:entry.label,path:entry.path,children};
  });
  return {
    source:payload.source as CanonicalErpIdentity['source'],
    user:{id:Number(user.id),name:user.name as string,email:user.email as string},
    permissionMode:payload.permissionMode as 'role' | 'custom',
    roleKeys:payload.roleKeys,
    effectivePermissionKeys:payload.effectivePermissionKeys,
    navigation,
  };
}

export async function getCanonicalErpIdentity(fetcher: typeof fetch = fetch): Promise<CanonicalErpIdentity> {
  // Same-origin only. If Laravel is not behind the Dev origin, HOLD rather than
  // trust another account system or a CORS-exposed staff snapshot.
  const response = await fetcher('/_erp2/identity', {
    method:'GET',
    credentials:'same-origin',
    cache:'no-store',
    redirect:'manual',
    headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if ([0, 302, 401, 403].includes(response.status)) {
    throw new Error('ERP2_CANONICAL_SESSION_REQUIRED');
  }
  if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) {
    throw new Error('ERP2_CANONICAL_ENDPOINT_UNAVAILABLE');
  }
  return parseCanonicalErpIdentity(await response.json());
}
