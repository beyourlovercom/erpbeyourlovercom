# ERP2 PR #766 — R2-C real-staff Dev session audit handoff

Date: 2026-11-16 (Hong Kong). **DEV ONLY / READ-ONLY BUSINESS REQUESTS / NO PRODUCTION.**
Execution provider: GPT_WEB (bounded test-only change). Verification tier: HIGH_RISK (authentication proof).

## Verified source facts
- The isolated Laravel Dev fixture on US Mac mini at `100.94.52.109:18811` contains exactly **4 users, all `@local.invalid`** (read-only SQLite inspection on 2026-11-16). No Vita, Liang, Dede, Emma, Guts or Hiko real account exists in this fixture.
- Existing 007windows `r2b-current-gateway.cjs` accepts only `owner/staff/kol/manager`; `logIn()` explicitly rejects non-`@local.invalid` identities. Thus its four-role PASS is synthetic and cannot close R2-C.
- **Owner Step 2 visual review PASS (2026-11-16)**, explicitly confirmed by Owner. This does not close genuine employee identity/permission parity.

## New versioned evidence runner
`apps/erp-siteops-vben/runtime/r2c-real-staff-dev-uat.cjs`, also installed by `scripts/bootstrap.cjs`.

The runner is **fail-closed** and does not provision accounts or scrape passwords. It accepts existing, legitimately established Laravel **Dev** session cookies from protected, untracked local files. It sends only GET business requests (`/orders` and server-filtered `/orders?search=...`); no fulfillment, inventory, Woo, OMS, status update, logout or Production API operations. It checks:
1. Exactly six distinct named employees, non-synthetic Dev emails (rejects `@local.invalid`, `@local.test`, reserved `.test`/`.invalid`/`.example`/`.localhost`), distinct canonical ERP numeric user IDs and cookie files, and an explicitly allowlisted Dev base URL.
2. Per-person authenticated Inertia `auth.user.id` **and** `auth.user.email` equal the expected canonical original ERP Dev account, with separate session cookies.
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
      "erpUserId": 12345,
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

The exact success marker is `R2C_LEGACY_STAFF_PREFLIGHT_PASS verifiedEmployees=6 readOnlyBusinessRequests=true erp2Parity=NOT_TESTED`; failures emit `R2C_LEGACY_STAFF_PREFLIGHT_HOLD` and exit nonzero. This verifies **original ERP Dev only**, not ERP2 identity binding or cross-system permissions. Syntax check passed; absent manifest correctly produced `REAL_STAFF_DEV_SESSIONS_NOT_PROVISIONED`. **No real employee PASS was claimed.**

## Remaining hard gates
- **P0 HOLD:** actual six employee Dev identities/sessions and read-only record-level evidence (3–6h after legitimate access). Backend audit is necessary but does not replace front-end session-bound Vben role UI verification.
- **Owner Step 2 visual UAT CLOSED, PASS:** Owner explicitly confirmed the 007windows ERP2 preview looks and behaves normally.
- P1 state parity/portable ZIP postponed until both P0 gates close per Owner direction.
- PR #766 stays Draft and Dev-only; no main merge or Production release.

## Owner P0 parity freeze — controlling instruction

See [`ERP2_IDENTITY_PERMISSION_PARITY_OWNER_FREEZE_20261116.md`](ERP2_IDENTITY_PERMISSION_PARITY_OWNER_FREEZE_20261116.md). Staff **permission equivalence requires comparing canonical ERP user IDs, persisted effective permission keys and record/action scopes between ERP1 and ERP2**. A successful legacy Dev-session preflight alone cannot close this gate. Remaining P1 stays HOLD until 6/6 original-ERP-versus-ERP2 comparison passes. PR #766 remains Draft.

## Read-only canonical effective-permission inventory (added 2026-11-16)

`apps/erp-siteops-vben/runtime/r2c-dev-effective-permission-snapshot.php` is a new **non-Production-only**, read-only Laravel CLI inventory. It uses the same private six-person manifest with each employee's **actual canonical `erpUserId` and legitimate Dev email**, loads the **persisted** role-permission grants plus custom grants, and writes each employee's effective permission keys and mode into a protected, new-once local JSON output (mode `0600`). Super-admin is represented by `*`. It **rejects synthetic `.test`/`.invalid` identities** and exits HOLD when any true account is missing/mismatched. It does not touch passwords, tokens, sessions, roles or business records; no permission snapshot is committed to Git.

```sh
BYL_R2C_DEV_STAFF_MANIFEST=/approved/private/staff.json \\
BYL_R2C_PERMISSION_SNAPSHOT_OUT=/approved/private/erp1-effective-permissions.json \\
php apps/erp-siteops-vben/runtime/r2c-dev-effective-permission-snapshot.php
```

**Verification:** `php -l` on US Mac mini passed; inspected isolated Dev DB has 10/10 synthetic users (six `@local.test`, four `@local.invalid`), so a genuine six-person inventory **cannot yet be executed**. This command only inventories original ERP Dev grants; it **does not** by itself verify ERP2 session binding, row/action scope or cross-system parity. Owner visual PASS stands; parity remains P0 HOLD; P1 remains paused.
