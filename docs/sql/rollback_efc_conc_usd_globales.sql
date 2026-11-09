/*
   Non-destructive rollback for efc_conc_usd_globales:
   revert the application code/routes and leave this table and its audit rows in
   TG. Do not DROP the table/index or mark assignments released automatically;
   both actions could destroy auditability or change reconciliation results.
   Reapply the forward migration to restore the application-facing schema.
*/
