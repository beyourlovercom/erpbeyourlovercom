# ERP-US-WAREHOUSE-ZONE-ROUTING-001

## Goal
Freeze the Owner-defined US three-warehouse routing policy and canonical warehouse identities.

## Canonical warehouses
- US-NY = US-NJ = 美东纽约仓 = 美东仓.
- US-LA = MorePlus = 美西洛杉矶仓 = USA 美西仓.
- US-SJ = SMGJ = 美西加州3仓 = 世纪仓 = 世迈仓 = 世迈国际仓.

## Routing policy
1. Only warehouses that can fulfill the whole order are candidates; no split shipment.
2. If US-NY is eligible and its USPS East Zone is <= 4, prefer US-NY.
3. Otherwise choose first eligible: US-SJ -> US-LA -> US-NY.
4. If the quote sheet cannot uniquely resolve a ZIP Zone, use the same safe fallback rather than guessing East.
5. US automatic allocation remains disabled until a separate Owner Production gate.

## Carrier policy
All three US warehouses default to USPS. DHL is manual override only; no automatic DHL decision.

## Source
US-NY USPS Zone data is derived from the Owner-provided 2026-05-01 warehouse quote sheet, tab `美东仓USPS分区表`. Unparseable/ambiguous source ranges are preserved and fail safe.
