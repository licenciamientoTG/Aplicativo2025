-- PLD Prepago -- Query 3: Tarjetas fisicas de clientes de debito activas por periodo.
--
-- CORREGIDO (2a vuelta) tras error real al ejecutar:
--   - "Incorrect syntax near '$.ID'"       -> OPENJSON no es compatible en esta
--     instancia (nivel de compatibilidad probablemente anterior a SQL Server 2016).
--     Se reemplazo por una tabla de variable con VALUES, sin JSON.
--   - "Must declare the scalar variable @Estaciones" -> cada GO abre un batch
--     nuevo y las variables NO persisten entre batches. Se quitaron los GO de
--     en medio: todo el archivo corre como UN solo batch de principio a fin.
--     (Si quieres correr solo el paso 3a primero, selecciona esa seccion en el
--     editor y ejecuta solo la seleccion -- no hace falta GO para eso.)
--
-- SIGUE PENDIENTE (no lo pude confirmar sin volver a consultar en vivo -- ver 3a/3b):
--   1. Que estos 37 SERVIDOR (IP) esten dados de alta como linked server en el
--      servidor central con ese mismo nombre. Se verifica en el paso 3a.
--   2. El campo real que liga PrepagoTarjetas con Clientes (no hay codopr/codcli
--      visible en su esquema: solo codtar, nrotar, codlot, esttar y acumulados).
--   3. El valor de "esttar" que representa "activa".
-- El paso 3c queda con placeholders para 2 y 3; hay que confirmarlos en UNA
-- estacion real (ej. Clara o Jarudo) antes de descomentar el EXEC final.

DECLARE @Estaciones TABLE (ID INT, NOMBRE NVARCHAR(100), SERVIDOR NVARCHAR(50), BD NVARCHAR(100));
INSERT INTO @Estaciones (ID, NOMBRE, SERVIDOR, BD) VALUES
    (2,  N'Gemela Grande',        '192.168.7.101',  'SG12_41882020'),
    (3,  N'Aguascalientes',       '192.168.28.101', 'SG12_11007+2020'),
    (5,  N'Lerdo',                '192.168.2.101',  'SG12_114912020'),
    (6,  N'Lopez Mateos',         '192.168.5.101',  'SG12_25262020'),
    (7,  N'Gemela Chica',         '192.168.6.101',  'SG12_4179_20'),
    (8,  N'Municipio Libre',      '192.168.9.101',  'SG12_53172020'),
    (9,  N'Aztecas',              '192.168.10.101', 'SG12_5465'),
    (10, N'Misiones',             '192.168.11.101', 'SG12_6410'),
    (11, N'Puerto de palos',      '192.168.19.101', 'SG12_6947_2020'),
    (12, N'Miguel de la madrid',  '192.168.13.101', 'CG_7167'),
    (13, N'Permuta',              '192.168.14.101', 'SG12_8244'),
    (14, N'Electrolux',           '192.168.15.101', 'SG12_9191'),
    (15, N'Aeronautica',          '192.168.16.101', 'SG12_92352020'),
    (16, N'Custodia',             '192.168.17.101', 'SG12_98852020'),
    (17, N'Anapra',               '192.168.18.101', 'SG12_9893'),
    (18, N'Parral',               '192.168.4.101',  'sg2172'),
    (19, N'Delicias',             '192.168.3.101',  'CG_1376'),
    (21, N'Plutarco',             '192.168.8.101',  'Custodia5170'),
    (22, N'Tecnologico',          '192.168.30.101', 'CG_1163'),
    (23, N'Ejercito Nacional',    '192.168.21.101', 'CG_9733'),
    (24, N'Satelite',             '192.168.22.101', 'CG_4457'),
    (25, N'Las fuentes',          '192.168.23.101', 'cg_1159'),
    (26, N'Clara',                '192.168.24.101', 'CG_1156'),
    (27, N'Solis',                '192.168.25.101', 'CG_10141'),
    (28, N'Santiago Troncoso',    '192.168.26.101', 'SG12_12097'),
    (29, N'Jarudo',               '192.168.27.101', 'CG_1148'),
    (30, N'Hermanos Escobar',     '192.168.29.101', 'CG_23214'),
    (31, N'Villa Ahumada',        '192.168.32.101', 'CG_1242'),
    (32, N'El castano',           '192.168.33.101', 'CG_19190'),
    (33, N'Travel Center',        '192.168.31.101', 'CG_24938'),
    (34, N'Picachos',             '192.168.34.101', 'CG_24499'),
    (35, N'Ventanas',             '192.168.35.101', 'CG_24500'),
    (36, N'San Rafael',           '192.168.36.101', 'CG_14946'),
    (37, N'Puertecito',           '192.168.37.101', 'CG_15071'),
    (38, N'Jesus Maria',          '192.168.38.101', 'CG_15901'),
    (39, N'Gabriela Mistral',     '192.168.39.101', 'CG_12442'),
    (40, N'PRAXEDIS',             '192.168.40.101', 'E10702');


-- ============================================================================
-- 3a) Verificar cuales de las 37 estaciones SI estan dadas de alta como linked
-- server (comparando SERVIDOR contra sys.servers.name). Si salen filas en
-- "No", el UNION federado de 3b/3c fallara para esas con "server ... does not
-- exist" y hay que resolverlo antes (dar de alta el linked server, o cambiar de
-- estrategia a conexiones directas por aplicacion en vez de T-SQL federado).
-- ============================================================================
SELECT
    e.ID, e.NOMBRE, e.SERVIDOR, e.BD,
    CASE WHEN s.name IS NOT NULL THEN 'Si' ELSE 'No' END AS RegistradoComoLinkedServer
FROM @Estaciones e
LEFT JOIN sys.servers s ON s.name = e.SERVIDOR
ORDER BY e.NOMBRE;


-- ============================================================================
-- 3b) Conteo total de tarjetas por estado (esttar), por estacion -- ya con el
-- nombre de base correcto de cada una. Sirve para (i) confirmar que la tabla
-- si tiene datos en las estaciones reales y (ii) descubrir el catalogo real de
-- "esttar" (que valor es "activa"). Sin filtrar todavia por cliente de debito.
-- ============================================================================
DECLARE @sql NVARCHAR(MAX) = N'';

SELECT @sql = @sql +
    N'SELECT ' + QUOTENAME(e.NOMBRE, '''') + N' AS Estacion, esttar AS EstadoTarjeta, COUNT(*) AS Cantidad ' +
    N'FROM ' + QUOTENAME(e.SERVIDOR) + N'.' + QUOTENAME(e.BD) + N'.dbo.PrepagoTarjetas WITH (NOLOCK) ' +
    N'GROUP BY esttar ' +
    N'UNION ALL '
FROM @Estaciones e;

IF LEN(@sql) > 0
BEGIN
    SET @sql = LEFT(@sql, LEN(@sql) - LEN('UNION ALL '));
    EXEC sp_executesql @sql;
END


-- ============================================================================
-- 3c) PENDIENTE DE CONFIRMAR (no correr hasta resolver el join y el estado
-- "activa" -- ver advertencia al inicio del archivo): tarjetas de CLIENTES DE
-- DEBITO activas por periodo, federado contra las 37 estaciones con su base
-- real cada una.
-- ============================================================================
DECLARE @EstadoActivo INT = 1;              -- CONFIRMAR con el resultado de 3b
DECLARE @from  DATE = '2021-07-01';
DECLARE @until DATE = GETDATE();
DECLARE @sql2 NVARCHAR(MAX) = N'';

SELECT @sql2 = @sql2 +
    N'SELECT ' + QUOTENAME(e.NOMBRE, '''') + N' AS Estacion, COUNT(DISTINCT t.nrotar) AS TarjetasActivas ' +
    N'FROM ' + QUOTENAME(e.SERVIDOR) + N'.' + QUOTENAME(e.BD) + N'.dbo.PrepagoTarjetas t WITH (NOLOCK) ' +
    N'INNER JOIN ' + QUOTENAME(e.SERVIDOR) + N'.' + QUOTENAME(e.BD) + N'.dbo.Clientes c WITH (NOLOCK) ' +
    N'    ON t.<campo_de_enlace> = c.cod ' +      -- PENDIENTE DE CONFIRMAR
    N'WHERE t.esttar = @EstadoActivo ' +
    N'  AND c.tipval = 4 ' +
    N'UNION ALL '
FROM @Estaciones e;

IF LEN(@sql2) > 0
BEGIN
    SET @sql2 = LEFT(@sql2, LEN(@sql2) - LEN('UNION ALL '));
    -- EXEC sp_executesql @sql2, N'@EstadoActivo INT', @EstadoActivo = @EstadoActivo;
    PRINT @sql2; -- revisar el SQL generado antes de descomentar el EXEC de arriba
END
