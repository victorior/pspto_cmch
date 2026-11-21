<?php

declare(strict_types=1);

/** @return list<array{href: string, label: string, active?: bool}> */
function oc_layout_nav(PDO $pdo, int $year, string $active = 'list'): array
{
    $yp = urlencode((string) $year);
    $uid = (int) (Auth::userId() ?? 0);
    $tid = (int) (Auth::tenantId() ?? 0);

    $nav = [
        ['href' => 'index.php?year=' . $yp . '&status=0', 'label' => 'OC abiertas', 'active' => $active === 'open'],
        ['href' => 'index.php?year=' . $yp . '&status=1', 'label' => 'OC cerradas', 'active' => $active === 'closed'],
        ['href' => 'index.php?year=' . $yp . '&status=2', 'label' => 'OC anuladas', 'active' => $active === 'void'],
        ['href' => 'oc_new.php?year=' . $yp, 'label' => 'Nueva OC', 'active' => $active === 'new'],
        ['href' => 'budget.php?year=' . $yp, 'label' => 'Presupuesto', 'active' => $active === 'budget'],
        ['href' => 'products.php?year=' . $yp, 'label' => 'Productos', 'active' => $active === 'products'],
        ['href' => 'suppliers.php?year=' . $yp, 'label' => 'Proveedores', 'active' => $active === 'suppliers'],
    ];
    if ($uid > 0 && $tid > 0 && UserPermissions::canManageOcPrintTemplate($pdo, $uid, $tid)) {
        $nav[] = [
            'href' => 'settings.php?year=' . $yp,
            'label' => t_es('plantilla_impresion'),
            'active' => $active === 'settings',
        ];
    }

    return $nav;
}
