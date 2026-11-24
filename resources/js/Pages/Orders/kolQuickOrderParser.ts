export type QuickOrderDraft = {
  recipient_name?: string
  country?: string
  province_state?: string
  city?: string
  address_line_1?: string
  address_line_2?: string
  postal_code?: string
  email?: string
  phone?: string
  recipient_customs_id?: string
  coupon_code?: string
  product_hint?: string
  social_accounts: { platform: string; handle: string; profile_url: string; followers?: number }[]
  unparsed: string[]
}

const labels: Record<string, keyof Omit<QuickOrderDraft, 'social_accounts' | 'unparsed'>> = {
  'full name': 'recipient_name', 'recipient name': 'recipient_name', name: 'recipient_name',
  country: 'country', state: 'province_state', province: 'province_state', city: 'city',
  'address line': 'address_line_1', 'address line 1': 'address_line_1', address: 'address_line_1',
  'address line 2': 'address_line_2', 'post code': 'postal_code', 'postal code': 'postal_code',
  'zip code': 'postal_code', zip: 'postal_code', email: 'email', 'e-mail': 'email',
  'phone number': 'phone', phone: 'phone', 'tax id': 'recipient_customs_id',
  rfc: 'recipient_customs_id', cpf: 'recipient_customs_id', code: 'coupon_code',
  'coupon code': 'coupon_code', product: 'product_hint', sku: 'product_hint',
}
const platforms: Record<string, string> = {
  'x.com': 'twitter', 'twitter.com': 'twitter', 'instagram.com': 'instagram',
  'tiktok.com': 'tiktok', 'youtube.com': 'youtube',
}

export function parseKolQuickOrder(raw: string): QuickOrderDraft {
  const draft: QuickOrderDraft = { social_accounts: [], unparsed: [] }
  const loose: string[] = []
  let seenField = false
  for (const part of raw.split(/\r?\n/)) {
    const line = part.trim()
    if (!line) continue
    const social = line.match(/^(https?:\/\/\S+)(?:\s+([\d,.]+)\s*([kKmM])?)?$/)
    if (social) {
      try {
        const url = new URL(social[1])
        const platform = platforms[url.hostname.toLowerCase().replace(/^www\./, '')]
        const path = url.pathname.split('/').filter(Boolean)
        const handle = path.length === 1 ? path[0].replace(/^@/, '') : ''
        if (platform && handle && !['home', 'explore', 'search', 'share', 'watch', 'reel', 'p'].includes(handle.toLowerCase())) {
          const value = social[2] ? Number(social[2].replace(/,/g, '')) : NaN
          const scale = social[3]?.toLowerCase() === 'm' ? 1e6 : social[3]?.toLowerCase() === 'k' ? 1e3 : 1
          const followers = Math.round(value * scale)
          draft.social_accounts.push({ platform, handle, profile_url: url.origin + url.pathname, ...(Number.isSafeInteger(followers) && followers >= 0 ? { followers } : {}) })
          continue
        }
      } catch { /* preserve unrecognized input for review */ }
      draft.unparsed.push(line)
      continue
    }
    const field = line.match(/^([^:：]+)[:：]\s*(.*)$/)
    const label = field?.[1].trim().toLowerCase() ?? ''
    const key = Object.prototype.hasOwnProperty.call(labels, label) ? labels[label] : undefined
    if (field && key) {
      seenField = true
      const value = field[2].trim()
      if (value && !draft[key]) draft[key] = value
      else if (value && draft[key] !== value) draft.unparsed.push(line)
    } else if (!field && seenField) loose.push(line)
    else draft.unparsed.push(line)
  }
  if (loose.length === 1 && !draft.product_hint) draft.product_hint = loose[0]
  else draft.unparsed.push(...loose)
  return draft
}
