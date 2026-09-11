# ERP-WOO-ORDER-IDENTITY-CANONICAL-HOTFIX-V2-001

## Owner-corrected canonical rule

Woo customer orders retain the customer type marker `c` and use the Woo business order number as the numeric identity component.

Canonical format:

`{prefix}-{MMDD}-c{Woo business order number}`

Examples:
- Woo `40414` on `2026-09-10` -> `007-0910-c40414`
- Woo `40415` on `2026-09-10` -> `007-0910-c40415`

The prior V1 hotfix correctly replaced the ERP customer sequence with the Woo order number, but incorrectly removed the `c` order-type marker.

Manual ERP customer orders keep the existing global customer-sequence contract. KOL identities keep the existing `k` contract.

Production data repair remains separately gated: code deploy -> read-only preview -> exact-count Owner apply authorization.
