import type { Router } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const fixtures = vi.hoisted(() => ({
  identity: vi.fn(), setMenus: vi.fn(), setUser: vi.fn(),
  closeTabs: vi.fn(), clearSnapshots: vi.fn(),
  tabs: [] as {path:string;key:string}[],
}));
vi.mock('@vben/stores', () => ({
  useAccessStore: () => ({setAccessMenus:fixtures.setMenus}),
  useUserStore: () => ({setUserInfo:fixtures.setUser}),
  useTabbarStore: () => ({tabs:fixtures.tabs,_bulkCloseByKeys:fixtures.closeTabs}),
}));
vi.mock('#/api/erp-hot-snapshots', () => ({clearHotOrderSnapshots:fixtures.clearSnapshots}));
vi.mock('#/api/erp-orders', () => ({getCurrentDevReadOnlyOrders:vi.fn()}));
vi.mock('#/api/erp-identity', () => ({
  getCanonicalErpIdentity:fixtures.identity,
  canonicalAuthorityFingerprint:(i:any) => JSON.stringify({
    user:i.user.id,roles:i.roleKeys,perms:i.effectivePermissionKeys,nav:i.navigation,
  }),
}));
import {
  bootstrapCanonicalDevNavigation, canonicalDevIdentity, clearAuthorizedDevNavigation,
  isCanonicalErpPathAllowed, setApprovedMenuSource, devBootstrapError,
  seedCanonicalVerifiedLogin, canonicalHomePath,
} from './erp-live-navigation';

const router = {
  hasRoute:vi.fn(() => false),
  addRoute:vi.fn(),
} as unknown as Router;
const canonical = {
  source:'ERP_ORIGINAL_LARAVEL_PRODUCTION_SESSION_V1',
  user:{id:18,name:'Vita',email:'vita@fixture.invalid'},
  permissionMode:'role',
  roleKeys:['operations'],
  effectivePermissionKeys:['orders.view'],
  navigation:[{key:'orders',label:'Orders',path:'/orders',children:[
    {key:'pending',label:'Pending',path:'/orders/pending'},
  ]}],
};
beforeEach(() => {
  fixtures.tabs=[];
  clearAuthorizedDevNavigation();
  vi.clearAllMocks();
  setApprovedMenuSource([{name:'Orders',path:'/erp/orders'}]);
});
describe('P0-C/D canonical navigation stability', () => {
  it('routes logistics-only staff to their authorized logistics page', () => {
    const staff = {...canonical, effectivePermissionKeys:['logistics.view'], navigation:[
      {key:'logistics', label:'Logistics', path:'/logistics', children:[
        {key:'logistics-wait-stock',label:'Pending stock',path:'/logistics/wait-stock'},
      ]},
    ]};
    expect(canonicalHomePath(staff as any)).toBe('/erp/logistics/logistics-wait-stock');
    expect(canonicalHomePath({...staff,navigation:[{key:'../../orders',label:'Invalid',path:'/',children:[]}] } as any)).toBe('/403');
  });
  it('sends authorized orders staff to orders master and no-module users to 403', () => {
    expect(canonicalHomePath(canonical as any)).toBe('/erp/orders/orders-master');
    expect(canonicalHomePath({...canonical, navigation:[]} as any)).toBe('/403');
  });
  it('uses the just-verified login identity for the first route, without an immediate second fetch', async () => {
    fixtures.identity.mockRejectedValue(new Error('ERP2_CANONICAL_ENDPOINT_UNAVAILABLE'));
    seedCanonicalVerifiedLogin(canonical as any);
    expect(await bootstrapCanonicalDevNavigation(router)).toBe(true);
    expect(canonicalDevIdentity.value?.user.id).toBe(18);
    expect(isCanonicalErpPathAllowed('/erp/orders/pending')).toBe(true);
    expect(fixtures.identity).not.toHaveBeenCalled();
    expect(await bootstrapCanonicalDevNavigation(router, true)).toBe(true);
    expect(fixtures.identity).toHaveBeenCalledTimes(1);
  });
  it('never reuses a login seed after logout invalidation', async () => {
    seedCanonicalVerifiedLogin(canonical as any);
    clearAuthorizedDevNavigation();
    fixtures.identity.mockRejectedValue(new Error('ERP2_CANONICAL_SESSION_REQUIRED'));
    expect(await bootstrapCanonicalDevNavigation(router)).toBe(false);
    expect(canonicalDevIdentity.value).toBeUndefined();
  });
  it('loads once; repeated route changes never refetch or blank the sidebar', async () => {
    fixtures.identity.mockResolvedValue(canonical);
    expect(await bootstrapCanonicalDevNavigation(router)).toBe(true);
    expect(isCanonicalErpPathAllowed('/erp/orders/pending')).toBe(true);
    expect(await bootstrapCanonicalDevNavigation(router)).toBe(true);
    expect(await bootstrapCanonicalDevNavigation(router)).toBe(true);
    expect(fixtures.identity).toHaveBeenCalledTimes(1);
    expect(fixtures.setMenus).toHaveBeenCalledTimes(1);
    expect(fixtures.setMenus.mock.calls[0]?.[0]?.length).toBe(1);
  });
  it('preserves menus and tabs on temporary identity network failures', async () => {
    fixtures.identity.mockResolvedValueOnce(canonical)
      .mockRejectedValueOnce(new Error('ERP2_CANONICAL_ENDPOINT_UNAVAILABLE'));
    await bootstrapCanonicalDevNavigation(router);
    fixtures.tabs=[{path:'/erp/orders/pending',key:'pending'}];
    fixtures.setMenus.mockClear();
    fixtures.closeTabs.mockClear();
    expect(await bootstrapCanonicalDevNavigation(router,true)).toBe(true);
    expect(devBootstrapError.value).toBe('ERP2_CANONICAL_ENDPOINT_UNAVAILABLE');
    expect(canonicalDevIdentity.value?.user.id).toBe(18);
    expect(fixtures.setMenus).not.toHaveBeenCalled();
    expect(fixtures.closeTabs).not.toHaveBeenCalled();
  });
  it('removes narrower permissions, stale tabs and hot snapshots on real ACL change', async () => {
    fixtures.identity.mockResolvedValueOnce(canonical).mockResolvedValueOnce({
      ...canonical,effectivePermissionKeys:[],navigation:[{
        key:'orders',label:'Orders',path:'/orders',children:[],
      }],
    });
    await bootstrapCanonicalDevNavigation(router);
    fixtures.tabs=[{path:'/erp/orders/pending',key:'pending'}];
    expect(await bootstrapCanonicalDevNavigation(router,true)).toBe(true);
    expect(isCanonicalErpPathAllowed('/erp/orders/pending')).toBe(false);
    expect(fixtures.closeTabs).toHaveBeenCalledWith(['pending']);
    expect(fixtures.clearSnapshots).toHaveBeenCalled();
  });
  it('invalidates identity on a confirmed expired session', async () => {
    fixtures.identity.mockResolvedValueOnce(canonical)
      .mockRejectedValueOnce(new Error('ERP2_CANONICAL_SESSION_REQUIRED'));
    await bootstrapCanonicalDevNavigation(router);
    expect(await bootstrapCanonicalDevNavigation(router,true)).toBe(false);
    expect(canonicalDevIdentity.value).toBeUndefined();
    expect(isCanonicalErpPathAllowed('/erp/orders/pending')).toBe(false);
    expect(fixtures.setMenus).toHaveBeenLastCalledWith([]);
  });
  it('cannot restore a stale in-flight identity after logout', async () => {
    let complete!: (value:typeof canonical) => void;
    fixtures.identity.mockResolvedValueOnce(canonical).mockImplementationOnce(
      () => new Promise((resolve) => { complete=resolve; }),
    );
    await bootstrapCanonicalDevNavigation(router);
    const inflight=bootstrapCanonicalDevNavigation(router,true);
    clearAuthorizedDevNavigation();
    complete(canonical);
    expect(await inflight).toBe(false);
    expect(canonicalDevIdentity.value).toBeUndefined();
    expect(fixtures.setMenus).toHaveBeenLastCalledWith([]);
  });
});
