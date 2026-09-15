# ERP-HISTORICAL-FULFILLMENT-RECONCILIATION-HFR5-001

## Goal
Recover additional exact historical warehouse and carrier/service evidence for customer Orders that remained REVIEW after HFR-4.

## Scope
- Production remains read-only until a new exact Production Data Write Gate is approved.
- Reuse HFR-3 classifier/backfill write contract unchanged.
- Add row-level historical warehouse evidence from the BYL Tencent logistics database.
- Add row-level carrier/service evidence from US warehouse monthly invoice Shipping sheets.
- No fuzzy matching, no current-routing inference, no provider-wide warehouse/service assumptions.

## Exact warehouse evidence contract
A Tencent logistics row can enrich warehouse only when:
- customer Order identity is exact;
- source Order No. normalizes exactly to the same Woo customer order id;
- source Tracking No. exactly equals the HFR tracking;
- raw warehouse name has an already frozen canonical mapping.

Approved existing mappings only:
- `USA 美西仓` -> `US-LA`;
- `美东仓` -> `US-NY`;
- `Factory 国内仓` -> `FACTORY`;
- `Canada 加拿大仓` -> `CA`.

`Europe 欧洲仓`, external-procurement warehouses and unknown names remain unresolved.
## Exact carrier/service evidence contract
A US warehouse invoice row can enrich carrier/service only when:
- customer Order identity is exact;
- invoice tracking exactly equals the HFR tracking;
- invoice tracking resolves to one carrier/service pair across all scanned invoice copies;
- service is explicit, not an instruction/placeholder;
- existing carrier/service does not conflict.

Explicit services accepted from source rows include:
- `USPS Ground Advantage`;
- `USPS Priority Mail`;
- `USPS First Class Mail`;
- `FedEx Ground`;
- `FedEx Home Delivery`;
- `UPS Ground`;
- `GOFO Express`.

Explicitly rejected as non-service evidence:
- `Selected by warehouse`;
- `No Shipping Service Request`;
- `Upload Shipping Label`;
- tracking-like values accidentally occupying the service column.

Any conflict sets `source_evidence_conflict` and fails closed.
## Source fingerprints
- Tencent warehouse projection SHA256: `950cd92c806f860b1f704c9b8f43e328078c1b6e68589f2de5befd6bbd38a5d6`
- US warehouse invoice service projection SHA256: `6a699957926081f848b50365a9833ec371419ed252a86c3a28108174ba13de3e`
- Combined candidate source checksum: `39f092cd3796e8344d87fab3c29e702e7988e46ef0ec030a40514b1fd6738afd`
- Combined source scan SHA256: `d9f9c1f42a76ca25e3344293ec9d8e5537dd85b7f4917f8d88e2dfaa60287eda`
- Combined HFR-3 plan checksum: `b2a6058ca2e673799145c1edcff2f6fdd2c79cfc60e15702741cd8ddfadf13fe`
- READY manifest SHA256: `59a299fcfc9954507533f9508bc2d2bd3874e9d03e4819e2b0b5cd8ea0fa7d94`

## Current read-only result
- total candidates: 5,354
- READY: 197
- REVIEW: 4,157
- SKIP: 1,000
- READY unique Orders: 197
- READY unique tracking: 197
- READY warehouse: all `US-LA` id=1
- READY carrier: all `USPS`
- READY service: all `USPS Ground Advantage`

## Write boundary
No HFR-5 Production write is authorized by this WP itself.
Any apply must use a fresh post-merge Production preflight and an exact Owner Production Data Write Gate.
