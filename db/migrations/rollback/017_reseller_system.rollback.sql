-- Rollback for migration 017_reseller_system.php (MySQL 8 compatible)
-- WARNING: drops reseller pricing policy data and the agent journal.
-- Run only with a database backup in place.

ALTER TABLE `product` DROP COLUMN `wholesale_price`;
ALTER TABLE `product` DROP COLUMN `min_retail_price`;
ALTER TABLE `product` DROP COLUMN `max_retail_price`;
ALTER TABLE `botsaz` DROP COLUMN `status`;
ALTER TABLE `botsaz` DROP COLUMN `platform`;
ALTER TABLE `botsaz` DROP COLUMN `expires_at`;
DROP TABLE IF EXISTS `agent_transaction`;
