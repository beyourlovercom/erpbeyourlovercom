$ErrorActionPreference='Stop'
$supervisor='D:\ai\erp-siteops\ops\ERP2-Dev-007-Supervisor.ps1'
$config=Get-Content -LiteralPath $supervisor -Raw
$m=[regex]::Match($config, '(?m)^\$env:ERP2_LOGISTICS_VBEN_DIST\s*=\s*''([^'']+)''')
if(-not $m.Success){throw 'ERP2_CURRENT_DIST_NOT_FOUND'}
$dist=$m.Groups[1].Value
$archive='D:\ai\erp-siteops\erp2-static-mirror-20261123.zip'
$exposed=Join-Path $dist 'erp2-static-mirror-20261123.zip'
if(-not (Test-Path (Join-Path $dist 'index.html'))){throw 'ERP2_DIST_MISSING'}
$files=Get-ChildItem -LiteralPath $dist -File -Recurse | Where-Object {$_.Name -ne 'erp2-static-mirror-20261123.zip'}
$total=($files|Measure-Object -Property Length -Sum).Sum
Write-Output ('ERP2_DIST_FILES='+$files.Count+' total_mb='+([math]::Round($total/1MB,2)))
$manifest=Join-Path $dist 'erp2-static-mirror.sha256'
if(Test-Path $exposed){Remove-Item $exposed -Force}
if(Test-Path $manifest){Remove-Item $manifest -Force}
if(Test-Path $archive){Remove-Item $archive -Force}
Compress-Archive -Path (Join-Path $dist '*') -DestinationPath $archive -CompressionLevel Fastest -Force
Copy-Item -LiteralPath $archive -Destination $exposed -Force
$h=(Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
Set-Content -LiteralPath $manifest -Value $h -Encoding ASCII -NoNewline
Write-Output ('ERP2_STATIC_ZIP_CREATED size='+((Get-Item $archive).Length)+' sha256='+$h+' url=http://100.99.21.57:18810/erp2-static-mirror-20261123.zip')