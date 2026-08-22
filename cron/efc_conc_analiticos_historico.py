"""Carga única de Analíticos REGIO históricos.

Importa únicamente archivos TOTAL GAS con fecha entre 31/07/2026 y 17/08/2026,
inclusive. La fecha es la del nombre del Excel, no la de recepción del correo.
Comparte el lector, la idempotencia SHA-256 y la deduplicación por REM NUM del proceso diario.
No modifica correos ni tablas fuente.
"""
from __future__ import annotations

import argparse
from datetime import datetime
import json
import sys

from efc_conc_analiticos_diario import load_env_file, sync


DEFAULT_FROM = "2026-07-31"
DEFAULT_TO = "2026-08-17"


def parse_date(value: str) -> datetime.date:
    try:
        return datetime.strptime(value, "%Y-%m-%d").date()
    except ValueError as exc:
        raise argparse.ArgumentTypeError("Use formato AAAA-MM-DD.") from exc


def main() -> int:
    parser = argparse.ArgumentParser(description="Importa una sola vez Analíticos REGIO históricos.")
    parser.add_argument("--from", dest="start_date", type=parse_date, default=parse_date(DEFAULT_FROM), help=f"Inicio inclusivo (predeterminado: {DEFAULT_FROM}).")
    parser.add_argument("--to", dest="end_date", type=parse_date, default=parse_date(DEFAULT_TO), help=f"Fin inclusivo (predeterminado: {DEFAULT_TO}).")
    parser.add_argument("--reprocess", action="store_true", help="Vuelve a interpretar archivos ya importados dentro del rango.")
    args = parser.parse_args()
    load_env_file()
    try:
        result = sync(args.start_date, args.end_date, include_archived=True, reprocess=args.reprocess)
        result["from"] = args.start_date.isoformat()
        result["to"] = args.end_date.isoformat()
        print(json.dumps(result, ensure_ascii=False))
        return 0
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
