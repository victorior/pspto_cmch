-- Paso 1: agregar account_id (id de plan_cuenta). Ejecutar luego: php database/import_plan_cuenta.php --all-tenants

ALTER TABLE `account_codes`
  ADD COLUMN `account_id` BIGINT UNSIGNED NULL AFTER `tenant_id`;
