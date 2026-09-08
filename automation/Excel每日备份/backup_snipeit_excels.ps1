$ErrorActionPreference = 'Stop'

$sourceDir = 'D:\snipe-it'
$backupRoot = 'D:\snipe-it\daily-backup'
$logPath = 'D:\snipe-it-excel-sync\Excel每日备份\backup.log'
$backupForDate = (Get-Date).AddDays(-1).ToString('yyyy-MM-dd')
$backupTime = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
$targetDir = Join-Path $backupRoot $backupForDate

$files = @(
  'asset_activity.xlsx',
  '借用中.xlsx',
  '入库.xlsx',
  '已换新.xlsx',
  '归还.xlsx',
  '维修中.xlsx',
  '维修完成已重新入库.xlsx',
  '退租.xlsx',
  '领用.xlsx',
  '删除资产.xlsx',
  '未分类.xlsx'
)

New-Item -ItemType Directory -Path $targetDir -Force | Out-Null

$copied = @()
$missing = @()
$failed = @()

foreach ($file in $files) {
  $source = Join-Path $sourceDir $file
  $destination = Join-Path $targetDir $file

  if (-not (Test-Path -LiteralPath $source)) {
    $missing += $file
    continue
  }

  try {
    Copy-Item -LiteralPath $source -Destination $destination -Force
    $item = Get-Item -LiteralPath $destination
    $copied += [ordered]@{
      name = $file
      bytes = $item.Length
      copied_at = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
    }
  } catch {
    $failed += [ordered]@{
      name = $file
      error = $_.Exception.Message
    }
  }
}

$manifest = [ordered]@{
  backup_time = $backupTime
  backup_for_date = $backupForDate
  source_dir = $sourceDir
  target_dir = $targetDir
  copied_count = $copied.Count
  missing_count = $missing.Count
  failed_count = $failed.Count
  copied_files = $copied
  missing_files = $missing
  failed_files = $failed
}

$manifestPath = Join-Path $targetDir 'backup-manifest.json'
$manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $manifestPath -Encoding UTF8

$summary = "[{0}] backup_for={1}; copied={2}; missing={3}; failed={4}; target={5}" -f `
  (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), `
  $backupForDate, `
  $copied.Count, `
  $missing.Count, `
  $failed.Count, `
  $targetDir

Add-Content -LiteralPath $logPath -Value $summary -Encoding UTF8
Write-Output $summary

if ($failed.Count -gt 0) {
  exit 1
}

exit 0
