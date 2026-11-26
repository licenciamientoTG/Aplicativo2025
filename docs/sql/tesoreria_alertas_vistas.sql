-- Tesorería > Movimientos bancos: "visto por usuario" de las alertas del módulo.
-- Hoy solo la alerta "Hacienda te devuelve" (tipo = 'hacienda_devuelve').
-- Guarda, por usuario y tipo de alerta, el id del último movimiento (id de
-- movimientos_bancarios) que ese usuario ya vio al abrir el modal; lo nuevo es
-- todo movimiento coincidente con id mayor.
-- Seguro de correr varias veces. Base: TG.
USE TG;
GO

IF OBJECT_ID('dbo.movimientos_alertas_vistas') IS NULL
BEGIN
    CREATE TABLE dbo.movimientos_alertas_vistas (
        usuario_id       INT          NOT NULL,           -- $_SESSION['tg_user']['Id']
        tipo             NVARCHAR(40) NOT NULL,           -- 'hacienda_devuelve'
        ultimo_id_visto  INT          NOT NULL DEFAULT 0, -- movimientos_bancarios.id
        actualizado      DATETIME     NOT NULL DEFAULT GETDATE(),
        CONSTRAINT PK_movimientos_alertas_vistas PRIMARY KEY (usuario_id, tipo)
    );
END
GO
