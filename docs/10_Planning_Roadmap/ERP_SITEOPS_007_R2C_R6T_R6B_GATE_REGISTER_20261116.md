# BYL ERP Vben R2-C → R6-T → R6-B — Dev acceptance and open gates
Date: 2026-11-16 (Hong Kong / Owner). Scope: **PR #766 only, isolated BYL ERP Vben on 007windows**.

## Current code and isolation
- GitHub PR: https://github.com/beyourlovercom/erpbeyourlovercom/pull/766 — Draft / not merged.
- Original ERP `main`: `b07885e36c491a997f88ae3c7a6ef0b14d17ad31`. Independent US Mac mini Laravel UAT checked out at this exact SHA, copied-only SQLite fixture (126 generated test orders, not Production records).
- R6-B integrated main into PR #766 with a normal non-force merge commit `a293ed9c3a92ed4c935ef876c7fe906d58fb878e`; no file conflicts; `main` is an ancestor; the resulting PR-to-main diff was 44 unique Vben/docs files before this new UAT evidence. Re-check main movement before release.
- 007windows Dev gateway: localhost `127.0.0.1:18786`; original Jutdo SiteOps directories, real ERP Production, Woo, OMS and business workers untouched.

## Executed evidence
1. **R2-C synthetic role authorization (PASS, not real-staff UAT):**
   `node D:\\ai\\erp-siteops\\r2b-current-smoke.cjs`.
   Owner 123 / 7 menu groups, ordinary operations 123 / 4, KOL specialist 20 / 3, KOL manager 41 / 3; Laravel session authorization, archived record exclusion, customer grid 20/page, PII stripping, anonymous 401, business POST 405, invalid role 400 PASS.
2. **R6-T existing Chrome scope/regression (PASS):** 30 maximum navigation bookmarks, 3 pins, 3 bounded Orders hot snapshots. A deliberately delayed GET leaves stale rows noninteractive pending server revalidation; an aborted GET clears rows, totals and menu and explicit retry recovers. Previous targeted Vitest 12/12, TypeScript and Vite build PASS.
3. **R6-T five-context Chrome stress (PASS):**
   Versioned runnable file `apps/erp-siteops-vben/runtime/r6t-five-session-browser-uat.cjs`; bootstrap copies it into isolated 007 runtime.
   Real Chrome executable on 007windows, five *independent* isolated Dev browser contexts: Owner×2, operations, KOL specialist, KOL manager. The test intentionally logs into the single-thread local Dev backend **sequentially**, then visits 36 routes in **each concurrent browser context** (180 route transitions). Each context reached a 30-tab cap and retained Dashboard pin. Returning to Orders recovered the expected per-role total, awaited server revalidation completion, and no JavaScript page errors occurred. The fifth context used a 390×844 viewport without document horizontal overflow.
   Final stdout: `R6T_5_SESSION_DEV_PASS contexts 5 tabTransitions 180 elapsedMs 238462`; process exit 0, total runtime approximately 239.73s. Earlier local *test harness* iterations initially used desktop-only mobile menu visibility and checked aria-busy too early; those assertions were corrected before the reported full PASS.
   This is **not** five actual employee accounts, five simultaneous PHP GET requests, or a Production throughput benchmark.
4. **R6-B ZIP-derived cold-source build (PASS on 007 host):**
   Original approved Vben ZIP SHA verified, 17 PR overrides copied into a separate fresh tree, deterministic pinned AG Grid `36.3.0` delta from `scripts/patch-pinned-ag-grid.cjs`, `pnpm install --offline --frozen-lockfile` PASS, `vue-tsc` PASS, targeted Vitest 12/12 PASS, Vite build PASS. See `ERP_SITEOPS_007_R6T_REPRODUCIBLE_BASE_20261115.md`.
5. **R6-B source reconciliation (PASS):**
   PR code has no same-file overlap with the 67 ERP main commits since original PR base; actual Git integration achieved zero conflicts. `ERP Workspace Tabs P0 Protection` passed on integrated SHA `a293ed9c` (run 101). Latest PR head CI must be checked separately.


### Owner sign-off and six named Dev counterpart evidence — 2026-11-16
- **Owner Step 2 visual UAT: PASS**, direct confirmation in ERP2 project conversation: “第二部，**Owner 视觉验收，全部正常。**” The Owner confirms the Vben visual screen and normal interaction for the requested review. No re-interpretation as Production cutover approval or formal role-matrix parity.
- **Six separately named isolated Laravel Dev accounts: 6/6 PASS** on 007windows. Versioned setup `apps/erp-siteops-vben/runtime/r2c-six-named-dev-seed.php` and client `apps/erp-siteops-vben/runtime/r2c-six-named-dev-uat.cjs`; distinct Vita, Liang, Dede, Emma, Guts, Hiko identities in an additional **copied** SQLite test DB, **not** existing Production employee accounts. Verified real Laravel form login/session, role-menu presence/absence, authorized example orders, specialist cross-owner denial. stdout: `R2C_NAMED_DEV_SUMMARY {"passed":6,"failed":0,"configured":6,"isolatedDev":true,"productionTouched":false}`. No customer data or session tokens recorded in Git.
- **Important gate distinction:** this is 6/6 *named UAT counterparts*, NOT validation of each real staff member's original Dev session, exact historical permissions or row-for-row current Dev data. The genuine R2-C gate remains explicitly HOLD until such proof exists.
- **P1 pending:** Orders filter/scroll/draft restoration and portable baseline remain not accepted. PR remains Draft; no main merge or Production deploy authorized by this Owner visual PASS.

## Open acceptance gates (not PASS)
| Priority | Gate | Why open / necessary proof | Estimated effective engineering hours |
|---|---|---|---|
| P0 | R2-C real staff sessions | Actual Vita, Liang, Dede, Emma, Guts and Hiko Dev login with original authorization and row/menu parity. The four `@local.invalid` identities do not qualify. Only use legitimately provisioned Dev credentials; Owner assistance may be necessary. | 3–6 h after access |
| CLOSED | Owner visual UAT — Owner PASS 2026-11-16 | Owner explicitly confirmed Step 2 visual review **全部正常** for the 007windows ERP Vben Dev review. Acceptance covers the Owner visual gate; automated 30/3/3 stress and role parity remain separately evidenced. | 0 h |
| P1 | R6-T cross-page state parity | Hot snapshots cover **Orders response only**. No general proof of unsaved form draft, AG Grid filter/scroll retention or future logistics/inventory module cache parity. Must be scoped with R3 migration; not silently asserted complete. | 4–8 h for Orders-focused gap, other modules deferred to R3 |
| P1 | R6-B truly portable vendor base | Pinned vendor ZIP and pnpm store currently exist only on approved 007 machine. ZIP-derived offline rebuild is verified *there*, but brand-new-machine artifact acquisition/CI is not. | 2–4 h subject to approved artifact distribution |
| P0 | Exact latest PR head CI + review | After test-script/document commits, confirm all required checks; independent review and Owner visual gates still apply. | 1–2 h |
| P0 | Main merge and Production | PR #766 is Draft; do not merge and do not publish ERP Production without applicable Owner UAT and separate release authorization. | gated, not estimated here |

## Next actions
1. Confirm latest-HEAD CI on PR #766 (not just previous integrated commit).
2. Owner visually approved the 007 Dev review on 2026-11-16; retain the Owner PASS as a completed gate, without claiming this substitutes for role-parity or P1 behavior tests.
3. Obtain legitimate employee Dev sessions for the real R2-C matrix, or keep a named HOLD rather than replacing proof with synthetic-role selector.
4. Address Orders in-memory filter/draft state in a separate bounded R6-T follow-up if Owner treats that as a pre-R3 criterion. Reuse the common 30/3/3 tab contract; do not turn unported placeholders into fake working modules.
5. On full gates PASS, prepare PR review. No Production changes without separate Owner authorization.

**Verdict (Owner update 2026-11-16):** R6-B main integration PASS; R6-T core + 5-context technical UAT PASS; six *named synthetic* Dev accounts pass 6/6; **Owner Step 2 visual UAT PASS**; R2-C genuine staff-identity/permission parity remains OPEN; overall PR #766 remains DRAFT / DEV ONLY.
