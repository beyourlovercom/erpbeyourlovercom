# ERP Global Grid V1: Stable First Paint and Per-View Preferences

Owner reported stockout showing two different arrangements across refreshes,
particularly the checkbox / ERP order / # sequence.

## Root cause

- OmsGrid showed default column layout before an async GET of saved account
  preferences. On response, the table visibly rearranged columns and pinning.
- There was no hydration guard against initial column events scheduling writes.
- All domestic views shared the same preference key, mixing one view's layout
  with another.

This is NOT mainly CSS file re-downloading. Grid assets are cacheable; the
visible waste was repeated preference hydration and layout reflow.

## Solution

1. Validated account-user-scoped localStorage snapshot restores before the
   grid becomes visible. Warm visits make ZERO preference GET requests.
2. First-time visits without local cache keep the Grid visually hidden while
   loading server settings (2s timeout per request); show only final layout.
   Legacy settings are a read-only migration fallback.
3. Every domestic view receives its own preference namespace,
   grid.logistics.domestic.{view}.global.v1, with prior shared namespace as
   legacy source. Remount the Grid on view change even if Inertia preserves state.
4. Block writes while hydrating. Cache human edits immediately and debounce
   account PUT; serialize PUTs to avoid an older response overwriting new edits.
5. Explicitly locked domain action columns remain pinned RIGHT even when
   a stale saved column order puts them in the middle.
6. No order, shipping, OMS, Tracking, finance, permissions or schema changes.
   Domestic rollout stays 2/9 until later Owner UAT.

## Browser acceptance

- Delayed server response on cold cache => NO default-layout flash.
- Repeated reload with warm cache => same columns and widths, no repeat GET
  and no initial preference PUT.
- Review and stockout preferences independent; action remains right pinned
  even when stale saved ordering contains actions in the middle.
- Human SKU visibility edit updates scoped local cache immediately,
  synchronizes once to server and survives reload.
- All existing view-specific and permission regressions remain required.

## Rollback

Controlled Production release must preserve the d7e9fe42 rollback target;
no real orders may be modified during visual verification.
