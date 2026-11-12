// Preserve calendar dates and clamp leap-day anniversaries instead of using JS date overflow.
export const plusThreeYears = (joinedDate: string): string => {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(joinedDate)) return ''
  const [y, m, d] = joinedDate.split('-').map(Number)
  const lastDay = new Date(Date.UTC(y + 3, m, 0)).getUTCDate()
  return [String(y + 3).padStart(4, '0'), String(m).padStart(2, '0'), String(Math.min(d, lastDay)).padStart(2, '0')].join('-')
}
