---
type: module-spec
module: kol-social
project: BYL ERP
version: 1.0
---

# KOL Social Enrichment

## User flow

```text
Paste Instagram/X profile link
→ parse platform/handle
→ create social sync run
→ official provider
→ profile/followers/avatar
→ local avatar mirror
→ follower snapshot
→ KOL profile
```

## X

Target fields:

- platform ID;
- username;
- display name;
- description;
- avatar;
- banner when available;
- followers;
- following;
- content count;
- verification;
- protected state.

## Instagram

Officially permitted scope only:

- Business;
- Creator;
- authorized professional discovery;
- permitted messaging profile.

Possible results:

```text
success
partial
manual_verification_required
unsupported_account_type
private_account
not_found
permission_denied
rate_limited
provider_unavailable
failed
```

No unapproved scraping fallback.

## Data

`kol_social_accounts`:

- KOL;
- platform;
- profile URL;
- handle;
- platform user ID;
- display name;
- bio;
- account type;
- verification;
- privacy;
- avatar asset;
- provider/method;
- status and timestamps.

`kol_social_snapshots`:

- followers;
- following;
- content count;
- captured time;
- source;
- payload hash;
- sync run.

## Scheduling

Possible later defaults:

- active collaboration: daily or every 3 days;
- candidate: weekly;
- archived: no automatic refresh.

Actual frequency depends on API quota and cost.
