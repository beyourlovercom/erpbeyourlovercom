# XLWMS T1 Virtual API and T2 Safe Draft Readiness

Owner approved T1 local simulated API integration and T2 safe draft before T3. No live warehouse fulfillment, no remote API create when a draft may auto-promote.

## Verified outcome
- T1 isolated SQLite test order TEST-XLWMS-001 has exactly VP676A x1, source manual_other, a fictitious address and an example.invalid recipient. This record exists ONLY in RefreshDatabase sqlite test fixtures; it is NOT created in Production ERP or OMS.
- OMS HTTP is fully faked, with preventStrayRequests guarding tests. The actual SingleOrderDispatch code is exercised with test-only exact-order allowlist, mocked create response, persisted status, duplicate block and unchanged order/Woo.
- T2 prepares a copy of the draft payload isSubmit=0 via a dry-run-only TestDraftPlan. No OMS client injection, persistence or external write method. Flags remote_created=false, remote_post_permitted=false, warehouse_fulfillment_permitted=false.
- Production XlwmsOrderGateway rejects isSubmit other than 1, even if a caller tries using the real dispatch path.
- Official XLWMS help says externally pushed drafts may automatically submit to warehouse processing regardless of OMS UI switches, within minutes. There is no verified isolated provider sandbox or agreement for this account. Hence actual create of isSubmit=0 has NOT been performed.

## T2 promotion gates
1. Provider written guarantees or an isolated test tenant prove draft cannot auto-submit, generate a label, reserve/consume physical inventory or enter warehouse handling.
2. Segregated warehouse or test account with no real fulfillment; only uniquely marked synthetic order and controlled nonrecipient data.
3. Signed read-only exact duplicate lookup, vendor warehouse binding and proper channel verified.
4. Dedicated draft-only API action distinct from live isSubmit=1, exact order allowlist and auditable response.
5. Immediate readback proves draft and no warehouse task, then safe cancellation/discard and final verification. No automatic retry.
6. Separate Owner authorization for any Production API writes and deployment.

## Validation
- US Mac mini isolated feature/xlwms-t1t2-safe-test-20261026 based on PR #510.
- 4 new tests / 28 assertions PASS; full targeted Xlwms, overseas warehouse, order lineage regression 72 tests / 580 assertions PASS.
- TypeScript typecheck and Vite production build PASS. No production change, real create request, OMS credential change or Woo updates.
- T3 and R1-R7 remain deferred.
