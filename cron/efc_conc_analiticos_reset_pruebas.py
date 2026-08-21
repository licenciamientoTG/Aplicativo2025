"""Reinicia únicamente Analíticos REGIO para volver a importar datos de prueba.

No toca conciliaciones CG-Banorte, correcciones, reclasificaciones, SG12 ni
movimientos bancarios. Requiere --confirmar para evitar una ejecución casual.
"""
from __future__ import annotations

import argparse
import json
import sys

from efc_conc_analiticos_diario import db_connection, ensure_schema, load_env_file


def count(cursor, table: str) -> int:
    return int(cursor.execute(f"SELECT COUNT(*) FROM dbo.{table}").fetchone()[0])


def main() -> int:
    parser = argparse.ArgumentParser(description="Vacía únicamente los datos de prueba de Analíticos REGIO.")
    parser.add_argument("--confirmar", action="store_true", help="Confirma el borrado limitado a efc_conc_analiticos_*.")
    args = parser.parse_args()
    if not args.confirmar:
        print("No se realizó ningún cambio. Ejecute con --confirmar para reiniciar Analíticos.", file=sys.stderr)
        return 2
    load_env_file()
    try:
        with db_connection() as connection:
            cursor = connection.cursor()
            ensure_schema(cursor)
            before = {
                "vinculos": count(cursor, "efc_conc_analiticos_vinculos") if cursor.execute("SELECT OBJECT_ID('dbo.efc_conc_analiticos_vinculos','U')").fetchone()[0] else 0,
                "errores": count(cursor, "efc_conc_analiticos_errores"),
                "papeletas": count(cursor, "efc_conc_analiticos_papeletas"),
                "importaciones": count(cursor, "efc_conc_analiticos_importaciones"),
            }
            if before["vinculos"]:
                cursor.execute("DELETE FROM dbo.efc_conc_analiticos_vinculos")
            cursor.execute("DELETE FROM dbo.efc_conc_analiticos_errores")
            cursor.execute("DELETE FROM dbo.efc_conc_analiticos_papeletas")
            cursor.execute("DELETE FROM dbo.efc_conc_analiticos_importaciones")
            ensure_schema(cursor)
            connection.commit()
        print(json.dumps({"reset": before, "aliases_preserved": True}, ensure_ascii=False))
        return 0
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
