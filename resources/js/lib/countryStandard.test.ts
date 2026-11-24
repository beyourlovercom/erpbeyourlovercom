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
