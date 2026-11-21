-- �ndices para SUM(amount) por l�nea / tenant (fuente �nica: po_budget_allocations)
SET NAMES utf8mb4;

ALTER TABLE `po_budget_allocations`
  ADD KEY `idx_tenant_detail` (`tenant_id`, `budget_detail_id`);
