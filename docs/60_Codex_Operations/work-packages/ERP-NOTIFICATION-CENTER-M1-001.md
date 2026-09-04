# ERP-NOTIFICATION-CENTER-M1-001

Status: COMPLETE / OWNER_PASS / MERGED / CLOSED
Base: `main@c9070f2005821648768a071ccbc22188a0cd8d82`
Implementation Branch: `feature/erp-notification-center-m1-001`
Merged PR: `#176`
Merge SHA: `8feea0bab4d9ff25dd61148fbe7fe790b56dcc9b`

## Purpose

Add one persistent in-app Notification Center to the authenticated BYL ERP shell. It is for business events that remain relevant after the transient Global Feedback Island disappears.

## Boundary With Global Feedback Island

- Global Feedback Island remains the only short/transient action-feedback surface.
- Notification Center owns persistent business reminders, assignments, exceptions, approvals, and workflow events.
- A single event may generate a persistent notification and a transient confirmation only when both meanings are genuinely needed.
- Module-local toast/snackbar/notification centers remain forbidden.

## Shell Placement

- Desktop entry: notification bell in the TopBar trailing action zone.
- Unread badge is shown only when unread count is greater than zero; cap visual text at `99+`.
- Desktop center opens as a right-side overlay drawer and does not shift page layout.
- Mobile center opens as a full-height sheet optimized for touch.
- The fixed top-center Global Feedback Island remains visually and behaviorally independent.
## M1 Interaction Contract

- Default drawer view shows newest notifications first.
- Header shows `通知中心`, unread count, and `全部已读` when applicable.
- Filters are intentionally minimal: `全部` and `未读` only.
- Every row shows: type icon, title, concise message, relative/absolute time, and optional entity/context label.
- Unread rows have a clear unread marker and restrained highlighted background.
- Selecting a notification switches the center from the list to the approved `通知详情` view; it does not navigate away from the current ERP page.
- Detail view exposes full content, creator, time, and structured related-business links such as order / procurement requirement / product.
- Related-business identifiers themselves are the navigation actions; generic `打开业务页面` buttons are not the primary detail interaction.
- Opening a notification into `通知详情` immediately marks it read; the unread dot and global unread badge update immediately.
- There is no separate per-notification `标记已读` action in the final Owner-approved interaction; opening detail is the read action.
- Opening detail does not create Global Feedback.
- When a target is deleted/unavailable, the historical notification remains readable but has no navigation action.
- Per-notification read state is updated by opening detail; global `全部已读` remains supported.
- Empty state is calm and explicit; no fake alerts are rendered.

## Security / Authorization

- Notifications are user-scoped; users never query another user's notification inbox.
- Business producers target explicit ERP users or resolved recipient sets.
- Destination hrefs are resolved server-side only when the recipient is authorized for that business surface.
- Notification payloads must not contain secrets or unnecessary business-record dumps.
- Read-state mutation is server-authorized and auditable where required by existing governance.

## Data Minimization

- Store one notification row per recipient/event, not copied business entities.
- Store only notification presentation fields plus stable entity reference metadata.
- The source business entity remains the canonical truth; notification text is a durable event summary, not a duplicated record snapshot.
- No email, Slack, SMS, browser push, or external delivery channel in M1.
## Initial Notification Types

Foundation supports generic business event keys. M1 UAT should demonstrate representative ERP events without coupling the foundation to one module:
- approval required
- assignment / action required
- fulfillment or logistics exception
- inventory exception
- informational workflow completion

Procurement-specific events are intentionally added by the Procurement WP after this foundation is accepted.

## Explicit Exclusions

- No standalone notification page in the primary sidebar for M1.
- No complex preferences matrix.
- No snooze/remind-later engine.
- No notification grouping/digests.
- No realtime websocket requirement; initial implementation may refresh through normal ERP requests/poll-safe mechanisms.
- No production deployment in this WP.

## Owner UI Gate

Before tracked implementation, Owner must approve desktop and mobile rendering for:
1. TopBar bell + unread badge.
2. Desktop right-side drawer.
3. Unread/read row distinction and direct business-record navigation.
4. `全部 / 未读` filtering and `全部已读` action.
5. Mobile full-height notification sheet.

OWNER UI GATE: PASS. DELIVERY GATE: PASS / MERGED BY PR #176. No Production deployment in this WP.
## Feedback Island coexistence rule — OWNER CONFIRMED
- Global Feedback Island and Notification Center use separate state and separate persistence.
- Feedback Island = transient result of the current user's explicit action; never unread, never history.
- Notification Center = persistent business event for a recipient; unread/read and history apply.
- A sender action may produce sender feedback plus a recipient notification, but the same user/event must not be double-broadcast.
- Passive/system events enter Notification Center only; they do not interrupt the current screen through the Feedback Island.
- Opening a notification marks it read and navigates; the navigation itself does not publish feedback.
- Subsequent business actions at the destination may publish normal transient feedback.
- The Notification Center never reads `globalFeedback`; the Feedback Island never reads the notifications table.

## Final Owner Interaction Rule
- Clicking a notification opens the designed notification-detail view and immediately marks that notification read.
- The unread dot and global unread badge update immediately; no separate `标记已读` action is shown.
- Related business entities are explicit clickable links inside the detail view.

## Formal Closure

- Owner closure command: `ERP-NOTIFICATION-CENTER-M1-001 正式关闭` on 2026-09-04.
- Owner UAT: PASS, including final notification-detail design and `open detail = read` interaction.
- Pull Request: `#176` — `feat: add global notification center M1`.
- Merge SHA: `8feea0bab4d9ff25dd61148fbe7fe790b56dcc9b`.
- GitHub CI: PASS, including Typecheck, frontend regression, production build, fresh migration rehearsal, backend regression, cross-module boundary regression, diff check, and no-dev-hot-marker assertion.
- Runtime/Production: no Production deployment was performed or authorized by this WP.
- Global Feedback Island remains a separate transient-feedback system; Notification Center remains persistent recipient business-event history.
- Package status is frozen as `COMPLETE / OWNER_PASS / MERGED / CLOSED`.

## Next Exact Gate

`ERP-PROCUREMENT-ORDER-REQUIREMENT-M1-001` pre-development contract + UI/interaction gate.
