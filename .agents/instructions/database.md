# Database Instructions

The application uses SQL Server through PDO `sqlsrv`: central `TG`, secondary
`SG12`, and station databases accessed through linked servers. Database changes
may affect 40+ station operations.

- Whenever providing SQL for this project, make it directly executable in
  DBeaver. Do not use the `GO` batch separator; separate statements with
  semicolons and account for DBeaver's SQL Server execution behavior.

- Inspect existing SQL/model usage before proposing changes. Parameterize values
  and preserve transaction boundaries.
- For schema or data changes, provide a forward migration, compatibility impact,
  rollback approach, affected databases, and data-loss assessment.
- Treat foreign keys, constraints, indexes, and cross-database queries as
  production-impacting. Do not execute destructive data changes without explicit
  authorization.
- There is no migration framework in this repository; supply reviewed SQL in the
  agreed project location or handoff rather than inventing one.
