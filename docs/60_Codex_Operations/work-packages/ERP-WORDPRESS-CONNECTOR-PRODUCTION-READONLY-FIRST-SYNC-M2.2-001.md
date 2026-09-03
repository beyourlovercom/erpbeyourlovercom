# ERP-WORDPRESS-CONNECTOR-PRODUCTION-READONLY-FIRST-SYNC-M2.2-001

## Authorization
Owner explicitly authorized M2.2 Production Read-Only First Sync on 2026-09-03.

## Scope
Production WordPress -> ERP read-only snapshot ingestion in strict order:
1. Subscribers
2. Affiliates
3. Coupons
4. Referrals
5. Payouts

No WordPress write-back or mutation is permitted in this WP.

## Safety gates
- Each resource must pass preflight before ERP write begins.
- Stop on provider/contract/transport error; do not advance automatically past a failed resource.
- Preserve sync-run evidence; no deletion or rewriting of failed runs.
- Reconcile source counts against ERP run outcomes before closure.

## M2.1 inherited baseline
- Production Connector signed health PASS.
- connection_key=beyourlover.com; contract_version=1.
- AffiliateWP gateway uses origin-compatible browser-prefixed User-Agent.
- Production server rejects pure custom User-Agent with HTTP 445.

## M2.2 preflight finding
Subscribers use an independent Marketing WordPress gateway which still used a pure custom User-Agent. This must be aligned to the M2.1 origin-compatible pattern before the first Production subscriber snapshot.
