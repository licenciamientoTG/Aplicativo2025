-- Resuelve 29 de las 31 solicitudes importadas del Excel SPEI que quedaron Pendientes (decisiones del usuario 2026-11-17).
-- Requiere docs/sql/alta_cuenta_soft_delete.sql. Usuario: alejandro.martinez (6296).
--  A) 12 duplicadas con CLABE mal capturada (la correcta ya está en el catálogo) -> borrado lógico.
--  B) 11 proveedores extranjeros sin banco/cuenta/routing completos -> borrado lógico.
--  C) 5 cuentas propias: 3 de DIAZ GAS se ligan a su cuenta existente (se completa Clave = CLABE);
--     ZAIDENERGY y GASOMEX se crean como Propias (emp_cod 105 y 16).
--  D) #12832: Excel perdió el 0 inicial de la CLABE (única corrección válida) -> se corrige y se aprueba como Terceros.
--  Se quedan Pendientes: #11396 ISPAVON (17 dígitos, 15 CLABE posibles) y #12291 GRUAS PESA (dígito verificador mal).
SET NOCOUNT ON; SET XACT_ABORT ON;
USE [TG];
BEGIN TRAN;

DECLARE @uid INT = (SELECT Id FROM dbo.Usuario WHERE Id = 6296 AND Usuario = 'alejandro.martinez');
IF @uid IS NULL THROW 50001, 'No existe el usuario alejandro.martinez (Id 6296).', 1;

-- A) Duplicadas: solicitud -> cuenta correcta del catálogo
DECLARE @dup TABLE (id INT PRIMARY KEY, cat_id INT);
INSERT INTO @dup VALUES (9801, 506), (10735, 4014), (10782, 1235), (11159, 1623), (11774, 2188), (11779, 2187),
                        (12013, 2405), (12112, 2476), (12299, 2667), (12598, 2944), (12750, 3082), (12802, 3158);
UPDATE r SET is_deleted = 1, deleted_at = GETDATE(), deleted_by = @uid,
       comentario = LEFT(ISNULL(r.comentario, N'') + N'; ELIMINADA: CLABE mal capturada, la cuenta correcta es la #' + CAST(d.cat_id AS NVARCHAR(10)) + N' del catálogo', 400)
  FROM dbo.bank_account_requests r JOIN @dup d ON d.id = r.id
 WHERE r.status = 1 AND r.is_deleted = 0;

-- B) Extranjeras sin datos bancarios completos
UPDATE dbo.bank_account_requests SET is_deleted = 1, deleted_at = GETDATE(), deleted_by = @uid,
       comentario = LEFT(ISNULL(comentario, N'') + N'; ELIMINADA: proveedor extranjero sin banco, cuenta y routing/SWIFT completos en el Excel; capturar de nuevo', 400)
 WHERE id IN (11885, 12833, 12809, 12815, 12930, 12950, 12955, 12961, 12964, 13015, 13159)
   AND status = 1 AND is_deleted = 0;

-- C1) DIAZ GAS: la CLABE es de una cuenta que ya existe (la cuenta va dentro de la CLABE) -> completar Clave y ligar
DECLARE @liga TABLE (id INT PRIMARY KEY, cat_id INT);
INSERT INTO @liga VALUES (11576, 3), (12606, 2950), (12934, 3267);
UPDATE c SET Clave = COALESCE(NULLIF(c.Clave, ''), r.clabe), Activo = 1, FechaModificacion = GETDATE(), UsuarioModificacion = @uid
  FROM dbo.CatalogosCuentasBancarias c JOIN @liga l ON l.cat_id = c.Id JOIN dbo.bank_account_requests r ON r.id = l.id;
UPDATE r SET status = 2, decided_by = @uid, decided_at = GETDATE(), fecha_alta = CAST(GETDATE() AS DATE), catalogo_cuenta_id = l.cat_id,
       comentario = LEFT(ISNULL(r.comentario, N'') + N'; aprobada 2026-11-17: cuenta propia ya registrada, se ligó a la #' + CAST(l.cat_id AS NVARCHAR(10)), 400)
  FROM dbo.bank_account_requests r JOIN @liga l ON l.id = r.id
 WHERE r.status = 1 AND r.is_deleted = 0;

-- C2) ZAIDENERGY y GASOMEX: nuevas cuentas Propias
DECLARE @propias TABLE (id INT PRIMARY KEY, emp INT);
INSERT INTO @propias VALUES (12652, 105), (12194, 16);
DECLARE @altaP TABLE (cat_id INT, cuenta VARCHAR(50));
INSERT INTO dbo.CatalogosCuentasBancarias
    (FechaAlta, CuentaLocal, Clave, Descripcion, TipoCuenta, Banco, Divisa, TitularCuenta, Tipo, emp_cod, Activo, FechaRegistro, UsuarioRegistro)
OUTPUT inserted.Id, inserted.CuentaLocal INTO @altaP
SELECT CAST(GETDATE() AS DATE), r.num_cuenta, r.clabe, e.den, 'Otros Bancos Nacionales', r.banco, 'NUEVO PESO MEXICANO',
       e.den, 'Propias', p.emp, 1, GETDATE(), CAST(@uid AS VARCHAR(20))
  FROM dbo.bank_account_requests r JOIN @propias p ON p.id = r.id JOIN dbo.razones_sociales_pago e ON e.cod = p.emp
 WHERE r.status = 1 AND r.is_deleted = 0
   AND NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias c WHERE c.CuentaLocal = r.num_cuenta OR c.Clave = r.num_cuenta);
UPDATE r SET status = 2, decided_by = @uid, decided_at = GETDATE(), fecha_alta = CAST(GETDATE() AS DATE), catalogo_cuenta_id = a.cat_id,
       comentario = LEFT(ISNULL(r.comentario, N'') + N'; aprobada 2026-11-17 como cuenta Propia', 400)
  FROM dbo.bank_account_requests r JOIN @altaP a ON a.cuenta = r.num_cuenta JOIN @propias p ON p.id = r.id;

-- D) #12832: CLABE sin el 0 inicial
UPDATE dbo.bank_account_requests SET num_cuenta = '012180001104885342', clabe = '012180001104885342',
       comentario = LEFT(ISNULL(comentario, N'') + N'; CLABE corregida (el Excel perdió el 0 inicial)', 400)
 WHERE id = 12832 AND num_cuenta = '12180001104885342' AND status = 1 AND is_deleted = 0;
DECLARE @altaT TABLE (cat_id INT, cuenta VARCHAR(50));
INSERT INTO dbo.CatalogosCuentasBancarias
    (FechaAlta, CuentaLocal, Clave, Descripcion, TipoCuenta, Banco, Divisa, TitularCuenta, Tipo, Direccion, Activo, FechaRegistro, UsuarioRegistro)
OUTPUT inserted.Id, inserted.CuentaLocal INTO @altaT
SELECT CAST(GETDATE() AS DATE), r.num_cuenta, r.clabe, r.proveedor, 'Otros Bancos Nacionales', r.banco, 'NUEVO PESO MEXICANO',
       r.proveedor, 'Terceros', NULLIF(r.benef_direccion, ''), 1, GETDATE(), CAST(@uid AS VARCHAR(20))
  FROM dbo.bank_account_requests r
 WHERE r.id = 12832 AND r.status = 1 AND r.is_deleted = 0 AND r.clabe = '012180001104885342'
   AND NOT EXISTS (SELECT 1 FROM dbo.CatalogosCuentasBancarias c WHERE c.CuentaLocal = r.clabe OR c.Clave = r.clabe);
UPDATE r SET status = 2, decided_by = @uid, decided_at = GETDATE(), fecha_alta = CAST(GETDATE() AS DATE), catalogo_cuenta_id = a.cat_id
  FROM dbo.bank_account_requests r JOIN @altaT a ON a.cuenta = r.num_cuenta WHERE r.id = 12832;
INSERT INTO dbo.bank_account_payers (catalogo_cuenta_id, emp_cod, created_by)
SELECT a.cat_id, p.emp_cod, @uid FROM @altaT a JOIN dbo.bank_account_request_payers p ON p.request_id = 12832
 WHERE NOT EXISTS (SELECT 1 FROM dbo.bank_account_payers x WHERE x.catalogo_cuenta_id = a.cat_id AND x.emp_cod = p.emp_cod);

-- Resultado
SELECT CASE WHEN is_deleted = 1 THEN 'borrada' WHEN status = 2 THEN 'aprobada' WHEN status = 1 THEN 'pendiente' ELSE CAST(status AS VARCHAR(5)) END AS estado,
       COUNT(*) AS n
  FROM dbo.bank_account_requests
 WHERE id IN (9801,10735,10782,11159,11774,11779,12013,12112,12299,12598,12750,12802,
              11885,12833,12809,12815,12930,12950,12955,12961,12964,13015,13159,
              11576,12606,12934,12652,12194,12832,11396,12291)
 GROUP BY CASE WHEN is_deleted = 1 THEN 'borrada' WHEN status = 2 THEN 'aprobada' WHEN status = 1 THEN 'pendiente' ELSE CAST(status AS VARCHAR(5)) END;
SELECT r.id, r.proveedor, r.status, r.catalogo_cuenta_id, c.Tipo, c.emp_cod, c.CuentaLocal, c.Clave
  FROM dbo.bank_account_requests r JOIN dbo.CatalogosCuentasBancarias c ON c.Id = r.catalogo_cuenta_id
 WHERE r.id IN (11576, 12606, 12934, 12652, 12194, 12832);

COMMIT;
