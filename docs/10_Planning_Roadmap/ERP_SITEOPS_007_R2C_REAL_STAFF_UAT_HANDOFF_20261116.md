# ERP2 PR #766 — R2-C real-staff Dev session audit handoff

Date: 2026-11-16 (Hong Kong). **DEV ONLY / READ-ONLY BUSINESS REQUESTS / NO PRODUCTION.**
Execution provider: GPT_WEB (bounded test-only change). Verification tier: HIGH_RISK (authentication proof).

## Verified source facts
- The isolated Laravel Dev fixture on US Mac mini at `100.94.52.109:18811` contains exactly **4 users, all `@local.invalid`** (read-only SQLite inspection on 2026-11-16). No Vita, Liang, Dede, Emma, Guts or Hiko real account exists in this fixture.
- Existing 007windows `r2b-current-gateway.cjs` accepts only `owner/staff/kol/manager`; `logIn()` explicitly rejects non-`@local.invalid` identities. Thus its four-role PASS is synthetic and cannot close R2-C.
- The Owner's existing 007windows Chrome process reports `R2C_OWNER_VISUAL_REVIEW_READY` at `http://127.0.0.1:18786/#/erp/orders/orders-master`, 123 isolated orders; Owner has not yet issued personal visual PASS.

## New versioned evidence runner
`apps/erp-siteops-vben/runtime/r2c-real-staff-dev-uat.cjs`, also installed by `scripts/bootstrap.cjs`.

The runner is **fail-closed** and does not provision accounts or scrape passwords. It accepts existing, legitimately established Laravel **Dev** session cookies from protected, untracked local files. It sends only GET business requests (`/orders` and server-filtered `/orders?search=...`); no fulfillment, inventory, Woo, OMS, status update, logout or Production API operations. It checks:
1. Exactly six distinct named employees, real Dev emails (rejects `@local.invalid`), and an explicitly allowlisted Dev base URL.
2. Per-person authenticated Inertia `auth.user.email` equals the expected actual Dev account, with separate session cookies.
3. Server-provided allowed/forbidden first-/second-level menu keys match the written expectations.
4. Positive exact visible order; for Emma/Guts/Hiko also a negative exact out-of-scope order. Any missing fixture is a HOLD, never an automatic PASS.
5. Only emits employee labels and pass/fail flags, never cookie values, passwords or customer rows.

## Provisioning needed (not yet completed)
Authorized ERP Dev administrator must supply **legitimate six employee Dev sessions** with appropriate canonical roles/permissions and a paired positive/negative non-Production order fixture. Do not pull passwords or sessions from ERP Production, change real employee permissions, or convert synthetic accounts by renaming their emails. Credentials/cookies must remain on the approved device under an untracked private directory; never commit, paste in chat, or upload them.

The manifest is a private JSON file named by `BYL_R2C_DEV_STAFF_MANIFEST`:

```json
{
  "baseUrl": "http://100.94.52.109:18811",
  "staff": [
    {
      "employee": "Vita",
      "email": "<legitimate-Vita-Dev-email>",
      "cookieFile": "/approved/private/vita.dev-cookie",
      "requiredMenuKeys": ["orders"],
      "forbiddenMenuKeys": [],
      "visibleOrderNumber": "<known-Dev-order>"
    }
  ]
}
```

The example deliberately includes only one of six required rows and placeholder values, so **cannot pass** as-is. Add `Liang`, `Dede`, `Emma`, `Guts`, `Hiko` with genuine values, approved menu expectations, and `forbiddenOrderNumber` for the three KOL staff. On macOS secure the manifest and every cookie file as mode `0600`.

Execution from approved US Mac mini:

```sh
BYL_R2C_DEV_STAFF_MANIFEST=/approved/private/staff-sessions.json \
  node apps/erp-siteops-vben/runtime/r2c-real-staff-dev-uat.cjs
```

The exact success marker is `R2C_REAL_STAFF_DEV_PASS verifiedEmployees=6 readOnlyBusinessRequests=true`; failures emit `R2C_REAL_STAFF_DEV_HOLD` and exit nonzero. Syntax check passed; absent manifest correctly produced `REAL_STAFF_DEV_SESSIONS_NOT_PROVISIONED`. **No real employee PASS was claimed.**

## Remaining hard gates
- **P0 HOLD:** actual six employee Dev identities/sessions and read-only record-level evidence (3–6h after legitimate access). Backend audit is necessary but does not replace front-end session-bound Vben role UI verification.
- **P0 Owner UAT READY, NOT PASS:** inspect the already-running 007windows Chrome live ERP Vben Orders, two-level navigation, 30/3/3 tabs and mobile; only Owner may declare PASS.
- P1 state parity/portable ZIP postponed until both P0 gates close per Owner direction.
- PR #766 stays Draft and Dev-only; no main merge or Production release.
