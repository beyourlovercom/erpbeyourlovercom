# ERP-PRODUCTION-STABILITY-P0AB-001

## Status
AUTHORIZED / IMPLEMENTATION ACTIVE

## Purpose
Create the cross-ERP production stability foundation for server-side 5xx diagnostics and safe Inertia navigation failure handling. This package is shared infrastructure and must not contain module-specific business fixes.

## Baseline
- Repository: `beyourlovercom/erpbeyourlovercom`
- Branch: `feature/erp-production-stability-p0ab-001`
- Baseline: `origin/main@2cc9386ed31ad9ed0df2a1bc4bada689b29fec7e`
- Execution provider: US Mac Claude Code for implementation; China Mac Codex for test/verification only.

## Authorized scope
1. Establish one UUID request id at the beginning of every web request, reusing valid `X-Request-ID` when supplied and sharing the same attribute used by AuditService.
2. Measure request duration, SQL query count, SQL cumulative time and peak memory for the request without persisting request bodies, SQL bindings, cookies, tokens or other sensitive data.
3. Add safe response headers for diagnostics: request id, query count, query time and server time.
4. On unhandled HTTP 5xx, generate one opaque ERP error id, emit one structured server log record containing safe diagnostic metadata, and expose the error id to the client without exposing exception details.5. For authenticated Inertia navigation requests that receive 5xx, prevent the default white Inertia invalid-response modal, keep the current page mounted, and publish a non-blocking global ERROR feedback containing a retry-oriented message plus the error id when available.
6. For direct non-Inertia browser 5xx requests, render a minimal branded production-safe error page containing the error id and no stack trace.
7. Add focused PHP and frontend tests for request diagnostics, structured 5xx response behavior, and Inertia invalid-response interception.
8. Keep existing auth, permission, audit, flash feedback and navigation behavior intact.

## Constraints
- No schema or migration change.
- No dependency or lockfile change.
- No module-specific performance remediation in this package.
- No external monitoring/SaaS integration.
- No production access, deploy or merge.
- Do not log request payloads, SQL text/bindings, cookies, authorization headers, secrets or PII.
- Do not catch/convert normal 4xx validation/auth/permission behavior into 5xx.

## Acceptance
- Existing audit records continue to receive the same request UUID established by the request diagnostics layer.
- Every successful web response has stable diagnostic headers.
- A synthetic 500 produces one safe structured log entry with `error_id`, `request_id`, route/method/user id when known, deploy SHA when configured, SQL count/time, request time, and peak memory.
- An Inertia 500 does not open the framework white modal and does not navigate away from the current page.
- Direct 500 output contains only safe generic copy + error id.
- Focused tests, full PHP suite, frontend tests, typecheck and build are delegated to China Mac verification after implementation is committed/pushed.

## Next package
After this foundation passes independent verification, proceed to `ERP-PRODUCTION-STABILITY-P0C-001` for cross-route query/request budget audit, then hotspot remediation packages beginning with Logistics/Inventory.