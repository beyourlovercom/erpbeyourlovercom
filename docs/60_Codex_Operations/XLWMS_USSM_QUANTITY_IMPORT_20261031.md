# US-SM WMS64 quantity import correction — 2026-10-31

## Real provider rejection
User supplied `Error_ParcelImportFirst.xlsx`, a provider-returned error report for ERP order `007-1027-c41845B` (independent export reference `C41845-007B-R66EBE3B247B3`; SKU `VX736A`, quantity 1). Error: `The outbound quantity must be in the range of 1~99999`.

The provider's **error-return workbook**, not the ERP source file, has an added `Error/错误原因` first column; in the error workbook `AA2` is stored as inline text `1.0`. The earlier original ERP XLSX was generated with a **numeric integer** `Z2=<v>1</v>`, confirmed by read-only production regeneration for order 7766. Do not attribute the `1.0` to the original ERP writer: the transformation occurred during or after provider import; exact internal cause is not proven.

## Compatibility candidate
For **US-SM only**, write quantities as canonical nondecimal *text digits* `"1"` (XLSX inlineStr) instead of numeric XLSX cells. Do not change other WMS64 warehouse profiles. Validate quantity is integer 1..99999 before export; reject invalid records with HTTP 422.

Scope: `Wms64OutboundExporter::row` shared by US-SM single, batch, and older backup Reship XLSX. No OMS create/import, no shipment/Tracking/Woo modifications. A corrected single-order 64-column XLSX was supplied in the conversation for human OMS retry. **Provider acceptance remains unverified** until actual manual reimport.

## Test repair
The four manual V1 PHP tests added in earlier PR #581 were accidentally appended inside `TrackingReturnFakeWooGateway` rather than the PHPUnit test class, and were therefore NOT previously executed despite all green CI statuses. This branch moves those tests into `OverseaWarehouseWorkbenchTest` and adds raw-XLSX cell-type and invalid-range assertions. Future pass counts must use the now-repaired full test suite.

## Acceptance gate
Manually confirm original same-order draft absent from current OMS 草稿-待提交. Upload corrected file, validate no quantity error, preserved SKU/VX736A, quantity 1, Yangfan, USPS/Upload_Shipping_Label and native status0. Do not submit warehouse or use a VOID placeholder as a real USPS label. Production release needs separate Owner pass.
