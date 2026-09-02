# BYL ERP Production Runtime Foundation

Canonical Production URL: `https://erp.beyourlover.com`.

This directory contains non-secret runtime definitions for the milestone-gated Production environment. It does not authorize deployment by itself.

Host layout:
- `/Users/a/AI/production/erp.beyourlover.com/releases` — immutable application releases.
- `/Users/a/AI/production/erp.beyourlover.com/current` — active release symlink; absent before deployment.
- `/Users/a/AI/production/erp.beyourlover.com/shared` — untracked Production environment/secrets.
- `/Users/a/AI/production/erp.beyourlover.com/backups` — protected DB/release rollback evidence.
- `/Users/a/AI/production/erp.beyourlover.com/logs` — runtime service logs.

Production services are isolated from `/Users/a/AI/localdev/localerp.beyourlover.com`.

Rules:
- Never commit `.env`, credentials, Shared Secret, Cloudflare tokens, DB passwords, or TLS private material.
- Never use a Tailnet IP as the canonical ERP URL.
- Never load the launchd templates until an accepted Production release exists at `current`.
- Production application deployment and migration execution require separate Owner authorization.
- WordPress signed health/read sync/mutation are outside this runtime-foundation package.

Prepared origin contract:
- Cloudflare Tunnel remains on `http_status:503` until the cutover gate.
- Production Nginx listens only on `127.0.0.1:18080`.
- Nginx trusts `CF-Connecting-IP` only from local `127.0.0.1` tunnel traffic.
- Nginx sets `HTTPS=on`; no Laravel proxy-code change is required.
- PHP-FPM uses a dedicated `erp-production` pool and private Unix socket.
- Redis data/cache isolation uses DB `15` / DB `14`; canonical queue name remains `default`.
- Queue, scheduler, Nginx and PHP-FPM LaunchAgents are prepared but not loaded.

Activation order is separately gated:
1. verify backup/rollback and dedicated Backup GitHub credential;
2. execute authorized Production migrations;
3. create `current` symlink to the accepted exact-SHA release;
4. load PHP-FPM, Nginx, queue and scheduler services;
5. verify loopback origin health;
6. change Cloudflare Tunnel ingress from 503 to `http://127.0.0.1:18080`;
7. verify public HTTPS before any WordPress Connector activity.
