-- Perfil tributario base de OC (Factura / Boleta). NULL = legado por inferencia de lineas.
ALTER TABLE `purchase_orders`
  ADD COLUMN `tax_doc_type` varchar(16) DEFAULT NULL AFTER `voided_at`,
  ADD COLUMN `tax_mode` varchar(16) DEFAULT NULL AFTER `tax_doc_type`,
  ADD COLUMN `tax_percent` decimal(5,2) DEFAULT NULL AFTER `tax_mode`;
