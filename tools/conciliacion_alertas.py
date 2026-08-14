"""Actualiza la caché de alertas V3. Escribe únicamente en la base TG."""
import json
import os
from datetime import datetime

import pyodbc

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
with open(os.path.join(BASE_DIR, "config.json"), encoding="utf-8") as config_file:
    CONFIG = json.load(config_file)

CONNECTION = (
    "DRIVER={" + CONFIG["driver"] + "};"
    "SERVER=" + CONFIG["server"] + ";DATABASE=TG;"
    "UID=" + CONFIG["user"] + ";PWD=" + CONFIG["password"] + ";"
    "TrustServerCertificate=yes;"
)


def conceptos_validos(descripcion, conceptos):
    palabras = [palabra.strip().upper() for palabra in (conceptos or "").split(",") if palabra.strip()]
    return not palabras or any(palabra in (descripcion or "").upper() for palabra in palabras)


def asegurar_tabla(cursor):
    cursor.execute("""
        IF OBJECT_ID('dbo.CV3_AlertasCambios', 'U') IS NULL
        CREATE TABLE dbo.CV3_AlertasCambios (
            detalle_id INT NOT NULL,
            origen VARCHAR(10) NOT NULL,
            grupo_id INT NOT NULL,
            estacion_id INT NOT NULL,
            estacion NVARCHAR(200) NULL,
            banco NVARCHAR(100) NULL,
            afiliacion NVARCHAR(200) NULL,
            fecha_operativa DATE NULL,
            monto_guardado DECIMAL(18,2) NOT NULL,
            monto_actual DECIMAL(18,2) NOT NULL,
            diferencia DECIMAL(18,2) NOT NULL,
            detectado_en DATETIME NOT NULL DEFAULT GETDATE(),
            CONSTRAINT PK_CV3_AlertasCambios PRIMARY KEY (detalle_id, origen)
        );
    """)


def tablas_existentes(cursor, tablas):
    placeholders = ",".join("?" for _ in tablas)
    rows = cursor.execute(
        f"SELECT table_name FROM information_schema.tables WHERE table_schema='dbo' AND table_name IN ({placeholders})",
        tablas,
    ).fetchall()
    return {row[0] for row in rows}


def leer_alertas_fuente(cursor, entidad_id, banco, fuentes, comisiones_amex=False):
    """Compara detalles con las mismas tablas que utiliza la vista por banco."""
    disponibles = tablas_existentes(cursor, [tabla for tabla, _ in fuentes])
    consultas = [sql for tabla, sql in fuentes if tabla in disponibles]
    if not consultas:
        return []

    fuente_sql = "\nUNION ALL\n".join(consultas)
    extra_amex = """
        + ISNULL((SELECT SUM(a.comision + a.iva)
                    FROM AMEX_Envios a
                   WHERE CONVERT(date, a.fecha_pago) = CONVERT(date, d.fecha_operacion)
                     AND TRY_CONVERT(BIGINT, a.establecimiento) = TRY_CONVERT(BIGINT,
                         SUBSTRING(d.referencia_externa, CHARINDEX('_', d.referencia_externa) + 1, 100))), 0)
    """ if comisiones_amex else ""

    return cursor.execute(f"""
        WITH fuente(fecha, texto, monto) AS ({fuente_sql}), actual AS (
            SELECT d.id, d.grupo_id, g.estacion_id,
                   ISNULL(s.Nombre, CONCAT('Estación ', g.estacion_id)) AS estacion,
                   e.Nombre AS banco, g.afiliacion, g.fecha_operativa, d.monto,
                   SUM(f.monto) {extra_amex} AS monto_actual
            FROM Conciliacion_V3_Detalles d
            JOIN Conciliacion_V3_Grupos g ON g.id=d.grupo_id AND g.entidad_id={entidad_id}
            LEFT JOIN Estaciones s ON s.Codigo=g.estacion_id
            LEFT JOIN Tesoreria_Entidad e ON e.id=g.entidad_id
            JOIN fuente f ON f.fecha=CONVERT(date, d.fecha_operacion)
              AND CHARINDEX(
                    CASE WHEN PATINDEX('%[^0]%', SUBSTRING(d.referencia_externa,
                                      CHARINDEX('_', d.referencia_externa)+1, 100)) = 0 THEN '0'
                         ELSE SUBSTRING(d.referencia_externa,
                              CHARINDEX('_', d.referencia_externa)
                              + PATINDEX('%[^0]%', SUBSTRING(d.referencia_externa,
                                  CHARINDEX('_', d.referencia_externa)+1, 100)), 100)
                    END,
                    ISNULL(f.texto, '')
                  ) > 0
            WHERE d.origen='TES' AND CHARINDEX('_', d.referencia_externa)>0
              AND g.fecha_operativa >= DATEADD(MONTH, DATEDIFF(MONTH,0,GETDATE())-1,0)
              AND g.fecha_operativa <  DATEADD(MONTH, DATEDIFF(MONTH,0,GETDATE())+1,0)
              AND NOT EXISTS (SELECT 1 FROM Conciliacion_V3_CierreMes cierre
                  WHERE cierre.estacion_id=g.estacion_id AND cierre.entidad_id=g.entidad_id
                    AND cierre.afiliacion=g.afiliacion
                    AND cierre.mes=CONVERT(VARCHAR(7),g.fecha_operativa,23))
            GROUP BY d.id,d.grupo_id,g.estacion_id,s.Nombre,e.Nombre,g.afiliacion,
                     g.fecha_operativa,d.monto,d.fecha_operacion,d.referencia_externa
        )
        SELECT id, 'TES', grupo_id, estacion_id, estacion, banco, afiliacion,
               fecha_operativa, monto, CAST(monto_actual AS DECIMAL(18,2))
        FROM actual
        WHERE ABS(monto-CAST(monto_actual AS DECIMAL(18,2)))>0.01
    """).fetchall()


def leer_alertas(cursor):
    tes = cursor.execute("""
        SELECT d.id, 'TES', d.grupo_id, g.estacion_id,
               ISNULL(s.Nombre, CONCAT('Estación ', g.estacion_id)), e.Nombre, g.afiliacion,
               g.fecha_operativa, d.monto,
               CAST(COALESCE(m.abono, t.Depositos) AS DECIMAL(18,2))
        FROM Conciliacion_V3_Detalles d
        JOIN Conciliacion_V3_Grupos g ON g.id = d.grupo_id
        LEFT JOIN Estaciones s ON s.Codigo = g.estacion_id
        LEFT JOIN Tesoreria_Entidad e ON e.id = g.entidad_id
        LEFT JOIN movimientos_bancarios m ON d.referencia_externa LIKE 'mb[_]%'
          AND m.id = TRY_CONVERT(INT, SUBSTRING(d.referencia_externa, 4, 50))
        LEFT JOIN Tesoreria_V3_Unificada t ON d.referencia_externa NOT LIKE 'mb[_]%'
          AND t.id_origen = d.referencia_externa
        WHERE d.origen = 'TES' AND (m.id IS NOT NULL OR t.id_origen IS NOT NULL)
          -- Mismas reglas de fuente que emitir_tesoreria_movimientos_bancarios.
          AND (
              (g.entidad_id = 1 AND m.banco = 'SANTANDER'
                  AND (m.descripcion LIKE '%DEPOSITO VENTAS DEL DIA%'
                       OR m.descripcion LIKE '%DEPOSITO VTAS%'))
              OR (g.entidad_id = 13 AND m.banco = 'AFIRME'
                  AND m.descripcion LIKE '%VENTA%')
          )
          AND g.fecha_operativa >= DATEADD(MONTH, DATEDIFF(MONTH, 0, GETDATE()) - 1, 0)
          AND g.fecha_operativa <  DATEADD(MONTH, DATEDIFF(MONTH, 0, GETDATE()) + 1, 0)
          AND ABS(d.monto - CAST(COALESCE(m.abono, t.Depositos) AS DECIMAL(18,2))) > 0.01
          AND NOT EXISTS (SELECT 1 FROM Conciliacion_V3_CierreMes cierre
              WHERE cierre.estacion_id=g.estacion_id AND cierre.entidad_id=g.entidad_id
                AND cierre.afiliacion=g.afiliacion
                AND cierre.mes=CONVERT(VARCHAR(7), g.fecha_operativa, 23))
    """).fetchall()

    amex_fuentes = [
        ('Tesoreria_5117', "SELECT CONVERT(date,Fecha) fecha, Concepto texto, CAST(ISNULL(Depositos,0) AS DECIMAL(18,2)) monto FROM dbo.Tesoreria_5117 WHERE Fecha>=DATEADD(MONTH,DATEDIFF(MONTH,0,GETDATE())-1,0) AND Fecha<DATEADD(MONTH,DATEDIFF(MONTH,0,GETDATE())+1,0)"),
        ('Tesoreria_0956', "SELECT CONVERT(date,Fecha), DescripcionDetallada, CAST(ISNULL(Depositos,0) AS DECIMAL(18,2)) FROM dbo.Tesoreria_0956 WHERE DescripcionDetallada IS NOT NULL AND Fecha>=DATEADD(MONTH,DATEDIFF(MONTH,0,GETDATE())-1,0) AND Fecha<DATEADD(MONTH,DATEDIFF(MONTH,0,GETDATE())+1,0)"),
        ('Tesoreria_8520', "SELECT CONVERT(date,Fecha), DescripcionDetallada, CAST(ISNULL(Depositos,0) AS DECIMAL(18,2)) FROM dbo.Tesoreria_8520 WHERE DescripcionDetallada IS NOT NULL AND Fecha>=DATEADD(MONTH,DATEDIFF(MONTH,0,GETDATE())-1,0) AND Fecha<DATEADD(MONTH,DATEDIFF(MONTH,0,GETDATE())+1,0)"),
    ]
    for tabla in ('Tesoreria_8504', 'Tesoreria_8492', 'Tesoreria_4638', 'Tesoreria_4777',
                  'Tesoreria_5247', 'Tesoreria_7291', 'Tesoreria_7533', 'Tesoreria_5791',
                  'Tesoreria_A6115', 'Tesoreria_4547', 'Tesoreria_8214', 'Tesoreria_4669'):
        amex_fuentes.append((tabla, f"SELECT CONVERT(date,Fecha), CONCAT(ISNULL(Referencia,''),' ',ISNULL(Concepto,'')), CAST(ISNULL(Depositos,0) AS DECIMAL(18,2)) FROM dbo.{tabla} WHERE Depositos>0 AND Fecha>=DATEADD(MONTH,DATEDIFF(MONTH,0,GETDATE())-1,0) AND Fecha<DATEADD(MONTH,DATEDIFF(MONTH,0,GETDATE())+1,0)"))
    tes_amex = leer_alertas_fuente(cursor, 3, 'AMEX', amex_fuentes, comisiones_amex=True)

    banorte_fuentes = [
        ('Tesoreria_0956', "SELECT CONVERT(date,Fecha), Descripcion, CAST(ISNULL(Depositos,0) AS DECIMAL(18,2)) FROM dbo.Tesoreria_0956 WHERE Depositos>0 AND Fecha>=DATEADD(MONTH,DATEDIFF(MONTH,0,GETDATE())-1,0) AND Fecha<DATEADD(MONTH,DATEDIFF(MONTH,0,GETDATE())+1,0) AND (Descripcion LIKE 'TOTAL GAS%' OR Descripcion LIKE 'DIAZ GAS%') AND UPPER(ISNULL(Descripcion,'')) NOT LIKE '%COMISI%' AND UPPER(ISNULL(Descripcion,'')) NOT LIKE '%IVA%' AND UPPER(ISNULL(Descripcion,'')) NOT LIKE '%DCC%' AND UPPER(ISNULL(Descripcion,'')) NOT LIKE '%REEMB%' AND UPPER(ISNULL(Descripcion,'')) NOT LIKE '%TRASPASO%' AND UPPER(ISNULL(Descripcion,'')) NOT LIKE '%PRESTAMO%' AND UPPER(ISNULL(Descripcion,'')) NOT LIKE '%CUENTA PROPIA%' AND UPPER(ISNULL(Descripcion,'')) NOT LIKE '%SPEI RECIBIDO%'")
    ]
    for tabla in ('Tesoreria_A9475', 'Tesoreria_8876'):
        banorte_fuentes.append((tabla, f"SELECT CONVERT(date,Fecha), CONCAT(ISNULL(Referencia,''),' ',ISNULL(Descripcion,'')), CAST(ISNULL(Depositos,0) AS DECIMAL(18,2)) FROM dbo.{tabla} WHERE Depositos>0 AND Fecha>=DATEADD(MONTH,DATEDIFF(MONTH,0,GETDATE())-1,0) AND Fecha<DATEADD(MONTH,DATEDIFF(MONTH,0,GETDATE())+1,0) AND UPPER(CONCAT(ISNULL(Referencia,''),' ',ISNULL(Descripcion,''))) NOT LIKE '%COMISI%' AND UPPER(CONCAT(ISNULL(Referencia,''),' ',ISNULL(Descripcion,''))) NOT LIKE '%IVA%' AND UPPER(CONCAT(ISNULL(Referencia,''),' ',ISNULL(Descripcion,''))) NOT LIKE '%DCC%' AND UPPER(CONCAT(ISNULL(Referencia,''),' ',ISNULL(Descripcion,''))) NOT LIKE '%REEMB%' AND UPPER(CONCAT(ISNULL(Referencia,''),' ',ISNULL(Descripcion,''))) NOT LIKE '%TRASPASO%' AND UPPER(CONCAT(ISNULL(Referencia,''),' ',ISNULL(Descripcion,''))) NOT LIKE '%SPEI RECIBIDO%'"))
    banorte_fuentes.append(('Tesoreria_FG4113', "SELECT CONVERT(date,Fecha), Descripcion, CAST(ISNULL(Depositos,0) AS DECIMAL(18,2)) FROM dbo.Tesoreria_FG4113 WHERE Depositos>0 AND Fecha>=DATEADD(MONTH,DATEDIFF(MONTH,0,GETDATE())-1,0) AND Fecha<DATEADD(MONTH,DATEDIFF(MONTH,0,GETDATE())+1,0) AND (Descripcion LIKE '%9662848%' OR Descripcion LIKE '%FORMULA GAS%') AND UPPER(ISNULL(Descripcion,'')) NOT LIKE '%COMISI%' AND UPPER(ISNULL(Descripcion,'')) NOT LIKE '%IVA%' AND UPPER(ISNULL(Descripcion,'')) NOT LIKE '%DCC%' AND UPPER(ISNULL(Descripcion,'')) NOT LIKE '%REEMB%' AND UPPER(ISNULL(Descripcion,'')) NOT LIKE '%TRASPASO%'"))
    tes_banorte = leer_alertas_fuente(cursor, 4, 'BANORTE', banorte_fuentes)

    cg = cursor.execute("""
        SELECT d.id, 'CG', d.grupo_id, g.estacion_id,
               ISNULL(s.Nombre, CONCAT('Estación ', g.estacion_id)), e.Nombre, g.afiliacion,
               g.fecha_operativa, d.monto,
               CAST(CASE WHEN transito.id IS NOT NULL THEN transito.monto_efectivo
                    ELSE CASE WHEN i.mto - ISNULL(diferido.monto_diferido, 0) < 0 THEN 0
                              ELSE i.mto - ISNULL(diferido.monto_diferido, 0) END END AS DECIMAL(18,2)),
               v.den, c.conceptos_cg
        FROM Conciliacion_V3_Detalles d
        JOIN Conciliacion_V3_Grupos g ON g.id = d.grupo_id
        JOIN Conciliacion_Configuracion c ON c.estacion_id = g.estacion_id
          AND c.entidad_id = g.entidad_id
          AND (c.afiliacion = g.afiliacion OR g.afiliacion LIKE '%' + c.afiliacion + '%')
        LEFT JOIN Estaciones s ON s.Codigo = g.estacion_id
        LEFT JOIN Tesoreria_Entidad e ON e.id = g.entidad_id
        CROSS APPLY (SELECT
          TRY_CONVERT(INT, PARSENAME(REPLACE(REPLACE(d.referencia_externa, '--', '-N'), '-', '.'), 4)) fch,
          TRY_CONVERT(INT, PARSENAME(REPLACE(REPLACE(d.referencia_externa, '--', '-N'), '-', '.'), 3)) codisl,
          TRY_CONVERT(INT, PARSENAME(REPLACE(REPLACE(d.referencia_externa, '--', '-N'), '-', '.'), 2)) nrotur,
          TRY_CONVERT(INT, REPLACE(PARSENAME(REPLACE(REPLACE(d.referencia_externa, '--', '-N'), '-', '.'), 1), 'N', '-')) codval
        ) ref
        JOIN SG12.dbo.Ingresos i ON i.fch=ref.fch AND i.codisl=ref.codisl AND i.nrotur=ref.nrotur AND i.codval=ref.codval
        JOIN SG12.dbo.Valores v ON v.cod=i.codval
        -- Debe coincidir con get_transitos_cg_v3: el corte origen conserva
        -- cualquier tránsito no cancelado de su mismo mes de origen.
        OUTER APPLY (SELECT TOP 1 tr.id, tr.monto_efectivo FROM CV3_Transito tr
            WHERE tr.corte_ref_id=d.referencia_externa AND tr.estado != 'CANCELADO'
              AND tr.mes_origen=CONVERT(VARCHAR(7), g.fecha_operativa, 23)
            ORDER BY tr.id DESC) transito
        OUTER APPLY (SELECT ISNULL(SUM(df.monto_diferido), 0) monto_diferido FROM CV3_Diferido df
            WHERE df.corte_ref_id=d.referencia_externa AND df.estado != 'CANCELADO'
              AND df.mes=CONVERT(VARCHAR(7), g.fecha_operativa, 23)) diferido
        WHERE d.origen='CG' AND ref.fch IS NOT NULL
          AND g.fecha_operativa >= DATEADD(MONTH, DATEDIFF(MONTH, 0, GETDATE()) - 1, 0)
          AND g.fecha_operativa <  DATEADD(MONTH, DATEDIFF(MONTH, 0, GETDATE()) + 1, 0)
          AND CONVERT(date, g.fecha_operativa) <> CONVERT(date, GETDATE())
          AND ABS(d.monto - CAST(CASE WHEN transito.id IS NOT NULL THEN transito.monto_efectivo
                ELSE CASE WHEN i.mto - ISNULL(diferido.monto_diferido, 0) < 0 THEN 0
                          ELSE i.mto - ISNULL(diferido.monto_diferido, 0) END END AS DECIMAL(18,2))) > 0.1
          AND NOT EXISTS (SELECT 1 FROM Conciliacion_V3_CierreMes cierre
              WHERE cierre.estacion_id=g.estacion_id AND cierre.entidad_id=g.entidad_id
                AND cierre.afiliacion=g.afiliacion
                AND cierre.mes=CONVERT(VARCHAR(7), g.fecha_operativa, 23))
        OPTION (FORCE ORDER, LOOP JOIN)
    """).fetchall()
    return list(tes) + list(tes_amex) + list(tes_banorte) + [row[:10] for row in cg if conceptos_validos(row[10], row[11])]


def main():
    with pyodbc.connect(CONNECTION, timeout=30) as connection:
        cursor = connection.cursor()
        asegurar_tabla(cursor)
        alertas = leer_alertas(cursor)
        cursor.execute("DELETE FROM dbo.CV3_AlertasCambios")
        cursor.fast_executemany = True
        cursor.executemany("""
            INSERT INTO dbo.CV3_AlertasCambios
            (detalle_id, origen, grupo_id, estacion_id, estacion, banco, afiliacion,
             fecha_operativa, monto_guardado, monto_actual, diferencia, detectado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """, [tuple(row) + (round(float(row[9]) - float(row[8]), 2), datetime.now()) for row in alertas])
        connection.commit()
        print(f"{len(alertas)} alertas actualizadas")


if __name__ == "__main__":
    main()
