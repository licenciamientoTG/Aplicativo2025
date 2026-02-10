import pyodbc
from datetime import date, timedelta, datetime
import smtplib
import ssl
from email.message import EmailMessage

# ==========================
# CONFIGURACIÓN SQL SERVER
# ==========================
SQL_SERVER   = "192.168.0.6"
SQL_DATABASE = "TG"
SQL_USER     = "operador"
SQL_PASS     = "operador"
TABLE_NAME   = "[TG].[dbo].[mojo_tickets]"  # no se usa, pero lo dejamos

SQL_CONN_STR = (
    "DRIVER={ODBC Driver 17 for SQL Server};"
    f"SERVER={SQL_SERVER};"
    f"DATABASE={SQL_DATABASE};"
    f"UID={SQL_USER};"
    f"PWD={SQL_PASS};"
)

# ==========================
# CONFIGURACIÓN CORREO
# ==========================
SMTP_HOST = "smtp.gmail.com"
SMTP_PORT = 465  # SMTPS
SMTP_USER = "no-reply@totalgas.com"
SMTP_PASS = "sysdhepknmlkigbs"

ALERT_RECIPIENT = "daniel.ramirez@totalgas.com"
ALERT_SUBJECT = "Alarma de facturación inusual en un solo día"
ANOMALIES_URL = "http://totalgasonline.net:400/income/anomalies"


def get_date_range():
    """
    hasta_eval = hoy
    desde_eval = hoy - 7 días
    """
    hoy = date.today()
    desde = hoy - timedelta(days=7)
    return desde, hoy


def run_query(desde_eval, hasta_eval):
    """
    Ejecuta el query en SQL Server para el rango de fechas dado.
    Regresa una lista de diccionarios (una por fila).
    """
    query = """
    /* ====== PARÁMETROS ====== */
    DECLARE @desde_eval date = ?;
    DECLARE @hasta_eval date = ?;

    DECLARE @k_sigma float = 3.0;   -- umbral σ
    DECLARE @iqr_mult float = 1.5;  -- umbral IQR

    /* ====== 1) HISTÓRICO (3 meses previos completos) ====== */
    DECLARE @desde_hist date = DATEFROMPARTS(YEAR(DATEADD(MONTH, -3, @desde_eval)),
                                             MONTH(DATEADD(MONTH, -3, @desde_eval)), 1);
    DECLARE @hasta_hist date = EOMONTH(DATEADD(MONTH, -1, @desde_eval));

    /* ====== 2) fchtrn ====== */
    DECLARE @desde_hist_i int = DATEDIFF(DAY,'1900-01-01',@desde_hist)+1;
    DECLARE @hasta_hist_i int = DATEDIFF(DAY,'1900-01-01',@hasta_hist)+1;
    DECLARE @desde_eval_i int = DATEDIFF(DAY,'1900-01-01',@desde_eval)+1;
    DECLARE @hasta_eval_i int = DATEDIFF(DAY,'1900-01-01',@hasta_eval)+1;

    /* ====== 3) HISTÓRICO diario ====== */
    ;WITH Hist AS (
        SELECT
            [codopr (F)] AS codopr,
            CAST(DATEADD(DAY, fchtrn - 1, CONVERT(date,'1900-01-01')) AS date) AS fecha,
            COUNT(DISTINCT nrofac) AS cnt_facturas_dia
        FROM TG.dbo.vw_DespachosConFactura
        WHERE fchtrn BETWEEN @desde_hist_i AND @hasta_hist_i
        GROUP BY [codopr (F)],
                 CAST(DATEADD(DAY, fchtrn - 1, CONVERT(date,'1900-01-01')) AS date)
    ),
    Baseline AS (
        SELECT DISTINCT
            codopr,
            AVG(CAST(cnt_facturas_dia AS float))  OVER (PARTITION BY codopr) AS media_hist,
            STDEV(CAST(cnt_facturas_dia AS float))OVER (PARTITION BY codopr) AS desv_hist,
            PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY cnt_facturas_dia)
                OVER (PARTITION BY codopr) AS q1_hist,
            PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY cnt_facturas_dia)
                OVER (PARTITION BY codopr) AS q3_hist,
            COUNT(*) OVER (PARTITION BY codopr) AS dias_hist
        FROM Hist
    ),
    EvalDaily AS (
        SELECT
            [codopr (F)] AS codopr,
            CAST(DATEADD(DAY, fchtrn - 1, CONVERT(date,'1900-01-01')) AS date) AS fecha,
            COUNT(DISTINCT nrofac) AS facturas_dia
        FROM TG.dbo.vw_DespachosConFactura
        WHERE fchtrn BETWEEN @desde_eval_i AND @hasta_eval_i
        GROUP BY [codopr (F)],
                 CAST(DATEADD(DAY, fchtrn - 1, CONVERT(date,'1900-01-01')) AS date)
    )
    SELECT
        e.codopr                                       AS codopr,
        c.den                                          AS nombre_cliente,   -- ⬅ nombre del cliente
        e.fecha,
        e.facturas_dia,
        CAST(b.media_hist AS decimal(18,2))            AS baseline_media_hist,
        CAST(b.desv_hist  AS decimal(18,2))            AS baseline_desv_hist,
        CAST(b.q1_hist    AS decimal(18,2))            AS q1_hist,
        CAST(b.q3_hist    AS decimal(18,2))            AS q3_hist,
        CAST((e.facturas_dia - b.media_hist) / NULLIF(b.desv_hist,0)
             AS decimal(18,2))                         AS zscore_hist,
        CAST((e.facturas_dia - b.media_hist) / NULLIF(NULLIF(b.media_hist,0),0) * 100.0
             AS decimal(18,2))                         AS incremento_pct_vs_hist
    FROM EvalDaily e
    JOIN Baseline b
      ON b.codopr = e.codopr
    LEFT JOIN SG12.dbo.Clientes c WITH (NOLOCK)        -- ⬅ antes usábamos TG.dbo.clientes
      ON c.cod = e.codopr                              -- ⬅ igual que en tu query bueno
    ORDER BY 
        zscore_hist DESC, 
        e.fecha, 
        e.codopr
    OPTION (RECOMPILE);


    """

    print(f"[INFO] Iniciando ejecución del query... {datetime.now().isoformat(sep=' ', timespec='seconds')}")

    conn = pyodbc.connect(SQL_CONN_STR)
    try:
        cur = conn.cursor()
        cur.execute(query, (desde_eval, hasta_eval))
        columns = [c[0] for c in cur.description]
        rows = cur.fetchall()
        print(f"[INFO] Query finalizado. Filas obtenidas: {len(rows)}. {datetime.now().isoformat(sep=' ', timespec='seconds')}")
    finally:
        conn.close()

    results = []
    for r in rows:
        row_dict = {col: getattr(r, col) for col in columns}
        results.append(row_dict)

    return results


def filter_anomalies(rows, zscore_threshold=20.0):
    """
    Filtra filas con zscore_hist > zscore_threshold.
    """
    anomalies = []
    for r in rows:
        z = r.get("zscore_hist")
        if z is not None and float(z) > zscore_threshold:
            anomalies.append(r)
    return anomalies


def build_email_bodies(anomalies, desde_eval, hasta_eval):
    """
    Construye el cuerpo del correo en texto plano y HTML.
    """
    # ---- Texto plano ----
    lines_text = []
    lines_text.append("Se ha detectado una alarma de facturación inusual en un solo día.\n")
    lines_text.append(f"Rango analizado: {desde_eval.isoformat()} a {hasta_eval.isoformat()}")
    lines_text.append(f"Días con z-score > 20: {len(anomalies)}")
    lines_text.append("")
    lines_text.append(f"Para revisar el detalle completo ingresa a: {ANOMALIES_URL}")
    lines_text.append("")
    lines_text.append("Detalle (resumen):")
    lines_text.append("codopr | nombre_cliente | fecha | facturas_dia | zscore_hist | incremento_pct_vs_hist")
    lines_text.append("-" * 120)

    for a in anomalies:
        lines_text.append(
            f"{a['codopr']} | "
            f"{a.get('nombre_cliente', '')} | "
            f"{a['fecha']} | "
            f"{a['facturas_dia']} | "
            f"{a['zscore_hist']} | "
            f"{a['incremento_pct_vs_hist']}"
        )

    text_body = "\n".join(lines_text)

    # ---- HTML ----
    html_lines = []
    html_lines.append("<html><body>")
    html_lines.append("<p>Se ha detectado una <strong>alarma de facturación inusual en un solo día.</strong></p>")
    html_lines.append(f"<p><strong>Rango analizado:</strong> {desde_eval.isoformat()} a {hasta_eval.isoformat()}</p>")
    html_lines.append(f"<p><strong>Días con z-score &gt; 20:</strong> {len(anomalies)}</p>")
    html_lines.append(f'<p>Para revisar el detalle completo ingresa a: <a href="{ANOMALIES_URL}">{ANOMALIES_URL}</a></p>')

    if anomalies:
        html_lines.append("<h3>Detalle de anomalías</h3>")
        html_lines.append("""
        <table border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px;">
            <thead style="background-color:#f0f0f0;">
                <tr>
                    <th>codopr</th>
                    <th>Nombre del cliente</th>
                    <th>Fecha</th>
                    <th>Facturas día</th>
                    <th>Media hist.</th>
                    <th>Desv. hist.</th>
                    <th>z-score</th>
                    <th>Incremento % vs hist.</th>
                </tr>
            </thead>
            <tbody>
        """)
        for a in anomalies:
            html_lines.append(f"""
                <tr>
                    <td>{a['codopr']}</td>
                    <td>{a.get('nombre_cliente', '') or ''}</td>
                    <td>{a['fecha']}</td>
                    <td>{a['facturas_dia']}</td>
                    <td>{a['baseline_media_hist']}</td>
                    <td>{a['baseline_desv_hist']}</td>
                    <td>{a['zscore_hist']}</td>
                    <td>{a['incremento_pct_vs_hist']}</td>
                </tr>
            """)
        html_lines.append("""
            </tbody>
        </table>
        """)
    html_lines.append("</body></html>")

    html_body = "\n".join(html_lines)

    return text_body, html_body


def send_email(subject, text_body, html_body, to_email):
    """
    Envía un correo usando SMTP con SSL, con texto plano + HTML (tabla).
    """
    msg = EmailMessage()
    msg["Subject"] = subject
    msg["From"] = SMTP_USER
    msg["To"] = to_email

    # Versión texto plano
    msg.set_content(text_body)

    # Versión HTML
    msg.add_alternative(html_body, subtype="html")

    context = ssl.create_default_context()

    with smtplib.SMTP_SSL(SMTP_HOST, SMTP_PORT, context=context) as server:
        server.login(SMTP_USER, SMTP_PASS)
        server.send_message(msg)


def main():
    desde_eval, hasta_eval = get_date_range()
    print(f"[INFO] Rango de fechas a evaluar: {desde_eval} a {hasta_eval}")

    # 1) Ejecutar query
    rows = run_query(desde_eval, hasta_eval)

    # 2) Filtrar anomalías con zscore > 20
    anomalies = filter_anomalies(rows, zscore_threshold=20.0)

    # 3) Si hay anomalías, mandar un solo correo con todo
    if anomalies:
        print(f"[INFO] Se encontraron {len(anomalies)} anomalías con zscore > 20. Enviando correo...")
        text_body, html_body = build_email_bodies(anomalies, desde_eval, hasta_eval)
        send_email(ALERT_SUBJECT, text_body, html_body, ALERT_RECIPIENT)
        print("[INFO] Correo enviado correctamente.")
    else:
        print("[INFO] No se encontraron anomalías con zscore > 20.")


if __name__ == "__main__":
    main()
