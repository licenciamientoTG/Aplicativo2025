-- Petrotal (razon social 102) paga desde Santander 65506799486 (Id 30, antes sin emp_cod).
-- Layout desde texto toma la cuenta propia por emp_cod + banco.
UPDATE TG.dbo.CatalogosCuentasBancarias SET emp_cod = 102 WHERE Id = 30 AND Tipo = 'Propias' AND CuentaLocal = '65506799486' AND emp_cod IS NULL;
