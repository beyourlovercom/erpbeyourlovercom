param(
  [Parameter(Mandatory = $true)][string]$JobPath,
  [Parameter(Mandatory = $true)][string]$OutputDir,
  [string]$TemplatePath = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Require-File([string]$Path, [string]$Label) {
  if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
    throw "$Label not found: $Path"
  }
}

function File-Sha256([string]$Path) {
  return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
if ([string]::IsNullOrWhiteSpace($TemplatePath)) {
  $TemplatePath = Join-Path $scriptRoot 'templates\blue-label-picture-master-v1.btw'
}

Require-File $JobPath 'job'
Require-File $TemplatePath 'BarTender master'
Require-File (Join-Path $scriptRoot 'render-label.cjs') 'render-label.cjs'
Require-File (Join-Path $scriptRoot 'embed-picture.vbs') 'embed-picture.vbs'

$nodeCommand = Get-Command node.exe -ErrorAction SilentlyContinue
if (-not $nodeCommand) { $nodeCommand = Get-Command node -ErrorAction SilentlyContinue }
if (-not $nodeCommand) { throw 'Node.js is required on the label worker.' }
$node = $nodeCommand.Source

$chromeCandidates = @(
  'C:\Program Files\Google\Chrome\Application\chrome.exe',
  'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
  'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
  'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
)
$chrome = $chromeCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
if (-not $chrome) { throw 'Chrome or Edge is required on the label worker.' }

$cscript = Join-Path $env:WINDIR 'SysWOW64\cscript.exe'
if (-not (Test-Path -LiteralPath $cscript)) {
  throw "32-bit cscript is required for BarTender 10.1 COM: $cscript"
}

$jobRaw = Get-Content -LiteralPath $JobPath -Raw -Encoding UTF8
$job = $jobRaw | ConvertFrom-Json
if ($job.schema_version -ne 'byl.blue-label.job.v1') { throw "Unsupported schema_version: $($job.schema_version)" }
if ($job.template_version -ne 'blue-label-picture-master-v1') { throw "Unsupported template_version: $($job.template_version)" }

if (-not $job.items -or @($job.items).Count -lt 1) { throw 'Job must contain at least one item.' }
if ([string]::IsNullOrWhiteSpace([string]$job.job_id)) { throw 'job_id is required.' }
if ([string]::IsNullOrWhiteSpace([string]$job.plan_reference)) { throw 'plan_reference is required.' }

$seen = @{}
foreach ($item in @($job.items)) {
  $labelSku = ([string]$item.label_sku).Trim()
  $productSku = ([string]$item.product_sku).Trim()
  $description = ([string]$item.label_description).Trim()
  if ($labelSku -notmatch '^[A-Za-z0-9._-]+$') { throw "Invalid label_sku: $labelSku" }
  if ([string]::IsNullOrWhiteSpace($productSku)) { throw "product_sku is required for $labelSku" }
  if ([string]::IsNullOrWhiteSpace($description)) { throw "label_description is required for $labelSku" }
  if ([int]$item.restock_qty -le 0) { throw "restock_qty must be > 0 for $labelSku" }
  if ($seen.ContainsKey($labelSku)) { throw "Duplicate label_sku in job: $labelSku" }
  $seen[$labelSku] = $true
}

if (Test-Path -LiteralPath $OutputDir) {
  throw "OutputDir already exists; label job outputs are immutable: $OutputDir"
}

$parent = Split-Path -Parent $OutputDir
if ([string]::IsNullOrWhiteSpace($parent)) { $parent = (Get-Location).Path }
New-Item -ItemType Directory -Force -Path $parent | Out-Null

$outputLeaf = Split-Path -Leaf $OutputDir
$staging = Join-Path $parent ('.' + $outputLeaf + '.tmp-' + [Guid]::NewGuid().ToString('N'))
$temp = Join-Path ([IO.Path]::GetTempPath()) ('byl-blue-label-' + [Guid]::NewGuid().ToString('N'))
$labelsDir = Join-Path $staging 'labels'
New-Item -ItemType Directory -Force -Path $labelsDir, $temp | Out-Null

$artifacts = @()
$bartenderRuntime = $null
$renderScript = Join-Path $scriptRoot 'render-label.cjs'
$embedScript = Join-Path $scriptRoot 'embed-picture.vbs'

try {
  foreach ($item in @($job.items)) {
    $labelSku = ([string]$item.label_sku).Trim()
    $description = ([string]$item.label_description).Trim()
    $png = Join-Path $temp ($labelSku + '.png')
    $pdf = Join-Path $labelsDir ($labelSku + '.pdf')
    $btw = Join-Path $labelsDir ($labelSku + '.btw')

    $renderJson = & $node $renderScript '--sku' $labelSku '--description' $description '--png' $png '--pdf' $pdf '--chrome' $chrome
    if ($LASTEXITCODE -ne 0) { throw "Renderer failed for $labelSku" }
    $render = $renderJson | ConvertFrom-Json

    $btOutput = & $cscript //nologo $embedScript $TemplatePath $png $btw 2>&1
    if ($LASTEXITCODE -ne 0) { throw "BarTender failed for $labelSku :: $($btOutput -join ' | ')" }
    $btOk = @($btOutput) | Where-Object { [string]$_ -like 'BT_OK*' } | Select-Object -Last 1
    if (-not $btOk) { throw "BarTender did not return BT_OK for $labelSku" }
    if (-not $bartenderRuntime) { $bartenderRuntime = [string]$btOk }

    Remove-Item -LiteralPath $png -Force
    Require-File $btw "$labelSku BTW"
    Require-File $pdf "$labelSku PDF"
    if ((Get-Item -LiteralPath $btw).Length -lt 1024) { throw "$labelSku BTW is unexpectedly small" }
    if ((Get-Item -LiteralPath $pdf).Length -lt 1024) { throw "$labelSku PDF is unexpectedly small" }

    $artifacts += [ordered]@{
      product_sku = ([string]$item.product_sku).Trim()
      label_sku = $labelSku
      label_description = $description
      restock_qty = [int]$item.restock_qty
      btw = "labels/$labelSku.btw"
      pdf = "labels/$labelSku.pdf"
      btw_sha256 = File-Sha256 $btw
      pdf_sha256 = File-Sha256 $pdf
      label_size_mm = @([double]$render.page_mm[0], [double]$render.page_mm[1])
      artwork_px = @([int]$render.image_px[0], [int]$render.image_px[1])
      barcode = 'Code128B'
    }
  }

  $manifest = [ordered]@{
    schema_version = 'byl.blue-label.output.v1'
    generator_version = 'blue-label-runtime-v1'
    job_id = [string]$job.job_id
    plan_reference = [string]$job.plan_reference
    plan_snapshot_checksum = [string]$job.plan_snapshot_checksum
    template_version = [string]$job.template_version
    template_sha256 = File-Sha256 $TemplatePath
    source_job_sha256 = (Get-FileHash -LiteralPath $JobPath -Algorithm SHA256).Hash.ToLowerInvariant()
    generated_at_utc = [DateTimeOffset]::UtcNow.ToString('o')
    runtime = [ordered]@{
      node = (& $node --version)
      chrome = $chrome
      bartender = $bartenderRuntime
    }
    artifacts = $artifacts
  }

  $manifestPath = Join-Path $staging 'manifest.json'
  $manifest | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $manifestPath -Encoding UTF8
  Require-File $manifestPath 'manifest'
  Move-Item -LiteralPath $staging -Destination $OutputDir
  $staging = $null
  Write-Output ("BLUE_LABEL_JOB_OK job_id={0} items={1} output={2}" -f $job.job_id, @($job.items).Count, $OutputDir)
} finally {
  if ($staging -and (Test-Path -LiteralPath $staging)) { Remove-Item -LiteralPath $staging -Recurse -Force }
  if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Recurse -Force }
}
