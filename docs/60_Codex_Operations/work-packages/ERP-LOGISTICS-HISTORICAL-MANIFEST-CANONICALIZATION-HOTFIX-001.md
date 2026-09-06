---
type: work-package
id: ERP-LOGISTICS-HISTORICAL-MANIFEST-CANONICALIZATION-HOTFIX-001
status: READY_FOR_DELIVERY
execution_provider: GPT_WEB
baseline: main@fd19d566c3c82651298b845db8596859260a0848
parallel_safe: YES
---

# Historical manifest canonicalization hotfix

Production Projection Preview proved MySQL JSON object key order differs from PHP insertion order while all 765 business manifests remain field-identical.

Scope:
- compare frozen projection manifests after recursive associative-key sorting;
- preserve list ordering and strict scalar types;
- keep real field drift as a whole-batch Apply blocker;
- no schema/data migration and no Production Apply.

Verification:
- Production Preview: 765 Ready / 0 Review; 598 exact / 167 unresolved.
- Canonical actual/expected SHA256: `5f47f9035bdbcff6522dc88b613bb0fe3b6a13673c34601e8c95a5257f2fda9d`.
- Focused: 10/10 tests, 87 assertions.
- Logistics regression: 65/65 tests, 576 assertions.
- `git diff --check` and PHP lint PASS.
