import type { GlobalGridAction } from '../../../Components/DataGrid/GlobalGridCell.vue'

/** Domain actions for the domestic review column.
 * The Grid is a view; every write still goes through the existing controllers
 * and server-side DomesticReviewAccess/payment/state checks.
 */
export type DomesticReviewRow = {
  order_id: number
  review_status: 'approved' | 'pending' | 'stockout' | 'conflict'
  shipment_status: string | null
}
export type DomesticReviewActionHandlers = {
  approve: () => void
  stockout: () => void
  reject: () => void
  restock: () => void
  reopen: () => void
}
export function domesticReviewGridActions(
  view: string,
  canReview: boolean,
  busy: boolean,
  row: DomesticReviewRow,
  handlers: DomesticReviewActionHandlers,
): GlobalGridAction[] {
  if (!canReview || row.shipment_status !== 'pending') return []
  const options = { disabled: busy }
  if (view === 'stockout') {
    return row.review_status === 'stockout'
      ? [{ label: '已到货-可发货', onClick: handlers.restock, ...options }] : []
  }
  if (view === 'review') {
    return [
      { label: '通过', onClick: handlers.approve, ...options },
      ...(row.review_status !== 'stockout'
        ? [{ label: '缺货', onClick: handlers.stockout, ...options }] : []),
      { label: '驳回', onClick: handlers.reject, danger: true, ...options },
    ]
  }
  return row.review_status === 'approved'
    ? [{ label: '重新审核', onClick: handlers.reopen, ...options }]
    : []
}
