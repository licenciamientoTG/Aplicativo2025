/*
  Estado F8 para diferencias de efectivo.
  Ejecutar conectado a la base TG. Es aditivo e idempotente.
*/
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
