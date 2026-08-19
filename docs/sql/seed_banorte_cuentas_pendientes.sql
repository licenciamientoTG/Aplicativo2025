-- docs/sql/seed_banorte_cuentas_pendientes.sql
-- Alta de las cuentas Banorte de "cuentas pendientes banorte.csv" que tienen
-- saldo pero llevan mucho tiempo sin generar movimientos exportables, para
-- que aparezcan en el mapa de estados de cuenta de /tesoreria/movimientos_bancos
-- (paneles "Saldo final por cuenta" y "por grupo").
--
-- Mismo patrón que seed_afirme_saldos_iniciales.sql y seed_vantage_cuenta.sql:
-- alta en el catálogo + un movimiento "SALDO INICIAL" con cargo/abono NULL.
--
-- CuentaLocal = cuenta corta (columna CUENTA del export), NO la CLABE de 18
-- dígitos: así es como parse_banorte_cheques_csv() guarda "cuenta" al
-- importar movimientos reales, así que si algún día esta cuenta empieza a
-- generarlos, van a coincidir con este alta en vez de crear una cuenta
-- paralela (el mismo problema que tuvimos con Bankaool 000003/00000369).
--
-- Saldos capturados de banca en línea Banorte al 2026-08-17.
--
-- Idempotente: se puede volver a correr sin duplicar (guardas por
-- CuentaLocal y por huella UNIQUE).
--
-- Para revertir cuando lleguen los movimientos reales de estas cuentas:
--   DELETE FROM TG.dbo.movimientos_bancarios
--    WHERE archivo_origen = 'ALTA MANUAL 2026-08-17';
USE TG;
GO

BEGIN TRANSACTION;
GO

-- 1. Catálogo
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '1156571752')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '1156571752', 'ZAIDENERGY SC', 'BANORTE', 'BANORTE 1156571752',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '1287899181')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '1287899181', 'GRUPO OPERADOR GASOLINERO TSA DEL CENTRO SA DE CV', 'BANORTE', 'BANORTE 1287899181',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '1212598226')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '1212598226', 'AQUA CAR CLUB SA DE CV', 'BANORTE', 'BANORTE 1212598226',
         'Propias', 'DOLAR AMERICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '1360692913')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '1360692913', 'CRISTAL PURE BY TOTAL GAS SA DE CV', 'BANORTE', 'BANORTE 1360692913',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '1360693107')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '1360693107', 'CRISTAL PURE BY TOTAL GAS SA DE CV', 'BANORTE', 'BANORTE 1360693107',
         'Propias', 'DOLAR AMERICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '1374059764')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '1374059764', 'DIAZ GAS SA DE CV', 'BANORTE', 'BANORTE 1374059764',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '1155446741')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '1155446741', 'DIAZ GAS SA DE CV', 'BANORTE', 'BANORTE 1155446741',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '0306907391')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '0306907391', 'DIAZ GAS SA DE CV', 'BANORTE', 'BANORTE 0306907391',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '0835923435')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '0835923435', 'DIAZ GAS SA DE CV', 'BANORTE', 'BANORTE 0835923435',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '0835923426')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '0835923426', 'DIAZ GAS SA DE CV', 'BANORTE', 'BANORTE 0835923426',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '0306913691')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '0306913691', 'ESTACION CUSTODIA SA DE CV', 'BANORTE', 'BANORTE 0306913691',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '1060894101')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '1060894101', 'ESTACION CUSTODIA SA DE CV', 'BANORTE', 'BANORTE 1060894101',
         'Propias', 'DOLAR AMERICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '0412991330')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '0412991330', 'FELINOS INVESTMENTS S DE RL DE CV', 'BANORTE', 'BANORTE 0412991330',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '1197347651')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '1197347651', 'INMO DIAZ SA DE CV', 'BANORTE', 'BANORTE 1197347651',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '1355503154')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '1355503154', 'PETROTAL SA DE CV', 'BANORTE', 'BANORTE 1355503154',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '1355503257')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '1355503257', 'PETROTAL SA DE CV', 'BANORTE', 'BANORTE 1355503257',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '1355503435')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '1355503435', 'PETROTAL SA DE CV', 'BANORTE', 'BANORTE 1355503435',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '1355503574')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '1355503574', 'PETROTAL SA DE CV', 'BANORTE', 'BANORTE 1355503574',
         'Propias', 'DOLAR AMERICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '1355503583')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '1355503583', 'PETROTAL SA DE CV', 'BANORTE', 'BANORTE 1355503583',
         'Propias', 'DOLAR AMERICANO', 1, GETDATE());
GO

-- 2. Movimientos: una fila "SALDO INICIAL" por cuenta. cargo/abono NULL.
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = 'c85c59484b36661eab8d2565f9a83a7e947dbcd3')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '1156571752', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 10000.00,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (ZAIDENERGY SC); la cuenta aun no genera movimientos exportables',
         'c85c59484b36661eab8d2565f9a83a7e947dbcd3', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = '1a89ea341c1fbc293c36b1789ead9f559bea477c')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '1287899181', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 10000.00,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (GRUPO OPERADOR GASOLINERO TSA DEL CENTRO SA DE CV); la cuenta aun no genera movimientos exportables',
         '1a89ea341c1fbc293c36b1789ead9f559bea477c', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = 'ff8aa1f25738f533816ad54ffa3ef2a4ed1518f9')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '1212598226', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 1249.20,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (AQUA CAR CLUB SA DE CV); la cuenta aun no genera movimientos exportables',
         'ff8aa1f25738f533816ad54ffa3ef2a4ed1518f9', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = '24e45aff7e41c321c273151a3c34a8ae772f5b63')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '1360692913', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 10862.80,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (CRISTAL PURE BY TOTAL GAS SA DE CV); la cuenta aun no genera movimientos exportables',
         '24e45aff7e41c321c273151a3c34a8ae772f5b63', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = '4937e95b9afcdd7875eb4a5a77ed3cacabf3f2f9')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '1360693107', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 1200.40,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (CRISTAL PURE BY TOTAL GAS SA DE CV); la cuenta aun no genera movimientos exportables',
         '4937e95b9afcdd7875eb4a5a77ed3cacabf3f2f9', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = '64913a188fce05c656af85ee0cdf8f4d2c70d5bc')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '1374059764', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 9547.60,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (DIAZ GAS SA DE CV); la cuenta aun no genera movimientos exportables',
         '64913a188fce05c656af85ee0cdf8f4d2c70d5bc', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = '5dc4035ca76f02179843dec7577391b82f4785a3')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '1155446741', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 10547.60,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (DIAZ GAS SA DE CV); la cuenta aun no genera movimientos exportables',
         '5dc4035ca76f02179843dec7577391b82f4785a3', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = '9aa4b21f3a2a0b2f88050f5dc2480f5d59605965')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '0306907391', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 10067.13,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (DIAZ GAS SA DE CV); la cuenta aun no genera movimientos exportables',
         '9aa4b21f3a2a0b2f88050f5dc2480f5d59605965', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = 'e313ec3ce7d9dc90e5192fdce12db2fc99ee5e8c')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '0835923435', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 9999.88,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (DIAZ GAS SA DE CV); la cuenta aun no genera movimientos exportables',
         'e313ec3ce7d9dc90e5192fdce12db2fc99ee5e8c', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = 'b5aa0b0b9dc6ed8ee841901fe95e7231502638fb')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '0835923426', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 0.00,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (DIAZ GAS SA DE CV); la cuenta aun no genera movimientos exportables',
         'b5aa0b0b9dc6ed8ee841901fe95e7231502638fb', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = '02dd6eb4358b0419898b2e0eae1ca385bc438a5e')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '0306913691', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 10065.93,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (ESTACION CUSTODIA SA DE CV); la cuenta aun no genera movimientos exportables',
         '02dd6eb4358b0419898b2e0eae1ca385bc438a5e', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = '48d69688da9af19c7d47d71fac098d8a23cbab9e')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '1060894101', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 1200.00,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (ESTACION CUSTODIA SA DE CV); la cuenta aun no genera movimientos exportables',
         '48d69688da9af19c7d47d71fac098d8a23cbab9e', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = '367761b053c83eb7a366e1d495b5433edf31ce1f')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '0412991330', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 41216.75,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (FELINOS INVESTMENTS S DE RL DE CV); la cuenta aun no genera movimientos exportables',
         '367761b053c83eb7a366e1d495b5433edf31ce1f', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = 'fa27277d7fd522bc3b3198c8f3390b9d596076ab')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '1197347651', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 10098.73,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (INMO DIAZ SA DE CV); la cuenta aun no genera movimientos exportables',
         'fa27277d7fd522bc3b3198c8f3390b9d596076ab', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = '7345be07c2686c79c6a8517ceddeed02a1998ac3')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '1355503154', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 10561.70,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (PETROTAL SA DE CV); la cuenta aun no genera movimientos exportables',
         '7345be07c2686c79c6a8517ceddeed02a1998ac3', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = '408b35337a64e9e5b747a403b2272cba114d93da')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '1355503257', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 9972.20,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (PETROTAL SA DE CV); la cuenta aun no genera movimientos exportables',
         '408b35337a64e9e5b747a403b2272cba114d93da', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = '45c7cbe6fc2387b339e96cee84c23c192848b9a7')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '1355503435', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 10547.60,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (PETROTAL SA DE CV); la cuenta aun no genera movimientos exportables',
         '45c7cbe6fc2387b339e96cee84c23c192848b9a7', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = 'fb2ddd337fd633177b63ffdb71dc635a9a313e83')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '1355503574', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 1259.40,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (PETROTAL SA DE CV); la cuenta aun no genera movimientos exportables',
         'fb2ddd337fd633177b63ffdb71dc635a9a313e83', 'ALTA MANUAL 2026-08-17');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = '85de5cf5f87189e0f0a0b88fcd263c91c50dcd9d')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('BANORTE', '1355503583', '2026-08-17', 'SALDO INICIAL', NULL, NULL, 1215.60,
         '', '', '',
         'Saldo capturado de banca en linea Banorte (PETROTAL SA DE CV); la cuenta aun no genera movimientos exportables',
         '85de5cf5f87189e0f0a0b88fcd263c91c50dcd9d', 'ALTA MANUAL 2026-08-17');
GO

COMMIT TRANSACTION;
GO
