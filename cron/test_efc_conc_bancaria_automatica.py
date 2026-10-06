"""Focused checks for GASOMEX's continuous operational shift sequence."""

from datetime import date, timedelta
import sys
import types
import unittest

odbc = types.ModuleType("pyodbc")
odbc.Cursor = type("Cursor", (), {})
odbc.Connection = type("Connection", (), {})
sys.modules.setdefault("pyodbc", odbc)

from efc_conc_bancaria_automatica import gasomex_candidates, gasomex_slots, paired_turns, praxedis_slots


class ManualPairReservationTests(unittest.TestCase):
    def test_pair_query_normalizes_turn_and_handles_old_schema(self):
        class Cursor:
            def __init__(self, has_column):
                self.has_column = has_column
                self.queries = []

            def execute(self, sql):
                self.queries.append(sql)
                return self

            def fetchone(self):
                return (4 if self.has_column else None,)

            def fetchall(self):
                return [(40, '2026-09-10', 'Turno 1', 'MN')]

        old = Cursor(False)
        self.assertEqual(paired_turns(old), set())
        self.assertEqual(len(old.queries), 1)
        self.assertEqual(paired_turns(Cursor(True)), {(40, '2026-09-10', '1', 'MN')})

    def test_pair_blocks_praxedis_and_gasomex_slots(self):
        rows = [{'Fecha': '2026-09-10', 'Turno': '1', 'MN': 100, 'Morralla': 0, 'Dolares': 0}]
        praxedis = praxedis_slots(rows, {'cg-40-2026-09-10-1-MN'})
        self.assertTrue(praxedis[(date(2026, 9, 10), '1')]['blocked'])
        gasomex = gasomex_slots(rows, {}, {'cg-23-2026-09-10-1-MN'}, 23)
        self.assertTrue(gasomex['MN'][(date(2026, 9, 10), '1')]['blocked'])


class GasomexSequenceTests(unittest.TestCase):
    def sequence(self, first_turn, count, missing_usd=False):
        day, turn = date(2026, 9, 10), first_turn
        rows, links = [], {}
        for index in range(count):
            raw_usd = 0 if missing_usd and turn == 4 else 10
            rows.append({"Fecha": day.isoformat(), "Turno": str(turn), "MN": index + 11,
                         "Morralla": 0, "Dolares": raw_usd, "Dolares2": 0})
            links[(day.isoformat(), str(turn), "MN")] = index + 11
            if raw_usd:
                links[(day.isoformat(), str(turn), "USD")] = raw_usd
            if turn == 4:
                day, turn = day + timedelta(days=1), 1
            else:
                turn += 1
        return rows, links

    def candidate(self, first_turn, count, account="65507674547", missing_usd=False):
        rows, links = self.sequence(first_turn, count, missing_usd)
        slots = gasomex_slots(rows, links, set(), 23)
        bucket = "USD" if account.endswith("8214") else "MN"
        total = sum(item["amount"] for slot in slots[bucket].values() for item in slot["items"])
        bank = (1, "2026-09-20", total, "", "", None, account)
        return gasomex_candidates(bank, slots, set(), 23), slots, bank

    def test_normal_extended_compensation_and_weekend(self):
        for start, length in ((2, 4), (2, 5), (3, 3), (2, 8), (2, 9)):
            with self.subTest(start=start, length=length):
                candidates, _, _ = self.candidate(start, length)
                self.assertEqual(len(candidates), 1)
                self.assertEqual(candidates[0]["shifts"], length)

    def test_zero_usd_shift_does_not_break_run(self):
        candidates, _, _ = self.candidate(2, 4, "65504998214", True)
        self.assertEqual(len(candidates), 1)
        self.assertEqual(len(candidates[0]["items"]), 3)

    def test_missing_link_and_used_turn_block_run(self):
        rows, links = self.sequence(2, 4)
        missing = dict(links)
        del missing[(rows[1]["Fecha"], rows[1]["Turno"], "MN")]
        slots = gasomex_slots(rows, missing, set(), 23)
        bank = (1, "2026-09-20", sum(row["MN"] for row in rows), "", "", None, "65507674547")
        self.assertEqual(gasomex_candidates(bank, slots, set(), 23), [])
        complete = gasomex_slots(rows, links, set(), 23)
        used_key = complete["MN"][(date(2026, 9, 10), "3")]["items"][0]["key"]
        self.assertEqual(gasomex_candidates(bank, complete, {used_key}, 23), [])

    def test_bank_cannot_precede_last_shift_or_cross_duplicate(self):
        rows, links = self.sequence(2, 4)
        complete = gasomex_slots(rows, links, set(), 23)
        total = sum(row["MN"] for row in rows)
        self.assertEqual(gasomex_candidates((1, "2026-09-10", total, "", "", None, "65507674547"), complete, set(), 23), [])
        duplicate = gasomex_slots(rows + [rows[1]], links, set(), 23)
        self.assertEqual(gasomex_candidates((1, "2026-09-20", total, "", "", None, "65507674547"), duplicate, set(), 23), [])


if __name__ == "__main__":
    unittest.main()
