-- Migration : ajout de raw_email_json et force_published_at sur t_ignored_emails
-- A lancer sur la prod AVANT de déployer le code.
-- Idempotent : les colonnes ne sont ajoutées que si elles n'existent pas.

ALTER TABLE `t_ignored_emails`
  ADD COLUMN IF NOT EXISTS `raw_email_json`    LONGTEXT     NULL DEFAULT NULL AFTER `excerpt`,
  ADD COLUMN IF NOT EXISTS `force_published_at` TIMESTAMP   NULL DEFAULT NULL AFTER `raw_email_json`;
