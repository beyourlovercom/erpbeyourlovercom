/** Preserve only the contact filter across the existing list navigation. */
export function currentContactQuery(): {filter_contact?: string} {
  if (typeof window === 'undefined') return {}
  const value = new URLSearchParams(window.location.search).get('filter_contact')?.trim()
  return value ? { filter_contact: value } : {}
}
