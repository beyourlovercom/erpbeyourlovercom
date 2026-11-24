# BYL AI Worker Architecture V1

Status: Owner-approved node allocation; Dev validation only.

## Node responsibilities
- US Mac mini (us-macmini): BYL AI Worker, Codex CLI cloud-assisted inference and future authenticated internal gateway. Use existing ChatGPT authentication for owner-controlled Dev validation, no API key fallback.
- 007windows: ERP business development and runtime node, local deterministic parser, form integration, identity/SKU matching, business validation and order/account workflows.
- OpenAI cloud: inference only. No Ollama or local LLM deployment.

## Request contract
Local rules first. Send only unresolved, minimized data to the worker, and only where approved. Prefer placeholders for personal contact information and customs IDs; reconcile locally. Return schema-validated draft fields, never executable instructions. No worker authority to create KOLs, approve affiliates, write ERP, or ship orders.
Use a bounded queue, request IDs, timeout, limited concurrency and no sensitive raw-text logging. If unavailable, fall back to local parsing and manual confirmation. No dependency on ChatGPT conversation/DC relay for production requests.
Production access requires a supported authentication/authorization arrangement, verified usage limits and privacy controls; personal Plus validation is not approval for a shared production service.
No public unauthenticated endpoint; no transfer of login tokens between nodes. No changes to existing production services or device/browser restarts.

## Rollout
A0-A1: check installed CLI and ChatGPT login.
A2-A3: isolated, synthetic noninteractive structured output smoke test.
A4: multilingual and adversarial synthetic corpus.
A5-A6: authenticated narrow-purpose gateway and 007windows cross-node test.
A7: at least 100 deidentified, human-labeled representative samples, field metrics and latency.
A8: integrate both ERP entry points in isolated Dev; production release separately approved.
