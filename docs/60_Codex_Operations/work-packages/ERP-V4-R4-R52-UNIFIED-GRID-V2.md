# ERP V4 R4–R5.2 · Unified Grid V2 public component contract

Owner actual-page UAT: PASS (2026-11-27). Implementation baseline: aa0f135f77d1887c0f42f2a71bfe5abf26ceeb57. Public contract frozen for the accepted sample; rollout is gated on a verified GitHub commit of this work package. Production deployment is not authorized by this record.

## Interface
- GlobalGrid.vue is the public business-list wrapper; OmsGrid.vue owns AG Grid lifecycle and account preferences.
- Existing props/events remain compatible. Explicit opt-in: :column-settings-version="2", :field-groups="fieldGroups", :cell-text-selection="true".
- FieldGroups = Readonly<Record<string, readonly string[]>>. Group keys are display labels; values are existing authorized colIds. Uncategorized fields go to 其他字段.
- Keep a stable namespace (order master: grid.orders.master.v1); preserve requiredColumnIds, fixed pins, server pagination, search, sort, filters, event handlers, original permissions and actions.
- Pass only fields already authorized by the canonical route. Do not create a second permission catalog, expose unauthorized columns, rewrite Laravel business modules, or duplicate the preference store.
- Changes are draft-only until Save; Cancel/Escape discards them. Reuse existing account preference schema and PUT /ui/preferences/{namespace}.
- Default remains V1 until each business page is explicitly admitted to R5.3. Do not mass-flip the default or independently rebuild warehouse grids.

## Accepted sample and evidence
- /orders, native Laravel + Vue + Inertia; Owner explicitly accepted R4–R5.2 order-master UAT.
- 76 Vue tests passed; 7 PHP tests, 206 assertions passed; typecheck and Vite build passed; 30-tab bundle protection passed.
- Authenticated Inertia response returned eight synthetic orders; five route smokes passed.
- Approved compiled manifest SHA256 DD39F2D69BE4CDA72F8E0C42D9ED322D83AF0C442EF408AC115D009F058FDB78.
- Compact light shell, account entry, existing refresh/fullscreen/sidebar/workspace navigation retained. Theme/language/lock parity was not independently established; do not claim global L5 completion.
- No live provider writes, production DB, or Production deployment.

## R5.3 ownership and gates
- This single V4 migration mainline owns warehouse table integration. Separate warehouse branch may resume only nonoverlapping work after verified GitHub foundation delivery and dependency handoff.
- Start with domestic pending shipment as first follow-on; inventory every real business list; classify mixed/detail/placeholder views. Each page must use existing columns and business events and pass actual-page interaction, permissions and regression gates.
- Use latest upstream main plus the approved foundation dependency. Do not overwrite newer warehouse business changes.
- GitHub commit readback is mandatory before unlocking implementation. Owner page PASS is not a waiver of CI, L5, or Production release gates.
