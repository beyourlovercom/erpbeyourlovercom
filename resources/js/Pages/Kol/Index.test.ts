import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Index from './Index.vue'

const { getMock, postMock } = vi.hoisted(() => ({ getMock: vi.fn(), postMock: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: getMock, post: postMock },
}))
vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const row = (overrides: Record<string, unknown> = {}) => ({
  id: 1,
  display_name: 'Bound Creator',
  country_code: 'US',
  status: 'active',
  relationship_stage: 'cooperating',
  owner: { id: 10, name: 'Owner' },
  primary_platform: 'instagram',
  latest_followers: 12345,
  is_100k_plus: false,
  joined_at: null,
  level: '腰部',
  categories: ['Lifestyle'],
  products: ['SKU-1'],
  content_format: '短视频',
  product_quantity: 1,
  internal_contact: null,
  mcn_agency: null,
  is_mcn_managed: false,
  historical_order_count: 2,
  has_published_content: true,
  affiliate_account: {
    affiliate_id: 1430,
    wordpress_user_id: 8811,
    wordpress_user_login: 'provider-login',
  },
  ...overrides,
})

const mountPage = (rows: ReturnType<typeof row>[]) => mount(Index, {
  props: {
    kols: { data: rows, current_page: 1, last_page: 1, per_page: 30, total: rows.length },
    filters: {},
    savedViews: [],
    ownerOptions: [],
    canOperate: true,
    canManageViews: true,
    isAllScope: true,
  },
})

describe('KOL library Affiliate account visibility', () => {
  it('shows provider-backed WordPress login and exact AffiliateWP id for a bound KOL', () => {
    const wrapper = mountPage([row()])
    expect(wrapper.text()).toContain('WordPress: provider-login')
    expect(wrapper.text()).toContain('AffiliateWP: #1430')
    expect(wrapper.text()).not.toContain('provider@example.test')
  })

  it('falls back to WordPress user id only when provider login is absent', () => {
    const wrapper = mountPage([row({ affiliate_account: {
      affiliate_id: 1238,
      wordpress_user_id: 7712,
      wordpress_user_login: null,
    } })])
    expect(wrapper.text()).toContain('WordPress: WP User #7712')
    expect(wrapper.text()).toContain('AffiliateWP: #1238')
  })

  it('shows an explicit muted unbound state without inventing an account', () => {
    const wrapper = mountPage([row({ id: 2, display_name: 'Historical No Affiliate', affiliate_account: null })])
    expect(wrapper.text()).toContain('未绑定 Affiliate')
    expect(wrapper.text()).not.toContain('AffiliateWP: #')
  })
})
