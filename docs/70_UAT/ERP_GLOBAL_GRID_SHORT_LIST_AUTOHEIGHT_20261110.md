# ERP Global Grid short-list empty-space fix (Owner screenshot, 2026-11-10)

## Complaint and root cause

Owner provided a Production screenshot of domestic stockout with only 2 orders
and a very large white area between those rows and the bottom pager.

The shared GlobalGrid wrapper imposed a 460px-800px viewport height, and
OmsGrid itself imposed a 500px minimum. Removing both was not sufficient:
AG Grid (the installed version) has a separate scrolling-rows minimum height
of 150px in autoHeight mode, which created 119px of whitespace even for a
one-record list. The cause is fixed layout minimums, not missing records,
repeated CSS loading, or a wrong order count.

## Minimal reusable fix

- For a server-backed table with <=10 currently loaded rows (including empty
  search results), use AG Grid autoHeight and remove only this mode's large
  container heights and 150px scrolling-row minimum.
- For 11+ loaded rows or full-screen mode, keep the original bounded scrolling
  grid and pagination. Server paging/filter/permissions remain unchanged.
- For zero rows, reserve only 72px for the native empty-state indicator
  instead of a 500px blank area.
- Keep pinned-right review/restock buttons, search, selection, existing footer,
  saved column preferences and native table fallback.
- No carrier, order, OMS, Woo, finance or inventory write operations.

## Measured synthetic Chrome evidence

- One stockout order: card height = 159px, AG Grid = 66px,
  last row to bottom pager = 1px.
- Maximize = full 900px viewport, restores compact 159px on exit.
- Browser reload still compact 159px after restored saved preferences.
- Empty filtered results = 199px total and native empty-state visible.
- Global Grid synthetic Chrome suite 9/9 PASS; test DB cleanup PASS.

Owner Production visual acceptance after guarded release:
https://erp.beyourlover.com/logistics/china?view=stockout

Only visual layout sizing changes; domestic rollout remains 2/9 and no
unapproved domestic view is enabled. Retain d9cea59d rollback release.
