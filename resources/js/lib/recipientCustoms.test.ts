import { describe, expect, it } from 'vitest'
import { recipientCustomsSpec } from './recipientCustoms'

describe('recipient customs shipping-country rules', () => {
  it.each([
    ['CL', 'CL'], ['Chile', 'CL'], ['Chili', 'CL'], ['智利', 'CL'],
    ['PE', 'PE'], ['Peru', 'PE'], ['Perú', 'PE'], ['秘鲁', 'PE'],
    ['AR', 'AR'], ['Argentina', 'AR'], ['阿根廷', 'AR'],
    ['BR', 'BR'], ['Brazil', 'BR'], ['巴西', 'BR'],
    ['MX', 'MX'], ['Mexico', 'MX'], ['México', 'MX'], ['墨西哥', 'MX'],
    ['KR', 'KR'], ['South Korea', 'KR'], ['Korea', 'KR'], ['韩国', 'KR'],
  ])('%s requires the recipient customs ID (%s)', (input, code) => {
    const result = recipientCustomsSpec(input)
    expect(result?.code).toBe(code)
    expect(result?.required).toBe(true)
  })

  it('Norway shows a seller VOEC explanation without requiring a recipient VOEC number', () => {
    for (const country of ['NO', 'Norway', '挪威']) {
      const spec = recipientCustomsSpec(country)
      expect(spec?.required).toBe(false)
      expect(spec?.hint).toContain('VOEC')
      expect(spec?.hint).toContain('卖家')
      expect(spec?.hint).toContain('单件商品')
    }
  })

  it('does not show a customs ID field for countries not on the list', () => {
    expect(recipientCustomsSpec('United States')).toBeNull()
    expect(recipientCustomsSpec('')).toBeNull()
    expect(recipientCustomsSpec(null)).toBeNull()
  })
})
