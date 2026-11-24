import dictionary from '../../data/countries/iso3166-alpha2.json'

export const countries = dictionary
export type Country = typeof countries[number]
const key = (value: string) => value.trim().replace(/\s+/gu, ' ').toLowerCase()
export function aliases(country: Country) {
  return [country.code, country.en, country.zh, ...country.aliases, label(country)]
}
export function label(country: Country) {
  return country.en + ' (' + country.code + ')'
}
export function resolveCountry(value: string | null | undefined): Country | undefined {
  const q = key(value ?? '')
  if (!q) return undefined
  const matches = countries.filter(c => aliases(c).some(a => key(a) === q))
  return matches.length === 1 ? matches[0] : undefined
}
export function searchCountries(value: string) {
  const q = key(value)
  return countries.filter(c => aliases(c).some(a => key(a).includes(q)))
}
