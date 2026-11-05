# ERP Order Sync Status Scope Fix — 2026-11-05

- **Work package:** ERP-ORDER-SYNC-STATUS-SCOPE-20261105
- **Provider:** GPT_WEB + US self-hosted Remote DC
- **Scope:** order-list presentation and sync workspace placement only
- **Target:** `/orders`, `/customers/orders`, `/kol/orders` and other `Orders/Index` variants do not display WooCommerce sync health or reconciliation notices.
- **Destination:** `/sync/orders` owns compact sync health / latest success / recent failure / manual check / manual import controls; `/sync` retains generic domain cards and run records without duplicating a Woo order health panel.
- **Backend:** remove `wooOrderSync` snapshot from `OrderController@index`; expose same snapshot and existing access gate through `ManualSyncController@index`.
- **Safety:** no database migrations, order mutations, scheduler changes, or new permissions; existing WooCommerce sync routes are unchanged.
- **Tests:** Orders/Index Vue (7), Sync/Index Vue (3), Sync/Orders Vue (8), SyncWorkspace Laravel (7), OrderSavedViews Laravel (8); typecheck and Vite build PASS on US Mac.
- **Acceptance:** no sync status/actions on order lists; summary on sync page, manual controls gated by `WooOrderManualSyncAccess`; order filtering and Tracking retained.
- **Production:** no automatic Production deploy. Owner approval required. Roll back by reverting PR; no data rollback.
