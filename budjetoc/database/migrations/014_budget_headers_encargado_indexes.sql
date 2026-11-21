-- Dashboard encargado: filtros por division / coordinacion / creador sobre tenant+ano.
CREATE INDEX `idx_bh_tenant_year_division` ON `budget_headers` (`tenant_id`, `budget_year`, `division_id`);
CREATE INDEX `idx_bh_tenant_year_department` ON `budget_headers` (`tenant_id`, `budget_year`, `department_id`);
CREATE INDEX `idx_bh_tenant_year_created_by` ON `budget_headers` (`tenant_id`, `budget_year`, `created_by`);
