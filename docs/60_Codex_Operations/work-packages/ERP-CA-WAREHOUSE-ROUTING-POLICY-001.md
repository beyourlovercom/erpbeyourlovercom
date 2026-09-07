# ERP-CA-WAREHOUSE-ROUTING-POLICY-001

Scope: Canada warehouse routing and carrier policy closure.

Owner-frozen business rules:
- CA02 is an alias of canonical warehouse CA / 加拿大仓库.
- Canada destination orders may only use warehouse CA.
- No US fallback and no split shipment.
- Canada carrier is Canada Post only.
- CA automatic allocation remains disabled until a later Owner UAT gate.

Inventory evidence:
- `加拿大 (2).xlsx` is the first confirmed CA Full Snapshot.
- MF414A 24; VX609A -> canonical 609A 106; TL278A -> canonical TL278A-Green 11.
- Total CA QOH after confirmation: 141.

Billing evidence supplied separately for 2026 Apr-Jul (CA02); do not materialize warehouse storage cost into order costs until a dedicated accounting evidence contract exists.
