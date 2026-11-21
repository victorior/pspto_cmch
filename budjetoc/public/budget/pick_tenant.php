<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
Auth::startRealm(Auth::REALM_BUDGET);
$pdo = Db::pdo($CONFIG);

$tenants = $_SESSION['pick_tenants'] ?? [];
$uid = (int) ($_SESSION['pick_user_id'] ?? 0);
if ($uid <= 0 || !is_array($tenants) || $tenants === []) {
    if (Auth::bootstrapPortalSessionFromAdmin($pdo, 'budget')) {
        if (Auth::tenantId() !== null) {
            redirect_to('budget/index.php');
        }
        redirect_to('budget/pick_tenant.php');
    }
    redirect_to('budget/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $tid = (int) ($_POST['tenant_id'] ?? 0);
    foreach ($tenants as $t) {
        if ((int) $t['id'] === $tid) {
            $isSa = !empty($_SESSION['pick_is_superadmin']);
            unset($_SESSION['pick_tenants'], $_SESSION['pick_user_id'], $_SESSION['pick_is_superadmin']);
            Auth::loginUser($uid, Auth::REALM_BUDGET, $tid, $isSa);
            Auth::commitSession();
            redirect_to('budget/index.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Elegir tenant</title>
  <link rel="stylesheet" href="../css/app.css">
</head>
<body>
  <div class="login-box card">
    <h1>Presupuesto &mdash; Tenant</h1>
    <p style="margin-bottom:0.75rem;"><a href="../admin/index.php">Volver al panel Admin</a></p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <label>Colegio / instituto</label>
      <select name="tenant_id" required>
        <?php foreach ($tenants as $t): ?>
        <option value="<?= (int) $t['id'] ?>"><?= h($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary" style="margin-top:0.75rem;width:100%;">Continuar</button>
    </form>
  </div>
</body>
</html>
