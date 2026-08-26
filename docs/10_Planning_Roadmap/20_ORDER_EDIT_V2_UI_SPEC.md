# Order Create/Edit V2 UI Specification

**Status: OWNER UAT PASS / UAT_READY_FROZEN**
**Final gate: `ORDER_EDIT_V2 = UAT_READY_FROZEN` — Owner UAT PASS on 2026-08-26**
**Date: 2026-08-25**
**Applies to:** `/customers/orders/create`, `/kol/orders/create`, `/customers/orders/{id}`, `/kol/orders/{id}`
**Parent rules:** `18_BYL_ERP_FORM_DESIGN_STANDARD_V1.md`, `16_BYL_ERP_CANONICAL_ORDER_FINANCE_KOL_DATA_CONTRACT_V1.md`

## 1. Goal

Upgrade the existing shared Order Create and Order Detail/Correction surfaces into a compact ERP Create/Edit V2 while preserving the current canonical Order, Product, Shipment, Sync, Audit and authorization contracts.

This is a bounded UI/projection change. It does not authorize a second Order model, new workflow states, source rewriting, owner reassignment, deletion, new payment/refund logic, schema changes, remote deployment or production access.

## 2. Shared rules

- Centered task column, target max width about 1080px.
- Cool gray page background, white cards, subtle borders, 16px radius, calm BYL teal primary action.
- Business-grouped cards; desktop fields normally use two columns; mobile collapses to one column.
- One dominant primary action per page.
- Labels remain visible and field errors render next to the relevant control.
- Processing state disables duplicate save.
- Read-only identity must not look editable.
- Customer and KOL surfaces reuse the same Vue implementation and route-derived order kind.
## 3. Create V2

Page header:
- Customer route title: `新建客户订单`.
- KOL route title: `新建网红履约订单`.
- Concise helper copy and `返回列表` secondary action.

Business cards:
1. `订单信息` — order kind read-only, source read-only, generated business order number with explicit pre-save edit affordance, order date.
2. `收件与地址` — recipient, phone, country, province/state, city, postal code, address line 1, address line 2.
3. `订单商品` — dynamic item lines using Product Master `SkuCombobox`, product summary, quantity and unit price; add/remove controls remain secondary.
4. `内部备注` — full-width textarea.

Action bar:
- `取消` secondary.
- `创建订单` is the only primary action.
- No identity strip and no Danger Zone on Create.

Create must preserve route authority for `order_kind`, `source_type=manual`, creator owner and generated ERP technical number.
## 4. Edit V2

Existing Order identity strip:
- business order number;
- order kind;
- source;
- order status;
- owner.

`internal_order_number` remains hidden by default in a technical-details disclosure and is never presented as a normal editable field.

Business cards:
1. `履约状态` — shipment status, carrier, tracking number and manual-sync state; existing permitted reopen action remains separate from Save.
2. `订单信息` — order date editable; immutable business number/kind/source/owner remain read-only identity.
3. `收件与地址` — editable address snapshot fields.
4. `订单商品` — existing items become editable through Product Master relations; SKU/product identity remains canonical and quantity/unit price remain writable.
5. `内部备注` — editable order note.

Action bar:
- Detail opens in read-only View Mode by default.
- View Mode shows an `编辑订单` secondary action in the page header/identity area and does not present editable controls as active inputs.
- `编辑订单` switches the same canonical Order surface into Edit Mode; no separate Order copy/model is created.
- Edit Mode shows `取消编辑` secondary plus `保存订单` as the only primary action.
- Cancel restores the current persisted Order projection without saving local edits.
- No delete action is introduced because the current Order contract does not authorize Order deletion.

### Owner UAT remediation — 2026-08-26

- Order Create and Order Detail/Edit must use the same frozen ERP V2 form visual system as Product Create/Edit: `erp-form-page`, `erp-form-content`, `erp-form-card`, `erp-form-grid`, `erp-form-field`, `erp-form-label`, `erp-input`/`erp-readonly-input`, `erp-textarea`, `erp-btn` and the same focus/error/read-only treatment.
- The previous `mvp-input` usage is invalid on these V2 surfaces because the controls are not inside the legacy `.mvp-form` selector scope; visible field boxes must not depend on legacy selector inheritance.
- Order-specific layout classes may remain only where Product has no reusable primitive (for example item-row or fulfillment layout); they must not redefine a competing form visual language.
- Historical and existing Orders are correctable: default View Mode is read-only, and authorized users can explicitly enter Edit Mode to correct editable Order fields.

## 5. Data and interaction invariants

- `orders.id` remains canonical Order truth.
- `external_order_number` remains the primary visible business number and immutable after save.
- `internal_order_number` remains generated, immutable and hidden by default.
- `order_kind` and `source_type` stay independent and are not editable through this V2 form.
- Owner remains canonical; no reassignment UI is introduced.
- Product selection remains Product Master authoritative; arbitrary nonexistent SKU cannot become a canonical new-order item.
- Existing address snapshot, item snapshot, Audit, Shipment and Manual Sync behavior remains intact.
- Empty optional values remain empty; no literal dash is persisted.

## 6. Implementation boundary

Allowed implementation files are limited to the shared Order Create/Edit surface, Order-specific tests/styles, and the smallest backend projection needed to expose existing `product_id` for editable Order items.

Do not modify `resources/css/shell.css`. If additional styling is required, use an Order-specific stylesheet or scoped Order styles built on existing BYL tokens.

Do not change schema, routes, permissions, menu, source semantics, order status semantics, shipment behavior, Sync behavior or Finance behavior.

`tests/Architecture/ModuleArchitectureTest.php` may change only for legitimate frozen frontend hash/file evidence created by this Work Package. No assertion weakening or skip is permitted.
## 7. Verification and UAT acceptance

Focused frontend must verify:
- Create renders four business cards, no identity strip, correct customer/KOL titles, Product Master item relation, primary `创建订单`, processing state and field errors.
- Edit renders identity strip plus five business cards, immutable identity, editable date/address/items/note, separated fulfillment action and primary `保存订单`.
- Existing items keep their `product_id` so saving an unchanged item does not require re-selecting the SKU.

Focused backend/E2E must verify:
- customer and KOL route scoping remains correct;
- create and update preserve canonical Product selection and snapshots;
- external business number cannot be mutated after save;
- source/kind/owner are not client-editable through V2;
- existing Logistics/Shipment/Sync regression remains green.

After focused verification passes, this Work Package may be added to the same local Owner UAT session as Product Edit V2. It must remain unmerged and undeployed until Owner UAT explicitly passes.

## 8. Owner UAT closure — 2026-08-26

- Owner confirmed the remediated interaction is correct.
- Product-aligned ERP V2 VI on Order Create/Edit: PASS.
- Historical Order default View Mode: PASS.
- Explicit `编辑订单` → Edit Mode: PASS.
- `取消编辑` reset and `保存订单` flow: PASS.
- `ORDER_EDIT_V2 = UAT_READY_FROZEN`.
