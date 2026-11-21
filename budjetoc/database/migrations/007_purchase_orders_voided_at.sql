-- Fecha de anulacion de OC (soft delete, conserva po_budget_allocations).
ALTER TABLE `purchase_orders`
  ADD COLUMN `voided_at` datetime DEFAULT NULL AFTER `created_at`;
