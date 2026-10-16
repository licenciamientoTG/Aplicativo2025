param([Parameter(Mandatory=$true)][string]$BoardId)
$ErrorActionPreference = 'Stop'
$stage = 'C:\Users\daniel.ramirez\AppData\Local\Temp\monday-backup-20261014'
$configPath = Join-Path $env:windir 'System32\inetsrv\config\applicationHost.config'
[xml]$config = Get-Content -LiteralPath $configPath
$required = @('TABLEROS_STORAGE_DRIVER','TABLEROS_STORAGE_SERVICE_URL','TABLEROS_STORAGE_SERVICE_TOKEN')
$selected = $null
foreach ($application in $config.SelectNodes("//*[local-name()='fastCgi']/*[local-name()='application']")) {
    if ($application.GetAttribute('fullPath') -notmatch 'php-cgi') { continue }
    $values = @{}
    foreach ($entry in $application.SelectNodes("./*[local-name()='environmentVariables']/*[local-name()='environmentVariable']")) { $values[$entry.GetAttribute('name')] = $entry.GetAttribute('value') }
    if (($required | Where-Object { -not $values.ContainsKey($_) -or [string]::IsNullOrWhiteSpace([string]$values[$_]) }).Count -eq 0 -and $values['TABLEROS_STORAGE_DRIVER'] -eq 'nas_service') { $selected = $values; break }
}
if ($null -eq $selected) { throw 'FastCGI NAS configuration missing' }
foreach ($name in $required) { Set-Item -Path "Env:$name" -Value ([string]$selected[$name]) }
$env:MONDAY_IMPORT_APP_ROOT = Join-Path $stage 'app-root'
$env:MONDAY_CA_BUNDLE = Join-Path $stage 'ca.pem'
$cli = Join-Path $stage 'tools\monday_import_cli.php'
$map = Join-Path $stage 'input\monday_board_api_key_map_2026-10-14.json'
$workbook = Join-Path $stage 'input\Segmentacion_Usuarios_Monday_TOTALGAS.xlsx'
$mapping = Join-Path $stage 'input\monday_pilot_mapping_2026-10-14.json'
& 'C:\php\php.exe' $cli "--map=$map" "--workbook=$workbook" '--summary' '--apply' '--run-key=monday-backup-2026-10-14' "--mapping-file=$mapping" "--board-ids=$BoardId"
exit $LASTEXITCODE
