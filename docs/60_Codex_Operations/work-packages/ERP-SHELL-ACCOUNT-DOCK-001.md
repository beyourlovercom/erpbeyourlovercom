---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-SHELL-ACCOUNT-DOCK-001
status: merged-pass
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-SHELL-ACCOUNT-DOCK-001 — Global Account Dock and Menu Emoji

## Current Accepted Status

```text
STATUS: MERGED / PASS
PR: #15
ACCEPTED_HEAD: 0cac340443d47591b7e242ebe051fb4407d0a986
MAIN_SQUASH_COMMIT: 32d7244b9b1c6d1fb0cf8863cb9af694e7acc10f
MERGED_AT: 2026-08-03T05:10:56Z
OWNER_VISUAL_ACCEPTANCE: PASS
MIGRATION: NO
DATABASE_MUTATION: NO
UAT: NO
DEPLOYMENT: NO
```

The original implementation and validation record below is retained unchanged as historical evidence.

## Work Package

```text
ID: ERP-SHELL-ACCOUNT-DOCK-001
TITLE: Global Account Dock and Menu Emoji
STATUS: IMPLEMENTATION COMPLETE / PENDING INDEPENDENT REVIEW
AUTHORIZED_BASE: e37c724f89dcaad73d1d7bb8a0221005abe6bee2
SOURCE_BRANCH: feature/erp-local-admin-bypass-001
IMPLEMENTATION_BRANCH: feature/erp-shell-account-dock-001
AUTHORIZATION_TYPE: BOUNDED FRONTEND IMPLEMENTATION ONLY
PR: NO
MERGE: NO
DEPLOYMENT: NO
MIGRATION: NO
DATABASE_MUTATION: NO
BACKEND_ROUTE_CHANGE: NO
AUTH_POLICY_CHANGE: NO
DEPENDENCY_CHANGE: NO
LOCK_FILE_CHANGE: NO
WORKFLOW_CHANGE: NO
REAL_ENV_CHANGE: NO
```

## Implemented Scope

### Top Bar

- `TopBar.vue` has no User prop, router import, logout function, identity DOM, `.shell-user`, `.shell-logout` or right-side container.
- It renders only the mobile-navigation button, module-sidebar button, breadcrumbs and title.
- `.shell-topbar` uses `justify-content: flex-start`; no hidden or fixed-width replacement was added.

### Account Dock

- `GlobalNavigation.vue` owns the safe User prop and renders `UserAccountMenu` after the independently scrollable primary-link region.
- The 92px desktop Rail remains unchanged.
- The visual dock trigger is a circular initials avatar. A screen-reader-only account name preserves existing page identity accessibility without occupying Rail width.
- The menu opens to the right and upward from the desktop Rail and remains above the viewport bottom.
- On narrow screens it remains inside the drawer; changing the full navigation to hidden closes the menu before the transformed drawer can expose it.
- No profile, preference or account-security link, page or route was fabricated.

Initials contract:

```text
Test User → TU
张三 → 张
empty name → ?
```

Interaction contract:

```text
TRIGGER: native button / mouse click / native Enter and Space activation
TOGGLE: repeated click closes
OUTSIDE: document pointerdown closes
ESCAPE: closes and restores trigger focus
ARIA: aria-expanded + aria-controls + aria-haspopup=menu
OPEN_FOCUS: moves to logout menu item
LOGOUT: router.post('/logout')
MOBILE_HIDDEN: parent nav inert + aria-hidden; open account menu is closed
```

### Navigation Emoji

`ShellNavigationItem` now has recursive `emoji?: string | null`. The PHP serializer adds `emoji` at every level and recursively serializes children while retaining permission filtering for any child that declares a permission.

Default primary navigation:

```text
📊 Dashboard
🚚 物流
🔄 同步
👥 客户
⭐ 网红
📦 库存
🛒 外采
🏷️ 产品
📖 指南
🧾 会计
⚙️ 设置
```

Every primary link renders a fixed-width `.shell-primary-emoji` span. Empty Emoji values retain the slot and label alignment. Emoji spans use `aria-hidden="true"`; labels remain visible and remain the accessible navigation names. Active-route behavior continues to use the path and is independent of Emoji.

The current formal configuration has no child entries. The recursive PHP and TypeScript contracts preserve optional Emoji on future second- and deeper-level entries without changing the unauthorized `ModuleSidebar.vue` path in this package.

## Exact File Boundary

```text
NEW:
resources/js/Components/AppShell/UserAccountMenu.vue
resources/js/Components/AppShell/UserAccountMenu.test.ts
resources/js/Components/AppShell/GlobalNavigation.test.ts
docs/60_Codex_Operations/work-packages/ERP-SHELL-ACCOUNT-DOCK-001.md
docs/60_Codex_Operations/logs/2026-08-03_ERP-SHELL-ACCOUNT-DOCK-001.md

MODIFIED:
resources/js/Components/AppShell/GlobalNavigation.vue
resources/js/Components/AppShell/TopBar.vue
resources/js/Layouts/AuthenticatedLayout.vue
resources/js/Layouts/AuthenticatedLayout.test.ts
resources/js/types/navigation.ts
resources/css/shell.css
config/erp_shell_navigation.php
app/Support/ErpShellNavigation.php
```

All other paths remain unchanged.

## Validation Evidence

```text
FRONTEND: 12 files / 70 tests / PASS
PHP: 130 tests / 1109 assertions / PASS
TYPECHECK: PASS
BUILD: PASS
AUTHORIZED_PINT: PASS
PHP_TEST_ENVIRONMENT: one-process ERP_LOCAL_ADMIN_BYPASS=false override because the existing local .env enables the fail-closed local bypass
REAL_ENV_CHANGE: NO
DATABASE_MUTATION: NO
MIGRATION: NO
DEPENDENCY_CHANGE: NO
```

## Rollback

1. Revert the single reviewed implementation commit.
2. Confirm TopBar receives the former User prop only if the entire package is intentionally reverted.
3. Confirm `erp_shell_navigation` and serialized payload return to their pre-Emoji shape.
4. Run frontend, PHP, typecheck and build regressions.

No database, Migration, dependency, route, authentication policy or environment rollback exists.

## Risks

- The account menu is intentionally minimal; future account features need separate routes and authorization.
- Native button Enter/Space behavior is browser-owned; jsdom proves the native button contract but is not browser E2E evidence.
- Formal child navigation is currently empty, so recursive child Emoji preservation is contract-tested with typed fixtures and code inspection rather than a live child configuration.
- Very long names and emails are truncated visually in the menu but remain present in DOM text.
- Local Bypass logout behavior remains owned by the existing authentication package and is not changed here.

## Hours

```text
ESTIMATED_EFFECTIVE_HOURS: 5–8h
PLANNING_VALUE: 6.5h
ACTUAL_EFFECTIVE_HOURS: 4.8h
ACTUAL_BREAKDOWN: shell/component implementation 1.4h; Emoji data and recursive serializer 0.6h; CSS and responsive behavior 0.8h; tests/remediation 1.3h; full validation/governance 0.7h
VARIANCE: -1.7h / -26% against planning value
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```

## Verdict

```text
FINAL_STATUS: ERP_SHELL_ACCOUNT_DOCK_001_IMPLEMENTATION_COMPLETE_PENDING_INDEPENDENT_REVIEW
NEXT_GATE: independent implementation review; PR remains unauthorized
```

## PROJECT POSITION

```text
CURRENT_PHASE: bounded App Shell usability enhancement alongside 001E-A review
CURRENT_WORK_PACKAGE: ERP-SHELL-ACCOUNT-DOCK-001
PACKAGE_STATUS: IMPLEMENTATION COMPLETE / PENDING INDEPENDENT REVIEW
BASELINE: e37c724f89dcaad73d1d7bb8a0221005abe6bee2
GITHUB_STATE: implementation branch only; no PR
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: independent review and separate Owner PR authorization
EVIDENCE_AS_OF: 2026-08-03
```
