# MorePlus R13: official OMS statuses and content-only duplicate rule

Public primary sources:
- https://open.shipout.com/portal/zh/api/order.html
- Raw Swagger https://open.shipout.com/portal/JSON/order.json
- JSON definitions: OpenOrderListVO.status and OrderDetail.status; OpenShipmentVO.outboundStatus and OpenShipmentVO.labelStatus.

Official order status: 2 Awaiting Fulfillment; 3 Being Fulfilled; 4 Shipped; 6 Cancelled; 10 Submitting; 11 Aborted (v2). Other numeric codes are undocumented. Shipment outboundStatus=4 and labelStatus=4 describe different sub-objects, not order.status.

Official GET /open-api/oms/order/query returns orderShipments, outboundNO, outboundStatus, trackingNumber, shippedTime, packageList[].trackingNumber. Use GET-only for future physical shipment evidence; order status 4 must NOT cause automatic ERP shipped or Woo/AfterShip update.

R13 state-only correction:
- 2: warehouse_awaiting / Issued, warehouse awaits fulfillment
- 3: R12 two signed GETs / Issued after confirmed, processing and not shipped
- 4: provider_shipped / Issued, OMS shipped but ERP Tracking pending; NOT ERP shipped yet
- 10: pending / Submitting
- 6/11: cancelled / Exception, prohibit resend
- Others: unverified / human review

Production historical status4 rows formerly read as unverified require fresh signed GET to append corrected audit entries, not editing prior audit rows.

MorePlus operator-provided deduplication rule: Same shipment within 14 days requires the entire product SKU+quantity list and recipient information to be the same; orderNo alone does not cause duplicate. Genuine differences mean different shipments, never fabricate differences to evade safety rules.

R9 local contentRiskHistoryPage compares partial fields in the public queryListV2 returned OpenOrderListVO. Its current candidate matching (any SKU qty pair plus partial name/ZIP/street) is more conservative than the provider's complete match; do not present this R9 risk result as proof of actual duplication. Public queryListV2 does not provide full recipient data and cannot prove full exact-field equality alone. Future GET detail and exact-content comparison need a dedicated read-only implementation, without permitting repeated POST on an already submitted ERP order.

R14 live batching remains OFF pending new-order Canary evidence. This change does not alter submission ledger, shipped status, Tracking, website synchronization, or warehouse batch flags.
