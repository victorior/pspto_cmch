<?php

declare(strict_types=1);

/**
 * Utilidades compartidas para paginas de catalogo por tenant.
 *
 * @return array{pdo: PDO, tid: int, tenant: array{id: int, name: string, slug: string}, err: string, ok: string}
 */
function tenant_catalog_bootstrap(): array
{
    global $CONFIG;
    $pdo = Db::pdo($CONFIG);
    Auth::startRealm(Auth::REALM_ADMIN);
    AdminAccess::requireSuperadminSession($pdo);

    $tid = (int) ($_GET['id'] ?? $_POST['tenant_id'] ?? 0);
    $st = $pdo->prepare('SELECT id, name, slug FROM tenants WHERE id = ?');
    $st->execute([$tid]);
    $tenant = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) {
        redirect_to('admin/tenants.php');
    }

    $err = '';
    $ok = '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['ok']) && $_GET['ok'] === '1') {
        $ok = (string) ($_GET['msg'] ?? 'Cambios guardados.');
    }

    return [
        'pdo' => $pdo,
        'tid' => $tid,
        'tenant' => $tenant,
        'err' => $err,
        'ok' => $ok,
    ];
}

function tenant_catalog_hub_href(int $tid): string
{
    return admin_href('tenant_catalog.php?id=' . $tid);
}

/** @param 'hub'|'cc'|'divisions'|'coordinations'|'activities'|'accounts' $active */
function tenant_catalog_subnav(int $tid, string $active): void
{
    $links = [
        'hub' => ['href' => tenant_catalog_hub_href($tid), 'label' => 'Resumen catalogo'],
        'cc' => ['href' => admin_href('tenant_catalog_cost_centers.php?id=' . $tid), 'label' => 'Centros de costo'],
        'divisions' => ['href' => admin_href('tenant_catalog_divisions.php?id=' . $tid), 'label' => 'Divisiones (estandar)'],
        'coordinations' => ['href' => admin_href('tenant_catalog_coordinations.php?id=' . $tid), 'label' => 'Coordinaciones'],
        'activities' => ['href' => admin_href('tenant_catalog_activities.php?id=' . $tid), 'label' => 'Actividades (catalogo)'],
        'accounts' => ['href' => admin_href('tenant_catalog_accounts.php?id=' . $tid), 'label' => 'Cuentas contables'],
    ];
    echo '<nav class="catalog-subnav" aria-label="Secciones del catalogo">';
    foreach ($links as $key => $item) {
        $cls = $key === $active ? ' class="active"' : '';
        echo '<a href="' . h($item['href']) . '"' . $cls . '>' . h($item['label']) . '</a>';
    }
    echo '</nav>';
}

function tenant_catalog_redirect_ok(int $tid, string $script, string $message): void
{
    redirect_to('admin/' . $script . '?id=' . $tid . '&ok=1&msg=' . rawurlencode($message));
}

/**
 * @param array{id: int, name: string, slug: string} $tenant
 */
function tenant_catalog_layout_start(
    array $tenant,
    int $tid,
    string $pageTitle,
    string $activeNav = 'tenants',
    bool $sectionPage = false,
): void {
    global $layoutTitle, $layoutRealm, $layoutCss, $layoutNav, $layoutPageHeading, $layoutBreadcrumb;
    global $layoutBackHref, $layoutBackLabel;

    $layoutTitle = $pageTitle . ' - ' . $tenant['name'];
    $layoutRealm = 'admin';
    $layoutCss = '../css/app.css';
    $layoutPageHeading = $pageTitle;
    $layoutBreadcrumb = [
        ['href' => admin_href('tenants.php'), 'label' => 'Tenants'],
        ['href' => tenant_catalog_hub_href($tid), 'label' => $tenant['name']],
        ['label' => $pageTitle],
    ];
    $layoutNav = AdminAccess::navItems($activeNav);
    if ($sectionPage) {
        $layoutBackHref = tenant_catalog_hub_href($tid);
        $layoutBackLabel = 'Volver al catalogo del tenant';
    } else {
        $layoutBackHref = null;
        $layoutBackLabel = 'Volver';
    }
    require dirname(__DIR__, 2) . '/inc/layout_top.php';
}

function tenant_catalog_flash(string $err, string $ok): void
{
    if ($err !== '') {
        echo '<div class="alert alert-err">' . h($err) . '</div>';
    }
    if ($ok !== '') {
        echo '<div class="alert alert-ok">' . h($ok) . '</div>';
    }
}

function tenant_catalog_tenant_header(array $tenant, int $tid, bool $sectionPage = false): void
{
    echo '<p class="catalog-tenant-meta">';
    if ($sectionPage) {
        echo '<a href="' . h(tenant_catalog_hub_href($tid)) . '" class="btn btn-back btn-primary">&larr; ' . h(t_es('catalogo')) . ' del tenant</a> ';
    }
    echo '<a href="' . h(admin_href('tenants.php')) . '" class="btn btn-back">&larr; Tenants</a>';
    echo ' &middot; Tenant <strong>' . h($tenant['name']) . '</strong> (id ' . $tid . ')</p>';
}
