# ERP-NOTIFICATION-CENTER-M1-001

Status: OWNER_PASS / IMPLEMENTED / DELIVERY_PENDING
Base: `main@c9070f2005821648768a071ccbc22188a0cd8d82`
Branch: `feature/erp-notification-center-m1-001`

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
- `标记已读` is explicit in the detail header; opening the detail alone does not create Global Feedback.
- When a target is deleted/unavailable, the historical notification remains readable but has no navigation action.
- Individual `标记已读` and global `全部已读` are supported.
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

OWNER UI GATE: PASS. NEXT EXACT GATE: final delivery reconciliation → commit → push → PR → CI → merge. No Production deployment in this WP.
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
