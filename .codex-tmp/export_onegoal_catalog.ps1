$ErrorActionPreference = 'Stop'

$outDir = Join-Path $PSScriptRoot 'onegoal-catalog-data'
New-Item -ItemType Directory -Force -Path $outDir | Out-Null

$env:SQLCMDPASSWORD = 'mEiLsS121806'
$server = '192.168.0.5'
$databases = & sqlcmd -S $server -U 'sa' -C -l 15 -Q "SET NOCOUNT ON; SELECT name FROM sys.databases WHERE state_desc='ONLINE' AND name LIKE '1G[_]%' ORDER BY name;" -h -1 -W

$all = [System.Collections.Generic.List[object]]::new()
foreach ($database in $databases) {
    $safeDatabase = $database.Replace(']', ']]')
    $query = @"
SET NOCOUNT ON;
SELECT (
  SELECT
    '$database' AS EmpresaBase,
    id_pro AS IdProducto,
    LTRIM(RTRIM(clave)) AS Clave,
    LTRIM(RTRIM(codigo)) AS Codigo,
    LTRIM(RTRIM(des)) AS Descripcion,
    LTRIM(RTRIM(presentacion)) AS Presentacion,
    LTRIM(RTRIM(marca)) AS Marca,
    LTRIM(RTRIM(udm_inv)) AS UDMInventario,
    LTRIM(RTRIM(udm_com)) AS UDMCompra,
    LTRIM(RTRIM(udm_vta)) AS UDMVenta,
    precio AS Precio,
    precio_pub AS PrecioPublico,
    costo_prom AS CostoPromedio,
    c_ventas AS VentaHabilitada,
    c_compras AS CompraHabilitada,
    fch_alta AS FechaAlta,
    fec_ult_mod AS FechaUltimaModificacion
  FROM [$safeDatabase].dbo.cat_pro
  WHERE status = 1
  FOR JSON PATH
) AS JsonData;
"@
    $jsonLines = & sqlcmd -S $server -U 'sa' -d $database -C -l 30 -Q $query -w 65535 -y 0
    $json = ($jsonLines | Where-Object { $_ -notmatch '^JsonData\s*$' -and $_ -notmatch '^-+\s*$' }) -join ''
    if (-not [string]::IsNullOrWhiteSpace($json) -and $json.Trim() -ne '[]') {
        foreach ($row in ($json | ConvertFrom-Json)) { $all.Add($row) }
    }
}

$all | ConvertTo-Json -Depth 4 | Set-Content -Encoding utf8 (Join-Path $outDir 'productos_activos_origen.json')

$groups = $all | Group-Object {
    $key = if (-not [string]::IsNullOrWhiteSpace($_.Clave)) { $_.Clave } else { $_.Codigo }
    $key.Trim().ToUpperInvariant()
}

$consolidated = foreach ($group in $groups) {
    $ordered = $group.Group | Sort-Object @{ Expression = { if ($_.FechaUltimaModificacion) { [datetime]$_.FechaUltimaModificacion } else { [datetime]'1900-01-01' } }; Descending = $true }, @{ Expression = { $_.EmpresaBase -eq '1G_TOTALGAS' }; Descending = $true }
    $canonical = $ordered | Select-Object -First 1
    [pscustomobject]@{
        Clave = $canonical.Clave
        Codigo = $canonical.Codigo
        Descripcion = $canonical.Descripcion
        Presentacion = $canonical.Presentacion
        Marca = $canonical.Marca
        UDMInventario = $canonical.UDMInventario
        UDMCompra = $canonical.UDMCompra
        UDMVenta = $canonical.UDMVenta
        Precio = $canonical.Precio
        PrecioPublico = $canonical.PrecioPublico
        CostoPromedio = $canonical.CostoPromedio
        VentaHabilitada = $canonical.VentaHabilitada
        CompraHabilitada = $canonical.CompraHabilitada
        FechaAlta = $canonical.FechaAlta
        FechaUltimaModificacion = $canonical.FechaUltimaModificacion
        FuenteElegida = $canonical.EmpresaBase
        EmpresasActivas = (($group.Group.EmpresaBase | Sort-Object -Unique) -join ', ')
        NumeroEmpresas = @($group.Group.EmpresaBase | Sort-Object -Unique).Count
        RegistrosActivosOrigen = $group.Count
    }
}

$consolidated | Sort-Object Clave, Codigo | ConvertTo-Json -Depth 4 | Set-Content -Encoding utf8 (Join-Path $outDir 'catalogo_consolidado.json')
[pscustomobject]@{
    BasesEmpresariales = @($databases).Count
    RegistrosActivosOrigen = $all.Count
    ProductosUnicos = @($consolidated).Count
    RegistrosDuplicadosEliminados = $all.Count - @($consolidated).Count
} | ConvertTo-Json | Set-Content -Encoding utf8 (Join-Path $outDir 'resumen.json')
