/*
  Estado F8 para diferencias de efectivo.
  Cambia a TG explícitamente; puede ejecutarse aunque DBeaver esté en master.
  Es aditivo e idempotente.
*/
USE [TG];

IF OBJECT_ID(N'dbo.efc_conc_diferencias_captura', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.efc_conc_diferencias_captura (
        estacion_id INT NOT NULL,
        fecha_operativa DATE NOT NULL,
        turno VARCHAR(20) NOT NULL,
        concepto VARCHAR(10) NOT NULL,
        capturado BIT NOT NULL,
        creado_por INT NULL,
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_efc_conc_dif_captura_creado_en DEFAULT SYSDATETIME(),
        actualizado_por INT NULL,
        actualizado_en DATETIME2(0) NULL,
        CONSTRAINT PK_efc_conc_diferencias_captura PRIMARY KEY (estacion_id, fecha_operativa, turno, concepto),
        CONSTRAINT CK_efc_conc_dif_captura_concepto CHECK (concepto IN ('MN','MORRALLA','USD'))
    );
END;

IF COL_LENGTH(N'dbo.efc_conc_diferencias_captura', N'requiere_actualizacion') IS NULL
BEGIN
    ALTER TABLE dbo.efc_conc_diferencias_captura
        ADD requiere_actualizacion BIT NOT NULL
            CONSTRAINT DF_efc_conc_dif_captura_requiere_actualizacion DEFAULT (0) WITH VALUES;
END;

/*
  F8 triestado: no_aplica distingue "No aplica" de "Sin captura".
  Compatible con registros existentes: todos reciben 0 y capturado no cambia.
  Alcance: base TG. La columna nueva es aditiva; no modifica otros valores.

  Rollback: revertir primero el código que usa no_aplica. Para evitar perder
  selecciones realizadas, dejar la columna instalada (la app anterior la ignora).
  Solo si se requiere eliminarla, respaldar sus valores y después ejecutar:
  ALTER TABLE dbo.efc_conc_diferencias_captura
      DROP CONSTRAINT DF_efc_conc_dif_captura_no_aplica;
  ALTER TABLE dbo.efc_conc_diferencias_captura DROP COLUMN no_aplica;
*/
IF COL_LENGTH(N'dbo.efc_conc_diferencias_captura', N'no_aplica') IS NULL
BEGIN
    ALTER TABLE dbo.efc_conc_diferencias_captura
        ADD no_aplica BIT NOT NULL
            CONSTRAINT DF_efc_conc_dif_captura_no_aplica DEFAULT (0) WITH VALUES;
END;
