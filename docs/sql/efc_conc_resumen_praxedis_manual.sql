IF OBJECT_ID('dbo.efc_conc_resumen_praxedis_manual','U') IS NULL
BEGIN
    CREATE TABLE dbo.efc_conc_resumen_praxedis_manual (
        estacion_id INT NOT NULL CONSTRAINT DF_efc_conc_resumen_praxedis_estacion DEFAULT 40,
        anio SMALLINT NOT NULL,
        mes TINYINT NOT NULL,
        venta_mn DECIMAL(19,2) NOT NULL,
        venta_morralla DECIMAL(19,2) NOT NULL,
        venta_total_mn DECIMAL(19,2) NOT NULL,
        depositos_mes_mn DECIMAL(19,2) NOT NULL,
        deposito_transito_mn DECIMAL(19,2) NOT NULL,
        total_comprobado DECIMAL(19,2) NOT NULL,
        diferencia DECIMAL(19,2) NOT NULL,
        venta_pesos_usd DECIMAL(19,2) NOT NULL,
        ventas_usd DECIMAL(19,2) NOT NULL,
        depositos_usd DECIMAL(19,2) NOT NULL,
        transito_usd DECIMAL(19,2) NOT NULL,
        venta_total_mn_global DECIMAL(19,2) NOT NULL,
        saldo_inicial DECIMAL(19,2) NOT NULL,
        actualizado_por INT NULL,
        actualizado_en DATETIME2 NOT NULL CONSTRAINT DF_efc_conc_resumen_praxedis_actualizado DEFAULT SYSDATETIME(),
        CONSTRAINT PK_efc_conc_resumen_praxedis_manual PRIMARY KEY(estacion_id,anio,mes),
        CONSTRAINT CK_efc_conc_resumen_praxedis_station CHECK(estacion_id=40),
        CONSTRAINT CK_efc_conc_resumen_praxedis_period CHECK(anio BETWEEN 2020 AND 2100 AND mes BETWEEN 1 AND 12)
    );
END;
