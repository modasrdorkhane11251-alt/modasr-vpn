-- Rollback for migration 018 (ledger operations).
-- WARNING: drops the operation log table. Only run on a backup/test copy.
-- MySQL 8 syntax (no DROP ... IF EXISTS for columns; DROP TABLE IF EXISTS is valid).

DROP TABLE IF EXISTS `reseller_operation`;
