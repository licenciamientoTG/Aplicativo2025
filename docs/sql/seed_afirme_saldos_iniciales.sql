-- docs/sql/seed_afirme_saldos_iniciales.sql
-- Alta de las 4 cuentas de Afirme que tienen saldo pero aún no generan
-- movimientos exportables, para que aparezcan en el card "Saldo final por
-- cuenta" de /tesoreria/movimientos_bancos.
--
-- Saldos capturados de banca en línea Afirme al 2026-07-28.
-- Spec: docs/superpowers/specs/2026-07-28-tesoreria-saldos-por-empresa-design.md
--
-- El movimiento lleva cargo y abono en NULL a propósito: el card lee la
-- columna saldo, mientras que los KPI de Abonos/Cargos/Neto suman cargo y
-- abono. Así el saldo se ve sin inventar flujo que nunca ocurrió.
--
-- Idempotente: se puede volver a correr sin duplicar (guardas por CuentaLocal
-- y por huella UNIQUE).
--
-- Para revertir cuando lleguen los movimientos reales de estas cuentas:
--   DELETE FROM TG.dbo.movimientos_bancarios
--    WHERE archivo_origen = 'ALTA MANUAL 2026-07-28';
USE TG;
GO

BEGIN TRANSACTION;
GO

-- 1. Catálogo: las 3 cuentas que solo existían como CLABE de 18 dígitos y
--    marcadas Terceros. No se tocan esos registros (2819 / 2822 / 2824): el
--    layout de pagos los usa para dispersar. Se dan de alta como Propias con
--    el número corto, que es lo que trae el export de movimientos.
--    177129372 ya existe como Propias (Id 3343), por eso no aparece aquí.
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '177122211')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '177122211', 'SERVICIO EL JARUDO SA DE CV', 'AFIRME', 'LIDER PYME 2211',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '177126713')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '177126713', 'DISTRIBUIDORA CLARA SA DE CV', 'AFIRME', 'LIDER PYME 6713',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '177129399')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '177129399', 'DISTRIBUIDORA GASOMEX SA DE CV', 'AFIRME', 'LIDER PYME 9399',
         'Propias', 'NUEVO PESO MEXICANO', 1, GETDATE());
GO
-- 2. Movimientos: una fila "SALDO INICIAL" por cuenta. cargo/abono NULL.
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = 'c3e8e6c0f47830164fb8e03f00f44685b192bf19')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('AFIRME', '177122211', '2026-07-28', 'SALDO INICIAL', NULL, NULL, 9535.60,
         '', '', '',
         'Saldo capturado de banca en linea; la cuenta aun no genera movimientos exportables',
         'c3e8e6c0f47830164fb8e03f00f44685b192bf19', 'ALTA MANUAL 2026-07-28');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = 'd137f070fbf9c6a8c10794163716bebc49aa1092')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('AFIRME', '177126713', '2026-07-28', 'SALDO INICIAL', NULL, NULL, 9535.60,
         '', '', '',
         'Saldo capturado de banca en linea; la cuenta aun no genera movimientos exportables',
         'd137f070fbf9c6a8c10794163716bebc49aa1092', 'ALTA MANUAL 2026-07-28');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = 'a5eabfe6bf7f58714f88fbd541e01aa1235906b3')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('AFIRME', '177129372', '2026-07-28', 'SALDO INICIAL', NULL, NULL, 10000.00,
         '', '', '',
         'Saldo capturado de banca en linea; la cuenta aun no genera movimientos exportables',
         'a5eabfe6bf7f58714f88fbd541e01aa1235906b3', 'ALTA MANUAL 2026-07-28');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.movimientos_bancarios WHERE huella = 'dc1b53402dc7ea1c029419fbc2796848827bc326')
    INSERT INTO dbo.movimientos_bancarios
        (banco, cuenta, fecha, descripcion, cargo, abono, saldo,
         banco_contraparte, cuenta_contraparte, nombre_contraparte,
         descripcion_larga, huella, archivo_origen)
    VALUES
        ('AFIRME', '177129399', '2026-07-28', 'SALDO INICIAL', NULL, NULL, 9535.60,
         '', '', '',
         'Saldo capturado de banca en linea; la cuenta aun no genera movimientos exportables',
         'dc1b53402dc7ea1c029419fbc2796848827bc326', 'ALTA MANUAL 2026-07-28');
GO
COMMIT TRANSACTION;
GO
