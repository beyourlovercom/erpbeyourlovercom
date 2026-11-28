# ERP1 warehouse exact-reference and manual retry V1

Owner 2026-11-28: duplicate check current order identity only, no unrelated history enumeration. A second explicit operator submission after a proven pre-CREATE failure may reuse that attempt; no deletion of provider history. Automatic all-pending runs never opt into retry. A known or uncertain provider CREATE is NOT proof of absence and must be reconciled; no blind repeat CREATE. Existing review, stock, routing, archive, identity, unique attempt, actor and tracking gates remain. ERP1 only.

- Hannover and US01: signed production read-only positive/negative probes verified thirdOrderNoList, platformOrderNoList, referOrderNoList (1/0 for each); exact detail only, no pageList scan. Manual order references also prevent repeat creation. DE and NY selected preview and exact confirmation bind the prior eligible attempt; audit records actual actor, old/new fingerprint. Reopened review is visible before retry.
- MorePlus: signed orderNO query positive/negative 1/0 verified; existing signed exact preflight retained. Remove mandatory 14-day content history scan from active dispatch. Selected retry may requeue completed pre-CREATE skipped item without attempt/provider evidence; actual accepted/uncertain/rejected provider attempts remain reconciliation-only. Successful later OMS visibility supersedes historical pre-CREATE skip only.
- US-SM manual import unchanged. Canada PR #926 not in production/main; exact reference-only contract must be verified and implemented in that independent integration before live release.
- Five-minute tracking unchanged. No real OMS CREATE, no production order mutation. Separate from #932 release authorization. No DB migration.
- PHP 294/294, 3891 assertions, frontend prior 87/87, production build and all-staff 30-tab protection PASS. Final CI and isolated browser/Owner UAT gate before production.


## R2 automatic NY label workflow and resolve-exception workbench action

- Provider official CREATE schema confirms isSubmit=1 triggers automatic submission for product outbound (subOrderType=1). ERP already sends 1 and the US01 gateway enforces it. Official help: https://help.xlwms.com/d458/fcbb/7023/48cf and https://apidoc-oms.xlwms.com/reference/createoutboundorderusingpost. No separately documented public label-acquisition endpoint verified. Do not invent one or repeat CREATE. Need provider-side automatic execution confirmation on the next authorized real order; do not claim a readback proves no manual click.
- Real read-only check K26112812-007 / OBS0122611280RX: OMS status 2, tracking present in OMS and ERP, current queue issued, shipment pending. No production mutation or label purchase by this branch.
- NY confirmed CREATE + draft status 0: display awaiting automatic label/submission in issued (not physically shipped) during 30-minute grace; later unresolved draft becomes exception; OMS code 7 is label error. Existing 5-minute polling retained.
- All warehouse exception lists (overseas individual/aggregate and domestic native/new grid) use explicit authorized resolve action. Proven pre-CREATE failures retain original ledger and may be restored to correct queue; only explicit selected resubmission can recover. Manual exception clears only the hold, known OMS reconciles existing order, uncertain CREATE remains protected. Actual shipped evidence is required before shipment exception may be restored to shipped. No new DB migrations.
- PHP 304/304, 4538 assertions, typecheck and production build passed. Browser UAT and exact-head CI pending. ERP2 untouched.
