<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
Auth::startRealm(Auth::REALM_OC);
$pdo = Db::pdo($CONFIG);

$tenants = $_SESSION['pick_tenants'] ?? [];
$uid = (int) ($_SESSION['pick_user_id'] ?? 0);
if ($uid <= 0 || !is_array($tenants) || $tenants === []) {
    if (Auth::bootstrapPortalSessionFromAdmin($pdo, 'oc')) {
        if (Auth::tenantId() !== null) {
            redirect_to('oc/index.php');
        }
        redirect_to('oc/pick_tenant.php');
    }
    redirect_to('oc/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $tid = (int) ($_POST['tenant_id'] ?? 0);
    foreach ($tenants as $t) {
        if ((int) $t['id'] === $tid) {
            $isSa = !empty($_SESSION['pick_is_superadmin']);
            unset($_SESSION['pick_tenants'], $_SESSION['pick_user_id'], $_SESSION['pick_is_superadmin']);
            Auth::loginUser($uid, Auth::REALM_OC, $tid, $isSa);
            Auth::commitSession();
            redirect_to('oc/index.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>OC &mdash; Tenant</title>
  <link rel="stylesheet" href="../css/app.css">
</head>
<body>
  <div class="login-box card">
    <h1>OC &mdash; Tenant</h1>
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
