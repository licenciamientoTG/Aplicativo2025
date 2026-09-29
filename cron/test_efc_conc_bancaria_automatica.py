"""Focused checks for GASOMEX's continuous operational shift sequence."""

from datetime import date, timedelta
import sys
import types
import unittest

odbc = types.ModuleType("pyodbc")
odbc.Cursor = type("Cursor", (), {})
odbc.Connection = type("Connection", (), {})
sys.modules.setdefault("pyodbc", odbc)

from efc_conc_bancaria_automatica import gasomex_candidates, gasomex_slots


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
        return gasomex_candidates(bank, slots, set()), slots, bank

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
        self.assertEqual(gasomex_candidates(bank, slots, set()), [])
        complete = gasomex_slots(rows, links, set(), 23)
        used_key = complete["MN"][(date(2026, 9, 10), "3")]["items"][0]["key"]
        self.assertEqual(gasomex_candidates(bank, complete, {used_key}), [])

    def test_bank_cannot_precede_last_shift_or_cross_duplicate(self):
        rows, links = self.sequence(2, 4)
        complete = gasomex_slots(rows, links, set(), 23)
        total = sum(row["MN"] for row in rows)
        self.assertEqual(gasomex_candidates((1, "2026-09-10", total, "", "", None, "65507674547"), complete, set()), [])
        duplicate = gasomex_slots(rows + [rows[1]], links, set(), 23)
        self.assertEqual(gasomex_candidates((1, "2026-09-20", total, "", "", None, "65507674547"), duplicate, set()), [])


if __name__ == "__main__":
    unittest.main()
