import { describe, expect, it, vi } from 'vitest';
import { canonicalAuthorityFingerprint, getCanonicalErpIdentity, parseCanonicalErpIdentity } from './erp-identity';

const valid = {
  source:'ERP_CANONICAL_LARAVEL_DEV_SESSION_V1',
  user:{id:18,name:'Vita',email:'vita@beyourlover.com'},
  permissionMode:'role',
  roleKeys:['role_preset_operations'],
  effectivePermissionKeys:['orders.view','orders.edit'],
  navigation:[{key:'orders',label:'订单',path:'/orders',children:[
    {key:'orders-all',label:'全部',path:'/orders'},
  ]}],
};
describe('ERP2 canonical identity contract', () => {
  it('accepts persisted real ID and permission keys only from Laravel response', () => {
    expect(parseCanonicalErpIdentity(valid)).toMatchObject({
      user:{id:18},roleKeys:['role_preset_operations'],
      effectivePermissionKeys:['orders.view','orders.edit'],
    });
  });
  it('distinguishes an in-place permission revocation without changing employee ID', () => {
    const current = parseCanonicalErpIdentity(valid);
    const revoked = parseCanonicalErpIdentity({...valid, effectivePermissionKeys:['orders.view']});
    expect(canonicalAuthorityFingerprint(current)).not.toBe(canonicalAuthorityFingerprint(revoked));
    const sameGrantsReordered = parseCanonicalErpIdentity({
      ...valid, effectivePermissionKeys:['orders.edit','orders.view'],
    });
    expect(canonicalAuthorityFingerprint(current)).toBe(canonicalAuthorityFingerprint(sameGrantsReordered));
    const differentUser = parseCanonicalErpIdentity({...valid,user:{...valid.user,id:17}});
    expect(canonicalAuthorityFingerprint(current)).not.toBe(canonicalAuthorityFingerprint(differentUser));
    const differentMenu = parseCanonicalErpIdentity({
      ...valid, navigation:[{key:'orders',label:'订单',path:'/orders',children:[]}],
    });
    expect(canonicalAuthorityFingerprint(current)).not.toBe(canonicalAuthorityFingerprint(differentMenu));
  });
  it('rejects fixture roles, missing employee ID and malformed navigation', () => {
    expect(() => parseCanonicalErpIdentity({...valid,source:'LOCAL_UAT'})).toThrow();
    expect(() => parseCanonicalErpIdentity({...valid,user:{id:0}})).toThrow();
    expect(() => parseCanonicalErpIdentity({...valid,navigation:[{key:'orders'}]})).toThrow();
  });
  it('fetches only same-origin authenticated Laravel GET without credentials in URL', async () => {
    const fetcher = vi.fn().mockResolvedValue(new Response(JSON.stringify(valid),{
      status:200,headers:{'content-type':'application/json'},
    }));
    await expect(getCanonicalErpIdentity(fetcher)).resolves.toMatchObject({user:{id:18}});
    expect(fetcher).toHaveBeenCalledWith('/_erp2/identity',{
      method:'GET',credentials:'same-origin',cache:'no-store',redirect:'manual',
      headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},
    });
  });
  it('fails closed on absent session, forbidden route and HTML login response', async () => {
    for (const status of [302,401,403]) {
      const fetcher = vi.fn().mockResolvedValue(new Response('',{status}));
      await expect(getCanonicalErpIdentity(fetcher)).rejects.toThrow('ERP2_CANONICAL_SESSION_REQUIRED');
    }
    const html = vi.fn().mockResolvedValue(new Response('<html>login</html>',{
      status:200,headers:{'content-type':'text/html'},
    }));
    await expect(getCanonicalErpIdentity(html)).rejects.toThrow('ERP2_CANONICAL_ENDPOINT_UNAVAILABLE');
  });
});
