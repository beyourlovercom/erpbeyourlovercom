# BYL AI Worker A7.3 / A8.3–A8.5 checkpoint

## A7.3 real-source local benchmark
Source: previously supplied 222.xlsx, 1621 historical rows. Shipping Info N
nonempty 1467; 1453 multiline, 1108 labeled multiline. Independent recorded
shipping fields M, O–V. Eligible 1429; seeded random sample of 100 rows.
No raw source or individual benchmark record is committed or sent to Codex.
Masked copies and raw comparisons ran only in device-local process memory.
Staff-entered columns are comparison references, not independent blind labels;
differences can reflect corrected/added fields. Scope: shipping fields only.
Before: 35/100 complete, 596/799 original field matches.
After: 37/100 complete, 616/799 original; 36/100 masked complete,
611/799 masked field matches in measured run. Masking uses a fresh per-row
secret in process memory, so masked metrics can vary slightly between runs.
This is a diagnostic baseline, not a release PASS. No real-data cloud benchmark.
Reproduce with audit-real-shipping-source.py SOURCE.xlsx ABSOLUTE_PARSER_PATH
on the authorized device; print aggregates only.

## A8.3
Dev-only Laravel fixture route accepts approved fixture IDs, never pasted raw
text, guarded by authentication, permission, throttle and environment flag.
Signed Node client calls the existing synthetic-only Mac worker; output schema
and fallback checked. Laravel tests fake the subprocess; UI tests mock HTTP.
Prior cross-device live tests verified the independent Windows client.
The new ERP route has not yet been validated against the live worker in a browser.
No new service or worker configuration was deployed this turn.

## A8.4
Order-to-profile session draft, encrypted payload, actor and 30-minute expiry,
canonical identity/created-profile binding, return to order form after successful
existing provisioning service, no implicit shipment or order. Explicit address
confirmation in the browser, server acceptance for resumed and opted-in requests;
legacy unmarked order API preserved. One draft per session, no raw input stored.
Product candidates searched using existing SKU selector, human selection required.
Existing-unbound affiliate repair and all browser flows remain unverified.

## A8.5
23 targeted Laravel tests / 292 assertions PASS, including new KOL approval
and affiliate/coupon fake readback, draft redirect, address confirmation,
linked-order persistence and draft invalidation.
89 frontend tests PASS. Full typecheck and build including frozen workspace-tab
verification PASS. Browser full-chain UAT did not execute; no employee-ready
Dev deployment, no owner UAT PASS, no merge or Production release.
Next: validate privacy-preserving AI assistance on independently labeled held-out
real formats, actual live backend bridge, isolated browser UAT, ERP2 adaptation.
