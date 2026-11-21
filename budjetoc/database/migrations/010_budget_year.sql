-- Ano presupuestario indexable (antes se parseaba desde activity_number).
ALTER TABLE `budget_headers`
  ADD COLUMN `budget_year` smallint unsigned NOT NULL DEFAULT 0 AFTER `activity_number`;

UPDATE `budget_headers`
SET `budget_year` = CAST(SUBSTRING_INDEX(`activity_number`, '-', -1) AS UNSIGNED)
WHERE `budget_year` = 0;

CREATE INDEX `idx_bh_tenant_year_month` ON `budget_headers` (`tenant_id`, `budget_year`, `month`);
