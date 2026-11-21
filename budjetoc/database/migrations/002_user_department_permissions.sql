-- Permisos por division / coordinacion (arquitectura BudgetFlow / PSPTO)

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
