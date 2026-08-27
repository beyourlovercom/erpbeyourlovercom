# ERP-GLOBAL-FEEDBACK-ISLAND-V1-001

## Purpose

Port the frozen AICC Global Feedback Island interaction contract into BYL ERP as the single global surface for short, transient, human-readable interaction feedback and current operational status.

## Source authority

The VI and behavior are transcribed from the verified AICC implementation:
- `AIcontrolcenter/src/components/GlobalFeedbackIsland.svelte`
- `AIcontrolcenter/src/lib/globalFeedbackInteraction.ts`
- `AIcontrolcenter/src/lib/globalFeedbackIsland.test.ts`
- `AIcontrolcenter/src/lib/globalFeedbackIslandClickCopy.test.ts`
- `AIcontrolcenter/AGENTS.md::AICC_GLOBAL_SHORT_FEEDBACK_RULE_V1`

## Frozen presentation contract

- Exactly three presentation states: `IDLE | COMPACT | EXPANDED`.
- Fixed viewport top-center: `top: 8px; left: 50%; transform: translateX(-50%); z-index: 60`.
- New non-idle feedback opens expanded and retracts to compact after `3000ms`.
- Compact click expands. Expanded click copies the canonical full feedback text when copyable.
- Copy-success overlay lasts `1500ms`, is not copyable, then restores the prior active feedback when applicable.

## Frozen VI

- Shell background: `rgba(248, 249, 251, 0.30)`.
- Backdrop: `blur(5px) saturate(115%)` including WebKit form.
- Border: `1px solid rgba(255, 255, 255, 0.45)`.
- Shadow: `0 4px 18px rgba(15, 23, 42, 0.07), inset 0 1px 0 rgba(255, 255, 255, 0.28)`.
- Text and icons remain fully opaque; no element-level alpha.
- Compact: min width `92px`, max width `min(300px, 100vw - 24px)`, height `24px`, pill radius.
- Expanded: width `min(440px, 100vw - 24px)`, `11px 14px` padding, restrained rounded rectangle.
- State icon colors: neutral `#73808b`, running `#3976b9`, success `#3e9b6b`, error `#c45d42`, blocked `#b78a31`.
- Visible content is bounded to at most two human-readable lines; internal codes/debug tokens are never rendered.

## Global governance

- One global renderer only, mounted by the authenticated App Shell.
- Module-local toast, snackbar, and short-notice surfaces are forbidden.
- Explicit button actions, side effects, async actions, fail-able actions, and collapse/expand controls publish short feedback globally.
- Ordinary row selection and tab switching do not require feedback.
- Passive hover never publishes feedback.
- Short/transient/current operational feedback belongs in the island; detailed/persistent/evidence-bearing content remains in its workspace.

## ERP integration contract

- Laravel session flash keys `success`, `error`, `warning`, and `info` are shared through Inertia as canonical global feedback input.
- Client-only ERP interactions may publish through the same singleton feedback authority; they must not create another renderer.
- Canonical copy text comes from feedback state, never from rendered/truncated DOM text.
- Flash messages survive redirect navigation and appear in the island at the destination page.
- Accessibility: global shell uses `role=status`; error/blocked feedback uses assertive live mode, other states polite.

## Acceptance gates

1. Existing `with('success', ...)` actions surface automatically without controller rewrites.
2. Error/warning/info flash variants render through the same island.
3. New feedback expands, retracts after 3 seconds, compact click expands, expanded click copies canonical text.
4. Copy success is non-recursive and restores the previous feedback after 1.5 seconds.
5. Exactly one island renderer exists in `AuthenticatedLayout`.
6. No application layout displacement; the island overlays the header.
7. Typecheck, Vitest, focused PHP/Inertia tests, Architecture gate, build, and `git diff --check` pass.

## Explicit exclusions

- No persistent notification center/history.
- No module-specific toast library.
- No business workflow redesign.
- No WooCommerce-specific behavior in this WP.
- No production deployment.
