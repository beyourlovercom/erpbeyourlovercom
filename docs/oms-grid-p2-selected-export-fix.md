# ERP OMS P2 - Selected order export fix (2026-11-06)

Root cause: The existing grid emitted selected rows, but its Orders adapter ignored the event. XLSX/CSV links always used the current SKU filter. Three checked orders therefore exported every matching SKU order.

Fix: Store a set of canonical Order IDs, count duplicate SKU item rows only once, keep and restore selection across server-side pages, and clearly label buttons as selected orders or filtered results. Selecting a line selects sibling SKU rows of that same Order.

When selection is nonempty, the export request contains selected_order_ids (up to 200 comma-separated positive integer IDs). It deliberately omits the current table filters to export all SKU items of the chosen Orders. Without selection, existing filtered exports remain unchanged.

Backend uses the existing OrderService query, KOL owner scope, and Production P2 authorization gate; validates numeric ID syntax, duplicates and accessible order count before exporting. Invalid or inaccessible selection returns HTTP 422, never an implicit broad export. No import or order writes.

Isolated Chrome UAT on 127.0.0.1:8298: selected 3 Orders yielded only three IDs in XLSX/CSV URLs; clearing selection restored the full filtered URL; selecting 1 SKU line selected both lines of a multi-SKU Order but counted it as one Order; cross-page selection persisted and restored original checkboxes. No JS page errors. PHP XLSX/CSV tests prove selected Orders override the broader SKU filter, retain full SKU lines, and reject invalid IDs.

No production code or configuration was modified by this branch. Production P2 remains as-is until separate exact-HEAD merge/deploy approval. P3 import remains OFF.
