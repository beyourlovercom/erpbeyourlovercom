# D6 frozen ERP2 domestic logistics candidate

This is a versioned source archive, not a Production deployment. Frontend base: ERP2 overlay draft PR #766, commit 4221361483220be28395e0ce063da5606e219bae; overlay these candidate files only after comparing the active Vben base. The exact tested 007windows build uses the archived files and locally installed pinned Vben dependencies. Do not assume a fresh checkout builds the full frontend without reconstructing that base. The 40 archived files have checksums in sha256.json; the transferred ZIP SHA256 was A857E78CCDBCF0283664840B2378384F2D4E0B1B87F0CF5D661A7875E9C39F43. No production database, session, private config, or compiled dist is included.

Backend test snapshot: 2caad5ae plus shared domestic review ACL and final two chained HTTP tests. Local evidence: 151 PHPUnit tests / 1890 assertions, 46 frontend tests, 9 browser mock checkpoints, 60 gateway mock checks, typecheck and build PASS. The new main-based PR still requires CI and exact-head revalidation. Actual employee browser/MySQL mutation UAT and controlled external Woo verification are not yet signed off. Overseas P0 remains open. No production merge or deploy.

CI patch: removed a single trailing space from domestic-allocation-panel.vue; its manifest hash/size updated. Original 40-file exact-byte archive preserved at commit 53f306ff. No functional code changed.
