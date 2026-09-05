# ERP-PROCUREMENT-TRACKING-JUMP-M1-001

## STATUS

- Scope: Purchase OS tracking-number quick query jump
- Base: `main@360da1038e8ffeb8764a7e2de672d9dd1b595805`
- Branch: `feature/erp-procurement-tracking-jump-m1-001`
- Owner live-page UAT: REQUIRED before commit/delivery
- Production: FORBIDDEN

## FROZEN SCOPE

1. Procurement Detail keeps the existing carrier + tracking number truth.
2. When tracking number is non-empty, show `一键查询物流`.
3. Click copies the current tracking number to clipboard.
4. The same click opens `https://www.kuaidi100.com/` in a new tab.
5. Empty tracking number disables the action.
6. Show concise copy-success/fallback feedback.
7. Support HTTP/internal-network browsers without modern Clipboard API.

## EXCLUSIONS

- No Kuaidi100 API integration.
- No API key, customer code, subscription, polling, webhook, or paid query.
- No schema, controller, route, status, notification, or procurement lifecycle change.
- No automatic tracking-state synchronization.
## VERIFICATION EVIDENCE

- `Show.test.ts`: 5/5 PASS.
- Typecheck: PASS.
- Production build: PASS.
- `git diff --check`: PASS.
- Localhost browser smoke: Kuaidi100 popup + copy feedback + 390px PASS.
- Tailscale HTTP smoke confirmed `Clipboard API=NO` and legacy synchronous copy fallback returns success.
- Tailscale HTTP opens Kuaidi100 in a new tab.
- Existing UAT procurement order was not mutated by smoke.

## OWNER UAT

Verify on the 18129 live page:
1. `一键查询物流` is visible with a tracking number.
2. Click opens Kuaidi100 in a new tab.
3. Paste confirms the copied value is the procurement tracking number.
4. 390px layout remains usable.

Do not commit, push, PR, merge, or deploy before explicit Owner `pass`.
## OWNER ACCEPTANCE

- Owner live UAT: PASS.
- Accepted behavior: one-click tracking opens Kuaidi100 and copies the current tracking number for paste/query.
- Delivery authorized: commit, push, PR, CI, and merge.
