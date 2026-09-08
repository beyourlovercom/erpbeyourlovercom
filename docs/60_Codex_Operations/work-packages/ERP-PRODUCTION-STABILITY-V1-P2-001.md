# ERP-PRODUCTION-STABILITY-V1-P2-001

## Scope

Production Observability Closure after Stability P0/P1. Close the request-correlation chain across Nginx → PHP-FPM/Laravel, make performance-budget warnings self-describing, and provide a one-command request trace runbook. No schema change, business workflow change, Production deployment, migration, credential change, or external-service mutation is authorized by this package.

## Baseline

- Base: `origin/main@2df9e6b5073c807a7b98f0fc1bf836ec5cd5f291`.
- Existing P0 already returns `X-ERP-Request-ID`, renders safe Inertia 5xx pages, and records duration/query metrics.
- Production evidence shows Laravel/PHP-FPM warnings contain request IDs, but the current Nginx access log does not, so cross-layer lookup is incomplete.
- Production logs also show `revision=null`; application fallback must identify the active release when no explicit revision env is configured.

## Implementation

1. Nginx owns/validates a correlation ID before FastCGI, forwards it as `X-ERP-Request-ID`, logs it with request/upstream timings, and returns it on every origin response.
2. Laravel continues to validate/reuse the same ID and falls back to the resolved release directory as `revision` when deploy metadata env is absent.
3. Performance warnings include the breached dimensions and configured budgets, not only raw measurements.
4. `ops/production/trace-request.sh <request-id>` searches Nginx, PHP-FPM/worker, and Laravel logs in one command.
5. Existing safe 5xx behavior remains unchanged and is regression tested.

## Acceptance gates

- Focused observability tests pass.
- Nginx template passes `nginx -t` on the Production host using a non-active temporary config.
- Trace helper passes shell syntax and can find a known request ID from representative logs.
- No full-suite PASS claim unless explicitly run.
- Merge and Production deployment remain separate Owner gates.

## Production note

P2 is observability, not another performance optimization package. End-to-end Production logs currently show materially higher query counts than the controller-only benchmark because middleware/Inertia shared-prop work is included. That evidence is intentionally preserved for the later global 5xx/performance sweep rather than hidden by changing budgets here.
