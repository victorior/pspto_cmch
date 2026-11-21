<?php

declare(strict_types=1);

/**
 * Carga modulos de dominio segun la ruta (evita Oc* en presupuesto puro y viceversa).
 * Si la ruta no es reconocible, carga todo (comportamiento conservador).
 */
function budjetoc_load_app_modules(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $isBudget = str_contains($script, '/budget/');
    $isOc = str_contains($script, '/oc/');
    $isAdmin = str_contains($script, '/admin/');
    $known = $isBudget || $isOc || $isAdmin;

    $needBudget = $isBudget || $isOc;
    $needOc = $isOc;
    $needCatalog = $isAdmin || $isOc;
    $needEditPolicy = $isBudget || $isAdmin;

    if (!$known) {
        $needBudget = $needOc = $needCatalog = $needEditPolicy = true;
    }

    if ($needEditPolicy) {
        require_once __DIR__ . '/EditPolicyService.php';
    }
    if ($isAdmin) {
        require_once __DIR__ . '/AdminUserCsvImportService.php';
    }
    if ($needCatalog) {
        require_once __DIR__ . '/CatalogService.php';
    }
    if ($needBudget) {
        require_once __DIR__ . '/AccountCodeCatalog.php';
        require_once __DIR__ . '/BudgetService.php';
        require_once __DIR__ . '/BudgetExportService.php';
        require_once __DIR__ . '/BudgetActivityRules.php';
    }
    if ($needOc) {
        require_once __DIR__ . '/OcService.php';
        require_once __DIR__ . '/OcPrintView.php';
        require_once __DIR__ . '/TenantPrintSettings.php';
        require_once __DIR__ . '/oc_tax_profile.php';
        require_once __DIR__ . '/ProductService.php';
        require_once __DIR__ . '/SupplierService.php';
    }

    $loaded = true;
}
