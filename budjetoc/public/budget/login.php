<?php

declare(strict_types=1);

/**
 * Login secundario Presupuesto: redirige al login central si solo hay Google OAuth.
 */
require dirname(__DIR__, 2) . '/src/bootstrap.php';
Auth::startRealm(Auth::REALM_BUDGET);
$pdo = Db::pdo($CONFIG);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (Auth::bootstrapPortalSessionFromAdmin($pdo, 'budget')) {
        if (Auth::tenantId() !== null) {
            redirect_to('budget/index.php');
        }
        redirect_to('budget/pick_tenant.php');
    }
    if (GoogleAuth::isEnabled() && !GoogleAuth::passwordLoginEnabled()) {
        redirect_to('index.php?err=use_google');
    }
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && GoogleAuth::passwordLoginEnabled()) {
    require_post_csrf();
    $loginEmail = (string) ($_POST['email'] ?? '');
    if (!LoginRateLimiter::checkAllowed('budget', $loginEmail)) {
        $err = LoginRateLimiter::lockoutMessage();
    } else {
        $user = Auth::attempt($pdo, $loginEmail, (string) ($_POST['password'] ?? ''));
        if (!$user) {
            LoginRateLimiter::recordFailure('budget', $loginEmail);
            $err = 'Credenciales inválidas.';
        } else {
            LoginRateLimiter::clear('budget', $loginEmail);
            $isSa = Auth::isSuperadminUser($pdo, $user['id']);
            $tenants = Auth::tenantsForApp($pdo, $user['id'], 'budget');
            if ($tenants === []) {
                $err = 'Sin acceso a presupuesto.';
            } elseif (count($tenants) === 1) {
                Auth::loginUser($user['id'], Auth::REALM_BUDGET, (int) $tenants[0]['id'], $isSa);
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
  <title>Presupuesto &mdash; Login</title>
  <link rel="stylesheet" href="../css/app.css">
</head>
<body>
  <div class="login-box card">
    <h1>Presupuesto</h1>
    <?php if ($err): ?><p class="alert alert-err"><?= h($err) ?></p><?php endif; ?>
    <?php if (GoogleAuth::isEnabled()): ?>
      <a class="btn btn-google" href="<?= h(GoogleAuth::startUrl()) ?>">Entrar con Google</a>
      <?php if (GoogleAuth::passwordLoginEnabled()): ?>
        <p class="login-divider"><span>o con email</span></p>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (GoogleAuth::passwordLoginEnabled()): ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <label>Email</label><input type="email" name="email" required>
      <label style="margin-top:0.5rem;">Contraseña</label><input type="password" name="password" required>
      <button type="submit" class="btn btn-primary" style="margin-top:0.75rem;width:100%;">Entrar</button>
    </form>
    <?php endif; ?>
    <p style="margin-top:1rem;"><a href="../index.php">Inicio</a></p>
  </div>
</body>
</html>
