<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
Auth::startRealm(Auth::REALM_OC);
$pdo = Db::pdo($CONFIG);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (Auth::bootstrapPortalSessionFromAdmin($pdo, 'oc')) {
        if (Auth::tenantId() !== null) {
            redirect_to('oc/index.php');
        }
        redirect_to('oc/pick_tenant.php');
    }
    if (GoogleAuth::isEnabled() && !GoogleAuth::passwordLoginEnabled()) {
        redirect_to('index.php?err=use_google');
    }
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && GoogleAuth::passwordLoginEnabled()) {
    require_post_csrf();
    $loginEmail = (string) ($_POST['email'] ?? '');
    if (!LoginRateLimiter::checkAllowed('oc', $loginEmail)) {
        $err = LoginRateLimiter::lockoutMessage();
    } else {
        $user = Auth::attempt($pdo, $loginEmail, (string) ($_POST['password'] ?? ''));
        if (!$user) {
            LoginRateLimiter::recordFailure('oc', $loginEmail);
            $err = 'Credenciales inválidas.';
        } else {
            LoginRateLimiter::clear('oc', $loginEmail);
            $isSa = Auth::isSuperadminUser($pdo, $user['id']);
            $tenants = Auth::tenantsForApp($pdo, $user['id'], 'oc');
            if ($tenants === []) {
                $err = 'Sin acceso a ordenes de compra.';
            } elseif (count($tenants) === 1) {
                Auth::loginUser($user['id'], Auth::REALM_OC, (int) $tenants[0]['id'], $isSa);
                Auth::commitSession();
                header('Location: index.php');
                exit;
            } else {
                $_SESSION['pick_user_id'] = $user['id'];
                $_SESSION['pick_tenants'] = $tenants;
                $_SESSION['pick_is_superadmin'] = $isSa;
                header('Location: pick_tenant.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>OC &mdash; Login</title>
  <link rel="stylesheet" href="../css/app.css">
</head>
<body>
  <div class="login-box card">
    <h1>&Oacute;rdenes de compra</h1>
    <?php if ($err): ?><div class="alert alert-err"><?= h($err) ?></div><?php endif; ?>
    <?php if (GoogleAuth::isEnabled()): ?>
      <a class="btn btn-google" href="<?= h(GoogleAuth::startUrl()) ?>">Entrar con Google</a>
      <?php if (GoogleAuth::passwordLoginEnabled()): ?>
        <p class="login-divider"><span>o con email</span></p>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (GoogleAuth::passwordLoginEnabled()): ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <label>Email</label><input type="email" name="email" required autocomplete="username">
      <label style="margin-top:0.5rem;">Contraseña</label><input type="password" name="password" required autocomplete="current-password">
      <button type="submit" class="btn btn-primary" style="margin-top:0.75rem;width:100%;">Entrar</button>
    </form>
    <?php endif; ?>
    <p style="margin-top:1rem;"><a href="../index.php">Inicio</a></p>
  </div>
</body>
</html>
