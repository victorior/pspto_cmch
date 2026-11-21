<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
$pdo = Db::pdo($CONFIG);

$err = '';
$redirErr = (string) ($_GET['err'] ?? '');
$user = null;
$googleEnabled = GoogleAuth::isEnabled();
$passwordLogin = GoogleAuth::passwordLoginEnabled();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $passwordLogin) {
    Auth::startRealm(Auth::REALM_ADMIN);
    require_post_csrf();
    $loginEmail = (string) ($_POST['email'] ?? '');
    if (!LoginRateLimiter::checkAllowed('home', $loginEmail)) {
        $err = LoginRateLimiter::lockoutMessage();
    } else {
        $user = Auth::attempt($pdo, $loginEmail, (string) ($_POST['password'] ?? ''));
        if (!$user) {
            LoginRateLimiter::recordFailure('home', $loginEmail);
            $err = 'Credenciales inválidas.';
        } else {
            LoginRateLimiter::clear('home', $loginEmail);
            Auth::establishAdminHomeSession($pdo, (int) $user['id']);
            redirect_to('');
        }
    }
}

$uid = Auth::ensureAdminHomeSession($pdo);
if ($uid !== null) {
    Auth::startRealm(Auth::REALM_ADMIN);
} elseif ($googleEnabled || $passwordLogin) {
    Auth::startRealm(Auth::REALM_ADMIN);
}

$isSaSession = Auth::isSuperadmin();
$isFullAdmin = $uid !== null && Auth::isSuperadminUser($pdo, (int) $uid);
$budgetTenants = $uid !== null ? Auth::tenantsForApp($pdo, (int) $uid, 'budget') : [];
$ocTenants = $uid !== null ? Auth::tenantsForApp($pdo, (int) $uid, 'oc') : [];

$currentUser = null;
if ($uid !== null) {
    $stMe = $pdo->prepare('SELECT email, full_name FROM users WHERE id = ? LIMIT 1');
    $stMe->execute([(int) $uid]);
    $currentUser = $stMe->fetch(PDO::FETCH_ASSOC) ?: null;
}

$googleErrMsg = match ($redirErr) {
    'google_disabled' => 'Google Sign-In no está configurado.',
    'google_denied' => 'Inicio de sesión con Google cancelado.',
    'google_state' => 'Sesión OAuth inválida. Intente de nuevo.',
    'google_token' => 'No se pudo validar la respuesta de Google.',
    'google_no_account' => 'Su cuenta de Google no está registrada en BudgetOC. Contacte al administrador.',
    'use_google' => 'Use el botón «Entrar con Google» en esta página.',
    default => '',
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>BudgetOC</title>
  <link rel="stylesheet" href="css/app.css">
</head>
<body>
  <div class="wrap" style="padding-top:3rem;">
    <div class="card" style="max-width:28rem;margin:0 auto;">
      <h1 style="margin:0 0 0.25rem;font-size:1.25rem;text-align:center;">BudgetOC</h1>
      <?php if ($uid === null): ?>
      <p style="color:var(--muted);margin:0 0 1rem;text-align:center;">Inicie sesión</p>
      <?php else: ?>
      <p style="color:var(--muted);margin:0 0 1rem;text-align:center;">Accesos disponibles</p>
      <?php endif; ?>

      <?php if ($uid === null): ?>
        <?php if ($err): ?><div class="alert alert-err"><?= h($err) ?></div><?php endif; ?>
        <?php if ($googleErrMsg !== ''): ?><div class="alert alert-err"><?= h($googleErrMsg) ?></div><?php endif; ?>

        <?php if ($googleEnabled): ?>
          <a class="btn btn-google" href="<?= h(GoogleAuth::startUrl()) ?>">Entrar con Google</a>
        <?php endif; ?>

        <?php if ($passwordLogin): ?>
          <?php if ($googleEnabled): ?>
            <p class="login-divider"><span>o con email</span></p>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <label>Email</label>
            <input type="email" name="email" required autocomplete="username">
            <label style="margin-top:0.5rem;">Contraseña</label>
            <input type="password" name="password" required autocomplete="current-password">
            <button type="submit" class="btn btn-primary" style="margin-top:0.85rem;width:100%;">Entrar</button>
          </form>
        <?php elseif (!$googleEnabled): ?>
          <div class="alert alert-err">Configure Google OAuth en config/config.php</div>
        <?php endif; ?>
      <?php else: ?>
        <?php if ($redirErr !== '' && $googleErrMsg === ''): ?>
          <div class="alert alert-err" style="margin-top:0.75rem;">
            <?php if ($redirErr === 'sesion_home_perdida'): ?>
              La sesión del home se perdió. Cierre sesión y vuelva a entrar en esta misma pestaña.
            <?php elseif ($redirErr === 'portal_invalido'): ?>
              Portal inválido.
            <?php elseif ($redirErr === 'portal_method'): ?>
              Use el botón del portal en esta página (no abra open_portal.php directamente).
            <?php elseif (str_starts_with($redirErr, 'sin_acceso_')): ?>
              No tiene acceso al portal solicitado.
            <?php else: ?>
              No se pudo abrir el portal. (<?= h($redirErr) ?>)
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <p style="margin:0 0 0.75rem;text-align:center;">
          <?= h(trim((string) (($currentUser['full_name'] ?? '') !== '' ? $currentUser['full_name'] : ($currentUser['email'] ?? '')))) ?>
          <?php if (!empty($currentUser['email']) && ($currentUser['full_name'] ?? '') !== ''): ?>
            <span style="color:var(--muted);">(<?= h((string) $currentUser['email']) ?>)</span>
          <?php endif; ?>
          <?php if ($isSaSession): ?><span class="badge badge-sa" style="margin-left:0.35rem;">Superadmin</span><?php endif; ?>
        </p>
        <div class="portal-links" style="justify-content:center;margin-top:1rem;">
          <?php if ($isFullAdmin): ?>
            <a href="<?= h(url_path('admin/index.php')) ?>"><strong>Administración</strong><span>Usuarios, permisos, catálogo</span></a>
          <?php endif; ?>
          <?php if ($budgetTenants !== []): ?>
            <form method="post" action="<?= h(url_path('open_portal.php')) ?>" class="portal-link-form">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="portal" value="budget">
              <button type="submit" class="portal-link-btn"><strong>Presupuesto</strong><span>Crear y consultar actividades</span></button>
            </form>
          <?php endif; ?>
          <?php if ($ocTenants !== []): ?>
            <form method="post" action="<?= h(url_path('open_portal.php')) ?>" class="portal-link-form">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="portal" value="oc">
              <button type="submit" class="portal-link-btn"><strong>&Oacute;rdenes de compra</strong><span>Imputar a actividades</span></button>
            </form>
          <?php endif; ?>
        </div>
        <p style="margin:1rem 0 0;text-align:center;">
          <a class="btn" href="<?= h(url_path('logout.php')) ?>">Cerrar sesión</a>
        </p>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
