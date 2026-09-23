-- Cola de propagación de estatus de responsables hacia las 37 BDs de
-- estación (linked servers). deactivate_responsable() ya no espera a que
-- las 37 UPDATEs remotos terminen antes de responder: solo actualiza la
-- copia central en SG12 y encola aquí; cron/responsables_sync_queue.php
-- procesa la cola en segundo plano vía ResponsablesModel::process_sync_queue().
USE [TG]
GO

CREATE TABLE [dbo].[responsables_sync_queue] (
    id              INT            IDENTITY(1,1) PRIMARY KEY,
    cod             INT            NOT NULL,                     -- SG12.dbo.Responsables.cod
    hab             INT            NOT NULL,                     -- valor destino (0/1)
    estado          VARCHAR(10)    NOT NULL DEFAULT 'pendiente', -- pendiente | ok | error | superada
    intentos        INT            NOT NULL DEFAULT 0,
    fecha_creacion  DATETIME       NOT NULL DEFAULT GETDATE(),
    fecha_procesado DATETIME       NULL,
    detalle_error   NVARCHAR(1000) NULL
);
GO

CREATE INDEX IX_responsables_sync_queue_estado ON [dbo].[responsables_sync_queue] (estado);
GO
