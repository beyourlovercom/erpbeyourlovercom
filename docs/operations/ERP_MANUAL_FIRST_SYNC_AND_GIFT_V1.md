# ERP manual-first Woo inbound & warehouse Gift identity V1

Owner rule (2026-11-14): all ERP parameters intentionally changed by an operator take precedence over any later automated WooCommerce inbound sync. Woo source data must never silently remove ERP edits, alter assigned physical warehouse SKU, or reset item identities.

- New inbound orders: import Woo address/order/line items, keeping Woo line item IDs for reconciliation.
- Existing unallocated orders: synchronize only fields without an ERP manual override. Genuine manual corrections write an audit and persistent field lock (e.g. address.city, order.currency, items); recognize legacy manual audit changes.
- Allocated or shipping orders: keep address, workflow state, entire fulfillment item set and actual SKU identities unchanged. Woo conflicts are audited and require explicit reconciliation.
- Line items: reconcile by Woo line item ID without delete/recreate.
- Gift: after Vita selected warehouse Gift, order_items.sku/product_id are actual physical identity; source_sku/source_product_id remain for traceability.
- Historical repair: artisan erp:repair-allocated-gift-skus defaults read-only; --apply is only allowed for verified pending orders; remaps stale allocation item IDs, never calls OMS or Woo.
- Unverifiable shipped Gift history: do not attribute it to today's mapping. Show 实际礼品SKU未登记 until historical evidence exists.
- Outbound: domestic Speeda/Weship and overseas file exports must refuse unresolved Gift.
- Acceptance: new and old operator changes survive a Woo reimport; China and overseas grids display real SKU for verified fulfillment, or explicit exception for unverified history; preserve existing 30-tab UI and parallel releases.
