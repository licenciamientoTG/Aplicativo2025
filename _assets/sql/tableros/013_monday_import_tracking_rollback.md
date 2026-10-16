# Monday import tracking: rollback and operating contract

## Scope and compatibility

`013_monday_import_tracking.sql` adds four tracking tables to `tableros` only:

- `tb_monday_import_run`: one source inventory/import run. `run_key` is a
  caller-supplied stable idempotency key unique within the source account.
- `tb_monday_import_entity`: stable source-to-local ID map. Its unique key is
  `(source_account_id, entity_type, monday_id)`; `local_id` is the local
  `tableros` identity. Names are descriptive only and must never be used to
  deduplicate.
- `tb_monday_import_checkpoint`: per-run entity status, payload hash, attempts,
  timestamps, and sanitized error code/summary. Composite FKs enforce that the
  run and mapped entity use the same source account.
- `tb_monday_import_file_checkpoint`: per-run transfer and derivative status,
  original SHA-256 and byte size, attempts, and sanitized error details. It
  references only a `file` entity map and stores no signed URL, NAS token, or
  object URL. Resolve a local file through `tb_monday_import_entity.local_id`.

Entity types cover workspace, space, folder, board, group, column, item, cell,
and file. Board source status is constrained to `active` or `archived`; source
`deleted` boards are out of scope. Run checks fix this import to 504 inventoried
boards, creator `6371`, and private target visibility. Inventory can be marked
complete only when active + archived counts equal 504 and its SHA-256 is set.

The migration is additive and does not alter/import into existing business
tables. SQL Server FKs intentionally use `NO ACTION`. Existing SQL Server/PDO
`sqlsrv` code can use ordinary parameterized `INSERT`/`UPDATE` statements and
read generated identities with `OUTPUT INSERTED.id`; submit hash strings as 64
hex characters. Use transactions for map/checkpoint transitions. For concurrent
workers, rely on unique keys and handle duplicate-key races by selecting the
existing row using the full source key.

Only store an allowlisted error code and a short sanitized summary. The checks
reject common URL, bearer, and API-key markers, but cannot detect every secret
or sensitive value; application code must never persist raw API responses,
cell contents, tokens, signed URLs, or exception dumps. Hashes are integrity
metadata and do not replace NAS verification.

## Safe re-entry

Use the same `source_account_id`, entity type, and Monday ID to find a mapped
entity across runs. Create one checkpoint per `(run_id, entity_id)`. A resumed
run skips only entities whose checkpoint is complete and whose recorded hash
matches the current source hash. If a payload hash changed, reprocess that
entity and its dependents in dependency order; update the map's `local_id` only
after the local transaction commits. File transfer completion requires a
verified original hash/size and required NAS derivatives. Keep archived-board
transition as a separate retryable phase after content verification. Never
infer source identity from a local name.

## Forward deployment

Connect DBeaver directly to `tableros` and execute
`013_monday_import_tracking.sql` after `012_access_control.sql`. The script has
no `GO` separators and wraps its additive DDL in a transaction. It performs no
import and does not contact Monday or NAS.

## Rollback and data-loss assessment

`013_monday_import_tracking_rollback.sql` drops only these four new tables and
only when every table is empty. It stops without deleting anything when it
finds runs, mappings, or checkpoints. This is intentional: populated mappings
are the only durable deduplication/provenance record and dropping them can make
a later import create duplicate boards, items, or files. Retain them by
default. If a populated rollback is ever required, first export and reconcile
all source-to-local mappings and NAS file hashes, assess dependent application
references, and obtain explicit authorization; prepare a separately reviewed
data-bearing rollback for that exact state. No existing business data or NAS
objects are deleted by either script.

## Read-only post-deployment verification

```sql
SELECT t.name
FROM sys.tables AS t
WHERE t.name IN (
    'tb_monday_import_run',
    'tb_monday_import_entity',
    'tb_monday_import_checkpoint',
    'tb_monday_import_file_checkpoint'
)
ORDER BY t.name;

SELECT i.name, i.is_unique, i.filter_definition
FROM sys.indexes AS i
WHERE i.object_id IN (
    OBJECT_ID(N'dbo.tb_monday_import_run'),
    OBJECT_ID(N'dbo.tb_monday_import_entity'),
    OBJECT_ID(N'dbo.tb_monday_import_checkpoint'),
    OBJECT_ID(N'dbo.tb_monday_import_file_checkpoint')
)
  AND i.index_id > 0
ORDER BY OBJECT_NAME(i.object_id), i.index_id;

SELECT COUNT_BIG(*) AS run_count FROM dbo.tb_monday_import_run;
SELECT COUNT_BIG(*) AS entity_map_count FROM dbo.tb_monday_import_entity;
SELECT COUNT_BIG(*) AS entity_checkpoint_count FROM dbo.tb_monday_import_checkpoint;
SELECT COUNT_BIG(*) AS file_checkpoint_count FROM dbo.tb_monday_import_file_checkpoint;
```

Expected immediately after migration: all four tables exist and counts are
zero. The SQL files were not executed as part of this change.
