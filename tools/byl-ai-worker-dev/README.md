# BYL AI Worker Dev V1

Synthetic-only proof of concept. Never deploy as a shared production service.
US Mac mini: Node HTTP gateway bound to the specific Tailscale IPv4 address,
peer allowlist and Ed25519 signed requests, one concurrent inference, 45s timeout.
007windows: private client key stays on that node; only its public key is
installed as client-public.pem next to the gateway. Keys and logs are not source.
POST /v1/health and POST /v1/kol/parse; application/json with content length.
Sign UTF-8 POST + newline + path + newline + Unix ms + newline + 32 hex nonce
+ newline + exact JSON body, base64 Ed25519 signature. Headers x-byl-ts,
x-byl-nonce, x-byl-signature. Timestamp tolerance 120 seconds.
Only exact approved synthetic fixture strings are accepted. Replay cache
is in memory (not persistent over restarts). Tailscale provides encryption.
Read-only ephemeral Codex invocation; no API key fallback. This is not a
complete security boundary for arbitrary untrusted prompts.
No raw requests, credentials or responses logged; no ERP mutation.
Production identity, provider authorization, rate limiting, durable replay,
privacy minimization and representative benchmark remain separate gates.
LaunchAgent label com.byl.ai-worker.dev-v1, service restart only if needed.
Stop via launchctl bootout gui/501/com.byl.ai-worker.dev-v1 on the Mac.
