# ERP2 Unified Grid V2 — G6 safe archive record

Date: 2026-11-26 (UTC+08:00).

## Frozen development standard

- Use the existing unified grid and one column-settings dialog for migrated business lists.
- Opaque white, grouped field selection on the left, selected fields and ordering on the right.
- Preserve stable column identifiers, page-specific defaults, account-specific preferences, and existing authorization boundaries.
- New pages reuse the shared component; do not duplicate column-settings implementations.
- Scope is migrated business lists, not unmigrated pages or transient result tables.

## Verified development milestone

- G5 controlled Dev rollout completed.
- G6 technical evidence: 102 frontend tests passed; 36 read-only backend requests and 221 assertions passed.
- Actual employee browser acceptance remains delegated to the unified U5–U6 acceptance track. Technical verification does not substitute for employee acceptance.
- No independent Production release is planned.

## Source snapshot integrity

The full source snapshot remains in the controlled internal workspace. This public record contains no source payload, account details, operational configuration, customer records or credentials.

Artifact: ERP2-Unified-Grid-V2-G6-source.zip
Source file count: 28
Size: 127484 bytes
SHA-256: 4fc98607c1f86c26ab8bf401f5473dc31b5202a6c56c94993466f35bb816bf2e
Snapshot date: 2026-11-26.

The snapshot represents the G6 validation baseline, not an independently deployable complete application. Later parallel changes must be reconciled with the current shared development baseline before any combined release.

## Archive boundary

This branch is an integrity and development-standard record only. Full source upload to GitHub is not performed because the formal repository is public. It requires a suitable approved private source repository or approved encrypted backup channel. Do not interpret this record as a complete GitHub source backup or Production release.

Independent feature development is closed; U5–U6 owns remaining employee acceptance, and any formal release belongs to the combined ERP2 release track.
