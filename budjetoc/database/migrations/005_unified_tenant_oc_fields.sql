-- Campos OC/impresi�n y plan de cuentas (paridad con oc/oc)
SET NAMES utf8mb4;

ALTER TABLE `tenants`
  ADD COLUMN `login_logo_url` varchar(512) DEFAULT NULL AFTER `slug`;

ALTER TABLE `tenant_settings`
  ADD COLUMN `business_line` varchar(128) NOT NULL DEFAULT '' AFTER `phone`,
  ADD COLUMN `print_contact_name` varchar(120) NOT NULL DEFAULT '' AFTER `business_line`,
  ADD COLUMN `print_contact_role` varchar(120) NOT NULL DEFAULT '' AFTER `print_contact_name`,
  ADD COLUMN `print_contact_email` varchar(255) NOT NULL DEFAULT '' AFTER `print_contact_role`,
  ADD COLUMN `print_delivery_hours` varchar(255) NOT NULL DEFAULT '' AFTER `print_contact_email`,
  ADD COLUMN `print_delivery_address` varchar(255) NOT NULL DEFAULT '' AFTER `print_delivery_hours`,
  ADD COLUMN `print_invoice_to` varchar(255) NOT NULL DEFAULT '' AFTER `print_delivery_address`,
  ADD COLUMN `print_footer_text` varchar(255) NOT NULL DEFAULT '' AFTER `print_invoice_to`,
  ADD COLUMN `print_logo_path` varchar(255) NOT NULL DEFAULT '' AFTER `print_footer_text`,
  ADD COLUMN `bodega_user_id` int unsigned DEFAULT NULL AFTER `print_logo_path`,
  ADD KEY `idx_ts_bodega` (`bodega_user_id`);

ALTER TABLE `purchase_orders`
  ADD COLUMN `seller_ref` varchar(64) NOT NULL DEFAULT '' AFTER `supplier_id`,
  ADD COLUMN `payment_terms` varchar(32) NOT NULL DEFAULT '' AFTER `seller_ref`,
  ADD COLUMN `account_code_1` int NOT NULL DEFAULT 0 AFTER `note`,
  ADD COLUMN `account_code_2` int NOT NULL DEFAULT 0 AFTER `account_code_1`,
  ADD COLUMN `account_code_3` int NOT NULL DEFAULT 0 AFTER `account_code_2`,
  ADD COLUMN `account_code_4` int NOT NULL DEFAULT 0 AFTER `account_code_3`,
  ADD COLUMN `invoice_number_in` bigint NOT NULL DEFAULT 0 AFTER `account_code_4`,
  ADD COLUMN `received` tinyint NOT NULL DEFAULT 0 AFTER `invoice_number_in`;

CREATE TABLE IF NOT EXISTS `plan_cuenta` (
  `id` int NOT NULL,
  `name` varchar(80) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
