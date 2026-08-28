# MVP1 Stabilization Defect Register

```text
REGISTER: MVP1-STABILIZATION-001
BASE: origin/main@50ecf4de1f270efdd95b44c5a301db5cb0a1f77b
SCOPE: MVP1.1–MVP1.3 stabilization plus Owner-reported cross-module regressions
STATUS: IN_REMEDIATION
RULE: repair and structural completion only; MVP2 is out of scope
```

| ID | Severity | Area | Finding | Status |
| --- | --- | --- | --- | --- |
| STAB-001 | HIGH | Global VI | Dark status/read-only surfaces use dark foreground and are unreadable | IN_REMEDIATION |
| STAB-002 | HIGH | Navigation | Customer Orders / KOL Orders were moved instead of duplicated as shortcuts | IN_REMEDIATION |
| STAB-003 | HIGH | Identity | Account menu lacks self profile and self password management | IN_REMEDIATION |
| STAB-004 | HIGH | Sync | `/sync` needs overview + Orders/Warehouses/KOL/Exports child workspaces and run history | IN_REMEDIATION |
| STAB-005 | HIGH | Logistics | Carrier entry disappeared; carrier shipment ledger/workspace is missing | IN_REMEDIATION |
| STAB-006 | HIGH | Orders | Orders workspace lacks default and user-defined Saved Views | IN_REMEDIATION |
| STAB-007 | HIGH | Guides | Guides is a placeholder; Markdown tutorial CMS and release changelog are missing | IN_REMEDIATION |

## Acceptance Baseline

All seven findings require focused automated coverage, architecture/navigation regression, typecheck/build, full PHP/Vitest gates, and Owner UAT before closure. Existing canonical Order, Shipment, Inventory, Sync Outbox and Sync Run truth must not be duplicated.