import { describe, expect, it } from 'vitest'
import { countries, resolveCountry, searchCountries } from './countryStandard'

describe('ERP country standard', () => {
  it('contains 249 codes and resolves exact aliases', () => {
    expect(countries).toHaveLength(249)
    for (const v of ['norway', 'NO', '挪威', 'Norway (NO)']) expect(resolveCountry(v)?.code).toBe('NO')
    expect(resolveCountry('Italy')?.code).toBe('IT')
    expect(resolveCountry('EU')).toBeUndefined()
    expect(resolveCountry('Korea')).toBeUndefined()
    expect(resolveCountry('nor')).toBeUndefined()
    expect(searchCountries('nor').some(c => c.code === 'NO')).toBe(true)
  })
})

import { orderCountryLabel } from './countryStandard'

it('formats order country labels consistently without conflating Germany coverage and EU membership', () => {
  expect(orderCountryLabel('US')).toBe('US-美国/北美')
  expect(orderCountryLabel('DE')).toBe('DE-德国/欧盟')
  expect(orderCountryLabel(' uk ')).toBe('GB-英国/欧洲（非欧盟）')
  expect(orderCountryLabel('NO')).toBe('NO-挪威/欧洲（非欧盟）')
  expect(orderCountryLabel('CH')).toBe('CH-瑞士/欧洲（非欧盟）')
  expect(orderCountryLabel('BR')).toBe('BR-巴西/南美')
  expect(orderCountryLabel(null)).toBe('—')
  expect(orderCountryLabel('ZZ')).toBe('ZZ/区域待确认')
})
