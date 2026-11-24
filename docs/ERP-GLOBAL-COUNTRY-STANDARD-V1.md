# ERP Global Country Standard V1

## Frozen contract

Search English/Chinese country names or ISO 3166-1 alpha-2 code on the frontend. Select an exact country (unique exact match commits on Enter/blur). Persist uppercase two-letter ISO codes; exact aliases from external sources are normalized automatically. Ambiguous regions and unknown values are never guessed. Nationality and shipping country remain independent.

The shared country dictionary is resources/data/countries/iso3166-alpha2.json (249 entries). CountryStandard is the backend write contract; CountrySelect and countryStandard.ts are the frontend components. This is the reusable contract for ERP2, not a second dictionary.

## Historical migration

Run erp:country-standard for read-only plan. Only after approved production release, verified backup and fresh plan review, use erp:country-standard --apply --expected-hash=<fresh fingerprint> --production-approved. The command changes only identified country fields, appends country.standardized audit records and leaves unresolved/blank fields intact. No order status, order number, SKU, shipping tracking or pre-existing audit is changed.

Production read-only precheck on 2026-11-24: 61 confidently convertible fields (46 order addresses, 4 KOL nationality, 11 KOL shipping country); 1134 unresolved nonblank field values, principally EU/OTHER/ASIA. Counts are field occurrences, not unique people/orders. Recompute before apply. No production writes authorized in R0-R4.

Order 8198: original Norway, candidate payload NO, SKU VA617A, DHL PAKET International N. Country blocker removed in local-only preview; subsequent production read-only check showed warehouse review pending. No real OMS create or stock sync executed.

## Remaining

R5: isolated Dev browser UAT, regression checks, review country selection across pages, including ERP2 frontend adaptation. R6: approved merge/release and separately reviewed migration apply. Do not merge or deploy on R0-R4 alone.

## R5 evidence (2026-11-24)

Isolated Chrome UAT: order create country search/selection/invalid country,
order edit and persisted DE readback, KOL nationality/shipping independent
selection and customs-field visibility: PASS. Disposable database cleaned.
Follow-up order detail fix: selector only rendered in edit mode; existing
customs ID must not hide the country selector. Targeted Vue regression
122 passed. ERP2 Vben adapter copied to 007windows; matching dictionary
SHA256 14f86bf9f1b83ff2751daa711aa7ff28bce4df5e1cbfd81946a097801ddf3cdc,
resolver unit test and Vben typecheck PASS. ERP2 currently has no native
country editing forms; component is prepared but native form UAT is deferred
until those pages exist. No production deployment or real data writes.
