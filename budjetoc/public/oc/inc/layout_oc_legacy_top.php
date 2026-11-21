<?php

declare(strict_types=1);

/** @var string $layoutTitle */
/** @var int $ocNavYear */
/** @var string $ocNavBrand */
$layoutTitle = $layoutTitle ?? 'OC';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($layoutTitle) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@3.4.1/dist/css/bootstrap.min.css" crossorigin="anonymous">
  <link rel="stylesheet" href="../vendor/jquery-ui/1.13.2/jquery-ui.min.css?v=20260541">
  <link rel="stylesheet" href="../css/oc-legacy.css?v=20260544">
</head>
<body class="page-oc-legacy">
<?php require __DIR__ . '/oc_legacy_nav.php'; ?>
<div class="container">
