export type FinanceFactRow = {
    id: number
    erp_order_id: string | null
    currency: string
    revenue_provenance: string | null
    provider_order_status: string | null
    provider_reconciliation_status: string | null
    paid_at: string | null
    values: Record<string, string | null>
}
