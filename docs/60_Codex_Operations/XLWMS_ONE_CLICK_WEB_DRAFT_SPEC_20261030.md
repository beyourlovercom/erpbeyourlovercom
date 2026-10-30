# XLWMS US-SM one-click native OMS web draft — frozen Owner scope, 2026-10-30

## Business rules (exact Owner instruction; no unrelated scope)
1. Every Dede manual click for a US-SM order creates a NEW OMS native **draft** using `Upload_Shipping_Label` / USPS and existing `public/assets/xlwms-VOID-TEST-DO-NOT-SHIP.png` placeholder.
2. First click directly creates draft if currently no same-order draft in OMS. On subsequent clicks, **ignore the number/status/reason of all historical OMS orders**, check ONLY the current OMS draft inbox (status 0) for the same ERP order. If none, create a new independent draft. No numeric limit; do not require prior cancel audit or Reship V1 approval.
3. Warehouse operator later replaces VOID image with real USPS shipping label and manually submits. ERP must NEVER call web `createAndSubmit` or native `submit/batch`.
4. If current draft already exists, return existing draft no and DO NOT create another. Prevent double click races; retain audit without treating historical records as blockers.
5. Other warehouses unchanged. Existing XLWMS OpenAPI `outboundOrder/create` production HARD BLOCK remains; DO NOT try to use it.

## Actual vendor runtime research from logged-in China Mac (read-only; verified)
- Vendor's SPA `warehouse-packet.9e8c14bf.js` `saveDraft(false)` sends native web `POST /gateway/woms/outboundOrder/create` using web Bearer session from `localStorage['oms-token']`, **not** signed public OpenAPI `/outboundOrder/create` AppKey; `saveDraft(true)` instead uses `/outboundOrder/createAndSubmit` and is strictly forbidden here.
- Web upload component calls multipart `POST /gateway/woms/appendix/upload` fields `bizAppendixType=1`, `bizCode=outbound`, `bizNo='' `, and `file=<PNG>`; response `data.fileName` and complete `data` appendix object. Browser's form `handleParams` puts that object in `appendixList`, and sends `logisticsSheet` fileName; channelType=3; `logisticsCarrier='usps'`.
- Native web read-only `POST /gateway/woms/outboundOrder/small/page`, JSON `{pageNum:1,pageSize:20,status:0,referOrderNo:<EXACT>}`, requires Auth Bearer and `Track-Key`; verified HTTP 200. Important: native referOrderNo filter is **EXACT**, NOT fuzzy. Existing OMS UI draft OBS0272610300RU has referOrderNo `007-1027-c41863-USPS-TEST4`, platform `BYL-7753-USPS-LABEL-TEST-1030`. Query partial `007-1027-c41863` returns 0 while exact returns 1. Therefore to check same ERP order, PAGE THROUGH **ALL CURRENT DRAFTS** status=0 and match stable ERP ID / known legacy test references using strict prefix boundaries; do NOT rely on exact referOrderNo filter alone. Fail closed if pagination incomplete or API status uncertain. New orders should use stable `referOrderNo=ERP front order number`, unique per-attempt `platformOrderNo` to avoid provider uniqueness conflicts. The existing test draft should count as same order.
- Web native `GET /gateway/woms/outboundOrder/detail?outboundOrderNo=OBS0272610300RU` with Bearer authenticated returned 200 and `status=0`, `logisticsChannel=Upload_Shipping_Label`, `logisticsCarrier=usps`, one appendix with `bizAppendixType=1`. Product CL279B×2.
- Native web POST `Track-Key` is HMAC-SHA256 of 'POST'+sha256_hex(canonical_sorted_json_body), prefix `v2:` base64; browser's key is in PUBLIC JS. A read-only native POST without Track-Key returned HTTP401; exactly signed POST with Bearer returned HTTP200. Implement signing isolated in an adapter; use secret from environment/config, NEVER hardcode OAuth session token or commit secrets.
- Both OMS read-only API status=0 and native web current-draft page status=0 need verification; beware earlier OpenAPI CREATE initially returned 0 then auto-submitted (different API!).
- Token observed JWT format; do not log/transmit user token. Need production-only secure connection via explicit environment credential; if absent fail clearly with no write, never fallback to old OpenAPI. No automatic token extraction from personal Chrome without explicit separate permission.

## Implementation ownership / release boundaries
- Branch `fix/xlwms-oms-web-oneclick-draft-20261030` exclusive worktree.
- Implement dedicated Laravel OMS-web-session adapter (HTTPS locked to oms.xlwms.com, env token + web HMAC key; canonical signing; multipart PNG upload; native web POST create; draft list read and exact native GET detail), separate from current signed OpenAPI gateway.
- Implement one authenticated ERP POST action and one row button for US-SM / Dede. It must serve FIRST and REPEAT with same entrypoint. Clicking when current OMS draft exists returns existing draft; no historical restrictions, no capped reissues; prevent concurrent duplicate HTTP CREATE.
- Persist minimal attempt log and stable unique attempt reference for network ambiguity/idempotency; never mutate original ERP Shipment or Woo/Tracking.
- Preserve existing XLS fallback but remove it as primary staff action; preserve VOID placeholder file.
- Add tests: initial create, repeat after old shipped/canceled/no draft, blocks when same current draft exists (including legacy suffix), separate orders not confused, paging completeness/fail-closed, two-click concurrency, upload+HMAC metadata, no OpenAPI call and no native submit, auth missing fail-closed, shipment unchanged. Tests HTTP fake only; no real CREATE.
- Run PHP tests, Vue typecheck, build, git diff --check. Commit/push/PR after green, then CI merge per AGENTS. **Do not deploy Production** (requires separate Owner pass); **do not perform any live native CREATE or token bootstrap** now.
- Do not pursue historical OMS cancellation reasons, reissue approval chains, Excel rework, other warehouses or Woo changes.

Existing status: PR #571 was merged and Production released manual XLS fallback. Owner has now superseded its daily flow with the exact one-click contract above.
## Implementation notes (development branch, not Production)

- Added `XlwmsOmsWebGateway` with web-session Bearer authentication and canonical POST `Track-Key`, native multipart `appendix/upload`, native `outboundOrder/create` and read-only current draft/details.
- Added `XlwmsWebDraftService` plus append-only per-click attempts. Only the **current** status-0 OMS inbox blocks duplicate creation, not earlier OMS counts or statuses. Uses unique `BYL-WEB-...` platform numbers and stable ERP refer numbers.
- The hard block on signed OpenAPI outbound CREATE remains in place on Production. Do not fallback if OMS web session is missing.
- Operational secrets `XLWMS_OMS_WEB_ENABLED`, `XLWMS_OMS_WEB_TOKEN`, `XLWMS_OMS_WEB_HMAC_KEY` must be configured securely and session expiry handled. Do not copy token to source control.
- HTTP-fake acceptance does not equal a real website create/UAT; standalone real OMS single-order Canary and Production deployment require explicit Owner pass.


## 2026-10-30 repeat-reference correction (vendor WMS setting)
Provider Help Center documents a configurable WMS setting: 禁止OMS提交重复参考单号的单据. This can reject a new warehouse submission if a previous canceled/shipped OMS order reused a reference. New OMS drafts must:
- keep the ERP front order ID as the referOrderNo prefix for current status-0 draft deduplication;
- append a fresh 14-hex attempt suffix to referOrderNo (total length <=100);
- use a separate unique BYL-WEB attempt ID for platformOrderNo;
- verify the full newly assigned referOrderNo after creation.
Owner's duplicate gate is unchanged: only CURRENT draft inbox matters, never historic OMS counts or statuses. Legacy draft reference formats remain recognized.
Official: https://help.xlwms.com/d458/dc94/25ac section 7.
