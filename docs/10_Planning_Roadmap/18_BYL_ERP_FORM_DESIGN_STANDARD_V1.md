# BYL ERP Form Design Standard V1

**Status: OWNER_APPROVED / HIGHEST UI-FORM RULE / FROZEN V1**
**Date: 2026-08-25**
**Authority: Owner + GPT Web**

> This standard is the highest UI/form rule for BYL ERP. It governs all create/edit/data-entry forms unless the Owner explicitly approves an exception. It does not override repository security, authorization, data-truth, or execution-governance rules.

## 1. Design Intent

BYL ERP forms must feel like a focused business task, not a developer/admin scaffold. The visual model is: clear title → grouped white cards → compact field rows → one dominant save action → secondary/destructive actions separated by hierarchy.

The reference interaction language is derived from the approved visual example: weak borders, strong whitespace, clear label/content hierarchy, lightweight helper controls, segmented choices, and a strong final save action.

## 2. Non-Negotiable Principles

1. One page, one primary task, one dominant primary action.
2. Group fields by business meaning, never by database column order.
3. Required information first; optional/advanced information later.
4. Labels must remain visible; placeholders never replace labels.
5. Dense information may use a 2-column desktop grid, but each field remains individually readable.
6. Avoid giant empty vertical gaps, isolated checkbox blocks, and full-width developer-style inputs.
7. Every writable field must have validation, error placement, loading state, success feedback, and keyboard focus state.
8. Read-only source identity is visually distinct from editable business data.
9. Destructive actions are separated from Save and always require confirmation.
10. Mobile/narrow layouts collapse to one column without changing field order or meaning.
## 3. Page Anatomy

Every formal create/edit page follows this order:

1. Breadcrumbs.
2. Page header: title, concise purpose, optional Back/List action.
3. Optional identity/status strip for existing records.
4. Business-section cards.
5. Upper-right Page Header Action Zone is the canonical location for page-level actions; no duplicate bottom action bar.
6. Optional destructive zone below or visually separated from the primary action.

Desktop content width target: 960–1120px. The form must not stretch edge-to-edge on large screens. The header and action bar align to the same content column.

## 4. Card and Section Rules

- Card background: white.
- Page background: cool light gray.
- Card radius: 16px.
- Card border: 1px subtle neutral; shadow minimal or none.
- Card padding: 24px desktop, 16px narrow.
- Section gap: 24px.
- Section title: 18–20px, semibold, dark text.
- Optional section helper copy: 13–14px, muted, maximum 1–2 lines.
- Long free-text fields occupy full row; short identifiers, amounts and selectors usually pair in 2 columns.
- Do not place unrelated fields into the same card merely to save space.
## 5. Field Composition

Each field follows a consistent stack:

- Label.
- Control: input/select/combobox/textarea/toggle/chips.
- Optional helper text.
- Validation error directly below the control.

Rules:
- Label: 13–14px, medium weight, dark neutral.
- Required marker: restrained `*`, not red-heavy decoration.
- Control height: 40–44px for ordinary inputs.
- Input radius: 10–12px.
- Textarea minimum height: 88–104px.
- Horizontal field gap: 16px; vertical row gap: 16–20px.
- Placeholder color is muted and never used as stored/default data.
- Units belong in suffix/prefix presentation where practical (`kg`, `cm`, currency), not repeated in labels alone.
- Numeric fields use numeric input semantics and right/decimal-friendly alignment when useful.
- Searchable entity relations use combobox/typeahead rather than free text.

## 6. Control Selection Rules

- Text input: identifiers, names, codes.
- Searchable select/combobox: Product, Supplier, Country, Warehouse, User, other master-data relations.
- Segmented chips: small closed sets such as Active/Inactive or simple mode choices.
- Toggle: binary preference only when immediate state is obvious; never use a giant isolated native checkbox.
- Currency input: amount + clearly associated currency.
- Textarea: notes, explanations, cost notes.
- Read-only identity: compact key/value row or muted read-only field, never an editable-looking input.
## 7. BYL ERP VI Tokens

The reference image's red/orange accent is not copied. BYL ERP keeps its own teal/green business identity.

Suggested semantic tokens:
- Page background: `#F4F7F8`.
- Surface/card: `#FFFFFF`.
- Border/divider: `#E4EAED` to `#E8EEF0`.
- Primary text: `#142126`.
- Secondary text: `#344B53`.
- Muted text: `#7A8C94`.
- Primary action: current BYL teal; fallback reference `#2D8C84`.
- Primary hover: approximately 8–12% darker than primary.
- Success: semantic green.
- Warning: semantic amber.
- Danger: `#D9534F` family.
- Focus ring: primary teal at low-opacity outer ring plus solid border.

Typography:
- Use the repository's existing product font stack; do not introduce a new web-font dependency merely for forms.
- Page title: 28–32px / semibold.
- Card title: 18–20px / semibold.
- Body/control text: 14–16px.
- Helper/error text: 12–13px.

The UI must look calm, compact and professional; no oversized headings, strong gradients, decorative shadows, novelty icons, or consumer-app decoration that harms ERP density.
## 8. Interaction and State Rules

Save:
- Primary button label uses the business action, e.g. `保存产品`.
- On submit: disable duplicate submission and show visible loading/progress state.
- Success: show a concise success toast/banner and remain on the intended record or redirect deterministically.
- Failure: preserve entered values, scroll/focus to first invalid field when appropriate, and show field-level messages.

Cancel/back:
- Secondary visual weight.
- If dirty-state protection exists, warn before abandoning unsaved edits.

Delete:
- Danger styling only.
- Never placed as an equal-weight neighbor to Save.
- Confirmation must state the record being deleted and any important persistence behavior.

Read-only/disabled:
- Capability remains understandable. If a user lacks permission, write controls should be absent or visibly disabled according to the page contract; server-side authorization remains authoritative.

## 9. Responsive Rules

- ≥ 1024px: normally 2-column field grid inside centered content.
- 768–1023px: 1–2 columns based on field complexity.
- < 768px: single column, full-width controls, sticky/fixed-safe action area when practical.
- Field order is invariant across breakpoints.
- No horizontal scrolling for ordinary forms.
- Touch targets must remain at least ~40px high.
## 10. Shared Form Component Direction

Future form work should reuse shared primitives rather than duplicate page-local styling. Preferred primitives:

- `FormPageShell`
- `FormSectionCard`
- `FormField`
- `FormTextInput`
- `FormSelect` / `FormCombobox`
- `FormCurrencyInput`
- `FormToggle` / segmented choice
- `FormTextarea`
- `FormIdentityStrip`
- `FormActionBar`
- `FormDangerZone`

Names are implementation guidance, not a requirement to create all components immediately. The architectural requirement is consistent reusable behavior and tokens.

## 11. Accessibility and Quality Gate

A form is not complete unless:
- Labels are programmatically associated with controls.
- Keyboard navigation and visible focus work.
- Error messages are understandable and connected to the relevant control.
- Contrast is adequate for normal text and states.
- Loading does not permit duplicate writes.
- Server-side validation and authorization remain authoritative.
- Empty values render as empty/placeholder states, never fabricated business data.
- Create/edit pages pass focused frontend tests plus relevant server-side feature tests.

## 12. Highest-Rule Enforcement

Any new or materially redesigned BYL ERP create/edit/data-entry page MUST read this document before implementation. Existing forms should migrate toward it when touched. A conflicting page-local style must yield to this standard unless the Owner explicitly approves an exception.

Changes to this V1 require explicit Owner approval and a new version or clearly recorded revision.
## 13. Global Page-Level Action Placement — Owner Approved Highest Rule

**Implementation Status: OWNER_UAT PASS / COMPLETE / FROZEN / CLOSED (Batch 1 + Batch 2, 2026-08-28). This section remains the mandatory global rule for future pages and future material changes.**

All page-level actions on Create, Edit, Show/Detail, Index/List, dashboard and operational workflow pages are consolidated in one upper-right Page Header Action Zone. Save/Create/Update, Cancel, Back/Back to List, Edit, New, Import, Export, Archive/Restore, Delete and similar actions must not be scattered across the page or duplicated at the bottom.

Order inside the cluster is stable: navigation/cancel first, secondary business actions next, destructive actions visually separated and confirmation-protected, and the single dominant primary action at the far right. On narrow screens the same top action zone may wrap without moving to a bottom action bar.

This rule governs page-level actions only. Table-row actions stay with the row; field-local actions stay with the field; filters/search controls stay in their toolbar; pagination stays with the list; modal-local actions stay within the modal. Existing pages migrate when materially touched.

This global rule supersedes the earlier Create/Edit bottom-action-bar wording in this document.