/*
  Indice unico de uuid en payment_request_invoices: solo filas ACTIVAS.
  2026-11-25  --  NO CORRIDO. Revisar y correr a mano.

  Hoy:   idx_payment_request_invoices_uuid  UNIQUE NONCLUSTERED (uuid) WHERE uuid IS NOT NULL
  Queda: mismo indice, mismas columnas, con filtro  uuid IS NOT NULL AND is_deleted = 0

  Motivo: al borrar (soft-delete) un pago que incluia una nota de cargo con uuid, la fila
  borrada seguia ocupando el uuid y volver a cobrar la nota fallaba con error generico de BD.

  OJO: este cambio tambien afecta a las facturas normales (no solo a notas): un uuid de una
  fila borrada deja de bloquear nuevas filas con ese uuid.

  DESPUES de correrlo: quitar en _assets/models/PaymentRequestsModel.php el bloque
  "El índice único de uuid también cuenta filas borradas" (create_payment_with_invoices),
  porque ya no hará falta y seguiría bloqueando recobrar notas de pagos borrados.
*/
USE TG;
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

-- Pre-chequeo: no debe haber uuid repetido entre filas activas (si hay, el CREATE falla)
IF EXISTS (
    SELECT 1 FROM dbo.payment_request_invoices
    WHERE uuid IS NOT NULL AND is_deleted = 0
    GROUP BY uuid HAVING COUNT(*) > 1
)
BEGIN
    SELECT uuid, COUNT(*) AS filas
    FROM dbo.payment_request_invoices
    WHERE uuid IS NOT NULL AND is_deleted = 0
    GROUP BY uuid HAVING COUNT(*) > 1;
    RAISERROR('Hay uuid duplicados entre filas activas: corrige antes de recrear el indice.', 16, 1);
    SET NOEXEC ON;
END
GO

DROP INDEX idx_payment_request_invoices_uuid ON dbo.payment_request_invoices;
GO

CREATE UNIQUE NONCLUSTERED INDEX idx_payment_request_invoices_uuid
    ON dbo.payment_request_invoices (uuid)
    WHERE uuid IS NOT NULL AND is_deleted = 0;
GO

SET NOEXEC OFF;
GO
