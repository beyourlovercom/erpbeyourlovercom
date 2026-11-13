# ERP Order Notes Append-Only V1 — Owner-approved

**Approval:** 2026-11-13. **Scope:** customer orders and KOL fulfillment order details.

## Frozen business rules

1. Any active employee who is authorized to view an order (`orders.view` plus existing KOL ownership scope) can append a note. No `orders.edit` privilege is needed.
2. No employee, including administrators and original authors, can overwrite or delete previously appended entries. Corrections are new entries at the end of the thread.
3. Each entry preserves a human-readable author name snapshot, author ID, original body, and creation timestamp; the system records an order audit event.
4. Existing `orders.order_note` is preserved as a read-only legacy/source field in order details. New internal entries live in `order_note_entries`, which WooCommerce inbound does not write to.
5. Note additions reuse the existing NotificationPublisher channel used for rejection notifications; recipient targeting is limited to the order owner and operations staff, excludes the actor, and requires `orders.view`. Failed notifications must not turn a successfully committed note into a reported failure.
6. Archived orders remain read-only. KOL specialist owner-only scope remains enforced server-side. Derived fulfillment segments inherit authorization from the parent, but retain their own note thread.
7. No update or delete API or UI exists for appended notes. The Eloquent model prevents update/delete attempts. Existing orders and fulfillment statuses are not modified by note submission.

## V1.1 — Compact latest-note column (Owner 2026-11-13)

- In order lists, show **one note only**, not an unread count or message drawer.
- The newest append-only staff entry wins; where none exists, show the legacy/source note; otherwise show a dash.
- The **备注** column is visible by default beside the order number, rendered as red text (\`#c62828\`), with a single-line ellipsis and full text on hover.
- Apply to customer Global Grid, legacy Orders Index, and Orders OMS Grid with existing user grid preferences unchanged. No special unread state, sorting, notifications, row color or logistics state changes.
- List projections must eagerly load \`latestNoteEntry\`; avoid an N+1 query for every order row. Restrict projection to the existing authorized order collection.
- Isolated UI handoff is on **007windows**, using the private Tailscale route to the synthetic-data US-hosted UAT runtime; do not deploy Production or restart existing Windows Chrome.

## API / data contract

- `POST /customers/orders/{order}/notes` — `orders.view` and customer order scope; body: `{body: string}` (1–2000 trimmed characters).
- `POST /kol/orders/{order}/notes` — same permission, mandatory KOL visibility and owner scope.
- Migration: `2026_11_13_170000_create_order_note_entries_table.php`, additive schema; no legacy data rewriting.
- Inertia `Orders/Show` props: `orderNotes`, `canAppendOrderNote`. `order.order_note` retained for legacy read-only display.
- Generic order PATCH rejects `order_note` **even when empty or null**, only after existing order visibility/ownership checks, to prevent bypassing append-only rules and preserve established 403/404 behavior.

## Verification / release gate

- Positive tests: view-only user (including Vita role capabilities), multi-author append, DB persistence, readback, audit, notification, retention across source note changes.
- Negative tests: missing permission, KOL cross-owner, archived order, blank body, attempt to mutate/delete old entries, legacy generic PATCH.
- Vue tests: independent composer without full order edit, thread rendering, immutable source text, preservation of draft through existing order/detail UI interactions.
- `OWNER_UAT_PASS` on real isolated Local/UAT UI is required before merge; Production requires a separate explicit Owner release authorization and migration backup/rollback plan.
