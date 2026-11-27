param([string]$Output,[string]$Route='/logistics/china?view=pending')
$ErrorActionPreference='Stop'
[Console]::OutputEncoding=[Text.Encoding]::UTF8
if($Route -notmatch '^/logistics/[a-z0-9/-]+(?:\?view=[a-z0-9-]+)?$'){throw 'Only exact allowlisted logistics navigation'}
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
$root=[Windows.Automation.AutomationElement]::RootElement
$all=[Windows.Automation.Condition]::TrueCondition
$tc=New-Object Windows.Automation.PropertyCondition([Windows.Automation.AutomationElement]::ControlTypeProperty,[Windows.Automation.ControlType]::TabItem)
$dc=New-Object Windows.Automation.PropertyCondition([Windows.Automation.AutomationElement]::ControlTypeProperty,[Windows.Automation.ControlType]::Document)
$ec=New-Object Windows.Automation.PropertyCondition([Windows.Automation.AutomationElement]::ControlTypeProperty,[Windows.Automation.ControlType]::Edit)
function Wins {@($root.FindAll([Windows.Automation.TreeScope]::Children,$all)|Where-Object {$_.Current.ClassName -eq 'Chrome_WidgetWin_1'})}
$chrome=(Get-Process chrome -ErrorAction SilentlyContinue|Where-Object {$_.MainWindowHandle -ne 0}|Select-Object -First 1).Path
if(!$chrome){throw 'Existing Chrome unavailable; do not restart'}
$urls=@(('https://erp.beyourlover.com'+$Route),('https://erp2.beyourlover.com'+$Route))
$known=@(Wins|ForEach-Object {$_.Current.NativeWindowHandle})
Start-Process -FilePath $chrome -ArgumentList (@('--new-window')+$urls)
$w=$null
for($i=0;$i -lt 16;$i++){Start-Sleep -Milliseconds 500;$found=@(Wins|Where-Object {$_.Current.NativeWindowHandle -notin $known});if($found.Count -eq 1){$w=$found[0];break}}
if(!$w){throw 'New audit window ambiguous; no other window touched'}
$tabs=$w.FindAll([Windows.Automation.TreeScope]::Descendants,$tc)
if($tabs.Count -ne 2){throw 'New window tab count ambiguous; left unchanged'}
$markers=@('国内发货','待审核','缺货单','外采单','待发货','今日订单','今日速达','今日纬狮','异常订单','全部国内订单','下载待发货批量表','下载 SKU 合并版','上传物流单号','物流商','运输 / 服务类型','物流单号','服务渠道','操作','查看详情','批量操作','应用','列设置','表格功能','刷新','同步官网物流','确认发货','69条码','含液体','隔离 Dev','原 Laravel')
$rows=@()
foreach($i in 0,1){
 $tabs.Item($i).GetCurrentPattern([Windows.Automation.SelectionItemPattern]::Pattern).Select()
 $doc=$null;$nodes=@();$addr=''
 for($j=0;$j -lt 12;$j++){
  Start-Sleep -Milliseconds 500
  $doc=@($w.FindAll([Windows.Automation.TreeScope]::Descendants,$dc)|Sort-Object {$_.FindAll([Windows.Automation.TreeScope]::Descendants,$all).Count} -Descending)|Select-Object -First 1
  if($doc){$nodes=@($doc.FindAll([Windows.Automation.TreeScope]::Descendants,$all))}
  foreach($e in $w.FindAll([Windows.Automation.TreeScope]::Descendants,$ec)){try{$v=$e.GetCurrentPattern([Windows.Automation.ValuePattern]::Pattern).Current.Value;if($v -match '^(https://)?erp2?\.beyourlover\.com(/|$)'){$addr=$v;break}}catch{}}
  if($nodes.Count -gt 30 -and $addr){break}
 }
 $visible=@()
 $counts=@{}
 foreach($n in $nodes){try{$name=$n.Current.Name;$type=$n.Current.ControlType.ProgrammaticName;$counts[$type]=1+[int]$counts[$type];$hit=@($markers|Where-Object {$name -eq $_ -or ($name -like "$_*" -and $_ -in @('隔离 Dev','原 Laravel','上传物流单号'))});if($hit.Count){$b=$n.Current.BoundingRectangle;$visible+=[pscustomobject]@{label=$hit[0];type=$type;x=[math]::Round($b.X);y=[math]::Round($b.Y);width=[math]::Round($b.Width);height=[math]::Round($b.Height);offscreen=$n.Current.IsOffscreen}}}catch{}}
 $expected=$urls[$i];$actual=if($addr -match '^https://'){$addr}else{'https://'+$addr}
 $rows+=[pscustomobject]@{requested=$expected;actual=$actual;exact=($actual -eq $expected);title=$w.Current.Name;nodes=$nodes.Count;controlCounts=$counts;markers=$visible;actorVerified=$false;loginScreen=($w.Current.Name -match 'Login|登录');bjt=(Get-Date).ToUniversalTime().AddHours(8).ToString('yyyy-MM-dd HH:mm:ss')}
}
$doc=[ordered]@{mode='REAL_DUAL_SITE_READ_ONLY';sameRole=$false;comparableRecord=$false;verifiedWorkflow=$false;rows=$rows;status='OPEN_ROLE_AND_WORKFLOW';noSubmissions=$true;note='Allowlisted control labels and rectangles only; no order/customer data or credentials captured.'}
[IO.File]::WriteAllText($Output,($doc|ConvertTo-Json -Depth 9),[Text.UTF8Encoding]::new($false))
foreach($r in $rows){[pscustomobject]@{requested=$r.requested;actual=$r.actual;exact=$r.exact;title=$r.title;nodes=$r.nodes;markers=@($r.markers|ForEach-Object {$_.label}|Select-Object -Unique);actorVerified=$false}|ConvertTo-Json -Compress -Depth 4}
try{$w.GetCurrentPattern([Windows.Automation.WindowPattern]::Pattern).Close()}catch{Write-Output 'Own audit window not closed; no other window touched'}
