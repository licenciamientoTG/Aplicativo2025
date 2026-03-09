"""
Detector de Ventas Millonarias Falsas - TotalGas
=================================================
Detecta ventas anómalas comparando cada registro contra el percentil 99
del día, y verificando el ratio entre el mayor y el segundo mayor valor.

Criterios de detección:
  1. Percentil 99: valor > P99 * PERC99_FACTOR  (por defecto 3x)
  2. Ratio max/segundo: si el máximo es > MAX_RATIO veces el segundo mayor,
     el máximo se marca como sospechoso.
  Solo se considera un registro anómalo si supera MONTO_MINIMO.

Uso:
    py detectar_ventas_anomalas.py [YYYY-MM-DD]
    py detectar_ventas_anomalas.py              # usa fecha de hoy - 1

Envía correo de alerta si encuentra anomalías.
"""

import sys
import io
import pyodbc
import pandas as pd
import smtplib
import ssl
from datetime import datetime, timedelta
from email.message import EmailMessage

# Fix Windows console encoding
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

# --- CONFIGURACIÓN ---
DB_SERVER   = "192.168.0.6"
DB_USER     = "cguser"
DB_PASS     = "sahei1712"
DB_NAME     = "SG12"

SMTP_HOST   = "smtp.gmail.com"
SMTP_PORT   = 465
SMTP_USER   = "no-reply@totalgas.com"
SMTP_PASS   = "sysdhepknmlkigbs"
ALERT_TO    = "daniel.ramirez@totalgas.com, kuwait.valenzuela@totalgas.com"

# Parámetros de detección — MontoTotal
PERC99_FACTOR = 3.0       # umbral = P99 * PERC99_FACTOR
MAX_RATIO     = 5.0       # si max > MAX_RATIO * segundo_max → sospechoso
MONTO_MINIMO  = 500_000   # ignorar registros de monto menores a este valor

# Parámetros de detección — VentasTotal (volumen en litros)
VOLUMEN_PERC99_FACTOR = 3.0   # misma lógica P99 aplicada al volumen
VOLUMEN_MAX_RATIO     = 5.0   # ratio max/2do sobre volumen
VOLUMEN_MINIMO        = 50_000  # ignorar registros de volumen menores a este valor

QUERY = """
SELECT
    X.Fecha,
    X.CodGasolinera,
    X.Estacion,
    X.Producto,
    X.Turno,
    X.CodProducto,
    CAST(SUM(X.VentasReales) AS BIGINT)        AS VentasTotal,
    CAST(SUM(X.Monto_total)  AS DECIMAL(18,2)) AS MontoTotal
FROM (
    SELECT
        CONVERT(VARCHAR, CONVERT(SMALLDATETIME, v.fch - 1, 103), 103) AS Fecha,
        isd.codgas  AS CodGasolinera,
        g.abr       AS Estacion,
        T3.den      AS Producto,
        v.nrotur    AS Turno,
        v.codprd    AS CodProducto,
        SUM(v.canven) AS VentasReales,
        SUM(v.mtoven) AS Monto_total
    FROM [SG12].[dbo].[Ventas] AS v
    INNER JOIN [SG12].[dbo].[ISLAS]        AS isd ON v.codisl = isd.cod
    INNER JOIN [SG12].[dbo].[Gasolineras]  AS g   ON isd.codgas = g.cod
    INNER JOIN [SG12].[dbo].[Productos]    AS T3  ON v.codprd = T3.cod
    WHERE v.fch BETWEEN (DATEDIFF(dd, 0, ?) + 1) AND (DATEDIFF(dd, 0, ?) + 1)
    GROUP BY
        CONVERT(VARCHAR, CONVERT(SMALLDATETIME, v.fch - 1, 103), 103),
        isd.codgas, g.abr, T3.den, v.nrotur, v.codprd
) AS X
GROUP BY X.Fecha, X.CodGasolinera, X.Estacion, X.Producto, X.Turno, X.CodProducto
"""


def conectar():
    conn_str = (
        f"DRIVER={{ODBC Driver 17 for SQL Server}};"
        f"SERVER={DB_SERVER};"
        f"DATABASE={DB_NAME};"
        f"UID={DB_USER};"
        f"PWD={DB_PASS};"
        f"TrustServerCertificate=yes;"
    )
    return pyodbc.connect(conn_str, timeout=15)


def obtener_datos(fecha_str: str) -> pd.DataFrame:
    """Trae las ventas del día indicado (formato YYYY-MM-DD)."""
    conn = conectar()
    cursor = conn.cursor()
    cursor.execute(QUERY, fecha_str, fecha_str)
    cols = [c[0] for c in cursor.description]
    rows = cursor.fetchall()
    cursor.close()
    conn.close()
    return pd.DataFrame([list(r) for r in rows], columns=cols)


def _outliers_serie(serie: pd.Series, minimo: float, factor_p99: float, ratio_max: float) -> dict:
    """
    Devuelve {idx: razon_str} para los registros anómalos de una serie numérica.
    Criterios: P99 * factor_p99  y  ratio max/segundo > ratio_max.
    Solo considera valores >= minimo.
    """
    p99         = serie.quantile(0.99)
    umbral_perc = p99 * factor_p99
    max_val     = serie.max()
    desc        = serie[serie >= minimo].sort_values(ascending=False)
    segundo_max = desc.iloc[1] if len(desc) > 1 else max_val

    resultado = {}
    for idx, v in serie.items():
        if v < minimo:
            continue
        razones = []
        if v > umbral_perc:
            razones.append(f"P99x{factor_p99}: {v:,.0f} > umbral {umbral_perc:,.0f}")
        if v == max_val and segundo_max > 0 and (v / segundo_max) > ratio_max:
            razones.append(f"Ratio max/2do: {v/segundo_max:.1f}x (2do={segundo_max:,.0f})")
        if razones:
            resultado[idx] = " | ".join(razones)
    return resultado


def detectar_anomalias(df: pd.DataFrame) -> pd.DataFrame:
    """
    Detecta anomalías en MontoTotal (monto) Y en VentasTotal (volumen).
    Un registro se marca si es outlier en cualquiera de los dos campos.
    """
    if df.empty:
        return df.head(0)

    outliers_monto   = _outliers_serie(
        df["MontoTotal"].astype(float), MONTO_MINIMO, PERC99_FACTOR, MAX_RATIO
    )
    outliers_volumen = _outliers_serie(
        df["VentasTotal"].astype(float), VOLUMEN_MINIMO, VOLUMEN_PERC99_FACTOR, VOLUMEN_MAX_RATIO
    )

    todos_idx = set(outliers_monto) | set(outliers_volumen)
    if not todos_idx:
        return df.head(0)

    razones_map = {}
    for idx in todos_idx:
        partes = []
        if idx in outliers_monto:
            partes.append(f"[Monto] {outliers_monto[idx]}")
        if idx in outliers_volumen:
            partes.append(f"[Volumen] {outliers_volumen[idx]}")
        razones_map[idx] = " | ".join(partes)

    anomalas = df.loc[list(todos_idx)].copy()
    anomalas["Razon"] = [razones_map[i] for i in anomalas.index]
    return anomalas.sort_values("VentasTotal", ascending=False)


COLS_EMAIL = ["Fecha", "CodGasolinera", "Estacion", "Producto", "Turno", "CodProducto", "VentasTotal", "MontoTotal"]

TABLA_STYLE = """
  <style>
    .tg { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 13px; width: 100%; }
    .tg th { background-color: #c0392b; color: #fff; padding: 10px 14px; text-align: left; font-weight: 600; border-bottom: 2px solid #922b21; }
    .tg td { padding: 9px 14px; border-bottom: 1px solid #e0e0e0; color: #333; }
    .tg tr:nth-child(even) td { background-color: #fdf2f2; }
    .tg tr:hover td { background-color: #fadbd8; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
  </style>
"""

def _build_html_table(df: pd.DataFrame) -> str:
    vista = df[COLS_EMAIL].copy()
    vista["Turno"]       = vista["Turno"].apply(lambda x: str(int(x))[0])
    vista["VentasTotal"] = vista["VentasTotal"].apply(lambda x: f"{int(x):,}".replace(",", ","))
    vista["MontoTotal"]  = vista["MontoTotal"].apply(lambda x: f"{float(x):,.2f}")

    cols_num = {"VentasTotal", "MontoTotal"}
    encabezados = "".join(f"<th>{c}</th>" for c in vista.columns)
    filas = ""
    for _, row in vista.iterrows():
        celdas = ""
        for col, val in zip(vista.columns, row):
            cls = ' class="num"' if col in cols_num else ""
            celdas += f"<td{cls}>{val}</td>"
        filas += f"<tr>{celdas}</tr>"
    return f'<table class="tg"><thead><tr>{encabezados}</tr></thead><tbody>{filas}</tbody></table>'


def enviar_alerta(anomalas: pd.DataFrame, fecha_str: str):
    tabla_html = _build_html_table(anomalas)
    vista_txt  = anomalas[COLS_EMAIL].copy()
    vista_txt["Turno"]       = vista_txt["Turno"].apply(lambda x: str(int(x))[0])
    vista_txt["VentasTotal"] = vista_txt["VentasTotal"].apply(lambda x: f"{int(x):,}")
    vista_txt["MontoTotal"]  = vista_txt["MontoTotal"].apply(lambda x: f"{float(x):,.2f}")
    tabla_txt  = vista_txt.to_string(index=False)

    msg = EmailMessage()
    msg["Subject"] = f"[TotalGas] ALERTA: {len(anomalas)} venta(s) millonaria(s) detectada(s) — {fecha_str}"
    msg["From"]    = SMTP_USER
    msg["To"]      = ALERT_TO
    msg.set_content(f"Se detectaron ventas millonarias el {fecha_str}:\n\n{tabla_txt}")
    msg.add_alternative(f"""
    <html><head>{TABLA_STYLE}</head><body>
    <h2 style="color:#c0392b;font-family:Arial,sans-serif;">Ventas Millonarias Detectadas &mdash; {fecha_str}</h2>
    <p style="font-family:Arial,sans-serif;color:#555;">Las siguientes ventas superan los umbrales estadísticos de detección:</p>
    {tabla_html}
    </body></html>
    """, subtype="html")

    ctx = ssl.create_default_context()
    with smtplib.SMTP_SSL(SMTP_HOST, SMTP_PORT, context=ctx) as s:
        s.login(SMTP_USER, SMTP_PASS)
        s.send_message(msg)
    print(f"Alerta enviada a: {ALERT_TO}")


def main():
    # Fecha: argumento o ayer
    if len(sys.argv) > 1:
        fecha_str = sys.argv[1]
    else:
        fecha_str = (datetime.today() - timedelta(days=1)).strftime("%Y-%m-%d")

    print(f"Analizando ventas del: {fecha_str}")

    df = obtener_datos(fecha_str)
    print(f"  Registros obtenidos: {len(df)}")

    if df.empty:
        print("  Sin datos para esa fecha.")
        return

    # Estadísticas del día
    montos = df["MontoTotal"].astype(float)
    print(f"  Mediana:  ${montos.median():>15,.2f}")
    print(f"  Promedio: ${montos.mean():>15,.2f}")
    print(f"  Máximo:   ${montos.max():>15,.2f}")

    anomalas = detectar_anomalias(df)

    if anomalas.empty:
        print("  Sin anomalías detectadas.")
        return

    print(f"\n  *** {len(anomalas)} ANOMALIA(S) DETECTADA(S) ***\n")
    pd.set_option("display.max_colwidth", 50)
    pd.set_option("display.width", 200)
    print(anomalas[["Fecha", "Estacion", "Producto", "Turno", "VentasTotal", "MontoTotal"]].to_string(index=False))

    enviar_alerta(anomalas, fecha_str)


if __name__ == "__main__":
    main()
