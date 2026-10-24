-- Rollback for migration 021_user_identity
ALTER TABLE `user`
    DROP KEY `uq_user_miniapp_key`,
    DROP KEY `ix_user_google_sub`,
    DROP COLUMN `display_name`,
    DROP COLUMN `miniapp_key`,
    DROP COLUMN `google_sub`,
    DROP COLUMN `google_email`;
