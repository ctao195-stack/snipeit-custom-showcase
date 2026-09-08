$ErrorActionPreference = 'Stop'

$task = 'SnipeIT Excel Activity Watcher'
Stop-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue
Start-Sleep -Seconds 2

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$scriptBackup = Join-Path 'D:\snipe-it-excel-sync' ('backup-' + $stamp)
$dataBackup = Join-Path 'D:\snipe-it' ('backup-' + $stamp)

New-Item -ItemType Directory -Path $scriptBackup -Force | Out-Null
New-Item -ItemType Directory -Path $dataBackup -Force | Out-Null

Copy-Item `
  'D:\snipe-it-excel-sync\sync_snipeit_activity_to_excel.py',`
  'D:\snipe-it-excel-sync\watch_snipeit_excel.py',`
  'D:\snipe-it-excel-sync\run_watch.cmd' `
  -Destination $scriptBackup `
  -Force

Copy-Item `
  'D:\snipe-it\*.xlsx' `
  -Destination $dataBackup `
  -Force `
  -ErrorAction SilentlyContinue

Write-Output ('SCRIPT_BACKUP=' + $scriptBackup)
Write-Output ('EXCEL_BACKUP=' + $dataBackup)
Write-Output ('TASK_STATE=' + (Get-ScheduledTask -TaskName $task).State)
