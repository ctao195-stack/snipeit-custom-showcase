$ErrorActionPreference = 'Stop'

$taskName = 'SnipeIT Excel Activity Watcher'
$alertDir = 'D:\snipe-it-excel-sync\活动记录同步'
$alertLog = Join-Path $alertDir 'alerts.log'
$latestAlert = Join-Path $alertDir 'latest-alert.txt'

function Write-SnipeAlert {
  param([string]$Message)

  $line = '[{0}] ALERT: {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
  New-Item -ItemType Directory -Path $alertDir -Force | Out-Null
  Add-Content -LiteralPath $alertLog -Value $line -Encoding UTF8
  Set-Content -LiteralPath $latestAlert -Value $line -Encoding UTF8
  Write-Output $line
}

function Get-WatcherProcess {
  Get-CimInstance Win32_Process |
    Where-Object { $_.CommandLine -like '*watch_snipeit_excel.py*' }
}

$task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if (-not $task) {
  Write-SnipeAlert "Scheduled task '$taskName' does not exist."
  exit 1
}

$watcher = Get-WatcherProcess
if ($task.State -ne 'Running' -or -not $watcher) {
  Write-SnipeAlert "Watcher was not running. TaskState=$($task.State). Attempting restart."
  Start-ScheduledTask -TaskName $taskName
  Start-Sleep -Seconds 5
  $watcher = Get-WatcherProcess
  $task = Get-ScheduledTask -TaskName $taskName
}

if (-not $watcher) {
  Write-SnipeAlert "Watcher restart failed. Please inspect D:\snipe-it-excel-sync\活动记录同步\watch.log."
  exit 1
}

Write-Output "Watcher OK. ProcessId=$($watcher[0].ProcessId); TaskState=$($task.State)"
exit 0
