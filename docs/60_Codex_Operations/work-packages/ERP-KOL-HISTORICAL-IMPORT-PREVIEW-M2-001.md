# ERP-KOL-HISTORICAL-IMPORT-PREVIEW-M2-001

STATUS: ACTIVE / IMPLEMENTED / REAL SOURCE GATE PASS / OWNER REAL-PAGE PASS / GIT DELIVERY READY
EXECUTION_PROVIDER: GPT_WEB
VERIFICATION_TIER: HIGH_RISK
OWNER: Harr
SYSTEM: BYL ERP
BASELINE: origin/main@f1d2509d54ee62a52b6cee9726b7bf3f0aa9460f
BRANCH: feature/erp-kol-historical-import-preview-m2-001
WORKTREE: /Users/a/AI/007/worktrees/erp-kol-historical-import-preview-m2-001
DEPENDENCY: ERP-KOL-REAL-DATA-RECONCILIATION-M1-001 / PR #158 MERGED

## Purpose
Turn one completed P1 reconciliation batch into deterministic identity-level KOL Master import candidates without creating/updating any canonical KOL, Affiliate, Referral, Order, Collaboration, Content or Provider record.

Canonical manual source is `0902-网红订单.xlsx` only. `KOL-tencent.xlsx` remains superseded.
WooCommerce remains order truth. AffiliateWP remains attribution/commission/payout truth. Tencent remains manual KOL CRM/history evidence.

## Frozen input boundary
P2 consumes persisted `kol_reconciliation_batches` + `kol_reconciliation_rows`; it does not add a second source-file parser or second upload path.
Each source row remains immutable evidence. P2 may persist derived preview candidates only.
No source PII is added to Git fixtures or WP documentation.

## Exact clustering contract
Candidate clustering uses only explicit exact identity tokens from source evidence: normalized valid email and individually parsed absolute http/https social profile URLs.
Multiple newline/whitespace-separated URLs in one source cell are split and normalized individually.
Rows sharing any exact token belong to one deterministic connected component. No fuzzy/name/address/phone/order/product/logistics matching is allowed.
Rows with no valid exact token are excluded from new-KOL candidates and counted as unresolved source rows.

## Existing-KOL guard
Every candidate is compared against active ERP KOL exact email/profile-url evidence using the same normalization semantics as P1.
Exactly one existing KOL => `existing_kol`; no existing KOL => eligible for new-KOL preview; multiple/disagreeing exact matches => `conflict`.
P2 never updates an existing KOL and never creates a duplicate around an exact existing identity.

## Canonical KOL field proposal
`display_name` is a proposal only: preferred exact social handle, otherwise normalized email local-part. It is not legal-name evidence.
`Contact Email` => proposed `contact_email` when one normalized value is deterministic; multiple values in one connected component remain visible and require review.
`Full name` => `shipping_recipient_name` only; MUST NOT populate `legal_name`.
Structured `Address Street`, `City`, `State`, `Zipcode`, `Phone` may propose shipping/contact fields. Free-form `收信 Shipping Info` is evidence only and is never parsed into canonical fields in M2.
`税Tax Number` may propose `tax_id`; `Payment Account`/`费用类型` remain review evidence and do not infer payout method without an explicit supported type.
Country values map deterministically to the existing KOL country vocabulary: US/GB/DE/FR/CA/AU/PH directly, EU member states => EU, other recognized/unknown countries => OTHER. No geocoding/network lookup.

## Social proposal
Every valid profile URL is preserved as a proposed social account. Platform/handle may be deterministically parsed from the URL host/path only; this is not fuzzy identity inference.
Known mappings use existing platform vocabulary: Instagram, TikTok, YouTube, Facebook and X/Twitter (`twitter`); all other valid hosts => `other`.
Followers may attach to a social account only when a source row has exactly one valid social URL. Multiple-social rows preserve raw followers as evidence but do not guess which account owns the metric.
When repeated exact social URLs have multiple follower observations, the newest valid `Input Time` wins; otherwise the latest source-row observation wins. Raw follower value is preserved.

## Owner proposal
`Belongs to` is source owner evidence only.
P2 may resolve it to `owner_id` only by exact case-insensitive match against one current ERP user name/email with KOL visibility. Zero matches => `unresolved`; multiple matches => `conflict`. No alias/fuzzy mapping is allowed without a separately approved mapping table.
Owner absence does not authorize assigning the importing user automatically.

## History boundary
`发布合作的URL`, source Status, warehouse/carrier/tracking, Order No., SKU/Product Title/Quantity, Discount Code/Affiliate Link, Price, Shipping Status and Notes remain historical evidence/summary only in M2.
They MUST NOT create/update Woo Orders, Collaborations, Content, Coupons, sales performance, commissions or payouts.
P2 may show per-candidate source-row count, source order count, published-URL count, product/SKU evidence count and date range for operator context.

## Preview status vocabulary
`ready_new_kol` = deterministic identity candidate, no existing ERP KOL, required display proposal available, no hard identity conflict.
`needs_review` = deterministic candidate exists but canonical field/owner/social observations vary or are incomplete.
`existing_kol` = exact evidence resolves to one existing active ERP KOL; no new KOL proposed.
`conflict` = exact evidence points to multiple existing KOLs or an internal deterministic identity contradiction.
`unresolved_source` = row has no valid exact identity token; never becomes a candidate.

## Persistence
Add preview-only `kol_import_preview_batches` and `kol_import_preview_candidates` linked to the source reconciliation batch.
Candidate row stores deterministic cluster key, source-row ids/count, exact identity evidence, proposed KOL JSON, proposed social JSON, source owner evidence/resolution, history summary, existing kol id/status, preview status/message and timestamps.
These tables are non-canonical evidence. No business module may treat them as actual KOL or performance truth.

## Permissions and UX
Workspace: `/kol/import-preview`, reachable from the P1 reconciliation workspace only; no level-1/level-2 menu change.
GET/generate preview require BOTH `kol.operate` and `kol.affiliates.manage`; specialist-only users are forbidden server-side.
Operator selects one completed P1 reconciliation batch and generates/re-generates the deterministic candidate preview.
Show batch identity, source rows, candidate count, unresolved rows and status counts; candidate table shows display proposal, exact email/social evidence, owner resolution, shipping proposal summary, Affiliate candidate presence, history summary, existing-KOL guard and status.
There is NO Confirm Import / Create KOL / Bind Affiliate / Provider action in M2.

## Explicit prohibitions
- No canonical KOL create/update/archive.
- No Affiliate bind/convert/provision or Provider write.
- No Woo Order, Collaboration, Content, Coupon, sales-performance, commission or payout import.
- No fuzzy/name-only/address/phone/order/logistics identity matching.
- No parsing free-form shipping blocks into canonical address fields.
- No automatic owner fallback.
- No source `Price 总价` treated as revenue/performance truth.
- No Production migration/deploy, tag/release or merge without separate Owner authorization.
- No China-machine verification by default.

## Acceptance criteria
- Fresh migration / rollback / reapply for preview tables PASS.
- A repeated order-grained source identity produces one deterministic connected candidate, not duplicate KOL proposals.
- Multi-URL source cells produce separate exact social proposals without network calls.
- No-identity rows are counted as unresolved and never create candidates.
- Existing exact ERP KOL identity blocks a new-KOL proposal; multiple exact ERP KOL candidates are conflict.
- Full name never becomes legal_name; free-form Shipping Info remains evidence only.
- Preview generation mutates preview tables only; KOL/Affiliate/Referral/Order/Collaboration/Content/provider facts remain byte-for-byte/logically unchanged.
- Current canonical 1,077-row source can be replayed through P1 staging and P2 candidate preview with deterministic summary counts.
- Focused PHPUnit, full KOL regression, frontend tests, typecheck, production build, PHP lint and git diff check PASS before Git Delivery.

## Delivery authorization
LOCAL IMPLEMENTATION: AUTHORIZED by Owner `下一步` on 2026-09-02.
COMMIT / PUSH / PR: AUTHORIZED by Owner `明确授权` on 2026-09-03.
MERGE / PRODUCTION / TAG / RELEASE: NOT AUTHORIZED.


## Real-source verification evidence (2026-09-02)
- Owner real-page UAT: PASS on 2026-09-03 after final display-only refinements; no canonical mutation action was introduced.
- Canonical manual source remains `0902-网红订单.xlsx`; full 31-column content replay SHA-256: `e4788ed9c81c2544b6fd99735d11f8949f83b63406c7ac48dcaa713c54cd834d`.
- Verified replay shape: 1,077 source rows / 31 columns; every CSV row has width 31.
- P1 real staging batch #7: 1,077 rows, 970 distinct exact source identities; `no_affiliate=118`, `unresolved=959`, all other P1 statuses 0.
- P2 real preview batch #3: 961 deterministic identity candidates, 12 source rows unresolved; `needs_review=961`, `ready_new_kol=0`, `existing_kol=0`, `conflict=0`.
- Review cause is Local identity-environment coverage, not fuzzy matching: source `Belongs to` values are Young/Emma/Hiko/Summer/Zhixi/Aries/Kelsey while Local has only UAT users. Owner status: 919 unresolved, 42 multiple-source-owner evidence. No fallback assignment is permitted.
- Other candidate review evidence: 27 field variations, 5 multiple-email clusters, 2 multiple-Affiliate-account clusters. Affiliate candidate coverage: 839 unique, 120 none, 2 multiple.
- Canonical mutation isolation after both previews: KOL=7, Affiliate=1,762, Referral=3,299 unchanged. Only P1/P2 staging batches/candidates changed.
- Real workspace server projection: ~494 ms, ~1.34 MB JSON, peak PHP memory ~54.5 MB for six recent batches including 961 candidates. Real page rendered successfully after initial load.

## Owner visual gate
- `AGENTS.md` owner visual approval rule entered main at `72677ce5b5735c5d35a714e11f8f8b0b8105095a` after this P2 implementation had already begun.
- Post-development real-page evidence is mandatory before P2 may be marked OWNER_UAT_PASS / merge-ready / CLOSED.
- Real Local/UAT page: `/kol/import-preview`, normal authentication, bypass disabled.
- OWNER REAL-PAGE PASS: PENDING.
