$ErrorActionPreference = 'Stop'

$taskName = 'SnipeIT Excel Watcher Health Check'
$scriptPath = 'D:\snipe-it-excel-sync\check_snipeit_excel_watcher.ps1'

$action = New-ScheduledTaskAction `
  -Execute 'powershell.exe' `
  -Argument ('-NoProfile -ExecutionPolicy Bypass -File "' + $scriptPath + '"')

$trigger = New-ScheduledTaskTrigger `
  -Once `
  -At (Get-Date).Date `
  -RepetitionInterval (New-TimeSpan -Minutes 5) `
  -RepetitionDuration (New-TimeSpan -Days 3650)

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
  -Description 'Checks the Snipe-IT Excel watcher process and restarts it when needed.' `
  -Force | Out-Null

Get-ScheduledTask -TaskName $taskName | Select-Object TaskName,State | Format-List
Get-ScheduledTaskInfo -TaskName $taskName | Select-Object LastRunTime,LastTaskResult,NextRunTime | Format-List
