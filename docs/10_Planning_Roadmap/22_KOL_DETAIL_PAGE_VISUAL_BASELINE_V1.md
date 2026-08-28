# KOL_DETAIL_PAGE_VISUAL_BASELINE_V1

Status: OWNER APPROVED / FROZEN
Date: 2026-08-28
Applies to: KOL Detail V2 inside ERP KOL M1.1

## 1. Purpose

This document freezes the Owner-approved KOL detail visual baseline. The approved rendered reference is the visual authority for layout, hierarchy, density, card placement, actions, spacing, badges and information rhythm.

Implementation may adapt only where required by the existing ERP shell, real canonical data, authorization, responsive behavior or accessibility. It must not redesign the approved composition during implementation.

## 2. Page composition

Desktop order is frozen as:
1. Profile Hero across full width.
2. Two-column content region.
3. Left column: Basic Information, then Social Media Data.
4. Right column: Current Opportunity, then Recent Communications.
5. Full-width bottom region: Historical Opportunities.

Desktop content columns: approximately 320px left + flexible right, with 16px gap. Mobile collapses to one column while preserving semantic order.
## 3. Frozen regions

### Region A — Profile Hero
Must contain: identity/avatar fallback, display name, primary social identity, country/region, language, category tags, relationship status, top metrics, and upper-right actions.

Upper-right actions: Return to List, Edit KOL, New Opportunity, New Communication. Capability buttons remain visible when possible; unavailable actions may be disabled rather than silently removed.

### Region B — Basic Information
Compact label/value rows. Core facts include display name, email, country/region, language, cooperation type, joined date, status, Owner and notes summary where appropriate.

### Region C — Social Media Data
Platform rows with platform identity, handle/profile link, followers and any canonical engagement/view metrics available. Missing metrics render as `—`; fabricated metrics are forbidden.

### Region D — Current Opportunity
One primary active opportunity card with title, type, target products, stage, estimated cost, expected publish date, next follow-up, Owner, stage timeline and highlighted next action.

### Region E — Recent Communications
Latest communication records with channel, timestamp, summary and recorder. Default density is 3–5 rows plus View All.

### Region F — Historical Opportunities
Full-width dense table for completed/paused historical opportunity facts. No duplicate canonical Order truth.
## 4. Visual tokens

Use the existing ERP shell and typography. Detail content uses white cards on the ERP page background, subtle neutral borders, restrained shadows, 12px card radius, 16px inter-card gaps and approximately 18–20px card padding.

Status semantics use compact pills. Green indicates active/success/cooperating states; amber indicates paused/pending states; red indicates failed/archived-risk states; neutral/blue may represent informational stages. Color must remain secondary to readable text labels.

The page should feel dense, operational and calm: no oversized marketing typography, no decorative gradients, no fake analytics and no redundant cards.

## 5. Data and architecture rules

Canonical KOL Master and Social Accounts remain the source for identity/profile facts. M1.1 Opportunity and Communication facts must be additive CRM records linked by `kol_id`; they must not duplicate canonical Orders, Products or Shipments.

Unavailable future data must display `—` or an explicit empty state. The visual baseline never authorizes invented demo values in production/runtime surfaces.

Existing server-side Owner scope, permissions, archive/reactivate behavior and audit requirements remain authoritative.

## 6. Implementation gates

Implementation order is frozen: A Page Skeleton + Profile Hero → B Basic Information → C Social Media Data → D Current Opportunity → E Recent Communications → F Historical Opportunities → full-page visual calibration/responsive pass → Owner UAT.

Each region should be visually reviewed before the next region materially expands. Final acceptance target is a close visual reproduction of the approved reference within the existing ERP shell and real-data constraints.