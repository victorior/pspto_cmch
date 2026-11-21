-- Ejecutar si la BD ya existia sin created_by.
ALTER TABLE `budget_headers`
  ADD COLUMN IF NOT EXISTS `created_by` int unsigned DEFAULT NULL COMMENT 'departments.id creador' AFTER `observations`,
  ADD KEY IF NOT EXISTS `idx_bh_created_by` (`created_by`);
