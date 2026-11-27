/* Agrega las ubicaciones Soporte 01 y Soporte 02 al inventario UROVO.
   Ejecutar en TG antes de desplegar la versión que permite registrar estos estados. */
USE [TG];
GO

ALTER TABLE [dbo].[inv_urovo_terminales]
    DROP CONSTRAINT [CK_inv_urovo_terminales_ubicacion];
ALTER TABLE [dbo].[inv_urovo_terminales] WITH CHECK
    ADD CONSTRAINT [CK_inv_urovo_terminales_ubicacion]
    CHECK ([ubicacion_tipo] IN ('estacion','almacen','soporte_01','soporte_02','reparacion','perdida','baja_definitiva'));
GO

ALTER TABLE [dbo].[inv_urovo_movimientos]
    DROP CONSTRAINT [CK_urovo_mov_origen];
ALTER TABLE [dbo].[inv_urovo_movimientos] WITH CHECK
    ADD CONSTRAINT [CK_urovo_mov_origen]
    CHECK ([ubicacion_origen_tipo] IS NULL OR [ubicacion_origen_tipo] IN ('estacion','almacen','soporte_01','soporte_02','reparacion','perdida','baja_definitiva'));
GO

ALTER TABLE [dbo].[inv_urovo_movimientos]
    DROP CONSTRAINT [CK_urovo_mov_destino];
ALTER TABLE [dbo].[inv_urovo_movimientos] WITH CHECK
    ADD CONSTRAINT [CK_urovo_mov_destino]
    CHECK ([ubicacion_destino_tipo] IS NULL OR [ubicacion_destino_tipo] IN ('estacion','almacen','soporte_01','soporte_02','reparacion','perdida','baja_definitiva'));
GO
