-- Listado OC por tenant, folio y estado.
CREATE INDEX `idx_po_tenant_year_status_num` ON `purchase_orders` (`tenant_id`, `folio_year`, `status`, `numero_oc`);
