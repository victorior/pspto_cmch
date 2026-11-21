<?php



declare(strict_types=1);



require dirname(__DIR__, 2) . '/src/bootstrap.php';

Auth::startRealm(Auth::REALM_ADMIN);

$pdo = Db::pdo($CONFIG);



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    if (AdminAccess::bootstrapAdminSessionFromPeerRealms($pdo)) {

        AdminAccess::redirectAfterAdminLogin($pdo);

    }

    if (GoogleAuth::isEnabled() && !GoogleAuth::passwordLoginEnabled()) {

        redirect_to('index.php?err=use_google');

    }

}



$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && GoogleAuth::passwordLoginEnabled()) {

    require_post_csrf();

    $loginEmail = (string) ($_POST['email'] ?? '');
    if (!LoginRateLimiter::checkAllowed('admin', $loginEmail)) {

        $err = LoginRateLimiter::lockoutMessage();

    } else {

        $user = Auth::attempt($pdo, $loginEmail, (string) ($_POST['password'] ?? ''));

        if ($user) {

            LoginRateLimiter::clear('admin', $loginEmail);

            $st = $pdo->prepare('SELECT 1 FROM super_admins WHERE user_id = ?');

            $st->execute([$user['id']]);

            if ($st->fetchColumn()) {

                Auth::loginUser($user['id'], Auth::REALM_ADMIN, null, true);

                $_SESSION['is_tenant_policy_admin'] = false;

                Auth::commitSession();

                redirect_to('admin/index.php');

            }

            $tenantIds = AdminAccess::tenantAdminTenantIds($pdo, (int) $user['id']);

            if ($tenantIds !== []) {

                Auth::loginUser((int) $user['id'], Auth::REALM_ADMIN, $tenantIds[0], false);

                $_SESSION['is_tenant_policy_admin'] = true;

                Auth::commitSession();

                redirect_to('admin/edit_permissions.php');

            }

            $err = 'No tiene permisos de administracion.';

        } else {

            LoginRateLimiter::recordFailure('admin', $loginEmail);

            $err = 'Credenciales inválidas.';

        }

    }

}

?>

<!DOCTYPE html>

<html lang="es">

<head>

  <meta charset="utf-8">

  <meta name="viewport" content="width=device-width, initial-scale=1">

  <title>Admin &mdash; Login</title>

  <link rel="stylesheet" href="../css/app.css">

</head>

<body>

  <div class="login-box card">

    <h1 style="margin:0 0 1rem;font-size:1.1rem;">Administración</h1>

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

      <label>Email</label>

      <input type="email" name="email" required autocomplete="username">

      <label style="margin-top:0.5rem;">Contraseña</label>

      <input type="password" name="password" required autocomplete="current-password">

      <button type="submit" class="btn btn-primary" style="margin-top:0.75rem;width:100%;">Entrar</button>

    </form>

    <?php endif; ?>

    <p style="margin-top:1rem;font-size:0.85rem;"><a href="../index.php">Inicio</a></p>

  </div>

</body>

</html>

