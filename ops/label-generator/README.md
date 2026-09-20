# BYL PI / Blue Label Generator — Windows Runtime V1

This directory contains the fixed, non-AI blue-label runtime used by the ERP execution chain.

The business source of truth is the confirmed `WarehouseRestockPlan` snapshot.
Codex / Desktop Commander may dispatch the runtime, but they do **not** decide SKU,
quantity, description, packaging data, or label identity.

## Runtime chain

```text
ERP confirmed Restock Plan
  -> Blue Label Job V1 JSON
  -> Codex/DC dispatch
  -> company Windows worker
  -> fixed Node renderer
  -> BarTender 10.1 Picture Master
  -> .btw + exact 50x30 mm .pdf
  -> manifest.json
```

The runtime has no AI-dependent rendering step and does not require an operator to open BarTender.

## Canonical master

Template:

```text
windows/templates/blue-label-picture-master-v1.btw
```

SHA-256:

```text
047e69f0645ac7f20b42a6ac23cdc78e3cf16945d367e6bd80ab713621409a82
```

The master is a clean BarTender 10.1 document with one embedded Picture object.
The runtime replaces that Picture with generated artwork and saves a new independent `.btw`.
The output `.btw` remains reopenable after the temporary PNG is deleted.

BarTender internally rounds the label page to approximately `50.013 x 29.997 mm`.
The companion PDF is created separately with an exact mathematical MediaBox of
`50.00000000 x 30.00000000 mm`.

## Windows prerequisites

- Windows company worker kept online.
- BarTender 10.1 Enterprise Automation.
- Node.js available as `node.exe`.
- Chrome or Edge.
- 32-bit Windows Script Host at `%WINDIR%\SysWOW64\cscript.exe`.
- Desktop Commander / Codex only for dispatch and controlled execution.

No Pillow, npm package install, custom font package, or BarTender manual editing is required.

The label artwork uses system fonts only:

```text
Arial, Segoe UI, sans-serif
```

Barcode format is Code128B. `label_sku` must match `[A-Za-z0-9._-]+`.

## Job contract

Canonical input schema:

```text
contracts/blue-label-job-v1.schema.json
```

Example:

```text
contracts/blue-label-job-v1.example.json
```

Important rules:

- `plan_reference` and `plan_snapshot_checksum` come from the immutable Restock Plan.
- `product_sku` remains the Product OS canonical SKU.
- `label_sku` is the effective blue-label identity and may differ from Product SKU.
- `label_description` is already frozen by ERP; the worker must not invent or rewrite it.
- `restock_qty` must be greater than zero.
- Duplicate `label_sku` values fail closed.

## Manual runtime invocation

From PowerShell:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\windows\generate-blue-labels.ps1 -JobPath .\contracts\blue-label-job-v1.example.json -OutputDir C:\Temp\BL-UAT-001
```

A successful run prints:

```text
BLUE_LABEL_JOB_OK job_id=<id> items=<n> output=<path>
```

Outputs are immutable. If `OutputDir` already exists, the runtime fails instead of overwriting it.

## Output contract

Canonical output schema:

```text
contracts/blue-label-output-v1.schema.json
```

Example structure:

```text
BL-UAT-001/
  manifest.json
  labels/
    C549A.btw
    C549A.pdf
    CA755B.btw
    CA755B.pdf
```

`manifest.json` records the job identity, Restock Plan identity, template hash,
runtime evidence, per-file SHA-256 hashes, label size, artwork dimensions, barcode type,
and the frozen restock quantity.

## Validation gates completed for M4

- Node core tests: Code128B, safe label identity, Chinese wrapping, 50x30 SVG contract, and exact PDF MediaBox.
- Windows batch UAT: 2 SKUs generated in one job.
- Real BarTender 10.1 automation: PASS.
- Temporary artwork deletion followed by independent `.btw` reopen/export: PASS.
- CoreGraphics PDF verification: `C549A.pdf` and `CA755B.pdf` are each 1 page at exactly `50.00000000 x 30.00000000 mm`.
- Existing output directory overwrite: blocked.
- Duplicate label SKU: blocked before any output directory is created.

Production ERP dispatch, job queue state, R2 upload, ZIP packaging, and retry/heartbeat
belong to the later M5–M8 integration stages and are intentionally not implemented here.
