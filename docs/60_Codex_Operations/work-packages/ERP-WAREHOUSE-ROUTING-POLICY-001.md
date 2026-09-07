# ERP-WAREHOUSE-ROUTING-POLICY-001

## Goal
Create one canonical business-policy source for warehouse selection and routing.

## Single Source of Truth
- Business policy: `config/warehouse_routing.php`
- Runtime switches only: `config/inventory_auto_allocation.php`
- Real-time facts remain in DB: warehouse active state, Full Snapshot, QOH, reservations, fulfillment date, service regions.

## Frozen V1 Rules
- DE: automatic=true, strategy=fixed, warehouse=DE.
- US: automatic=false, strategy=discovery, warehouses=US-LA / US-NY / SMGJ.
- Split shipment=false.
- Cross-region fallback=false until explicitly defined.

## Runtime Integration
`WarehouseRoutingPolicy` is the only application reader for routing policy used by Automatic Allocation eligibility and scheduler candidate filtering.
