# ERP Logistics Overseas Provider Field Contract V1

Status: **OWNER REVIEW IN PROGRESS**

## Purpose

Every column in every provider upload/download XLSX must have an explicit field-level contract before Production use. A copied header row is not a completed integration.

Canonical machine-readable draft:
`resources/logistics/provider-contracts/provider-field-contracts.v1.json`

## Contract dimensions

Each provider column records:
- exact ordinal + provider header;
- direction: ERP → Provider (`OUT`) or Provider → ERP (`RETURN`);
- semantic role;
- ERP/Woo/provider source and fallback;
- required / optional / conditional / provider-generated disposition;
- transform / validation / provider rule;
- current implementation status;
- Owner status + Owner notes.

## Governance

1. `owner_review` is not a confirmed business rule.
2. There must be no silent blank column. Blank output must be explicitly confirmed as optional, N/A, conditional, or provider-generated.
3. Provider template changes require contract update and regression before Production.
4. Export/import UAT validates the complete contract, not spot checks.
5. US-SM return remains fail-closed until a real return template exists.
6. Canada return remains fail-closed because the supplied sample is incomplete for tracking.
7. GitHub is the canonical contract source; review spreadsheets are Owner-review surfaces only.

## Owner review order

1. MorePlus OUT — 23 fields
2. MorePlus RETURN — 49 fields
3. US-NY OUT — 64 fields
4. US-NY RETURN — 78 fields
5. US-SM OUT — 64 fields
6. US-SM RETURN — blocked pending real sample
7. DE OUT — 64 fields
8. DE RETURN — 59 fields
9. CA OUT — 69 fields
10. CA RETURN — blocked pending real sample

## Runtime changes

None in the initial registry commit. Exporter/reader behavior changes are applied only after the corresponding Owner field contract is confirmed and covered by regression tests.
