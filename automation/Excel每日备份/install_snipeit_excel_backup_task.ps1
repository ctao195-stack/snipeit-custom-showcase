$ErrorActionPreference = 'Stop'

$taskName = 'SnipeIT Excel Daily Backup'
$scriptPath = 'D:\snipe-it-excel-sync\backup_snipeit_excels.ps1'

$action = New-ScheduledTaskAction `
  -Execute 'powershell.exe' `
  -Argument ('-NoProfile -ExecutionPolicy Bypass -File "' + $scriptPath + '"')

$trigger = New-ScheduledTaskTrigger -Daily -At 1:00am

$principal = New-ScheduledTaskPrincipal `
  -UserId 'Administrator' `
  -LogonType S4U `
  -RunLevel Highest

$settings = New-ScheduledTaskSettingsSet `
  -Compatibility Win8 `
  -AllowStartIfOnBatteries `
  -DontStopIfGoingOnBatteries `
  -StartWhenAvailable `
  -MultipleInstances IgnoreNew

Register-ScheduledTask `
  -TaskName $taskName `
  -Action $action `
  -Trigger $trigger `
  -Principal $principal `
  -Settings $settings `
  -Description 'Daily backup for Snipe-IT Excel total workbook and status workbooks.' `
  -Force | Out-Null

Get-ScheduledTask -TaskName $taskName | Select-Object TaskName,State | Format-List
Get-ScheduledTaskInfo -TaskName $taskName | Select-Object LastRunTime,LastTaskResult,NextRunTime | Format-List
