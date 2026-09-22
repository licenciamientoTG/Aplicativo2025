"""Focused checks for deterministic automatic-link tie resolution.

Run with: python cron/test_efc_conc_analiticos_diario.py
"""
from datetime import date
import sys
import types
import unittest

# Estas pruebas sólo ejercitan helpers sin I/O; permitir su ejecución aun si el
# runtime de desarrollo no tiene los conectores de producción instalados.
sys.modules.setdefault("pyodbc", types.ModuleType("pyodbc"))
sys.modules.setdefault("xlrd", types.ModuleType("xlrd"))
if "openpyxl" not in sys.modules:
    openpyxl = types.ModuleType("openpyxl")
    openpyxl.load_workbook = None
    sys.modules["openpyxl"] = openpyxl

from efc_conc_analiticos_diario import (
    closest_paper,
    is_total_gas_excel,
    parse_consolidated_tsv,
    remittance_sort_key,
    sequential_tie_groups,
)


def turn(turn_date: date, label: str, amount: float = 100.0) -> dict:
    return {"date": turn_date, "turn": label, "concept": "MN", "amount": amount}


def paper(identifier: int, remittance: object, paper_date: date) -> dict:
    return {"id": identifier, "remittance": remittance, "date": paper_date}


class SequentialRemittanceTieTests(unittest.TestCase):
    def test_accepts_diaz_gas_and_gasomex_analytic_filenames(self) -> None:
        self.assertTrue(is_total_gas_excel("TOTAL GAS 16-09-2026.xls"))
        self.assertTrue(is_total_gas_excel("GASOMEX 16-09-2026.xls"))
        self.assertTrue(is_total_gas_excel("ANALITICOS GASOMEX 16-09-2026.xlsx"))
        self.assertFalse(is_total_gas_excel("ACTA DIFERENCIA GASOMEX 16-09-2026.pdf"))
        self.assertFalse(is_total_gas_excel("reporte_16-09-2026.xls"))

    def test_pairs_turns_by_turn_order_then_numeric_remittance(self) -> None:
        same_day = date(2026, 9, 14)
        first, second = turn(same_day, "Turno 1"), turn(same_day, "Turno 2")
        high = paper(8, "10243944.0", same_day)
        low = paper(7, "10243941.0", same_day)

        groups = sequential_tie_groups([(second, [(high, 0), (low, 0)]), (first, [(high, 0), (low, 0)])])

        self.assertEqual(1, len(groups))
        turns, papers, gap = groups[0]
        self.assertEqual([first, second], turns)
        self.assertEqual([low, high], papers)
        self.assertEqual(0, gap)

    def test_different_date_gaps_pair_known_turns_by_remittance_sequence(self) -> None:
        paper_date = date(2026, 9, 9)
        turno_4 = {"date": date(2026, 9, 8), "turn": "Turno 4", "concept": "USD", "amount": 31.0}
        turno_1 = {"date": paper_date, "turn": "Turno 1", "concept": "USD", "amount": 31.0}
        low = paper(7, "10238785", paper_date)
        high = paper(8, "10238787", paper_date)

        groups = sequential_tie_groups([
            (turno_1, [(high, 0), (low, 0)]),
            (turno_4, [(high, 1), (low, 1)]),
        ])

        self.assertEqual(1, len(groups))
        turns, papers, _diagnostic_gap = groups[0]
        self.assertEqual([turno_4, turno_1], turns)
        self.assertEqual([low, high], papers)

    def test_twenty_day_gap_still_uses_chronological_sequence(self) -> None:
        paper_date = date(2026, 10, 1)
        early = turn(date(2026, 9, 11), "Turno 2")
        later = turn(date(2026, 9, 29), "Turno 1")
        low = paper(7, "10238785", paper_date)
        high = paper(8, "10238787", paper_date)

        groups = sequential_tie_groups([
            (later, [(high, 2), (low, 2)]),
            (early, [(high, 20), (low, 20)]),
        ])

        self.assertEqual(1, len(groups))
        turns, papers, _diagnostic_gap = groups[0]
        self.assertEqual([early, later], turns)
        self.assertEqual([low, high], papers)

    def test_single_turn_with_two_equally_close_papers_stays_ambiguous(self) -> None:
        same_day = date(2026, 9, 14)
        current_turn = turn(same_day, "Turno 1")
        choices = [paper(7, "10243941.0", same_day), paper(8, "10243944.0", same_day)]

        self.assertIsNone(closest_paper(choices, current_turn))
        self.assertEqual([], sequential_tie_groups([(current_turn, [(choices[0], 0), (choices[1], 0)])]))

    def test_unequal_tie_group_cardinality_stays_ambiguous(self) -> None:
        same_day = date(2026, 9, 14)
        first, second, third = turn(same_day, "Turno 1"), turn(same_day, "Turno 2"), turn(same_day, "Turno 3")
        papers = [paper(identifier, str(identifier), same_day) for identifier in (7, 8, 9)]
        three_choices = [(item, 0) for item in papers]
        two_choices = three_choices[:2]

        self.assertEqual([], sequential_tie_groups([(first, three_choices), (second, three_choices)]))
        self.assertEqual([], sequential_tie_groups([(first, two_choices), (second, two_choices), (third, two_choices)]))

    def test_existing_unique_nearest_paper_is_unchanged(self) -> None:
        same_day = date(2026, 9, 14)
        current_turn = turn(same_day, "Turno 1")
        nearest = paper(7, "10243941.0", same_day)
        later = paper(8, "10243944.0", date(2026, 9, 15))

        self.assertEqual((nearest, 0), closest_paper([later, nearest], current_turn))

    def test_remittance_fallback_is_deterministic(self) -> None:
        self.assertLess(remittance_sort_key(paper(2, "10243941.0", date.today())), remittance_sort_key(paper(1, "10243944.0", date.today())))
        self.assertLess(remittance_sort_key(paper(1, "sin remesa", date.today())), remittance_sort_key(paper(2, "sin remesa", date.today())))
        self.assertLess(remittance_sort_key(paper(2, "10243941.0", date.today())), remittance_sort_key(paper(1, "10243941.00", date.today())))

    def test_consolidated_file_uses_date_column_and_applies_requested_correction(self) -> None:
        content = (
            "Fecha\tCodigo\tEstacion\tHora\tCuenta\tRemesa\tDICE MN\tREAL MN\tDif MN\tDICE USD\tREAL USD\tDif USD\n"
            "09/09/2026\t12097\tSANTIAGO TRONCOSO\t\t65507674669\t10209599\t$12,550.15\t$12,550.00\t-$0.15\t\t\t\n"
            "13/09/2026\t12097\tSANTIAGO TRONCOSO\t\t65507674669\t10209599\t$149,912.30\t$149,912.00\t-$0.30\t\t\t\n"
        ).encode()
        papers, errors = parse_consolidated_tsv(
            content,
            "concentrado.txt",
            [(28, "Santiago")],
            {"12097": 28},
        )

        self.assertEqual([], errors)
        self.assertEqual(["9569", "10209599"], [item[11] for item in papers])
        self.assertEqual(12550.15, papers[0][12])


if __name__ == "__main__":
    unittest.main()
