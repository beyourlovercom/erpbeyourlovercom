import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const source = readFileSync('resources/js/Pages/Kol/Show.vue', 'utf8')

describe('KOL Show formal collaboration projection', () => {
  it('keeps the latest formal collaboration visible and uses post URL as the primary value', () => {
    expect(source).toContain('props.kol.collaborations[0] ?? null')
    expect(source).toContain('currentCollaboration.post_url')
    expect(source).toContain('待补合作发帖 URL')
    expect(source).toContain('当前正式合作')
  })

  it('projects M1.3 content separately from formal collaboration', () => {
    expect(source).toContain('props.kol.contents ?? []')
    expect(source).toContain('内容与发布')
    expect(source).toContain('content.evidence_classification')
    expect(source).toContain('content.publication_status')
  })

})

describe('R6 profile-to-order entry contract', () => {
  it('only shows the new order action for users with create access and a non-archived KOL', () => {
    expect(source).toContain('data-testid="kol-create-order"')
    expect(source).toContain('canCreateOrders && !kol.archived_at')
    expect(source).toContain('/kol/orders/create?kol_id=')
    expect(source).not.toContain('data-testid="affiliatewp-unbound-warning"')
  })
})

describe('KOL two-hop AffiliateWP account action', () => {
  it('uses the server-validated affiliate account URL, not the WordPress user ID', () => {
    expect(source).toContain('affiliateAdminUrl')
    expect(source).toContain('data-testid="affiliatewp-edit-link"')
    expect(source).toContain('data-testid="affiliatewp-copy-url"')
    expect(source).toContain('target="_blank" rel="noopener noreferrer"')
  })
})

describe('P0: failed AffiliateWP creation remains actionable', () => {
  it('keeps the WordPress diagnostic link available without using status as an order gate', () => {
    expect(source).toContain('data-testid="kol-provision-alert"')
    expect(source).toContain('data-testid="kol-provision-wp-check"')
    expect(source).toContain('role="alert" aria-live="assertive"')
    expect(source).toContain('account.provider_status !== \'active\'')
    expect(source).toContain('不要重复创建')
    expect(source).toContain('affiliateReady')
    expect(source).toContain('kolCreateFailureReason')
  })
})


describe('KOL profile referral link projection', () => {
  it('shows the stored KOL profile URL in basic information, rather than the affiliate slug', () => {
    const basicInfo = source.slice(source.indexOf('<dl class="kol-basic-list">'), source.indexOf('</dl>', source.indexOf('<dl class="kol-basic-list">')))
    expect(basicInfo).toContain('Referral Link')
    expect(basicInfo).toContain(':href="kol.kol_profile_url"')
    expect(basicInfo).toContain('{{ kol.kol_profile_url }}')
    expect(basicInfo).toContain("kol.kol_profile_url || '—'")
    expect(basicInfo).toContain('target="_blank" rel="noopener noreferrer"')
    expect(basicInfo).toContain('data-testid="kol-referral-link"')
    expect(basicInfo).not.toContain('affiliateAdminUrl')
  })
})
