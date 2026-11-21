# ERP KOL Search Latency P1 · Work Package · 2026-11-20

WORK_PACKAGE_ID: ERP-KOL-SEARCH-LATENCY-P1-20261120
EXECUTION_PROVIDER: GPT_WEB + US Remote Desktop Commander
VERIFICATION_TIER: HIGH_RISK (production runtime incident analysis; implementation stays isolated Dev)
BASELINE: origin/main 476db8c123e66966b111e533295ea5ce4a973deb (rebased from original 79d09408)
BRANCH: fix/kol-search-latency-20261120
WORKTREE: /Users/a/AI/worktrees/erp-kol-search-latency-20261120
DEPENDENCIES: PR #816 KOL ERP fulfillment order detached from AffiliateWP; PR #819 binding review deployed.
DEPENDENCY_TYPE: MERGED_PR
DEPENDENCY_STATUS: prior dependencies present in origin/main; production runtime contains PR #819.
PARALLEL_SAFE: YES; isolated worktree, controller, combobox, tests, and additive index migration.
FOUNDATION_CHANGE_REQUIRED: NO

## Owner-confirmed scope
1. Preserve arbitrary IG usernames literally (leading/trailing underscores, symbols, case); exact account/name lookup before fuzzy search.
2. Preserve all KOL permissions, non-archived filtering, affiliate-unbound order eligibility, 20-item bound and numeric-ID behavior. Eliminate accidental SQL LIKE wildcard interpretation of user-entered underscore and percent.
3. Reduce overlapping stale requests via cancellation; retain visible UI, 200 ms debounce and selection semantics.
4. Diagnose 20-Nov Production P95/P99 incident read-only using Nginx request_time, PHP performance logs, MySQL plans/latency, FPM worker lifecycle and Mac resources. The previously Owner-authorized Spotlight Plan A excludes /Users/a/AI from indexing while preserving volume indexing; no Production database, application, service or FPM configuration is changed.
5. Include a forward-only additive pair of exact-username indexes, with down migration, tested exclusively in isolated Dev. Applying the migration on Production is a separate release gate.
6. Targeted regression tests, full relevant CI gates, isolated US Dev Owner UAT; no Production deployment without new Owner authorization.

## Evidence baseline
- Nginx GET /kol/orders/kol-search for _dolordemuelas_: 20.501s and 26.312s, HTTP 200.
- Same endpoint PHP request logs: 9 queries, SQL total 2.387-6.351s, PHP duration 11.307-17.585s.
- Read-only current SQL reproduction: 28.03ms, 1 result. EXPLAIN scans 1,432 KOL rows; small tables social=1,527, affiliate=2,244.
- PHP-FPM max_children=8; at incident several FPM children exited on SIGKILL; FPM prior-day capacity warnings. Shared Mac mini currently 16 GiB RAM, swap used about 2.35 GiB, Spotlight mds_stores high CPU. These are corroborating conditions, not a proven single root cause.
- Existing request budget log distinguishes PHP duration/query totals; missing historical FPM queue / OS resource samples prevents exact attribution. Kernel jetsam/memorystatus search returned no incident-window evidence, so SIGKILL cannot be proven to mean OOM.
- Read-only Production comparison of exact account: original correlated lookup approximately 37.64 ms SQL versus independent handle UNION approximately 3.99 ms plus scoped KOL query 6.37 ms (10.36 ms combined), without adding an index on Production. These are quiet-time SQL microbenchmarks, not HTTP incident reproductions.
- On 21-Nov after the authorized Spotlight AI-path exclusion, swap was approximately 1.93 GiB (versus 2.35 GiB during triage) and mds_stores was absent from the high-CPU process list. This is encouraging resource relief, not proof of unique causality.

## Acceptance
- Exact `_dolordemuelas_` and case variants locate same authorized KOL, without dropping first/trailing underscores or relying on affiliate binding.
- Fuzzy fallback remains available when no exact match; literal underscore/percent are not interpreted as SQL wildcards.
- Numeric-ID search still ranks the exact ID first even with 20+ fuzzy matches.
- Stale browser searches are aborted on new input/selection/unmount, and latest results win.
- Local test suite and Laravel/Vue build gates pass; Dev UAT visible to Owner. No Production writes or service restarts.
- Isolated Dev migration adds kol_social_handle_exact_idx(handle, is_active, kol_id) and kol_affiliate_login_exact_idx(wordpress_user_login, kol_id); SQLite EXPLAIN QUERY PLAN must show both COVERING INDEX lookups. No Production migration without authorization.

## Prohibitions
- No production migration, live database writes, SSH key/credential copying, real OMS/Woo/order mutation, service or Chrome restart.
- No change to AffiliateWP/order eligibility or search permission/ownership policy.

## Roadmap
R0 Incident triage (read-only) → R1 exact/fuzzy search contract and covering indexes → R2 stale request cancellation and tests → R3 US Dev verification/Owner UAT → separately authorized Production release.

## Evidence from isolated Dev (2026-11-21)
- Fresh Dev SQLite database, all migrations applied, LocalUatSeeder administrative account; exactly 1,432 KOLs, 1,527 active social accounts and 2,244 affiliate rows as synthetic data. Synthetic KOL #984 has account `_dolordemuelas_` and no AffiliateWP binding.
- Real authenticated Dev endpoint `GET /kol/orders/kol-search?q=_dolordemuelas_`: HTTP 200, `[{"value":984,"label":"_dolordemuelas_","account":"_dolordemuelas_"}]`; first sample approximately 73 ms, subsequent ten exact-account samples 18–24 ms. Fuzzy (`dolor`) ten samples 19–24 ms, numeric ID ten samples approximately 20 ms. These are single-user built-in PHP server timings, not production P95 or concurrency proof.
- SQLite `EXPLAIN QUERY PLAN` confirms `SEARCH kol_social_accounts USING COVERING INDEX kol_social_handle_exact_idx (handle=? AND is_active=?)` and `SEARCH kol_affiliate_accounts USING COVERING INDEX kol_affiliate_login_exact_idx (wordpress_user_login=?)`.
- PHP Orders KOL search/scope/logistics suite: 18 passed (345 assertions). Vue combobox: 7 passed. Typecheck: passed. Full Vite build and 30-tab workspace gate: passed.
- Owner browser acceptance and Production load/monitoring remain open. New code and index migration are not deployed to Production.

## Incident attribution and remaining production checks
- A 20–26s Nginx duration with 11–18s PHP work and 2–6s SQL cannot be explained solely by a quiet-time 28–38ms table scan. The endpoint lacked exact-match index access, and the shared Mac showed resource pressure and PHP-FPM child exits. A single causal source is not recoverable conclusively from available historical samples.
- Following any separately approved Production release, correlate Nginx request_time/request ID with PHP request-budget SQL total/count and PHP-FPM worker/queue, MySQL processlist/slow log, Spotlight CPU, memory/swap and user-perceived search time over an agreed observation window. If slow requests persist despite indexed exact match, investigate OS resource contention/FPM queue rather than adding more KOL indexes.

PROJECT POSITION
PROGRAM_ROADMAP: Architecture Migration → MVP1 Operations → ERP KOL OS → Logistics OS → Finance → Production Hardening.
CURRENT_TRACK_PHASE_MAP: R0-R2 implemented and validated in isolated Dev; R3 Owner browser UAT pending; Production release gated.
COMPLETED_PHASES: Historical KOL order selector PR #801, AffiliateWP independence PR #816.
CURRENT_PHASE: R3
CURRENT_WORK_PACKAGE: ERP-KOL-SEARCH-LATENCY-P1-20261120
PACKAGE_STATUS: DEV_VERIFIED_AWAITING_OWNER_UAT
WAITING_PHASES: Owner browser UAT, Production release approval, post-release monitoring
BASELINE: origin/main 476db8c123e66966b111e533295ea5ce4a973deb (rebased from original 79d09408)
GITHUB_STATE: Dedicated local branch implemented; commit/PR preparation in progress.
SOURCE_CONFLICTS: Repository AI/CURRENT_CONTEXT.md contains stale August delivery snapshots; runtime and current GitHub history govern.
GLOBAL_GATE: No Production release without Owner approval; Owner UAT required for visible search behavior.
PRODUCTION_DEPLOYMENT_STATUS: NO - diagnostic reads only.
NEXT_DEPENDENCY: Owner live-browser acceptance of Dev KOL selector; independently authorized Production migration/release.
EVIDENCE_AS_OF: 2026-11-21

ROADMAP HOURS
CURRENT_PACKAGE_ESTIMATE: 5 effective hours; range 3.5-8h.
KNOWN_CURRENT_TRACK_REMAINING: R3 Owner browser acceptance and separately gated release only within this package.
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE - unrelated broad ERP program excluded.
MVP1_TOTAL_ESTIMATE: UNKNOWN WITH EVIDENCE - historical program baseline not re-estimated.
MVP1_PLANNING_BASELINE: UNKNOWN WITH EVIDENCE - historical program baseline not re-estimated.
MVP1_MAXIMUM_ENVELOPE: UNKNOWN WITH EVIDENCE - historical program baseline not re-estimated.
MVP1_REMAINING: UNKNOWN WITH EVIDENCE - unrelated program.
POST_MVP_KNOWN_INCREMENT: UNKNOWN WITH EVIDENCE - unrelated program.
LONG_TERM_CUMULATIVE_RANGE: UNKNOWN WITH EVIDENCE - unrelated program.
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE - unrelated program.
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE - accepted Work Log not consulted.
ESTIMATE_CONFIDENCE: MEDIUM
ESTIMATE_AS_OF: 2026-11-21
ESTIMATE_METHOD: Narrow controller/combobox/tests, additive index migration plus Dev check; no live release.

ESTIMATED CODEX HOURS
PREFLIGHT_AND_SCOPE: 1h (range 0.5-1.5h)
CORE_EXECUTION: 2h (range 1.5-3h)
TEST_AND_VALIDATION: 1.5h (range 1-2.5h)
REMEDIATION_BUFFER: 0.5h (range 0.5-1h)
ESTIMATED_EFFECTIVE_HOURS: 5h
ESTIMATE_RANGE: 3.5-8h
ESTIMATE_BASIS: Code-level bounded latency fix and runtime read-only analysis; deployment excluded.
OWNER_REVIEW: INCLUDED
MERGE: INCLUDED
MIGRATION: INCLUDED (Dev schema rehearsal only; Production migration requires separate release approval)
UAT: INCLUDED
DEPLOYMENT: EXCLUDED
