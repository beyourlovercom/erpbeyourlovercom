import { describe, expect, it } from 'vitest'
import { parseKolQuickOrder } from './kolQuickOrderParser'

describe('KOL quick order text parser', () => {
  it('extracts social identity, followers, shipping, product and coupon without inventing values', () => {
    const draft = parseKolQuickOrder('https://x.com/_example_ 29.4K\nFull Name: Example Person\nCountry: México\nState: Nuevo León\nCity: Monterrey\nAddress Line: Example 1\nPost Code: 01234\nEmail: test@example.com\nPhone Number: 001234\n\nSample Product\nCode: SAMPLE20')
    expect(draft).toEqual({ social_accounts: [{ platform: 'twitter', handle: '_example_', profile_url: 'https://x.com/_example_', followers: 29400 }], unparsed: [], recipient_name: 'Example Person', country: 'México', province_state: 'Nuevo León', city: 'Monterrey', address_line_1: 'Example 1', postal_code: '01234', email: 'test@example.com', phone: '001234', product_hint: 'Sample Product', coupon_code: 'SAMPLE20' })
  })
  it('preserves conflicting and ambiguous values for review', () => {
    const draft = parseKolQuickOrder('Email: first@example.com\nEmail: other@example.com\nMaybe one\nMaybe two\nhttps://x.com/example/status/123')
    expect(draft.email).toBe('first@example.com')
    expect(draft.product_hint).toBeUndefined()
    expect(draft.social_accounts).toEqual([])
    expect(draft.unparsed).toEqual(expect.arrayContaining(['Email: other@example.com', 'Maybe one', 'Maybe two', 'https://x.com/example/status/123']))
  })
  it('does not synthesize missing tax IDs or coupon codes', () => {
    expect(parseKolQuickOrder('Country: Mexico')).toEqual({ country: 'Mexico', social_accounts: [], unparsed: [] })
  })
})
