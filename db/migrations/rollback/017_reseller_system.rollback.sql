-- Rollback for migration 017_reseller_system.php
-- WARNING: drops reseller pricing policy data and the agent journal.
-- Run only with a database backup in place.

ALTER TABLE `product` DROP COLUMN IF EXISTS `wholesale_price`;
ALTER TABLE `product` DROP COLUMN IF EXISTS `min_retail_price`;
ALTER TABLE `product` DROP COLUMN IF EXISTS `max_retail_price`;
ALTER TABLE `botsaz` DROP COLUMN IF EXISTS `status`;
ALTER TABLE `botsaz` DROP COLUMN IF EXISTS `platform`;
ALTER TABLE `botsaz` DROP COLUMN IF EXISTS `expires_at`;
DROP TABLE IF EXISTS `agent_transaction`;
