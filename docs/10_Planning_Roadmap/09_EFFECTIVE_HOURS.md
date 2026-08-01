---
type: estimate
project: BYL ERP
version: 1.0
basis: effective-hours
---

# Effective Hours

## Definition

Includes:

- requirement/field confirmation;
- architecture;
- implementation;
- backend/frontend/E2E tests;
- typecheck/build;
- review remediation;
- documentation;
- Roadmap and Decision updates.

Excludes:

- waiting for Owner;
- external platform approval;
- account access delays;
- unpredictable outages;
- large manual data cleansing;
- idle UAT waiting.

## MVP1 estimate

Original manual fulfillment MVP:

```text
106–166h
```

Minimum order analysis addition:

```text
+16–24h
```

Current MVP1 estimate:

```text
122–190h
Classification: total estimate
```

Planning baseline:

```text
166h
Classification: total planning baseline
```

Maximum planning envelope:

```text
192h
Classification: maximum total planning envelope
```

One-month delivery requires approximately:

```text
40–48 effective hours per week
```

Scope reductions may be required if weekly effective capacity is lower.

## Incremental automation

WooCommerce inbound and single outbound beyond MVP1:

```text
approximately +68–120h
Classification: post-MVP incremental estimate
```

## Long-term ranges

These remain cumulative planning ranges, not additive components or commitments:

- architecture foundation: 235–402h from earlier full-path estimate;
- first highly automated operations MVP: 603–1,018h cumulative;
- Product/SEO growth layer: 1,059–1,802h cumulative;
- full core ERP: 1,595–2,714h cumulative;
- all expansion: 1,819–3,146h cumulative.

The one-month fast track intentionally narrows the initial architecture and feature implementation while preserving final-compatible models.

## Estimate Classification Rules

- a total estimate is not the same as a remaining estimate;
- cumulative ranges must never be added together;
- an incremental estimate must not be added to a cumulative estimate that already includes it;
- remaining effort requires accepted actual-hours evidence and a documented non-overlapping scope map;
- unknown remaining values use `UNKNOWN WITH EVIDENCE`, never zero or an inferred subtraction;
- completed actual hours derive only from accepted repository Work Logs;
- the authoritative progress ledger and classification snapshot is [[13_PROGRAM_ROADMAP_AND_HOURS]].
