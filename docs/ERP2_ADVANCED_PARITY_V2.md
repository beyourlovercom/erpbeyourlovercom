# ERP2 Advanced Parity Standard V2 — ERP2 全量高级同步标准

**Rule ID: ERP2_ADVANCED_PARITY_P0_V2 · OWNER APPROVED 2026-11-26 (Beijing) · PERMANENT P0 · effective on merge to main.**

## 1. Authority and scope
ERP1 is the minimum approved functional, data, permission, workflow and UX baseline. ERP2 must inherit it completely and improve it; a simplified replacement is prohibited. Applies to every existing, migrated or new ERP2 business route, component, feature, version sync and release acceptance. Use the latest verified ERP1 runtime and GitHub main, preserving already approved ERP2 improvements and canonical Laravel business authorities. Do not rebuild a second backend or weaken security, legal, accounting, SKU, inventory, payment, shipment, duplicate prevention or Owner gates. Retired ERP1 functions and material workflow changes require explicit Owner approval and a documented exception; no silent omission. Pixel-identical appearance is not required: functional and interaction equivalence and equal or better design are.

## 2. Acceptance levels (not percentages)
- L1 ROUTE: canonical route, navigation, deep link, reload and correct errors.
- L2 DATA: canonical fields, values, relationships, calculations, inventory and statuses.
- L3 FUNCTION: all valid ERP1 business operations, permissions and positive/negative outcomes.
- L4 INTERACTION: equivalent or better entry points, layouts, controls, dialogs, workflow steps and feedback; no needless additional clicks.
- L5 ADVANCED PARITY: all L1–L4 plus approved ERP2 shared components, compact UI, accessibility, responsiveness, performance and real dual-site workflow evidence.

Only L5 qualifies as page migration PASS. CI, HTTP 200, a build, screenshot, mock fixture, actingAs or read-only API test alone is not L5 or employee UAT. Missing evidence is NOT PASS; no average score or partial-pass substitution.

## 3. Eight mandatory gates
| ID | Gate | Evidence required |
|---|---|---|
| S01 | Functional completeness | Inventory every ERP1 action, button, tab, dialog, form, secondary operation and state; implement and exercise each applicable action. |
| S02 | Data parity | Same canonical record and equivalent snapshot, exact field mappings, relationships, status/aggregation, stock and calculations; distinguish legitimate snapshot differences. |
| S03 | Permission parity | Same actual authorized role/effective capabilities, allowed and denied operations, account isolation; no staff-name exceptions or broadened admin/finance access. |
| S04 | Interaction parity | Mature operation entry points, steps, navigation, error handling and readback; no substitute developer forms or unnecessary saves/clicks. |
| S05 | Layout and design | Preserve mature information hierarchy and business workbench structure, full detail sections, responsive/accessibility and equal or improved density. |
| S06 | Shared components | All business lists use Unified Grid V2, canonical account preferences and server query contracts; preserve tabs, feedback island and approved shared components. |
| S07 | Compact UI | Maximize business viewport, title-inline actions/switches/counters, shared toolbar; static hints at footer; dynamic feedback via island; contextual blocking validation and confirmations remain visible. Dev labels must not consume business space; enforce actual guards. |
| S08 | Real dual-site UAT | Same route, comparable record/data and role, same workflows and state transitions; real browser evidence on exact deployed build and role. Owner visual and live UAT PASS where applicable. |

All eight gates must pass, or a narrowly documented Owner-approved exception must identify the precise applicability boundary. Mark unverified or blocked gates OPEN/BLOCKED, never PASS. Existing ERP2-only improvements must not regress.

## 4. Implementation and release workflow
1. Before implementation: inspect actual ERP1 page and full source/component tree, controllers/services, route contracts, permission policy, all states and operations; compare latest ERP2 source and runtime. Record source revision, route, canonical data and actor.
2. Produce per-page field/action/interaction/role matrix and gap register. Classify missing/partial/simplified/placeholder functionality and false feedback (e.g. a 404 toast on a successful detail page). Keep unknowns explicit. Obtain rendered design approval for material UI changes under existing Owner gate.
3. Reuse mature ERP1 interaction and canonical business rules with ERP2 shared components. Preserve previous releases. For mutations test exact method/route, authorization, validation, atomic persistence, readback/audit and no partial writes; no invented data or controls without actions.
4. Run targeted regressions, full applicable CI, typecheck/build and cross-role tests on exact candidate. Validate real pages on 007windows by default; no restarting READY machines or existing Chrome. Production inspection is read-only, write tests only isolated Dev fixtures with outbound OMS/Woo/WMS/payment/email/webhooks disabled or simulated. Never impersonate an employee or mistake an admin session for an employee. Clean up approved fixtures safely.
5. Compare same business record/role or equivalent verified isolated fixtures across ERP1/ERP2 and all material states. Record real URLs, release SHA/build hash, actual actor (or UNKNOWN), Beijing timestamp, observed results, permission/data differences and unresolved gaps. Use evidence references and respect existing screenshot hygiene. A mock or technical test is clearly labeled separately.
6. Apply Owner visual/live UAT gates and CI, then controlled release according to existing authorization/rollback rules. Recheck exact deployed build and relevant operations, preserve other branches. Record actual verified releases in canonical changelog Markdown only after release; documentation adoption is not a product release.
7. Affected routes cannot be closed, labeled L5 PASS, or formally released as parity-complete if an applicable S01–S08 gate is missing. PA6 full migration acceptance is blocked by every unresolved in-scope parity gap. Never use this policy to bypass existing security or production approval.

## 5. Existing PA4–PA6 roadmap upgrade (no duplicate implementation stream)
- PA4: Reopen previously accepted pages that only passed route/data/interface checks; rebaseline all business routes against S01–S08. Keep genuinely valid evidence; do not erase historical results. Prioritize P0 order details/edit/create, domestic/overseas logistics, allocation/fulfillment and KOL management/orders, then P1 inventory/packaging/sync/customer/product/procurement, then P2 finance/marketing/settings and remaining pages. Order detail P0: preserve ERP1 full workbench (compact header, navigation, status/warehouse, fulfillment, auxiliary flags, inventory availability, order/address/items, packaging/weight, notes, finance, audit); conditional controls must be checked with applicable record/role.
- PA5: Real dual-site page and workflow regression, including feedback and visual comparison; U5–U6 employee UAT evidence is reused without duplication. Test comparable records, not empty test records against populated production records.
- PA6: Route-by-route L5 gate, evidence ledger and final Owner acceptance; missing/partial/placeholder or unverified actions block closure. Do not repeat completed compliant implementation merely to satisfy documentation. Shared locks and latest baseline must be checked before changes.

## 6. Required per-page acceptance record
route | ERP1 source/runtime revision | ERP2 source/deployed build | role/actor | comparable record/state | S01–S08 each PASS/FAIL/OPEN/NA (approved reason for NA) | missing actions and blocker | test/real browser evidence | Owner visual/live UAT | Beijing timestamp | release scope/status

The policy is binding on later work; automated enforcement is a separate implementation concern. No existing page is certified merely by adopting this document. No independent product release or new duplicate PA implementation stream is created by this governance change.
