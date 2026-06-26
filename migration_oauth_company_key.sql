-- Migration: per-company OAuth tokens  (MariaDB 10.11+)
-- Lets the same user (admin_id) connect the same platform under different
-- companies, storing one row per (admin_id, company_id, platform).
-- Idempotent — safe to run more than once.
--
--   mysql -u user_inaamalvi1403 -p user_hypnotherapy_db2 < migration_oauth_company_key.sql
--
-- NOTE: this only changes the schema. Existing rows with company_id = 0
-- (legacy connections) are NOT reassigned — decide separately whether to
-- backfill them to a specific company or have users reconnect.

-- 1. Ensure the company_id column exists (already present in prod; safe).
ALTER TABLE hdb_oauth_tokens
    ADD COLUMN IF NOT EXISTS company_id INT NOT NULL DEFAULT 0 AFTER id;

-- 2. Collapse any duplicate rows WITHIN the same company (none expected)
--    before the new unique key is added.
DELETE t1 FROM hdb_oauth_tokens t1
INNER JOIN hdb_oauth_tokens t2
  WHERE t1.id < t2.id
    AND t1.admin_id   = t2.admin_id
    AND t1.company_id = t2.company_id
    AND t1.platform   = t2.platform;

-- 3. Drop the old unique keys that block per-company rows.
--    Live names: admin_platform (admin_id, platform)
--                uq_admin_platform_channel (admin_id, platform, channel_id)
ALTER TABLE hdb_oauth_tokens DROP INDEX IF EXISTS admin_platform;
ALTER TABLE hdb_oauth_tokens DROP INDEX IF EXISTS uq_admin_platform_channel;
ALTER TABLE hdb_oauth_tokens DROP INDEX IF EXISTS uniq_admin_platform; -- older name, just in case

-- 4. Add the per-company unique key.
ALTER TABLE hdb_oauth_tokens
    ADD UNIQUE KEY IF NOT EXISTS uniq_admin_company_platform (admin_id, company_id, platform);

-- 5. Helper (non-unique) index for fallback lookups by (admin_id, platform)
--    when company_id is unknown (cron publish paths).
ALTER TABLE hdb_oauth_tokens
    ADD INDEX IF NOT EXISTS idx_admin_platform (admin_id, platform);
