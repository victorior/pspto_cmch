-- BudgetOC (budjetoc) � una BD, multitenant, presupuesto + OC separados por login
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `tenants` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `login_logo_url` varchar(512) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `budget_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `oc_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tenant_settings` (
  `tenant_id` int unsigned NOT NULL,
  `company_name` varchar(255) NOT NULL DEFAULT '',
  `address` varchar(255) NOT NULL DEFAULT '',
  `tax_id` varchar(64) NOT NULL DEFAULT '',
  `phone` varchar(64) NOT NULL DEFAULT '',
  `business_line` varchar(128) NOT NULL DEFAULT '',
  `print_contact_name` varchar(120) NOT NULL DEFAULT '',
  `print_contact_role` varchar(120) NOT NULL DEFAULT '',
  `print_contact_email` varchar(255) NOT NULL DEFAULT '',
  `print_delivery_hours` varchar(255) NOT NULL DEFAULT '',
  `print_delivery_address` varchar(255) NOT NULL DEFAULT '',
  `print_invoice_to` varchar(255) NOT NULL DEFAULT '',
  `print_footer_text` varchar(255) NOT NULL DEFAULT '',
  `print_logo_path` varchar(255) NOT NULL DEFAULT '',
  `bodega_user_id` int unsigned DEFAULT NULL,
  `tax_percent` decimal(5,2) NOT NULL DEFAULT 19.00,
  PRIMARY KEY (`tenant_id`),
  KEY `idx_ts_bodega` (`bodega_user_id`),
  CONSTRAINT `fk_ts_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `full_name` varchar(255) NOT NULL DEFAULT '',
  `avatar_url` varchar(512) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`),
  UNIQUE KEY `uq_google_id` (`google_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `super_admins` (
  `user_id` int unsigned NOT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_sa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/** app: budget | oc � accesos separados por aplicaci�n */
CREATE TABLE IF NOT EXISTS `user_tenant_roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `app` enum('budget','oc') NOT NULL,
  `role` varchar(32) NOT NULL DEFAULT 'operator',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_tenant_app` (`user_id`,`tenant_id`,`app`),
  KEY `idx_tenant_app` (`tenant_id`,`app`),
  CONSTRAINT `fk_utr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_utr_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/**
 * Organizacion interna del colegio (divisiones y coordinaciones).
 * type estandar = division; type coordinacion = coordinacion / C.C. organico.
 * Permisos y created_by apuntan aqui. Ver CostCenterCatalog para el catalogo contable plano.
 */
CREATE TABLE IF NOT EXISTS `departments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` enum('estandar','coordinacion') NOT NULL DEFAULT 'estandar',
  `parent_id` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `fk_dept_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dept_parent` FOREIGN KEY (`parent_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/**
 * Catalogo plano de centros de costo contables (sin FK a departments).
 * Usado en budget_headers.cost_center_id cuando la division carga la actividad.
 */
CREATE TABLE IF NOT EXISTS `cost_centers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `fk_cc_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activities` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `fk_act_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `account_codes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL COMMENT 'Id de plan_cuenta (ej. 51131002)',
  `name` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_account_id` (`tenant_id`,`account_id`),
  CONSTRAINT `fk_ac_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_department_permissions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `department_id` int unsigned NOT NULL,
  `can_edit` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_tenant_dept` (`user_id`,`tenant_id`,`department_id`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `fk_udp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_udp_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_udp_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tenant_department_edit_policy` (
  `tenant_id` int unsigned NOT NULL,
  `department_id` int unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `allow_edit` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`tenant_id`,`department_id`,`year`),
  CONSTRAINT `fk_tdep_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tdep_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_sequences` (
  `tenant_id` int unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `last_number` int unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`,`year`),
  CONSTRAINT `fk_as_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `budget_headers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `activity_number` varchar(32) NOT NULL,
  `budget_year` smallint unsigned NOT NULL DEFAULT 0,
  `month` tinyint unsigned NOT NULL,
  `cost_center_id` int unsigned DEFAULT NULL COMMENT 'cost_centers.id; solo si creador es division',
  `activity_id` int unsigned NOT NULL,
  `division_id` int unsigned DEFAULT NULL COMMENT 'departments type estandar (destino/creador)',
  `department_id` int unsigned DEFAULT NULL COMMENT 'departments: coordinacion creadora o sub-area estandar',
  `observations` text,
  `created_by` int unsigned DEFAULT NULL COMMENT 'departments.id creador (division o coordinacion)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_activity` (`tenant_id`,`activity_number`),
  KEY `idx_tenant_month` (`tenant_id`,`month`),
  KEY `idx_bh_tenant_year_month` (`tenant_id`,`budget_year`,`month`),
  KEY `idx_bh_tenant_year_division` (`tenant_id`,`budget_year`,`division_id`),
  KEY `idx_bh_tenant_year_department` (`tenant_id`,`budget_year`,`department_id`),
  KEY `idx_bh_tenant_year_created_by` (`tenant_id`,`budget_year`,`created_by`),
  CONSTRAINT `fk_bh_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bh_cc` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_bh_act` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_bh_div` FOREIGN KEY (`division_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bh_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bh_created_by` FOREIGN KEY (`created_by`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `budget_details` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `budget_header_id` int unsigned NOT NULL,
  `account_code_id` int unsigned NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `observation` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_header` (`budget_header_id`),
  CONSTRAINT `fk_bd_header` FOREIGN KEY (`budget_header_id`) REFERENCES `budget_headers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bd_ac` FOREIGN KEY (`account_code_id`) REFERENCES `account_codes` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `legal_name` varchar(255) NOT NULL DEFAULT '',
  `trade_name` varchar(255) NOT NULL DEFAULT '',
  `phone` varchar(64) NOT NULL DEFAULT '',
  `email` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  CONSTRAINT `fk_sup_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(255) NOT NULL,
  `unit_price` decimal(14,4) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_code` (`tenant_id`,`code`),
  CONSTRAINT `fk_prod_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `folio_year` varchar(6) NOT NULL,
  `numero_oc` int NOT NULL,
  `ordered_at` datetime NOT NULL,
  `supplier_id` int unsigned NOT NULL,
  `seller_ref` varchar(64) NOT NULL DEFAULT '',
  `payment_terms` varchar(32) NOT NULL DEFAULT '',
  `total_amount` decimal(18,4) NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '0 abierta 1 cerrada 2 anulada',
  `note` varchar(500) NOT NULL DEFAULT '',
  `account_code_1` int NOT NULL DEFAULT 0,
  `account_code_2` int NOT NULL DEFAULT 0,
  `account_code_3` int NOT NULL DEFAULT 0,
  `account_code_4` int NOT NULL DEFAULT 0,
  `invoice_number_in` bigint NOT NULL DEFAULT 0,
  `received` tinyint NOT NULL DEFAULT 0,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `voided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_folio_num` (`tenant_id`,`folio_year`,`numero_oc`),
  KEY `idx_tenant_status` (`tenant_id`,`status`),
  KEY `idx_po_tenant_year_status_num` (`tenant_id`,`folio_year`,`status`,`numero_oc`),
  CONSTRAINT `fk_po_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_po_sup` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_po_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `po_lines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `purchase_order_id` int unsigned NOT NULL,
  `product_code` varchar(64) NOT NULL DEFAULT '',
  `product_name` varchar(512) NOT NULL DEFAULT '',
  `qty` decimal(12,4) NOT NULL DEFAULT 0,
  `unit_price` decimal(18,4) NOT NULL DEFAULT 0,
  `sort_order` smallint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_po` (`purchase_order_id`),
  CONSTRAINT `fk_pol_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pol_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/** Imputaci�n OC ? l�nea de presupuesto (mismo tenant, sin API) */
CREATE TABLE IF NOT EXISTS `po_budget_allocations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `purchase_order_id` int unsigned NOT NULL,
  `budget_detail_id` int unsigned NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0,
  `req_number` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_po` (`purchase_order_id`),
  KEY `idx_detail` (`budget_detail_id`),
  KEY `idx_tenant_detail` (`tenant_id`,`budget_detail_id`),
  CONSTRAINT `fk_pba_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pba_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pba_detail` FOREIGN KEY (`budget_detail_id`) REFERENCES `budget_details` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plan_cuenta` (
  `id` int NOT NULL,
  `name` varchar(80) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
