<?php

declare(strict_types=1);

/** Datos de prueba local: 1 tenant, catalogo minimo, usuario presupuesto y OC. */
$root = dirname(__DIR__);
$config = require $root . '/config/config.php';
require_once __DIR__ . '/require_budjetoc_db.php';
require_budjetoc_database($config);
require_once $root . '/src/helpers.php';
require_once $root . '/src/BudgetService.php';

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db']['host'], $config['db']['name']),
    $config['db']['user'],
    $config['db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$st = $pdo->query("SELECT id FROM tenants WHERE slug = 'demo' LIMIT 1");
if ($st->fetchColumn()) {
    echo "Demo ya existe (slug demo). Nada que hacer.\n";
    exit(0);
}

$pdo->beginTransaction();
$pdo->prepare('INSERT INTO tenants (name, slug) VALUES (?, ?)')->execute(['Colegio Demo', 'demo']);
$tid = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO tenant_settings (tenant_id, company_name) VALUES (?, ?)')
    ->execute([$tid, 'Colegio Demo']);

$pdo->prepare('INSERT INTO cost_centers (tenant_id, name) VALUES (?, ?)')->execute([$tid, 'Administracion']);
$ccId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO departments (tenant_id, name, type) VALUES (?, ?, ?)')->execute([$tid, 'Division General', 'estandar']);
$divId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO activities (tenant_id, name) VALUES (?, ?)')->execute([$tid, 'Mantenimiento']);
$actId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO account_codes (tenant_id, account_id, name) VALUES (?, ?, ?)')
    ->execute([$tid, 51121001, 'Insumos']);

$year = (int) date('Y');
$actNum = '0001-' . $year;
$pdo->prepare('INSERT INTO activity_sequences (tenant_id, year, last_number) VALUES (?, ?, 1)')
    ->execute([$tid, $year]);
$pdo->prepare(
    'INSERT INTO budget_headers (tenant_id, activity_number, month, cost_center_id, activity_id, division_id, observations)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
)->execute([$tid, $actNum, (int) date('n'), $ccId, $actId, $divId, 'Actividad de prueba local']);
$hid = (int) $pdo->lastInsertId();
$acid = (int) $pdo->query('SELECT id FROM account_codes WHERE tenant_id = ' . $tid . ' LIMIT 1')->fetchColumn();
$pdo->prepare(
    'INSERT INTO budget_details (budget_header_id, account_code_id, quantity, unit_price) VALUES (?, ?, 1, 500000)'
)->execute([$hid, $acid]);

$pdo->prepare('INSERT INTO suppliers (tenant_id, legal_name) VALUES (?, ?)')
    ->execute([$tid, 'Proveedor Demo SpA']);

$hash = password_hash('Demo1234!', PASSWORD_DEFAULT);
foreach ([
    ['presupuesto@demo.test', 'Encargado Presupuesto', 'budget'],
    ['oc@demo.test', 'Encargado OC', 'oc'],
] as [$email, $name, $app]) {
    $pdo->prepare('INSERT INTO users (email, password_hash, full_name) VALUES (?, ?, ?)')
        ->execute([$email, $hash, $name]);
    $uid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_tenant_roles (user_id, tenant_id, app) VALUES (?, ?, ?)')
        ->execute([$uid, $tid, $app]);
}

$pdo->commit();

echo "Tenant demo creado (id {$tid}).\n";
echo "Actividad: {$actNum} con linea $500.000\n";
echo "Presupuesto: presupuesto@demo.test / Demo1234!\n";
echo "OC: oc@demo.test / Demo1234!\n";
