$ErrorActionPreference = 'Stop'
$outDir = Join-Path $PSScriptRoot 'onegoal-catalog-data'
New-Item -ItemType Directory -Force -Path $outDir | Out-Null
$env:SQLCMDPASSWORD = 'mEiLsS121806'
$server = '192.168.0.5'
$dbs = & sqlcmd -S $server -U 'sa' -C -l 15 -Q "SET NOCOUNT ON; SELECT name FROM sys.databases WHERE state_desc='ONLINE' AND name LIKE '1G[_]%' ORDER BY name;" -h -1 -W
$usage = [System.Collections.Generic.List[object]]::new()
foreach ($db in $dbs) {
  $safeDb = $db.Replace(']', ']]')
  $query = @"
SET NOCOUNT ON;
WITH movimientos AS (
  SELECT p.id_pro AS IdProducto, d.fec_doc AS Fecha, 'Orden normal' AS Tipo
  FROM [$safeDb].dbo.com_mov_doc d
  INNER JOIN [$safeDb].dbo.com_mov_part p ON p.id_compra=d.id_compra
  WHERE d.id_tip_doc=86 AND d.status IN (1,2) AND d.fec_doc >= '20250101'
  UNION ALL
  SELECT ID_PRO, FECHA_REG, 'Compra directa'
  FROM [$safeDb].dbo.vta_compra_dir_pdv
  WHERE STATUS IN (1,2) AND FECHA_REG >= '20250101'
)
SELECT '$db' AS EmpresaBase, IdProducto, MAX(Fecha) AS UltimoUso, SUM(CASE WHEN Tipo='Orden normal' THEN 1 ELSE 0 END) AS LineasOrdenNormal, SUM(CASE WHEN Tipo='Compra directa' THEN 1 ELSE 0 END) AS LineasCompraDirecta
FROM movimientos GROUP BY IdProducto ORDER BY IdProducto;
"@
  $lines = & sqlcmd -S $server -U 'sa' -d $db -C -l 30 -Q $query -s '|' -W -h -1
  foreach ($line in $lines) {
    if ([string]::IsNullOrWhiteSpace($line)) { continue }
    $p = $line -split '\|'
    $usage.Add([pscustomobject]@{ EmpresaBase=$p[0]; IdProducto=[int]$p[1]; UltimoUso=$p[2]; LineasOrdenNormal=[int]$p[3]; LineasCompraDirecta=[int]$p[4] })
  }
}
$usage | ConvertTo-Json -Depth 3 | Set-Content -Encoding utf8 (Join-Path $outDir 'uso_productos_oc_desde_2025.json')
[pscustomobject]@{
  BasesConUso = @($usage.EmpresaBase | Sort-Object -Unique).Count
  ProductosEmpresaUsados = $usage.Count
  ProductosConOrdenNormal = @($usage | Where-Object { $_.LineasOrdenNormal -gt 0 }).Count
  ProductosConCompraDirecta = @($usage | Where-Object { $_.LineasCompraDirecta -gt 0 }).Count
} | ConvertTo-Json | Set-Content -Encoding utf8 (Join-Path $outDir 'resumen_uso_productos_oc_desde_2025.json')
