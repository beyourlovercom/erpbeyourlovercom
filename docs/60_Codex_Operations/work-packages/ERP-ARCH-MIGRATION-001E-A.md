---
type: byl-erp-work-package
project: BYL ERP
work_package: ERP-ARCH-MIGRATION-001E-A
status: merged-pass
standard_id: LARGE-PROJECT-EXEC-001
standard_version: 1.1
---

# ERP-ARCH-MIGRATION-001E-A — Shared UI and Design Tokens

## Current Accepted Status

```text
STATUS: MERGED / PASS
PR: #17
ACCEPTED_HEAD: e46fb54a1b41a1efa620b4af3646e104ff7bcbbc
MAIN_SQUASH_COMMIT: 442a3a872628764fecb7209049d2daf752624e9a
MERGED_AT: 2026-08-03T04:16:11Z
DEPENDENCY_CHANGE: NO
MIGRATION: NO
UAT: NO
DEPLOYMENT: NO
NEXT_PACKAGE: ERP-ARCH-MIGRATION-001E-B PREFLIGHT
```

The authorization, implementation and remediation evidence below is retained unchanged as historical package evidence.

## Work Package

```text
ID: ERP-ARCH-MIGRATION-001E-A
TITLE: Shared UI and Design Tokens
STATUS: 800PX SHELL LAYOUT REMEDIATION COMPLETE / PENDING INDEPENDENT REVIEW
GOVERNANCE_BASELINE: eb6b4c425551bd60401fa20c85ee139f3d0e4785
APPLICATION_BASELINE: main@592b9732c09902cb2803f3b62f5686075619849d
PARENT_PACKAGE: ERP-ARCH-MIGRATION-001E
PREFLIGHT_AUTHORIZATION: YES / COMPLETE
IMPLEMENTATION_AUTHORIZATION: YES / COMPLETE
IMPLEMENTATION_BRANCH: feature/erp-arch-migration-001e-a-shared-ui-design-tokens
APPLICATION_CODE_CHANGE: YES / BOUNDED
CSS_CHANGE: YES / BOUNDED
DEPENDENCY_CHANGE: NO
LOCK_FILE_CHANGE: NO
PLAYWRIGHT: NO
WORKFLOW_CHANGE: NO
MIGRATION: NO
UAT: NO
DEPLOYMENT: NO
```

This document freezes the 001E-A contract and records its bounded implementation result. Independent review, the required Owner visual check and separate merge authorization remain mandatory.

## Implementation Result

```text
TOKENS_SOURCE: resources/css/tokens.css / UNIQUE
COMPONENTS_IMPLEMENTED: UiButton, FormField, TextInput, UiAlert, EmptyState
CONSUMERS_MIGRATED: Login, TopBar, Foundation, Placeholder, App Shell CSS
LOGIN_SEMANTIC_COLOR_SCAN: PASS / no slate, cyan or rose semantic utility remains
FOUNDATION_SEMANTIC_COLOR_SCAN: PASS / no slate, cyan or rose semantic utility remains
BREAKPOINT_VARIABLE_SCAN: PASS / --byl-breakpoint-shell-narrow absent
SHELL_NARROW_BREAKPOINT: @media (max-width: 800px) preserved
FRONTEND_TESTS: PASS / 10 files / 58 tests
TYPECHECK: PASS
BUILD: PASS
FULL_PHP_TEST_SUITE: PASS / 110 tests / 1006 assertions
CODEX_VISUAL_REMEDIATION_CHECK: PASS / 799px, 800px and 801px
OWNER_VISUAL_CHECK: NOT PERFORMED / REQUIRED BEFORE MERGE AUTHORIZATION
DEPENDENCY_CHANGE: NO
LOCK_FILE_CHANGE: NO
WORKFLOW_CHANGE: NO
PHP_CHANGE: NO
MIGRATION: NO
PR: #17 / OPEN / DRAFT
MERGE: NO
UAT: NO
DEPLOYMENT: NO
```

The Login retains POST `/login`, approved copy, password reveal, no registration and robots metadata. Its submit text is `登录系统`, changes to `验证中…` during processing, never renders the generic `处理中…` fallback, and remains disabled with `aria-busy`. TopBar retains POST logout, toggle events and `aria-expanded`. Existing hidden-navigation focus contracts remain passing.

## Component Contract Remediation Result

```text
AUTHORIZED_HEAD: 5c2902dedf1ab43702ec1c5ad104fd5a9bb71c36
ATTRIBUTE_ALLOWLISTS: IMPLEMENTED FOR ALL FIVE COMPONENTS
DIRECT_UNFILTERED_ATTR_BINDING: REMOVED
UI_BUTTON_REJECTED: formaction, formmethod, formenctype, formtarget and all unfrozen attributes
FORM_FIELD_REJECTED: id, role, tabindex, event listeners and all unfrozen attributes
TEXT_INPUT_REJECTED: form, formaction, tabindex, event listeners and all unfrozen attributes
EMPTY_STATE_REJECTED: role, tabindex, event listeners, unfrozen aria and all unfrozen attributes
UI_ALERT_ROLE_AND_LIVE: EXTRACTED FROM ORDINARY ATTRS / SAFE DEFAULTS AUTHORITATIVE
FORM_FIELD_SLOT_ONLY_DESCRIPTION: SUPPORTED
FORM_FIELD_SLOT_ONLY_ERROR: SUPPORTED / role=alert / invalid=true
FOCUSED_COMPONENT_TESTS: PASS / 5 files / 41 tests
FULL_FRONTEND_TESTS: PASS / 10 files / 57 tests
CSS_TOKEN_CHANGE: NO
CONSUMER_CHANGE: NO
DEPENDENCY_CHANGE: NO
LOCK_FILE_CHANGE: NO
WORKFLOW_CHANGE: NO
PHP_CHANGE: NO
MIGRATION: NO
PR: NO
MERGE: NO
OWNER_VISUAL_CHECK: NOT RUN
UAT: NO
DEPLOYMENT: NO
```

`UiAlert` preserves `role="region"` only for `live="off"`, supplies `status/polite` when polite has no role, supplies `alert/assertive` for default error behavior, and rejects a caller `alert` role that conflicts with polite behavior. Component-owned `type`, `disabled`, `aria-disabled`, `aria-busy`, `id`, `value`, `readonly` and `aria-invalid` remain authoritative.

## 800px Shell Layout Remediation Result

```text
AUTHORIZED_HEAD: 561caa0109d8388c02dd24602464f640b1442eb1
AUTHORIZED_BASE: main@592b9732c09902cb2803f3b62f5686075619849d
PR: #17 / OPEN / DRAFT
VISUAL_FINDING: max-width 800px placed .shell-workspace in a zero-width Grid column
ROOT_CAUSE: narrow Grid retained two zero-width columns after fixed Rail and hidden Sidebar left normal Grid flow
REMOVED_RULE: grid-template-columns: 0 0 minmax(0, 1fr)
NARROW_RULE: grid-template-columns: minmax(0, 1fr)
BREAKPOINT: @media (max-width: 800px) / UNCHANGED
REGRESSION_CONTRACT: AuthenticatedLayout.test.ts rejects the former two-zero-column rule and requires one workspace column
FOCUSED_LAYOUT_TESTS: PASS / 1 file / 7 tests
FULL_FRONTEND_TESTS: PASS / 10 files / 58 tests
FULL_PHP_TEST_SUITE: PASS / 110 tests / 1006 assertions
COMPOSER_VALIDATE: PASS
TYPECHECK: PASS
BUILD: PASS
800PX_WORKSPACE: PASS / 800px wide / horizontal overflow false
799PX_WORKSPACE: PASS / 799px wide / horizontal overflow false
801PX_DESKTOP: PASS / 92px Rail / 220px Sidebar / 489px Workspace
NAV_CLOSED: PASS / inert=true / aria-hidden=true / translated outside viewport
NAV_OPEN: PASS / 220px drawer / inert=false / aria-hidden=false / full-viewport Backdrop
BROWSER_CONSOLE: PASS / no warning or error
VISUAL_EVIDENCE: repository-external temporary screenshots only / not committed
OWNER_VISUAL_ACCEPTANCE: NOT PERFORMED
DEPENDENCY_CHANGE: NO
LOCK_FILE_CHANGE: NO
WORKFLOW_CHANGE: NO
PHP_CHANGE: NO
MIGRATION: NO
MERGE: NO
UAT: NO
DEPLOYMENT: NO
```

The remediation changes only narrow-screen Grid placement. It preserves the literal `800px` breakpoint, desktop columns, navigation drawer, Backdrop and hidden-navigation accessibility state. Owner visual acceptance remains a separate gate.

## Purpose and Architecture Position

Establish one semantic BYL UI token source and five consumer-proven Vue components for the active Login, Foundation and App Shell surfaces. Tailwind 4 remains the utility/compiler mechanism; legacy Blade prototype styles remain reference-only and are not design truth.

```text
UNIQUE_SEMANTIC_TOKEN_SOURCE: resources/css/tokens.css
SECOND_TOKEN_SOURCE: PROHIBITED
TAILWIND_ROLE: utility and build mechanism
LEGACY resources/css/app.css: EXCLUDED / PROTOTYPE ONLY
IMPLEMENTATION_APPROACH: bounded value-preserving migration plus evidenced accessibility corrections
ARCHITECTURE_CONFLICT: NONE WITH EVIDENCE
```

## Preflight Evidence Sources

```text
ACTIVE CSS:
- resources/css/foundation.css
- resources/css/shell.css
- scoped CSS in resources/js/Pages/Auth/Login.vue

ACTIVE VUE CONSUMERS:
- resources/js/Pages/Auth/Login.vue
- resources/js/Pages/Architecture/Foundation.vue
- resources/js/Pages/Dashboard/Index.vue
- resources/js/Pages/Shell/Placeholder.vue
- resources/js/Layouts/AuthenticatedLayout.vue
- resources/js/Components/AppShell/GlobalNavigation.vue
- resources/js/Components/AppShell/ModuleSidebar.vue
- resources/js/Components/AppShell/TopBar.vue

BUILD AND TEST CONTRACT:
- resources/views/app.blade.php
- vite.config.js
- vitest.config.ts
- package.json
- package-lock.json
- current resources/js/**/*.test.ts
```

`resources/views/app.blade.php` currently loads `foundation.css`, then `shell.css`, then `app.ts`. `foundation.css` currently imports Tailwind 4. The repository uses Vue 3.5, Vite 8, Tailwind 4 and Vitest 4 with jsdom. No dependency or configuration change is required for 001E-A.

## 1. Active Raw Visual Inventory

### App Shell

| Category | Current raw values | Active source / use |
|---|---|---|
| Canvas and text | `#eef2f4`, `#20313b`, `#fff` | shell canvas, primary text, surfaces |
| Primary navigation | `#0b202b`, `#d9e8ed`, `#9eb5bf`, `#143642`, `#174b50`, `#65d5c5` | nav surface, text, hover, active, marker |
| Secondary text | `#87959d`, `#71818a`, `#526670`, `#819099`, `#35515c`, `#7b8b94`, `#667881`, `#277d78` | headings, empty copy, links, breadcrumbs, controls, identity and eyebrow |
| Borders and focus | `#dce4e7`, `#cfdadd`, `#52b9ad` | shell borders, button borders, 3px focus outline |
| Backdrop and shadow | `#07151db3`, `0 4px 18px #20313b0a` | mobile backdrop, cards |
| Geometry | columns `92px / 220px`, top bar `76px`, content max `760px`, breakpoint `800px`, mobile nav `220px`, control min-height `36px` | shell layout |
| Type | `10px`, `12px`, `18px`, `20px`, `24px`; line-height `1.6 / 1.7`; tracking `.12em / .15em / .16em` | shell labels and copy |
| Spacing | `4, 6, 8, 9, 10, 12, 14, 16, 18, 20, 22, 24, 26, 30px` | gaps, padding and margin |
| Radius and motion | `4, 6, 7, 14px`; `180ms ease`; focus `3px / 2px offset` | nav, controls, cards and shell transitions |

### Authentication page

| Category | Current raw values | Active source / use |
|---|---|---|
| Canvas and decorations | `#07111f`, `#16344a33`, `#2a91a655`, `#8be9e4`, `#54d6d0`, `#5fd7cf88` | canvas, grid, orbit, nodes and line |
| Brand and status | `#9db6c6`, `#fff`, `#8da8b8`, `#65e2b2` | brand lockup and online state |
| Responsive panel | `#07111fcc`, `#1d3a4d` | panel below `1024px` |
| Tailwind colors | slate `100/300/400/500/700/800/950`; cyan `200/300`; rose `300` | text, fields, actions and errors |
| Type | Tailwind `xs/sm/4xl/5xl`; custom `10/13/20px`; line `28px`; tracking `.18/.24/.28em` | page hierarchy |
| Geometry | grid `64px`; node `5px`; panel padding `24px`, border `1px`, radius `16px`; input/button radius `8px` | active Login layout |
| Motion | Tailwind transition; orbit transform `rotate(-24deg)` removed for reduced motion | active motion |

### Foundation page

| Category | Current raw values | Active source / use |
|---|---|---|
| Surface and text | slate `950`, `900/80`, `800`, `300`; white | page body, card, border and copy |
| Accent | cyan `200/300`, cyan `400/50`, cyan `400/10`, cyan `950/20` | label, control, hover and tinted shadow |
| Geometry | max width `48rem`; radius `16px / 8px`; spacing `8–64px`; text `12/14/30px` | foundation validation page |
| Focus | cyan `300`, 2px ring and 2px offset over slate `900` | acknowledgement button |

Tailwind palette values are the installed Tailwind 4 values (OKLCH in `node_modules/tailwindcss/theme.css`); the sRGB fallbacks below are the frozen BYL token values used for deterministic contrast evidence and do not modify Tailwind's own palette.

## 2. Exact Token Contract

`resources/css/tokens.css` is the sole future semantic token fact source. Exact primitive names and values are frozen below.

### Typography, spacing, geometry and motion primitives

```css
:root {
  --byl-font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", "Noto Sans", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
  --byl-font-size-10: 0.625rem;
  --byl-font-size-12: 0.75rem;
  --byl-font-size-14: 0.875rem;
  --byl-font-size-18: 1.125rem;
  --byl-font-size-20: 1.25rem;
  --byl-font-size-24: 1.5rem;
  --byl-font-size-30: 1.875rem;
  --byl-font-size-36: 2.25rem;
  --byl-font-size-48: 3rem;
  --byl-font-weight-medium: 500;
  --byl-font-weight-semibold: 600;
  --byl-font-weight-bold: 700;
  --byl-font-weight-extrabold: 800;
  --byl-line-tight: 1.25;
  --byl-line-normal: 1.5;
  --byl-line-copy: 1.7;

  --byl-space-0: 0;
  --byl-space-4: 4px;
  --byl-space-6: 6px;
  --byl-space-8: 8px;
  --byl-space-9: 9px;
  --byl-space-10: 10px;
  --byl-space-12: 12px;
  --byl-space-14: 14px;
  --byl-space-16: 16px;
  --byl-space-18: 18px;
  --byl-space-20: 20px;
  --byl-space-22: 22px;
  --byl-space-24: 24px;
  --byl-space-26: 26px;
  --byl-space-28: 28px;
  --byl-space-30: 30px;
  --byl-space-48: 48px;
  --byl-space-56: 56px;
  --byl-space-64: 64px;

  --byl-border-width: 1px;
  --byl-radius-xs: 4px;
  --byl-radius-sm: 6px;
  --byl-radius-md: 7px;
  --byl-radius-lg: 8px;
  --byl-radius-xl: 14px;
  --byl-radius-2xl: 16px;
  --byl-radius-round: 9999px;
  --byl-focus-width: 3px;
  --byl-focus-offset: 2px;
  --byl-control-min-height: 36px;
  --byl-shell-rail-width: 92px;
  --byl-shell-sidebar-width: 220px;
  --byl-shell-topbar-height: 76px;
  --byl-content-reading-width: 760px;
  --byl-motion-fast: 150ms;
  --byl-motion-shell: 180ms;
  --byl-motion-ease: ease;
  --byl-shadow-card: 0 4px 18px rgb(32 49 59 / 0.04);
  --byl-shadow-elevated: 0 25px 50px -12px rgb(0 0 0 / 0.25);
  --byl-shadow-foundation: 0 25px 50px -12px rgb(8 51 68 / 0.20);
}
```

Only values used by the frozen active consumers are included. New scale values require consumer evidence and review. Under `@media (prefers-reduced-motion: reduce)`, nonessential component and shell transition duration resolves to `0ms`; no JavaScript mirror is permitted.

### Structural breakpoint contract

```text
SHELL_NARROW_BREAKPOINT: 800px
CLASSIFICATION: STRUCTURAL CSS CONTRACT
TOKEN_CLASSIFICATION: NOT A CSS CUSTOM-PROPERTY TOKEN
SOURCE_LOCATION: resources/css/shell.css
IMPLEMENTATION_FORM: @media (max-width: 800px)
```

`800px` remains a frozen layout contract, but it is not part of `tokens.css` and must never be written as `var(--byl-breakpoint-shell-narrow)`. Ordinary CSS custom properties cannot be used directly as media-query conditions, and this package authorizes no custom media, PostCSS plugin, Tailwind configuration, Vite configuration or dependency change. The literal structural breakpoint does not create a second semantic design-token source.

The Token inventory contains no other value intended for use as a media-query condition. Login's existing `@media (max-width: 1024px)` and `@media (prefers-reduced-motion: reduce)` remain local structural/environment contracts expressed directly in their media queries; neither becomes a CSS custom-property token.

### Color primitives

```css
:root {
  --byl-color-white: #ffffff;
  --byl-color-shell-canvas: #eef2f4;
  --byl-color-shell-ink: #20313b;
  --byl-color-shell-nav: #0b202b;
  --byl-color-shell-nav-text: #d9e8ed;
  --byl-color-shell-nav-muted: #9eb5bf;
  --byl-color-shell-nav-hover: #143642;
  --byl-color-shell-nav-active: #174b50;
  --byl-color-shell-nav-marker: #65d5c5;
  --byl-color-shell-text-secondary: #667881;
  --byl-color-shell-text-muted: #526670;
  --byl-color-shell-control: #35515c;
  --byl-color-shell-border-subtle: #dce4e7;
  --byl-color-shell-border-control: #71818a;
  --byl-color-shell-accent: #277d78;
  --byl-color-shell-accent-hover: #174b50;
  --byl-color-shell-focus: #277d78;
  --byl-color-shell-backdrop: rgb(7 21 29 / 0.70);

  --byl-color-auth-canvas: #07111f;
  --byl-color-auth-surface: #020617;
  --byl-color-auth-panel: rgb(7 17 31 / 0.80);
  --byl-color-auth-text: #f1f5f9;
  --byl-color-auth-text-secondary: #cbd5e1;
  --byl-color-auth-text-muted: #94a3b8;
  --byl-color-auth-border: #64748b;
  --byl-color-auth-accent: #67e8f9;
  --byl-color-auth-accent-hover: #a5f3fc;
  --byl-color-auth-accent-ink: #020617;
  --byl-color-auth-danger: #fda4af;
  --byl-color-auth-focus: #67e8f9;
  --byl-color-auth-grid: rgb(22 52 74 / 0.20);
  --byl-color-auth-orbit: rgb(42 145 166 / 0.33);
  --byl-color-auth-node: #8be9e4;
  --byl-color-auth-glow: #54d6d0;
  --byl-color-auth-line: rgb(95 215 207 / 0.53);
  --byl-color-auth-brand-muted: #9db6c6;
  --byl-color-auth-status-muted: #8da8b8;
  --byl-color-auth-online: #65e2b2;
  --byl-color-auth-panel-border: #1d3a4d;

  --byl-color-foundation-surface: rgb(15 23 42 / 0.80);
  --byl-color-foundation-border: #1e293b;
  --byl-color-foundation-action-border: rgb(34 211 238 / 0.50);
  --byl-color-foundation-action-hover: rgb(34 211 238 / 0.10);
  --byl-color-foundation-shadow: rgb(8 51 68 / 0.20);
}
```

The current low-contrast `#87959d`, `#71818a` as text, `#819099`, `#7b8b94`, slate-500 account note and `#52b9ad` light focus ring are inventory evidence, not approved semantic token values. They are replaced by the accessible aliases above during implementation; all other mappings preserve current appearance.

### Semantic aliases and fallback contract

```css
:root {
  --byl-canvas: var(--byl-color-shell-canvas, #eef2f4);
  --byl-surface: var(--byl-color-white, #ffffff);
  --byl-text: var(--byl-color-shell-ink, #20313b);
  --byl-text-secondary: var(--byl-color-shell-text-secondary, #667881);
  --byl-text-muted: var(--byl-color-shell-text-muted, #526670);
  --byl-border-subtle: var(--byl-color-shell-border-subtle, #dce4e7);
  --byl-border-control: var(--byl-color-shell-border-control, #71818a);
  --byl-accent: var(--byl-color-shell-accent, #277d78);
  --byl-accent-hover: var(--byl-color-shell-accent-hover, #174b50);
  --byl-accent-ink: var(--byl-color-white, #ffffff);
  --byl-focus-ring: var(--byl-color-shell-focus, #277d78);
  --byl-danger: #be123c;
}

[data-byl-theme="dark"] {
  --byl-canvas: var(--byl-color-auth-canvas, #07111f);
  --byl-surface: var(--byl-color-auth-surface, #020617);
  --byl-text: var(--byl-color-auth-text, #f1f5f9);
  --byl-text-secondary: var(--byl-color-auth-text-secondary, #cbd5e1);
  --byl-text-muted: var(--byl-color-auth-text-muted, #94a3b8);
  --byl-border-control: var(--byl-color-auth-border, #64748b);
  --byl-accent: var(--byl-color-auth-accent, #67e8f9);
  --byl-accent-hover: var(--byl-color-auth-accent-hover, #a5f3fc);
  --byl-accent-ink: var(--byl-color-auth-accent-ink, #020617);
  --byl-focus-ring: var(--byl-color-auth-focus, #67e8f9);
  --byl-danger: var(--byl-color-auth-danger, #fda4af);
}
```

Every semantic alias has a literal fallback. Components consume semantic aliases only. Pages may consume named decoration primitives only where there is no reusable semantic meaning. No equivalent TypeScript object, Tailwind config map or component-local palette may be introduced. Login and Foundation roots receive `data-byl-theme="dark"`; the authenticated shell uses root aliases.

## 3. Contrast Evidence

Contrast was calculated with WCAG relative luminance from the frozen sRGB values. Text requires 4.5:1 for normal text and 3:1 for large text; meaningful control boundaries and focus indicators require 3:1 against adjacent colors. Alpha colors were composited over the documented background before calculation.

### Current passing evidence

| Pair | Ratio | Result |
|---|---:|---|
| Shell text `#20313b` / canvas `#eef2f4` | 11.92:1 | PASS |
| Nav text `#d9e8ed` / nav `#0b202b` | 13.31:1 | PASS |
| Nav muted `#9eb5bf` / nav `#0b202b` | 7.82:1 | PASS |
| White / hover `#143642` | 12.84:1 | PASS |
| White / active `#174b50` | 9.73:1 | PASS |
| Shell secondary `#667881` / white | 4.60:1 | PASS |
| Shell accent `#277d78` / white | 4.89:1 | PASS |
| Auth slate-100 / `#07111f` | 17.28:1 | PASS |
| Auth slate-300 / `#07111f` | 12.75:1 | PASS |
| Auth slate-400 / `#07111f` | 7.39:1 | PASS |
| Auth cyan-300 / `#07111f` | 13.06:1 | PASS |
| Auth rose-300 / `#07111f` | 10.01:1 | PASS |
| Auth action ink `#020617` / cyan-300 | 13.92:1 | PASS |
| Foundation cyan-400/50 border / composited slate surface | 3.41:1 | PASS non-text |

### Current failures and frozen correction

| Current pair | Current | Frozen replacement | Replacement evidence |
|---|---:|---|---:|
| `#87959d` / white, 10px text | 3.08:1 FAIL | `--byl-text-muted: #526670` | 6.01:1 PASS |
| `#71818a` / white, 12px text | 4.03:1 FAIL | `--byl-text-muted: #526670` | 6.01:1 PASS |
| `#819099` / white, 10px text | 3.29:1 FAIL | `--byl-text-muted: #526670` | 6.01:1 PASS |
| `#7b8b94` / white, 10px text | 3.52:1 FAIL | `--byl-text-muted: #526670` | 6.01:1 PASS |
| slate-500 / `#07111f`, 12px text | 3.98:1 FAIL | auth muted `#94a3b8` | 7.39:1 PASS |
| `#52b9ad` focus / white | 2.36:1 FAIL | shell focus `#277d78` | 4.89:1 PASS |
| `#52b9ad` focus / `#eef2f4` | 2.09:1 FAIL | shell focus `#277d78` | 4.34:1 PASS |
| slate-700 input border / slate-950 | 1.95:1 FAIL | auth border `#64748b` | 4.24:1 PASS |
| cyan-300/30 ring / slate-950 | 2.11:1 FAIL | opaque auth focus `#67e8f9` | 13.92:1 PASS |

The foundation surface itself is only 1.10:1 from its page after alpha compositing; the existing cyan control border passes, while the card's slate border is decorative and cannot be used as the sole focus or control boundary. Browser rendering, anti-aliasing and OKLCH-to-device output still require the Owner visual check defined below.

## 4. Exact Component API Contracts

All components live under `resources/js/Components/UI/`, use `<script setup lang="ts">`, set `inheritAttrs: false` where required, and expose no global registration.

### UiButton

```ts
type UiButtonTone = 'primary' | 'secondary' | 'quiet'
type UiButtonSize = 'sm' | 'md'

interface UiButtonProps {
  type?: 'button' | 'submit' | 'reset' // default: 'button'
  tone?: UiButtonTone                  // default: 'secondary'
  size?: UiButtonSize                  // default: 'md'
  loading?: boolean                    // default: false
  disabled?: boolean                   // default: false
}
```

- slots: `default`; optional `loading` with default text `处理中…`;
- events: `click(event: MouseEvent)` only; no event emits when disabled or loading;
- native contract: renders exactly one native `<button>`; forwards `id`, `name`, `value`, `form`, `autofocus`, `aria-*`, `data-*`, `class` and `style` through `$attrs`; computed `disabled`, `aria-disabled` and `aria-busy` cannot be overridden while loading;
- exclusions: no anchor/router mode, no icon API and no speculative danger tone. Navigation remains a link, never a styled button.

### FormField

```ts
interface FormFieldProps {
  id: string
  label: string
  description?: string
  error?: string
  required?: boolean // default: false
}
```

- slots: `default({ controlId, describedBy, invalid })`; optional `label`, `description`, `error` overrides;
- events: none;
- generated IDs: `${id}-description` and `${id}-error`; `describedBy` contains only present IDs, description first and error second;
- native contract: `$attrs` (`class`, `style`, `data-*`, wrapper ARIA only) bind to the outer field container; input attributes must be supplied to the slotted control, never guessed or forwarded through the wrapper;
- label always uses `for=id`; error uses `role="alert"`; required marker is visible and has accessible text without relying on color.

### TextInput

```ts
type TextInputType = 'text' | 'email' | 'password'

interface TextInputProps {
  id: string
  modelValue: string
  type?: TextInputType       // default: 'text'
  invalid?: boolean          // default: false
  disabled?: boolean         // default: false
  readonly?: boolean         // default: false
}
```

- slot: optional `trailing` for a button such as password visibility; no prefix/content slot;
- events: `update:modelValue(value: string)`, `input(event: Event)`, `change(event: Event)`, `focus(event: FocusEvent)`, `blur(event: FocusEvent)`;
- native contract: renders one native `<input>` inside a visual wrapper; forwards `name`, `autocomplete`, `required`, `placeholder`, `minlength`, `maxlength`, `pattern`, `inputmode`, `autocapitalize`, `spellcheck`, `aria-*`, `data-*`, `class` and `style` to that input; component props win for `id`, `type`, `value`, `disabled`, `readonly` and `aria-invalid`;
- the trailing slot is outside the input but inside the focus-within wrapper; the consumer owns its button name and behavior.

### UiAlert

```ts
type UiAlertTone = 'info' | 'success' | 'warning' | 'error'
type UiAlertLive = 'off' | 'polite' | 'assertive'

interface UiAlertProps {
  tone?: UiAlertTone       // default: 'info'
  title?: string
  dismissible?: boolean   // default: false
  live?: UiAlertLive      // default: error => 'assertive'; others => 'polite'
}
```

- slots: `default`; optional `title` override;
- event: `dismiss()` only, emitted by a named native close button when `dismissible=true`;
- native contract: `$attrs` bind to the root; default role is `alert` for error and `status` otherwise; `live='off'` removes live-region behavior; an explicit caller `role` may only replace the default when it does not conflict with `live`;
- tone is never the sole status indicator; Login uses non-dismissible error behavior.

### EmptyState

```ts
interface EmptyStateProps {
  title: string
  description?: string
  headingLevel?: 2 | 3 // default: 2
  eyebrow?: string
}
```

- slots: optional `icon`; optional `default` description override; optional `actions`;
- events: none;
- native contract: `$attrs` bind to the root section; caller may provide `id`, `aria-labelledby`, `class`, `style` and `data-*`; component generates no business text and renders the requested native heading level;
- actions remain caller-owned links or buttons and are not synthesized.

## 5. Consumer Migration Map

| Consumer | Exact implementation migration | Preserved contract |
|---|---|---|
| `Login.vue` | add `data-byl-theme="dark"`; replace email/password labels with `FormField` and `TextInput`; use trailing `UiButton tone="quiet" size="sm"` for visibility; replace submit with `UiButton type="submit" tone="primary" :loading="form.processing"`; replace error paragraph with non-dismissible `UiAlert tone="error"` | exact approved copy, no registration, email/password names and autocomplete, `form.post('/login')`, processing, validation error, password reveal, robots meta |
| `TopBar.vue` | replace mobile toggle, sidebar toggle and logout buttons with `UiButton tone="secondary" size="sm"`; preserve existing shell hook classes | POST `/logout`, exact `aria-label`, `aria-expanded`, toggle emits, responsive visibility |
| `Foundation.vue` | add `data-byl-theme="dark"`; replace acknowledgement button with `UiButton tone="secondary"` | state toggle and exact visible text |
| `Placeholder.vue` | replace section body with `EmptyState`, passing eyebrow, title and description; preserve section class and `placeholder-title` association | authorized placeholder only; no business data or action |
| `shell.css` | replace semantic color, border, radius, shadow, focus, spacing and motion literals with frozen tokens; keep grid dimensions and responsive behavior identical | rail/sidebar/topbar dimensions, active route, hidden navigation focus contract, mobile backdrop |
| `foundation.css` | import `tokens.css` once after Tailwind | Tailwind utilities and build entry remain intact |

`Dashboard/Index.vue`, `AuthenticatedLayout.vue`, `GlobalNavigation.vue` and `ModuleSidebar.vue` require no implementation edit. Their tests remain regression gates. Scoped Login decoration geometry may remain component-local only when it references the frozen decoration primitives; it may not redeclare semantic colors.

### Login semantic Tailwind migration contract

| Current class/value | Frozen implementation mapping |
|---|---|
| root `bg-[#07111f]` | `bg-[var(--byl-canvas)]` |
| `text-slate-100` | `text-[var(--byl-text)]` |
| `text-slate-300` | `text-[var(--byl-text-secondary)]` |
| `text-slate-400` | `text-[var(--byl-text-muted)]` |
| `text-slate-500` | `text-[var(--byl-text-muted)]` |
| `text-cyan-300` | `text-[var(--byl-accent)]` |
| `text-cyan-200` | `text-[var(--byl-accent)]` |
| `text-rose-300` | `UiAlert tone="error"` resolving through `var(--byl-danger)` |
| `bg-slate-950` | `bg-[var(--byl-surface)]` |
| `border-slate-700` | `border-[var(--byl-border-control)]` |
| `border-slate-800` | `border-[var(--byl-border-subtle)]` |
| `focus:border-cyan-300` | `focus:border-[var(--byl-focus-ring)]` |
| `focus:ring-cyan-300/30` | opaque `focus:ring-[var(--byl-focus-ring)]` using the approved focus width/offset contract |
| `focus-within:border-cyan-300` | `focus-within:border-[var(--byl-focus-ring)]` |
| `focus-within:ring-cyan-300/30` | opaque `focus-within:ring-[var(--byl-focus-ring)]` using the approved focus width/offset contract |
| `hover:bg-cyan-200` | `hover:bg-[var(--byl-accent-hover)]` |
| primary-action `text-slate-950` | `text-[var(--byl-accent-ink)]` |

Login body text, labels, helper copy, errors, control surfaces, control boundaries, state colors and focus treatment must resolve through BYL Tokens. Tailwind remains permitted for structure, layout, width, spacing, typography scale and responsive display only. Grid, orbit, node, glow and line decoration may use the named auth decoration primitives. No page-local semantic color may remain or be added.

### Foundation semantic Tailwind migration contract

| Current class/value | Frozen implementation mapping |
|---|---|
| page/body `bg-slate-950` | root `data-byl-theme="dark"` plus `bg-[var(--byl-canvas)]` |
| `bg-slate-900/80` | `bg-[var(--byl-color-foundation-surface)]` |
| `border-slate-800` | `border-[var(--byl-color-foundation-border)]` |
| `text-white` | `text-[var(--byl-text)]` |
| `text-slate-300` | `text-[var(--byl-text-secondary)]` |
| `text-cyan-300` | `text-[var(--byl-accent)]` |
| `text-cyan-200` | `text-[var(--byl-accent)]` |
| `border-cyan-400/50` | `border-[var(--byl-color-foundation-action-border)]` |
| `hover:bg-cyan-400/10` | `hover:bg-[var(--byl-color-foundation-action-hover)]` |
| `shadow-cyan-950/20` | `box-shadow: var(--byl-shadow-foundation)` |
| cyan focus ring and offset | opaque `var(--byl-focus-ring)` with frozen focus width and offset |

Foundation keeps `data-byl-theme="dark"`. All semantic colors resolve through `tokens.css`; Tailwind retains only structural, layout and typography-scale utilities. No `@theme` mirror, TypeScript color object or component-local semantic palette is permitted.

### Login loading-copy contract

```vue
<UiButton
    type="submit"
    tone="primary"
    :loading="form.processing"
>
    登录系统

    <template #loading>
        验证中…
    </template>
</UiButton>
```

```text
NORMAL_TEXT: 登录系统
LOADING_TEXT: 验证中…
GENERIC_UI_BUTTON_LOADING_FALLBACK: 处理中…
LOGIN_OVERRIDE_REQUIRED: YES
POST_BEHAVIOR: form.post('/login') UNCHANGED
```

## 6. Final File Boundary

```text
NEW:
resources/css/tokens.css
resources/js/Components/UI/UiButton.vue
resources/js/Components/UI/UiButton.test.ts
resources/js/Components/UI/FormField.vue
resources/js/Components/UI/FormField.test.ts
resources/js/Components/UI/TextInput.vue
resources/js/Components/UI/TextInput.test.ts
resources/js/Components/UI/UiAlert.vue
resources/js/Components/UI/UiAlert.test.ts
resources/js/Components/UI/EmptyState.vue
resources/js/Components/UI/EmptyState.test.ts

MODIFIED:
resources/css/foundation.css
resources/css/shell.css
resources/js/Pages/Auth/Login.vue
resources/js/Pages/Auth/Login.test.ts
resources/js/Components/AppShell/TopBar.vue
resources/js/Layouts/AuthenticatedLayout.test.ts
resources/js/Pages/Architecture/Foundation.vue
resources/js/Pages/Architecture/Foundation.test.ts
resources/js/Pages/Shell/Placeholder.vue
resources/js/Pages/Shell/Placeholder.test.ts

PROHIBITED:
resources/css/app.css
resources/views/erp/**
resources/views/welcome.blade.php
package.json
package-lock.json
vite.config.js
vitest.config.ts
tsconfig.json
.github/**
PHP files
Migration files
business pages
```

Any path outside this list requires a new Owner scope decision. Governance records for the later implementation report are not implementation files and remain governed separately.

## 7. CSS Import Order and Tailwind 4 / Vite Contract

The only approved import change is:

```css
/* resources/css/foundation.css */
@import 'tailwindcss';
@import './tokens.css';
```

Frozen behavior:

1. all imports remain before declarations;
2. Tailwind loads first and the independent `--byl-*` semantic variables load second;
3. `tokens.css` is imported exactly once, through `foundation.css`;
4. `resources/views/app.blade.php` retains `foundation.css` before `shell.css` and `app.ts`;
5. `shell.css` remains a separate Vite input and consumes already-loaded variables;
6. no `@theme` mirror is created because it would become a second token map;
7. `vite.config.js`, Tailwind configuration, package files and lock file do not change;
8. `npm run build` must prove Tailwind 4/Vite compilation; generated assets are not committed.

## 8. Exact Test Contract

### New component Vitest files

```text
UiButton.test.ts
- renders a native button with default type and forwards id/name/form/aria/data/class
- applies every frozen tone and size
- emits one click when enabled
- disables and suppresses activation while disabled or loading
- exposes aria-busy and the loading slot/text while loading

FormField.test.ts
- connects label and stable control ID
- composes description/error IDs in order
- exposes invalid/describedBy slot props and an alert error
- renders required semantics and forwards wrapper attributes only

TextInput.test.ts
- implements modelValue/update:modelValue
- forwards the frozen native attribute allow-list
- supports only text/email/password types
- exposes the trailing slot without changing the input
- enforces disabled/readonly/invalid props
- emits input/change/focus/blur with native events

UiAlert.test.ts
- maps error to alert/assertive and other tones to status/polite
- renders all finite tones without color-only status
- renders default and optional title slots
- renders and emits dismiss only when dismissible

EmptyState.test.ts
- renders native h2/h3 according to headingLevel
- renders description or default slot without inventing copy
- renders optional icon/actions and forwards root attributes
```

### Modified consumer and regression tests

```text
Login.test.ts
- preserves email/password fields, approved account copy, no registration and robots meta
- proves FormField/TextInput associations and password visibility behavior
- proves the non-processing submit displays `登录系统`
- proves `form.processing=true` displays `验证中…` and does not display the generic `处理中…` fallback
- proves processing keeps the submit button disabled and `aria-busy`
- proves submit still calls form.post('/login')
- renders the exact generic server error through UiAlert without internal-state disclosure

AuthenticatedLayout.test.ts
- preserves the existing navigation/accessibility behaviors
- proves all TopBar shared buttons retain aria-expanded, emits and POST logout
- requires a single narrow-screen workspace Grid column and rejects the former two-zero-column rule

Foundation.test.ts
- preserves typed props and acknowledgement state through UiButton
- proves dark-theme token scope is present

Placeholder.test.ts
- preserves exact authorized placeholder copy and heading association through EmptyState
- proves no action or business data is invented

Dashboard/Index.test.ts
- unchanged baseline regression remains passing
```

Required implementation validation:

```text
npm run test
npm run typecheck
npm run build
git diff --check
git status --short
```

No PHP application test, Playwright suite or workflow edit belongs to 001E-A. Existing PHP tests may be run as non-mutating regression evidence but are not required by this bounded frontend package.

## 9. jsdom Limitations

Vitest/jsdom cannot prove:

- actual CSS cascade, computed colors, OKLCH conversion or contrast in a browser;
- pixel layout at `800px` and `1024px` breakpoints, overflow, clipping or text wrapping;
- real Tab order, browser focus-ring painting or focus visibility against composited surfaces;
- native form autofill, password-manager behavior or mobile virtual keyboards;
- screen-reader announcements, accessibility-tree ordering or live-region timing;
- reduced-motion media-query rendering;
- pointer/touch target size and visual disabled/loading feedback;
- screenshots or cross-browser rendering.

Tests may prove DOM contracts and state transitions only. These limitations remain `UNVERIFIED` until the Owner visual check and the separately authorized 001E-B/C browser work; they must not be reported as PASS from jsdom.

## 10. Owner Visual Review Requirement

```text
OWNER_VISUAL_CHECK: REQUIRED BEFORE 001E-A MERGE AUTHORIZATION
CLASSIFICATION: bounded visual acceptance, not UAT
```

The check must compare Login, Foundation, Dashboard and one Placeholder at 375x812, 800x900 and 1440x900; inspect default/hover/focus/disabled/loading/error states; keyboard through Login and App Shell controls; and verify that the contrast corrections do not damage BYL visual hierarchy. Authentication Trace/Video upload is not part of this check. Any intentional redesign beyond the frozen accessibility corrections requires a new Owner decision.

## 11. Confirmed Implementation Estimate

```text
TOKEN_SOURCE_AND_CSS_MIGRATION: 3–4h; planning value 3.5h
FIVE_COMPONENTS_AND_UNIT_TESTS: 5–8h; planning value 6.5h
CONSUMER_MIGRATION: 3–5h; planning value 4h
REGRESSION_AND_BUILD_VALIDATION: 3–4h; planning value 3.5h
REMEDIATION_BUFFER: 2–3h; planning value 2.5h
CONFIRMED_IMPLEMENTATION_ESTIMATE: 20h
CONFIRMED_IMPLEMENTATION_RANGE: 16–24 effective hours
OWNER_REVIEW: EXCLUDED
```

This confirms, rather than expands, the parent's 16–24h planning allocation. The estimate is not authorization.

The page-level Login and Foundation semantic Tailwind mappings frozen by remediation are already included in `TOKEN_SOURCE_AND_CSS_MIGRATION` and `CONSUMER_MIGRATION`. They add no file, component or estimate scope; the 16–24h range and 20h planning value remain unchanged.

## 12. Rollback Checkpoints

```text
R0: governance-only baseline 6c644136d54f6b9c343b5a377d8ec111b5f5100e
R1: tokens.css plus foundation import; no consumer migration
R2: five isolated components and their unit tests; no consumers
R3: Login migrated and authentication regressions passing
R4: TopBar migrated and navigation/logout regressions passing
R5: Foundation and Placeholder migrated
R6: shell.css semantic literals migrated; full validation passing
```

Each implementation checkpoint must be independently revertible. Revert consumers in reverse order, remove a component only after its final consumer is reverted, and remove the token import only after raw baseline values are restored. No data, Migration, dependency or legacy prototype rollback exists.

## 13. Architecture Conflict Conclusion

```text
ARCHITECTURE_CONFLICT: NONE WITH EVIDENCE
BREAKPOINT_TOKEN_CONFLICT: RESOLVED
BREAKPOINT_CLASSIFICATION: STRUCTURAL CSS CONTRACT
TOKEN_SOURCE_CONFLICT: NONE; one CSS semantic source only
CONSUMER_CONFLICT: NONE; all five components have active consumers
DEPENDENCY_CONFLICT: NONE; current Vue/Vite/Tailwind/Vitest stack is sufficient
LEGACY_CONFLICT: NONE; resources/css/app.css and Blade prototypes remain excluded
ACCESSIBILITY_FINDING: CURRENT LOW-CONTRAST VALUES REQUIRE THE FROZEN CORRECTIONS
SCOPE_EXPANSION: NO
```

The accessibility findings change specific semantic values but do not conflict with the architecture. They prohibit a purely mechanical preservation of failing colors.

## Owner Gates

```text
INDEPENDENT_IMPLEMENTATION_REVIEW: REQUIRED NEXT
OWNER_VISUAL_CHECK: REQUIRED BEFORE MERGE AUTHORIZATION / NOT RUN
001E_A_IMPLEMENTATION_AUTHORIZATION: YES / CONSUMED
APPLICATION_CODE_CHANGE_AUTHORIZATION: YES / BOUNDED / COMPLETE
CSS_CHANGE_AUTHORIZATION: YES / BOUNDED / COMPLETE
DEPENDENCY_CHANGE: NOT INCLUDED
PLAYWRIGHT: NOT INCLUDED
WORKFLOW_CHANGE: NOT INCLUDED
MIGRATION: NOT INCLUDED
UAT: NOT INCLUDED
DEPLOYMENT: NOT INCLUDED
001E_B: NOT INCLUDED
001F: NOT INCLUDED
```

## Preflight Verdict

```text
FINAL_IMPLEMENTATION_VERDICT: PR_17_800PX_SHELL_LAYOUT_REMEDIATION_COMPLETE_PENDING_INDEPENDENT_REVIEW
```

## PROJECT POSITION

```text
PROGRAM_ROADMAP: Architecture Migration / Global Architecture Gate → MVP1 Manual Fulfillment / Tencent Docs Replacement → MVP1.1 WooCommerce Inbound → MVP1.2 Single Tracking Outbound → MVP1.3 Sync Orchestration → Customer / Lead Pilot → Product / Media → WordPress Product and Image Sync → Content / SEO → Posts / Pages → GA4 / GSC / Cloudflare → Google Docs Publishing → Full Order Workspace → Logistics OS → KOL OS and Social Providers → Inventory and Procurement → Accounting / ROI Expansion → Shopify and Additional Integrations → Dev UAT and Production Hardening
CURRENT_TRACK_PHASE_MAP: 001A MERGED / PASS → 001B MERGED / PASS → 001C MERGED / PASS → 001D-A MERGED / PASS → 001D-B MERGED / PASS → 001D-C MERGED / PASS → 001D COMPLETE / PASS → 001E-A 800PX SHELL LAYOUT REMEDIATION COMPLETE / PENDING INDEPENDENT REVIEW → 001E-B WAITING → 001E-C WAITING → 001F WAITING
COMPLETED_PHASES: 001A, 001B, 001C, 001D-A, 001D-B, 001D-C, 001D
CURRENT_PHASE: 001E-A implementation review gate
CURRENT_WORK_PACKAGE: ERP-ARCH-MIGRATION-001E-A
PACKAGE_STATUS: 800PX SHELL LAYOUT REMEDIATION COMPLETE / PENDING INDEPENDENT REVIEW / MERGE AUTHORIZATION NO
WAITING_PHASES: 001E-A independent review and Owner visual check, 001E-B, 001E-C, 001F
BASELINE: remediation-authorized Head 561caa0109d8388c02dd24602464f640b1442eb1 based on main@592b9732c09902cb2803f3b62f5686075619849d
GITHUB_STATE: PR #17 open / Draft / remediation commit pushed / independent review pending / no merge
SOURCE_CONFLICTS: NONE WITH EVIDENCE; current accessibility failures are frozen implementation corrections, not competing architecture facts
GLOBAL_GATE: FAIL
PRODUCTION_DEPLOYMENT_STATUS: NO
NEXT_DEPENDENCY: Independent implementation review and required Owner visual check before separate merge authorization
EVIDENCE_AS_OF: 2026-08-02; bounded implementation diff, PHP/frontend tests, typecheck, production build and static scans
```

## ROADMAP HOURS

```text
CURRENT_PACKAGE_ESTIMATE: 20h planning value; confirmed 16–24h implementation range
KNOWN_CURRENT_TRACK_REMAINING: 40–62h parent 001E planning range; 001E-B/C estimates still require their own Preflights
ARCHITECTURE_GATE_REMAINING: UNKNOWN WITH EVIDENCE; 001E-B/C and 001F are incomplete
MVP1_TOTAL_ESTIMATE: 122–190h total estimate
MVP1_PLANNING_BASELINE: 166h total planning value
MVP1_MAXIMUM_ENVELOPE: 192h maximum total envelope
MVP1_REMAINING: UNKNOWN WITH EVIDENCE; accepted actual-hours ledger and overlap map are incomplete
POST_MVP_KNOWN_INCREMENT: approximately 68–120h incremental beyond MVP1
LONG_TERM_CUMULATIVE_RANGE: 235–402h through 1,819–3,146h; cumulative non-additive horizons
PROGRAM_REMAINING: UNKNOWN WITH EVIDENCE; Architecture Gate and project-wide actual ledger are incomplete
COMPLETED_ACTUAL_EFFECTIVE_HOURS: UNKNOWN WITH EVIDENCE; project-wide accepted actual-hours ledger remains incomplete
ESTIMATE_CONFIDENCE: HIGH for completed bounded 001E-A implementation; LOW for remaining Architecture Gate
ESTIMATE_AS_OF: 2026-08-02
ESTIMATE_METHOD: file-level inventory, five frozen component contracts, consumer migration map, test contract and evidenced remediation allowance
```

## ESTIMATED CODEX HOURS

```text
PREFLIGHT_AND_SCOPE: 3–4h; planning value 3.5h
CORE_EXECUTION: 8–13h; planning value 10.5h
TEST_AND_VALIDATION: 3–4h; planning value 3.5h
REMEDIATION_BUFFER: 2–3h; planning value 2.5h
ESTIMATED_EFFECTIVE_HOURS: 20h
ESTIMATE_RANGE: 16–24 effective hours
ESTIMATE_BASIS: confirmed bounded implementation estimate; planning values 3.5 + 10.5 + 3.5 + 2.5 = 20h
ACTUAL_EFFECTIVE_HOURS: 5.2h implementation including component remediation; 7.1h cumulative including 1.9h Preflight and governance remediation
ACTUAL_HOURS_BREAKDOWN: tokens and CSS 1h; five components 1.25h; consumer migration 0.75h; initial component and regression tests 0.75h; initial full validation and governance record 0.75h; attribute/role/slot remediation and validation 0.7h
VARIANCE: -14.8h / -74% against 20h implementation planning value
VARIANCE_REASON: frozen contracts, existing test infrastructure and tightly bounded consumers reduced discovery and integration effort; limited remediation stayed inside existing components; Owner visual review remains excluded and not performed
OWNER_REVIEW: EXCLUDED
MERGE: EXCLUDED
MIGRATION: EXCLUDED
MIGRATION_FILE_AUTHORING: EXCLUDED
MIGRATION_EXECUTION: EXCLUDED
UAT: EXCLUDED
DEPLOYMENT: EXCLUDED
```
