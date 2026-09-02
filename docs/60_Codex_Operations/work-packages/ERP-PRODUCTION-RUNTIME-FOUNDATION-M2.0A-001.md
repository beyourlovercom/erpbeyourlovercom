# ERP-PRODUCTION-RUNTIME-FOUNDATION-M2.0A-001

Status: ACTIVE / PREFLIGHT COMPLETE / INFRASTRUCTURE MUTATION GATED

## Governance
- Program: `ERP-WORDPRESS-CONNECTOR-FULL-CHAIN-ENABLEMENT-M2`.
- Parent phase: `ERP-WORDPRESS-CONNECTOR-PRODUCTION-READINESS-M2.0-001`.
- Execution: `PARALLEL INDEPENDENT WP`.
- Delivery: `ONE WP / ONE BRANCH / ONE WORKTREE / ONE PR`.
- Branch: `feature/erp-production-runtime-foundation-m2-0a-001`.
- Worktree: `/Users/a/AI/007/worktrees/erp-production-runtime-foundation-m2-0a-001`.
- Frozen base: `origin/main@db1cd7da0feffc47a4526d294184d16b70d327ac`.
- Base contains merged ERP PR #142.
- No work directly on `main`.
- No merge without explicit Owner authorization.
- No Production application deployment without explicit Owner authorization.
- No WordPress business sync, WordPress mutation, Shared Secret rotation, or M2.1 signed health in this WP.

## Purpose
Establish the auditable Production runtime foundation required before WordPress Connector M2 can enter signed Production health or read synchronization.

This WP owns infrastructure/readiness only: Production runtime topology, isolation, DNS/TLS path, deploy/rollback contract, backup contract and runtime verification contract.
## Read-only preflight evidence — 2026-09-02
- `erp.beyourlover.com`: no A / AAAA / CNAME record; HTTPS cannot resolve.
- `deverp.beyourlover.com`: no A / AAAA / CNAME record; HTTPS cannot resolve.
- `beyourlover.com` and `www.beyourlover.com` resolve through Cloudflare.
- Repository contains no canonical Production deploy workflow, Docker image, nginx server file, release script or rollback utility.
- Canonical repository documentation consistently treats `https://erp.beyourlover.com` as milestone-gated and not yet deployed.
- GitHub deployment/environment evidence was not available from the current unauthenticated `gh` session; no repository-local deployment evidence exists.

## Application runtime contract
- PHP: `^8.3`; current US Mac CLI is PHP `8.3.33`.
- Laravel: `^13.8`.
- Database baseline: MySQL; current US Mac MySQL is `8.4.11`.
- Cache: Redis.
- Queue: Redis.
- Session: database.
- Scheduler: `runtime:health` every five minutes, `withoutOverlapping(10)`, `onOneServer()`.
- Architecture: single deployment unit, no SSR.
- Authenticated runtime health endpoint: `/system/runtime/health` guarded by `system.permissions.manage`.

## US Mac host capability evidence
- nginx installed and active on loopback port 80.
- MySQL 8.4 and Redis are active as Homebrew services.
- Existing Local ERP queue and scheduler run from `/Users/a/AI/localdev/localerp.beyourlover.com`.
- Existing databases are Local/UAT/probe databases only; no Production ERP database exists.
- Redis has isolated/unused logical DB capacity.
- Approximately 803 GiB disk space was free during preflight.
## Recommended Production topology
Use the existing US Mac as a dedicated small-scale Production runtime host, but create a fully isolated Production unit rather than reusing Local/UAT state.

Proposed isolated components:
- Canonical URL: `https://erp.beyourlover.com`.
- Public ingress: Cloudflare Tunnel or equivalent trusted HTTPS ingress; never a Tailnet IP in ERP config.
- Release root: dedicated Production path, not `/Users/a/AI/localdev/localerp.beyourlover.com` and not a development worktree.
- Database: new dedicated Production MySQL database and least-privilege Production DB user.
- Redis: dedicated Production logical DB/prefix and Production queue namespace.
- Queue: dedicated Production launchd service.
- Scheduler: dedicated Production launchd service.
- Web runtime: nginx/php-fpm or isolated loopback application runtime behind the trusted ingress.
- `APP_ENV=production`, `APP_DEBUG=false`.
- Production secrets stored only in runtime environment / untracked `.env`; never committed.

## Deployment and rollback contract
1. Resolve exact accepted `origin/main` SHA.
2. Create immutable/reproducible release directory from that SHA.
3. Install Production Composer dependencies and build frontend assets.
4. Configure Production environment without rendering secrets into logs/evidence.
5. Create DB backup immediately before every migration batch.
6. Run migration status audit before any migration execution.
7. Migration execution is a separate Owner gate.
8. Switch web runtime only after runtime health, DB, Redis, queue and scheduler preflight pass.
9. Rollback must support application release rollback plus DB restore decision point.
10. Retain exact deployed SHA and backup evidence for every Production release.

## Current blockers
- Public Production ingress is absent.
- `erp.beyourlover.com` DNS is absent.
- `cloudflared` is not installed/configured on the US Mac.
- No Production DB/user, release directory, queue service, scheduler service, or backup/rollback artifact exists yet.
- Production WordPress runtime remains unverified and is outside this infrastructure WP.
## Exact gates
`M2.0A-PREFLIGHT = PASS`

Before infrastructure mutation:
`OWNER_GATE = PRODUCTION_INFRASTRUCTURE_MUTATION_AUTHORIZATION_REQUIRED`

Authorized mutation scope for that future gate may include only:
- install/configure trusted ingress software;
- create isolated Production release/runtime directories;
- create isolated Production DB/user and Redis namespace;
- create Production queue/scheduler service definitions;
- create backup/rollback storage and scripts;
- create/update `erp.beyourlover.com` DNS/TLS configuration.

Still separately gated after infrastructure exists:
- deploy ERP application code to Production;
- execute Production database migrations;
- configure Connector Shared Secret;
- execute WordPress signed health;
- execute any WordPress business read sync;
- execute any WordPress mutation;
- merge this WP.

## Exit criteria
`M2.0A = READY_FOR_PRODUCTION_APP_DEPLOYMENT_GATE` only when ingress, DNS/TLS, isolated DB/Redis, runtime services, backup and rollback are all created and verified without business synchronization.
## Infrastructure foundation evidence — 2026-09-02
- Execution provider: `GPT_WEB + Remote Desktop Commander`.
- Host root created: `/Users/a/AI/production/erp.beyourlover.com`.
- Created isolated directories: `releases`, `shared`, `backups`, `logs`, `runtime`, `service-definitions`.
- `shared` and `backups` are mode `700`.
- No `current` release pointer exists; application deployment remains `NO`.
- Empty MySQL database `byl_erp_prod` created with zero tables.
- Production DB credential was intentionally not created after the credential-writing step was security-blocked.
- Redis logical DB `15` reserved for Production default/queue and DB `14` reserved for Production cache; both were empty at reservation time.
- Non-secret runtime namespace contract stored at `/Users/a/AI/production/erp.beyourlover.com/shared/runtime-nonsecret.env` with mode `600`.
- No Production queue/scheduler service has been loaded or started yet.
- WordPress Connector health/sync/mutation count remains zero for M2.

`PRODUCTION_DEPLOYMENT_STATUS = NO`
## Foundation verification evidence — 2026-09-02
- Owner explicitly authorized starting `ERP-PRODUCTION-RUNTIME-FOUNDATION-M2.0A-001`.
- That authorization was used only for isolated runtime-foundation mutation; ERP application deployment, Production migrations and Connector actions remain separately gated.
- Production queue launchd template: `plutil` PASS; template is not loaded.
- Production scheduler launchd template: `plutil` PASS; template is not loaded.
- Empty Production DB foundation backup created under protected `backups` storage.
- Backup gzip integrity: PASS.
- Backup SHA-256 verification: PASS.
- Production DB table count at backup time: `0`.
- Repository `git diff --check`: PASS.
- Attempt to install `cloudflared` through the remote command channel was blocked by platform safety controls; no package installation occurred.
- ChatGPT Cloudflare connector is not currently installed/available as an invokable control plane.

Current external blocker:
`CLOUDFLARE_TUNNEL_AND_DNS_CONTROL_PLANE = BLOCKED / OWNER CONNECTION ACTION REQUIRED`

No Production HTTP ingress, DNS record, TLS endpoint, application release, migration, Connector secret, signed health, business sync or WordPress mutation has been created by this checkpoint.

## External ingress checkpoint — 2026-09-02
- Cloudflare origin authorization completed by Owner on US Mac.
- `cloudflared` version: `2026.8.3`.
- Dedicated Tunnel created: `byl-erp-production` / `16316426-1b58-456a-8c97-9981536933cd`.
- Tunnel credential file remains under `~/.cloudflared/`; credential content was never read or printed.
- User LaunchAgent `com.byl.erp.production.tunnel` is running with KeepAlive and four active QUIC edge connections.
- Runtime config is permission `600`; origin cert is `600`; tunnel credential file is read-only owner access.
- Ingress validation: PASS.
- `erp.beyourlover.com` currently maps to `http_status:503` maintenance state; no Local ERP or Production application is exposed.
- Public DNS route creation is still pending because the automated `cloudflared tunnel route dns` mutation was blocked by the execution safety layer.
- `erp.beyourlover.com` currently has no CNAME/A answer.
- Production application deployment, migration, Connector signed health, read sync and WordPress mutation remain NOT EXECUTED.

CURRENT_GATE: `EXTERNAL_INGRESS_DNS_BINDING_PENDING`
NEXT_EXACT_ACTION: Owner executes `cloudflared tunnel route dns byl-erp-production erp.beyourlover.com`; GPT verifies DNS/TLS/503 and then stops at Production Application Deployment Gate.

## External ingress final verification — 2026-09-02

`EXTERNAL_INGRESS = PASS`

- `erp.beyourlover.com` now resolves through Cloudflare proxy addresses.
- HTTPS request returned the intentionally configured `503 Maintenance` response.
- TLS verification passed (`ssl_verify_result=0`).
- Presented certificate CN is `beyourlover.com`, issued by Google Trust Services, valid during the verification window.
- Cloudflare edge response was confirmed (`server: cloudflare`) and the Tunnel LaunchAgent remained `running`.
- Production `current` release pointer remained absent, so no ERP application was exposed by this gate.
- No Migration, Connector Signed Health, business read sync, WordPress mutation or secret rotation occurred.

`M2.0A = READY_FOR_PRODUCTION_APPLICATION_DEPLOYMENT_GATE`

## Main drift reconciliation — 2026-09-02

- During ingress work, `origin/main` advanced from `db1cd7da0feffc47a4526d294184d16b70d327ac` to `0a9ab219ac7fa80dfcde1958350503d8206c92d8`.
- The drift contains merged PRs #143 through #157, including Backup/Migration M1.0–M1.5 and the fresh-install MySQL index hotfix.
- Connector PR #142 remains an ancestor of the new main baseline.
- No Connector transport/settings redesign was introduced by this drift; the relevant runtime overlap is scheduler registration for canonical automatic backup jobs.
- M2.0A must reuse the merged Backup domain for Production backup/restore/scheduled retention rather than create a second backup engine.
- The local M2.0A branch was cleanly rebased with no conflicts to `origin/main@0a9ab219ac7fa80dfcde1958350503d8206c92d8`.

`PRODUCTION_RELEASE_CANDIDATE = origin/main@0a9ab219ac7fa80dfcde1958350503d8206c92d8`
`PRODUCTION_APPLICATION_DEPLOYMENT = NOT AUTHORIZED / NOT EXECUTED`

## Production application deployment preparation — 2026-09-02

Owner explicitly authorized `M2.0A Production Application Deployment Preparation` only. Migration execution, `current` cutover, public 503 removal, Connector Signed Health/sync/mutation and merge remained excluded.

Preparation evidence:
- Frozen release SHA: `0a9ab219ac7fa80dfcde1958350503d8206c92d8`; PR #142 ancestry PASS.
- Detached release prepared at `/Users/a/AI/production/erp.beyourlover.com/releases/0a9ab219ac7fa80dfcde1958350503d8206c92d8`.
- Composer production install used `--no-dev --no-scripts`; 99 packages installed and platform requirements PASS.
- `composer setup` was explicitly not used because it contains `php artisan migrate --force`.
- `npm ci --ignore-scripts` installed 238 packages; Vite production build PASS with manifest plus six built assets.
- Shared Production `.env.production` created mode `600`; APP_KEY, DB password and Backup encryption key were never rendered.
- Dedicated MySQL principal `byl_erp_prod_app@127.0.0.1` created with privileges confined to `byl_erp_prod.*`; application DB connectivity PASS.
- Production database remains zero tables; no migration or seed ran.
- Redis isolation: data/queue DB `15`, cache DB `14`, canonical queue name `default`; runtime health PASS including queue contract.
- Shared storage overlay and `public/storage` link prepared; source release remains exact SHA with only documented runtime storage overlay paths differing from Git index.
- Laravel package discovery and config/route/view cache preparation PASS; table count remained zero before and after.
- Backup AES-256 key contract PASS; `mysqldump`, `tar` and `zstd` tooling are present; automatic backup timezone is `Asia/Singapore`.
- Canonical private repository `beyourlovercom/erp-backups` is reachable from the host.
- `BACKUP_GITHUB_TOKEN` remains intentionally absent; current broad GitHub CLI credential was not copied into Production.
- Dedicated PHP-FPM pool, loopback Nginx origin (`127.0.0.1:18080`), queue and scheduler LaunchAgent definitions are prepared.
- Nginx real-IP handling trusts only local Tunnel traffic from `127.0.0.1` and uses `CF-Connecting-IP`; FastCGI forces HTTPS context without application proxy-code changes.
- Four Production service plists lint PASS; PHP-FPM config test PASS; Nginx config test PASS.
- PHP-FPM, Nginx, queue and scheduler Production services are all `NOT_LOADED`.
- Cloudflare public state remains HTTPS `503` with TLS verification PASS; `current` remains absent.
- Repository secret scan across M2.0A docs/ops artifacts PASS; no Production secret material is tracked.

Main continued to advance during this preparation (`origin/main@31e300cc1079f9d7b4448f8b67e8308ede86b1bf` at final checkpoint). This does not alter the explicitly frozen release candidate for this authorization.

`PRODUCTION_APP_PREPARATION = PASS`
`PRODUCTION_MIGRATION = NOT EXECUTED`
`CURRENT_CUTOVER = NOT EXECUTED`
`PUBLIC_ORIGIN_ENABLEMENT = NOT EXECUTED`
`BACKUP_GITHUB_CREDENTIAL = PENDING / SECURITY GATE`
`NEXT_GATE = DEDICATED_BACKUP_CREDENTIAL + PRODUCTION_MIGRATION/CUTOVER AUTHORIZATION`

## Backup GitHub credential gate — PASS — 2026-09-02

- Owner supplied a dedicated GitHub Fine-grained PAT through the local hidden-input importer; the token value was never printed or copied into chat.
- First credential attempt was rejected before installation because repository scope was broader than the approved single-repository boundary.
- Owner corrected repository access to `Only select repositories -> erp-backups` with `Contents: Read and write`.
- The credential importer contract requires all of the following before environment installation: private `erp-backups` write access, denial of access to private `erpbeyourlovercom`, temporary Release creation, Release deletion, tag deletion, and deletion verification.
- `.env.production` now contains a Fine-grained PAT and remains mode `600`; this is evidence that the importer reached its post-validation install path.
- Existing broad GitHub CLI credential was not copied into Production.
- Platform safety controls blocked redundant post-install secret-bearing API probes; no bypass was attempted.
- Safety checkpoint after credential installation: Production DB tables `0`; `current` absent; public HTTPS still returns expected `503` with normal TLS verification; Production PHP-FPM/Nginx/queue/scheduler all remain NOT_LOADED.

`BACKUP_GITHUB_CREDENTIAL_GATE = PASS`
`CURRENT_GATE = PRODUCTION_MIGRATION_AND_CUTOVER_OWNER_AUTHORIZATION_PENDING`

## Production migration + cutover — 2026-09-02

Owner explicitly authorized `M2.0A Production Migration + Cutover`.

- Frozen Production release remained `0a9ab219ac7fa80dfcde1958350503d8206c92d8`; no main drift was adopted.
- Pre-migration DB snapshot: `byl_erp_prod-pre-migration-20260902-004846.sql.gz`; gzip + SHA-256 verification PASS; mode `600`.
- `php artisan migrate --force --no-interaction`: PASS.
- Migration verification: `70/70 Ran`; Production DB table count after migration: `78`.
- Post-migration/pre-cutover snapshot: `byl_erp_prod-post-migration-pre-cutover-20260902-004935.sql.gz`; gzip + SHA-256 verification PASS.
- `current` atomically switched to the frozen release SHA.
- Dedicated PHP-FPM, Nginx, Queue and Scheduler LaunchAgents are loaded and running.
- Production runtime health: PASS across cache, queue contract, Redis default and Redis cache.
- Fresh backup schedule policy is `enabled=false`, cadence `daily`; no automatic backup was triggered during cutover.
- Queue/Redis baseline at cutover: DB jobs `0`, failed jobs `0`, Redis DB14 `0` keys, Redis DB15 `0` keys.

### Public activation result

- Cloudflare rollback config preserving the `503 Maintenance` route was retained before cutover.
- Tunnel ingress changed from `http_status:503` to `http://127.0.0.1:18080`; ingress validation PASS.
- Tunnel LaunchAgent remained running after restart.
- Public `https://erp.beyourlover.com/up`, `/login`, and `/` each returned HTTP `200` with normal TLS verification (`ssl_verify_result=0`).
- Four build assets referenced by the live login page each returned HTTP `200` with normal TLS verification.
- Public response remained served through Cloudflare.
- Production login page was opened on the US Mac for Owner visual verification.

`PRODUCTION_RUNTIME = LIVE`
`PRODUCTION_DEPLOYMENT_SHA = 0a9ab219ac7fa80dfcde1958350503d8206c92d8`

### Fresh-install Identity bootstrap gap

- After canonical migrations: `users=0`, `roles=0`, while permissions and initial warehouses exist.
- The canonical `DatabaseSeeder` is NOT Production-safe because it creates `Test User / test@example.com`; it was not executed.
- UAT seeders were not executed.
- `erp:user:create` requires an approved role row to already exist, so it cannot provision the first Production administrator while `roles=0`.
- No local-admin bypass was enabled; Production remains `ERP_LOCAL_ADMIN_BYPASS=false`.
- Therefore public runtime cutover is successful, but authenticated Owner UAT is blocked until a Production-safe role/admin bootstrap contract is added.

`CURRENT_GATE = PRODUCTION_IDENTITY_BOOTSTRAP_FIX_PENDING`
`WORDPRESS_CONNECTOR_SIGNED_HEALTH = NOT EXECUTED`
`WORDPRESS_BUSINESS_SYNC_OR_MUTATION = NOT EXECUTED`

## Production Identity Bootstrap Fix — 2026-09-02
- Owner explicitly authorized `M2.0A Production Identity Bootstrap Fix`.
- Fresh Production migration completed successfully but yielded `USERS=0`, `ROLES=0`, while the current authorization model uses `RolePreset` keys such as `role_preset_administrator`.
- The legacy `erp:user:create` command still offered `super_admin/admin/manager/operator`, which no longer matches the canonical RolePreset authorization model.
- `DatabaseSeeder` was explicitly rejected for Production because it creates `Test User / test@example.com`; Local/KOL UAT seeders were also rejected.
- Current `origin/main` was audited and contains no later Identity bootstrap fix, so the gap is real and remains applicable upstream.
- Fix: `erp:user:create` now derives choices from all eight canonical `RolePreset` values and creates a missing preset role using the same seed-on-first-create permission semantics as `UserService::ensureRole()`.
- Existing role templates are never overwritten by this bootstrap path.
- Fresh-install administrator coverage verifies `role_preset_administrator`, `system.permissions.manage`, and `backup.manage`.
- Validation: PHP syntax PASS; `git diff --check` PASS; Vite production build PASS; targeted Identity suite completed with 215 assertions and zero failures.
- Production remained live on the previous frozen release throughout patch development.

## Production Identity Bootstrap — PASS

- Active Production release: `9d998c970b3994d81d3085419d9c30a172517e44`
- First Production user: created via canonical `erp:user:create`
- Active users: `1`
- Role: `role_preset_administrator`
- Administrator permission contract: expected `43`, actual `43`, missing `0`
- `system.permissions.manage`: PASS
- `backup.manage`: PASS
- Password: hashed at rest; plaintext not recorded
- Audit: one `user.created` record with source `erp:user:create`
- Runtime health: PASS
- Public `/login`: HTTP 200, TLS verification PASS
- WordPress Connector Signed Health / sync / mutation: NOT EXECUTED

`PRODUCTION_IDENTITY_BOOTSTRAP = PASS`
`M2.0A_RUNTIME_FOUNDATION = IMPLEMENTATION_COMPLETE`
`NEXT_GATE = OWNER_LOGIN_UAT + GIT DELIVERY AUTHORIZATION`

### Production Login Identifier Patch — Deploy Prep

- Patch SHA: `1c0b6ec0215818af7e37bd19e77e40a8732f05ad`
- Exact-SHA release artifact promoted to Production releases directory.
- Dependency locks unchanged vs active `9d998c970b3994d81d3085419d9c30a172517e44`.
- Composer platform requirements PASS.
- Production Vite build PASS.
- Release runtime health PASS.
- No pending migrations introduced.
- Login source contract present: `名称或邮箱` + default domain `beyourlover.com`.
- Active `current` remains `9d998c970b3994d81d3085419d9c30a172517e44`.
- Public `/login` remains HTTP 200 / TLS verify PASS.
- `PRODUCTION_LOGIN_PATCH_DEPLOY = NOT EXECUTED`.
- `NEXT_GATE = OWNER AUTHORIZATION TO SWITCH current TO 1c0b6ec... AND RESTART APP RUNTIME`.

## Production Login Identifier Patch Deploy
- Authorized deploy completed for `1c0b6ec0215818af7e37bd19e77e40a8732f05ad`.
- `current` atomically switched from `9d998c970b3994d81d3085419d9c30a172517e44` to `1c0b6ec0215818af7e37bd19e77e40a8732f05ad`.
- Production PHP-FPM restarted successfully; runtime health remains OK.
- Public `/login`: HTTP 200, TLS verify PASS.
- Browser-rendered login label: `名称或邮箱`; placeholder: `andy 或 andy@beyourlover.com`.
- Public build assets: 4/4 HTTP 200 with TLS verify PASS.
- Production read-only identifier check: full email resolves to existing administrator; display name resolves to same administrator.
- Existing administrator email is not on the `@beyourlover.com` domain, so company-local-part alias is not applicable to that specific account.
- No migration, Connector Signed Health, WordPress sync, or WordPress mutation executed.

PRODUCTION_LOGIN_IDENTIFIER_PATCH = PASS
NEXT_GATE = OWNER_LOGIN_UAT

## Owner Login UAT Closure
- OWNER_LOGIN_UAT = PASS
- Production login identifier contract = PASS
- Active Production release = 1c0b6ec0215818af7e37bd19e77e40a8732f05ad
- Public login route = HTTP 200 / TLS verified
- Runtime health = PASS
- WordPress Connector Signed Health / Sync / Mutation = NOT EXECUTED
- M2.0A functional gates = CLOSED
- NEXT_GATE = GIT_DELIVERY_OWNER_AUTHORIZATION
