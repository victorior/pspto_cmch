-- Rellena fecha de anulacion en OCs ya marcadas como anuladas.
UPDATE purchase_orders
SET voided_at = COALESCE(voided_at, ordered_at, created_at, NOW())
WHERE status = 2 AND voided_at IS NULL;
