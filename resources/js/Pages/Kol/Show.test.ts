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
    expect(source).toContain('canCreateOrders && !kol.archived_at && affiliateAdminUrl')
    expect(source).toContain('/kol/orders/create?kol_id=')
    expect(source).toContain('data-testid="affiliatewp-unbound-warning"')
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
