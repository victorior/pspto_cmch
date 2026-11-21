<?php

declare(strict_types=1);

/** @var string $layoutTitle */
/** @var string $layoutRealm admin|budget|oc */
/** @var list<array{href: string, label: string, active?: bool}> $layoutNav */
/** @var string|null $layoutHomeHref */

$layoutTitle = $layoutTitle ?? 'BudgetOC';
$layoutNav = $layoutNav ?? [];
$layoutHomeHref = $layoutHomeHref ?? (
    ($layoutRealm ?? '') === 'admin'
        ? AdminAccess::adminHomeHref()
        : realm_home_href($layoutRealm ?? '')
);
$realmLabel = match ($layoutRealm ?? '') {
    'admin' => 'Admin',
    'budget' => 'Presupuesto',
    'oc' => 'OC',
    default => '',
};

$layoutPortalContext = $layoutPortalContext ?? null;
if ($layoutPortalContext === null && Auth::userId() !== null) {
    global $CONFIG;
    $layoutPortalContext = layout_portal_context(Db::pdo($CONFIG));
}
$layoutPortalUserName = trim((string) ($layoutPortalContext['user_name'] ?? ''));
$layoutPortalTenantName = trim((string) ($layoutPortalContext['tenant_name'] ?? ''));
$layoutPortalMetaParts = array_values(array_filter(
    [$layoutPortalTenantName, $layoutPortalUserName],
    static fn (string $s): bool => $s !== ''
));
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($layoutTitle) ?></title>
  <?php if (($layoutRealm ?? '') === 'admin' && !headers_sent()): ?>
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
  <?php endif; ?>
  <?php if (!empty($layoutBootstrap)): ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css" crossorigin="anonymous">
  <?php endif; ?>
  <?php if (!empty($layoutJqueryUi)): ?>
  <link rel="stylesheet" href="vendor/jquery-ui/1.13.2/jquery-ui.min.css?v=20260541">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= h($layoutCss ?? '../css/app.css') ?>?v=20260527">
</head>
<body class="<?= h(trim((!empty($layoutBootstrap) ? 'bg-light ' : '') . ($layoutBodyClass ?? ''))) ?>">
<header class="topbar">
  <div class="topbar-start">
    <a href="<?= h($layoutHomeHref) ?>" class="topbar-brand">BudgetOC &mdash; <?= h($realmLabel) ?></a>
    <?php if (Auth::isSuperadmin()): ?>
    <span class="badge badge-sa" title="Acceso total sin restriccion de division">Superadmin</span>
    <?php endif; ?>
    <?php if ($layoutPortalMetaParts !== []): ?>
    <span class="topbar-meta" title="Colegio y usuario activos">
      <?= h(implode(' · ', $layoutPortalMetaParts)) ?>
    </span>
    <?php endif; ?>
  </div>
  <nav class="topbar-menu" aria-label="Menu principal">
    <?php foreach ($layoutNav as $item): ?>
      <a href="<?= h($item['href']) ?>"<?= !empty($item['active']) ? ' class="active"' : '' ?>><?= h($item['label']) ?></a>
    <?php endforeach; ?>
    <?php if (Auth::isSuperadmin() && ($layoutRealm ?? '') !== 'admin'): ?>
      <form method="post" action="../admin/enter_from_portal.php" class="topbar-inline-form">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_from_realm" value="<?= h($layoutRealm ?? 'budget') ?>">
        <button type="submit" class="topbar-link-btn">Admin</button>
      </form>
    <?php elseif (($layoutRealm ?? '') === 'admin' && Auth::isSuperadmin()): ?>
      <form method="post" action="<?= h(admin_href('open_portal.php')) ?>" class="topbar-inline-form">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="portal" value="budget">
        <button type="submit" class="topbar-link-btn">Presupuesto</button>
      </form>
      <form method="post" action="<?= h(admin_href('open_portal.php')) ?>" class="topbar-inline-form">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="portal" value="oc">
        <button type="submit" class="topbar-link-btn">OC</button>
      </form>
    <?php elseif (in_array($layoutRealm ?? '', ['budget', 'oc'], true)): ?>
      <a href="<?= h(portal_inicio_href($layoutRealm ?? '')) ?>">Inicio</a>
    <?php endif; ?>
    <?php if (($layoutRealm ?? '') === 'admin'): ?>
    <a href="<?= h(admin_href('logout.php')) ?>">Salir</a>
    <?php else: ?>
    <a href="logout.php">Salir</a>
    <?php endif; ?>
  </nav>
</header>
<main class="wrap-wide">
<?php require __DIR__ . '/page_nav.php'; ?>
