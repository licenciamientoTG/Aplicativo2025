param(
  [switch]$Apply,
  [switch]$DryRun,
  [switch]$SkipAttachments,
  [string]$BoardId,
  [string]$SourceAccountId,
  [string]$RunKey = 'monday-backup-2026-10-14'
)
$ErrorActionPreference = 'Stop'
$stage = Split-Path -Parent $MyInvocation.MyCommand.Path
$appRoot = Join-Path $stage 'app-root'
$configPath = Join-Path $env:windir 'System32\inetsrv\config\applicationHost.config'
[xml]$config = Get-Content -LiteralPath $configPath
$required = @('TABLEROS_STORAGE_DRIVER','TABLEROS_STORAGE_SERVICE_URL','TABLEROS_STORAGE_SERVICE_TOKEN')
$candidates = @()
foreach ($application in $config.SelectNodes("//*[local-name()='fastCgi']/*[local-name()='application']")) {
  if ($application.GetAttribute('fullPath') -notmatch 'php-cgi') { continue }
  $values = @{}
  foreach ($entry in $application.SelectNodes("./*[local-name()='environmentVariables']/*[local-name()='environmentVariable']")) {
    $values[$entry.GetAttribute('name')] = $entry.GetAttribute('value')
  }
  if (($required | Where-Object { -not $values.ContainsKey($_) -or [string]::IsNullOrWhiteSpace([string]$values[$_]) }).Count -eq 0 -and $values['TABLEROS_STORAGE_DRIVER'] -eq 'nas_service') {
    $candidates += ,$values
  }
}
if ($candidates.Count -lt 1) { throw 'No se encontró la configuración FastCGI NAS requerida.' }
$selected = $candidates[0]
foreach ($name in $required) { Set-Item -Path "Env:$name" -Value ([string]$selected[$name]) }
$env:MONDAY_IMPORT_APP_ROOT = $appRoot
$env:MONDAY_CA_BUNDLE = Join-Path $stage 'ca.pem'
$php = 'C:\php\php.exe'
$cli = Join-Path $stage 'tools\monday_import_cli.php'
$map = Join-Path $stage 'input\monday_board_api_key_map_2026-10-14.json'
$workbook = Join-Path $stage 'input\Segmentacion_Usuarios_Monday_TOTALGAS.xlsx'
$mapping = Join-Path $stage 'input\monday_pilot_mapping_2026-10-14.json'
$args = @("--map=$map", "--workbook=$workbook", '--summary')
if ($Apply) { $args += @('--apply', "--run-key=$RunKey", "--mapping-file=$mapping"); if ($SourceAccountId) { $args += "--source-account-id=$SourceAccountId" } }
else { $args += '--dry-run' }
if ($SkipAttachments) { $args += '--skip-attachments' }
if ($BoardId) { $args += "--board-ids=$BoardId" }
if ($DryRun) { $args = @("--map=$map", "--workbook=$workbook", '--dry-run', '--summary', '--board-ids=9149354961') }
& $php $cli @args
exit $LASTEXITCODE
