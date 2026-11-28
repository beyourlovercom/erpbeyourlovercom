import orderDisplay from '../../data/countries/order-country-display.json'
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

// Presentation only. Keep source country codes unchanged for filtering,
// address writes, carrier selection, export and dispatch.
const orderRegions = new Map<string,string>()
for (const [region,codes] of Object.entries(orderDisplay.groups)) {
  for (const code of codes) orderRegions.set(code, region)
}
const germanyDestinations = new Set(orderDisplay.germany_destinations)
export function orderCountryLabel(value: unknown): string {
  const raw = typeof value === 'string' ? value.trim() : ''
  if (!raw || raw === '—') return '—'
  const country = resolveCountry(raw)
  if (!country) return raw + '/区域待确认'
  const region = orderRegions.get(country.code) ?? '区域待确认'
  return country.code + '-' + country.zh + '/' + (region === '欧盟' && !germanyDestinations.has(country.code) ? '欧洲' : region)
}
