/*
    Monitor de cambios de cortes para EFC ControlGas.
    Base: TG. Migracion aditiva e idempotente; ejecutar en DBeaver como script
    completo (sin separadores GO). No modifica datos ni objetos existentes.

    Despliegue: respaldar TG, revisar permisos y ejecutar este archivo completo.
    El proceso de escaneo debe actualizar snapshot e insertar eventos dentro de
    una transaccion; persistir estado/resumen de cada corrida y encolar correo.

    Rollback: detener primero los procesos que consumen estas tablas y conservar
    cualquier historial requerido. Eliminar solamente los objetos creados aqui,
    en orden: outbox, resultados, corridas, cambios, snapshot.
    La columna user_id del outbox usa 0 como identificador fijo del destinatario
    de correo; no se requiere una tabla de destinatarios configurables.
    No ejecutar DROP sobre tablas preexistentes. El rollback elimina los datos
    nuevos almacenados en estas tablas.
*/
USE [TG];

IF OBJECT_ID(N'dbo.efc_conc_cortes_snapshot', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.efc_conc_cortes_snapshot (
        estacion_id INT NOT NULL,
        fecha_operativa DATE NOT NULL,
        turno VARCHAR(20) NOT NULL,
        concepto VARCHAR(10) NOT NULL,
        importe_observado DECIMAL(19,4) NOT NULL,
        importe_aceptado DECIMAL(19,4) NOT NULL,
        observado_en DATETIME2(0) NOT NULL,
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_efc_cortes_snapshot_creado DEFAULT SYSDATETIME(),
        actualizado_en DATETIME2(0) NOT NULL CONSTRAINT DF_efc_cortes_snapshot_actualizado DEFAULT SYSDATETIME(),
        CONSTRAINT PK_efc_conc_cortes_snapshot PRIMARY KEY (estacion_id, fecha_operativa, turno, concepto),
        CONSTRAINT CK_efc_conc_cortes_snapshot_concepto CHECK (concepto IN ('MN','MORRALLA','USD'))
    );
END;

IF OBJECT_ID(N'dbo.efc_conc_cortes_cambios', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.efc_conc_cortes_cambios (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_efc_conc_cortes_cambios PRIMARY KEY,
        estacion_id INT NOT NULL,
        fecha_operativa DATE NOT NULL,
        turno VARCHAR(20) NOT NULL,
        concepto VARCHAR(10) NOT NULL,
        importe_anterior DECIMAL(19,4) NOT NULL,
        importe_nuevo DECIMAL(19,4) NOT NULL,
        detectado_en DATETIME2(0) NOT NULL CONSTRAINT DF_efc_cortes_cambios_detectado DEFAULT SYSDATETIME(),
        estado VARCHAR(12) NOT NULL CONSTRAINT DF_efc_cortes_cambios_estado DEFAULT ('PENDIENTE'),
        decidido_por INT NULL,
        decidido_en DATETIME2(0) NULL,
        grupo_id INT NULL,
        CONSTRAINT CK_efc_conc_cortes_cambios_estado CHECK (estado IN ('PENDIENTE','APROBADO','RECHAZADO','SUPERSEDIDO')),
        CONSTRAINT CK_efc_conc_cortes_cambios_concepto CHECK (concepto IN ('MN','MORRALLA','USD'))
    );
    CREATE INDEX IX_efc_conc_cortes_cambios_estado_detectado
        ON dbo.efc_conc_cortes_cambios (estado, detectado_en);
    CREATE INDEX IX_efc_conc_cortes_cambios_clave_detectado
        ON dbo.efc_conc_cortes_cambios (estacion_id, fecha_operativa, turno, concepto, detectado_en DESC);
    CREATE INDEX IX_efc_conc_cortes_cambios_grupo
        ON dbo.efc_conc_cortes_cambios (grupo_id) WHERE grupo_id IS NOT NULL;
END;

IF OBJECT_ID(N'dbo.efc_conc_cortes_runs', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.efc_conc_cortes_runs (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_efc_conc_cortes_runs PRIMARY KEY,
        iniciado_en DATETIME2(0) NOT NULL CONSTRAINT DF_efc_cortes_runs_inicio DEFAULT SYSDATETIME(),
        terminado_en DATETIME2(0) NULL,
        estado VARCHAR(12) NOT NULL CONSTRAINT DF_efc_conc_cortes_runs_estado DEFAULT ('EN_PROCESO'),
        estaciones_total INT NOT NULL CONSTRAINT DF_efc_conc_cortes_runs_total DEFAULT (0),
        estaciones_ok INT NOT NULL CONSTRAINT DF_efc_conc_cortes_runs_ok DEFAULT (0),
        estaciones_error INT NOT NULL CONSTRAINT DF_efc_conc_cortes_runs_error DEFAULT (0),
        cambios_detectados INT NOT NULL CONSTRAINT DF_efc_conc_cortes_runs_cambios DEFAULT (0),
        error_resumen NVARCHAR(2000) NULL,
        CONSTRAINT CK_efc_conc_cortes_runs_estado CHECK (estado IN ('EN_PROCESO','COMPLETADO','PARCIAL','ERROR'))
    );
    CREATE INDEX IX_efc_conc_cortes_runs_inicio ON dbo.efc_conc_cortes_runs (iniciado_en DESC);
END;

IF OBJECT_ID(N'dbo.efc_conc_cortes_runs_estaciones', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.efc_conc_cortes_runs_estaciones (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_efc_conc_cortes_runs_estaciones PRIMARY KEY,
        run_id BIGINT NOT NULL,
        estacion_id INT NOT NULL,
        iniciado_en DATETIME2(0) NOT NULL CONSTRAINT DF_efc_conc_cortes_runs_estaciones_inicio DEFAULT SYSDATETIME(),
        terminado_en DATETIME2(0) NULL,
        estado VARCHAR(12) NOT NULL CONSTRAINT DF_efc_conc_cortes_runs_estaciones_estado DEFAULT ('EN_PROCESO'),
        cambios_detectados INT NOT NULL CONSTRAINT DF_efc_conc_cortes_runs_estaciones_cambios DEFAULT (0),
        error_detalle NVARCHAR(2000) NULL,
        CONSTRAINT FK_efc_conc_cortes_runs_estaciones_run FOREIGN KEY (run_id) REFERENCES dbo.efc_conc_cortes_runs(id),
        CONSTRAINT UQ_efc_conc_cortes_runs_estaciones_run UNIQUE (run_id, estacion_id),
        CONSTRAINT CK_efc_conc_cortes_runs_estaciones_estado CHECK (estado IN ('EN_PROCESO','COMPLETADO','ERROR'))
    );
    CREATE INDEX IX_efc_conc_cortes_runs_estaciones_estacion ON dbo.efc_conc_cortes_runs_estaciones (estacion_id, iniciado_en DESC);
END;

IF OBJECT_ID(N'dbo.efc_conc_cortes_notificaciones', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.efc_conc_cortes_notificaciones (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_efc_conc_cortes_notificaciones PRIMARY KEY,
        change_id BIGINT NOT NULL,
        user_id INT NOT NULL,
        estado VARCHAR(12) NOT NULL CONSTRAINT DF_efc_conc_cortes_notificaciones_estado DEFAULT ('PENDIENTE'),
        intentos INT NOT NULL CONSTRAINT DF_efc_conc_cortes_notificaciones_intentos DEFAULT (0),
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_efc_conc_cortes_notificaciones_creado DEFAULT SYSDATETIME(),
        enviado_en DATETIME2(0) NULL,
        ultimo_intento_en DATETIME2(0) NULL,
        error_detalle NVARCHAR(2000) NULL,
        CONSTRAINT FK_efc_conc_cortes_notificaciones_cambio FOREIGN KEY (change_id) REFERENCES dbo.efc_conc_cortes_cambios(id),
        CONSTRAINT UQ_efc_conc_cortes_notificaciones_cambio_usuario UNIQUE (change_id, user_id),
        CONSTRAINT CK_efc_conc_cortes_notificaciones_estado CHECK (estado IN ('PENDIENTE','ENVIANDO','ENVIADO','ERROR')),
        CONSTRAINT CK_efc_conc_cortes_notificaciones_intentos CHECK (intentos >= 0)
    );
    CREATE INDEX IX_efc_conc_cortes_notificaciones_estado_creado ON dbo.efc_conc_cortes_notificaciones (estado, creado_en);
END;
