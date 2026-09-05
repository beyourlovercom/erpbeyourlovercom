# ERP Roadmap + Production Reconciliation V2

Date: 2026-09-05
Owner authorization: explicit in chat.
Baseline main: `9443d91410968288e54c587bc85f226b8b772cad` (includes KOL Production Closure #186).
Production current: `purchase-os-v1-rebased-20260904-235138-a36e9d`.

## Truth model

V2 forbids one percentage from representing four different facts. Every module is tracked separately as **Menu Surface**, **Main**, **Closure**, and **Production**. Menu progress means implementation of the currently approved level-1/level-2 menu surface only; it is not long-term OS maturity. Production is a bounded-release composition and is not assumed to equal `main`.

## Production composition

Current bounded release is based on `kol-p4b-enable-bounded-20260904-232029-98405b1`, adds Purchase OS V1 source `ea490c74e9bd919c5072c408f971c62873e32d5d`, then MySQL hotfix `a36e9d34076f811cdf824b86b875d398a1049ed6`. Public `/purchase` and `/purchase/orders` now resolve to authenticated ERP routes. Production is therefore newer than the previous 404 snapshot, but still not a full-tree deployment of main.

## Reconciled status matrix

| Module | Menu Surface | Main | Closure | Production | V2 interpretation |
|---|---:|---|---|---|---|
| Dashboard | 20% | active | none | live | operational shell, roadmap dashboard still partial |
| Order OS | 100% | merged | Order/Fulfillment closures complete | live, but later packaging/product projections not fully promoted | core OS closed; production parity is not exact-main |
| Logistics OS | 40% | pending + carrier implemented | no V1 closure | partial | awaiting-tracking / in-transit / exceptions remain future workspaces |
| Sync | 100% | M1.3 + connector work merged | core sync complete | live | future automation is a later roadmap layer |
| Customer OS | 100% | M1.9 merged | Owner UAT PASS / closure complete | 100% module parity to main at audit | closed core OS |
| Marketing OS | 20% | subscriber foundation merged | not closed | subscriber surface live | newsletters/campaigns/automations/promotions remain planned |
| KOL OS | 77% | V1 + P4/P4B merged | KOL V1 closure complete | V1 live; P4 import 961 complete; P4B binding CLOSED (856 bound; 2,416 referrals re-resolved; 0 ambiguity) | closure 100% does not make future placeholder menu pages complete |
| Inventory OS | 100% approved menu surface | M1 + packaging bridge merged | Inventory M1 closed | core live; packaging/main parity not yet fully promoted | MVP2 Reservation is next roadmap work, not current menu debt |
| Purchase OS | 33% approved menu surface | V1 closure merged | Purchase OS V1 Owner UAT PASS | live now | V1 is closed; approved future `采购文件/记录` remain planned |
| Product OS | 100% approved menu surface | M1.6 merged | M1.5/M1.6 UAT PASS; final cross-phase closure pending | older Product surface live; M1.6/packaging files not fully promoted | highest-priority closure/promotion gap |
| Guide OS | 100% | merged | accepted | live | closed |
| Accounting | 25% | order-cost surface merged | no OS closure | partial | next business-value layer after fulfillment facts stabilize |
| Settings | 46% | mixed | no aggregate closure | partial | users/roles/changelog live; backup partial; several approved pages still placeholders |

## Main versus Production parity audit

A tracked-file comparison of `main@a36e9d3` to current Production showed bounded composition rather than exact-main deployment. Module parity samples: Customers 100%; Marketing 100%; Finance 100%; Guides 100%; Sync 95.1%; KOL 92.9%; Orders 83.9%; Procurement runtime 80% (missing test files only); Inventory 73.8%; Logistics 66.7%; Products 62.7%. This metric is diagnostic only and must not be treated as feature completion.

## Current program direction

The previous `KOL V1 FULL COMPLETION TRACK` current marker is superseded. KOL V1 is closed. The current strategic line is **Production Consolidation → Fulfillment Intelligence**.

Recommended sequence after V2: Product final closure/promotion; Logistics OS V1; MVP2 Inventory Reservation Foundation; Global Search V1; Accounting/Order Profit V1; Marketing expansion; later Automatic Fulfillment.

## Parallel execution contract

Four simultaneous chats are allowed only with one branch/worktree per package, no shared tracked-file editing, no menu/roadmap metadata edits inside feature packages, and GitHub `main` as the only synchronization path. Any shared-contract change discovered by one lane stops that lane for GPT reconciliation rather than editing another lane's owned files.
