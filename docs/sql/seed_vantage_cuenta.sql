-- docs/sql/seed_vantage_cuenta.sql
-- Alta de la cuenta de Vantage Bank en el catálogo, para que sus movimientos
-- agrupen bajo DIAZ GAS SA DE CV en el card "Saldo final por cuenta".
--
-- El AccountHistory de Vantage trae la cuenta ENMASCARADA a 4 dígitos (5577),
-- así se guarda en movimientos_bancarios y así debe estar aquí: el card hace
-- match exacto de CuentaLocal contra la cuenta del movimiento.
--
-- Divisa DOLAR AMERICANO: es un banco de EEUU. Sin esto sus saldos se sumarían
-- a la línea de pesos del panel, que es justo el defecto que se corrigió al
-- separar monedas.
--
-- Idempotente: se puede volver a correr sin duplicar.
--
-- Spec: docs/superpowers/specs/2026-07-28-tesoreria-saldos-por-empresa-design.md
USE TG;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias WHERE CuentaLocal = '5577')
    INSERT INTO dbo.CatalogosCuentasBancarias
        (FechaAlta, CuentaLocal, Descripcion, Banco, TitularCuenta, Tipo, Divisa, Activo, FechaRegistro)
    VALUES
        (GETDATE(), '5577', 'DIAZ GAS SA DE CV', 'VANTAGE', 'VANTAGE BANK 5577 DLLS',
         'Propias', 'DOLAR AMERICANO', 1, GETDATE());
GO
