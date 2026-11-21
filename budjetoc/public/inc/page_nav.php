<?php

declare(strict_types=1);

/** @var string|null $layoutPageHeading */
/** @var string|null $layoutBackHref */
/** @var string|null $layoutBackLabel */
/** @var list<array{href?: string, label: string}>|null $layoutBreadcrumb */

$layoutPageHeading = $layoutPageHeading ?? null;
$layoutBackHref = $layoutBackHref ?? null;
$layoutBackLabel = $layoutBackLabel ?? 'Volver';
$layoutBreadcrumb = $layoutBreadcrumb ?? [];

$showNav = $layoutBreadcrumb !== [] || $layoutBackHref !== null || ($layoutPageHeading !== null && $layoutPageHeading !== '');
if (!$showNav) {
    return;
}
?>
<div class="page-nav">
  <div class="page-nav-row">
    <?php if ($layoutBreadcrumb !== []): ?>
      <?= breadcrumb_html($layoutBreadcrumb) ?>
    <?php endif; ?>
    <?php if ($layoutBackHref !== null && $layoutBackHref !== ''): ?>
      <a href="<?= h($layoutBackHref) ?>" class="btn btn-back"><?= h($layoutBackLabel) ?></a>
    <?php endif; ?>
  </div>
  <?php if ($layoutPageHeading !== null && $layoutPageHeading !== ''): ?>
    <h1 class="page-heading"><?= h($layoutPageHeading) ?></h1>
  <?php endif; ?>
</div>
