# Newsletter Owner Final UAT — ERP current-Core isolated compatibility gate

**Track:** Core→ERP isolated synthetic integration. This is **not** a live Dev signed GET, Gmail delivery, or production subscriber import.

## Exact baseline
- ERP main at branch creation: `01fd9c79aee04d78bde03d4d30183d805bd84691`.
- Core main with Owner-approved Newsletter M3 isolated E2E GitHub closure: `fc2f60023a2d11210ebcd2c720d81a737f52964c`.
- Website main with G5 and Newsletter one-use safety gate: `766b4728215315ce6865bd14755ad8217b9e5d20`.
- Marketing M2.8 live signed GET gate remains independently blocked by security execution refusal: [ERP #452](https://github.com/beyourlovercom/erpbeyourlovercom/issues/452).
- Real outbound confirmation/Gmail gate remains independently blocked/not executed: [Website #67](https://github.com/beyourlovercom/beyourlover-web-v3/issues/67).

## Isolated runner-only E2E
The existing `.github/workflows/erp-marketing-m2-joint-uat.yml` tests a disposable WordPress 6.6.2 / MySQL 8.0 Core plugin and isolated ERP/Laravel SQLite with real loopback TCP, not an HTTP mock. It seeds synthetic pending/subscribed/unsubscribed WordPress rows and legacy fixture, signs HMAC requests to WordPress, validates ERP native + legacy source separation, and repeats sync twice with no duplicate records.

This PR **only updates the exact Core Git pin** to the current main and tracks the result; no production application code, ERP connection/secret, Dev site, live WordPress, WWW/Production, or subscriber records touched.

## Owner Final UAT readiness gates
1. **PASS requirement:** joint CI on this PR exact head and updated Core git pin, with no real email/data. Any failure must be diagnosed rather than waived.
2. **Separate Website code closure:** Newsletter M3 Draft PR #62 latest-head Theme OS CI and Owner review. Do not merge/deploy automatically.
3. **Independent live Dev mailbox Owner UAT:** one authorized real confirmation, actual receipt + deliberate POST, no broad outgoing mail. **A previous executor action was safety-blocked. Do not bypass.**
4. **Independent ERP M2.8 live Dev signed zero-data GET:** requires safe reviewed execution capability without credential relay; no `/sync/wpapi/subscribers` or real write on probe. **Previous credential execution was safety-blocked. Do not bypass.**
5. Only once all applicable real gates pass (or Owner expressly accepts documented deferrals) can the complete Newsletter Owner Final UAT be truthfully closed. Synthetic PASS is not evidence of email deliverability or live ERP connectivity.

**Scope:** Draft PR; code-only CI and docs; exact-head main merge, Dev/WWW/ERP deployment and operational canaries all require separate approvals.
