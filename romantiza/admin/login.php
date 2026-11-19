<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

start_secure_session();

// Si no hay ningún administrador todavía, mandamos directo al instalador.
$hayAdmins = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
if (!$hayAdmins && file_exists(__DIR__ . '/instalar.php')) {
    header('Location: instalar.php');
    exit;
}

// Si ya está logueado, directo al panel.
if (!empty($_SESSION['admin_id'])) {
    $chk = $pdo->prepare('SELECT id FROM admins WHERE id = ? AND active = 1');
    $chk->execute([$_SESSION['admin_id']]);
    if ($chk->fetch()) {
        header('Location: index.php');
        exit;
    }
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'La sesión del formulario expiró. Recargá la página e intentá de nuevo.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $admin = attempt_login($pdo, $username, $password);
        if ($admin) {
            header('Location: index.php');
            exit;
        }
        $error = 'Usuario o contraseña incorrectos.';
    }
}

$notice = null;
if (isset($_GET['desactivado'])) { $notice = 'Tu cuenta fue desactivada. Consultá con el administrador principal.'; }
if (isset($_GET['ya_instalado'])) { $notice = 'El panel ya tiene un administrador principal configurado.'; }
if (isset($_GET['salida'])) { $notice = 'Sesión cerrada correctamente.'; }
?>
<!doctype html>
<html lang="es-AR">
<head>
<meta charset="UTF-8">
<title>Iniciar sesión — Panel Romantiza</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="../assets/img/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="../assets/img/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="../assets/img/favicon-16x16.png">
<link rel="apple-touch-icon" href="../assets/img/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,450;0,9..144,650;1,9..144,500&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css?v=<?= h((string) asset_version(__DIR__ . '/../assets/css/style.css')) ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= h((string) asset_version(__DIR__ . '/../assets/css/admin.css')) ?>">
</head>
<body class="admin-body">
<div class="auth-screen">
  <div class="auth-card">
    <img class="auth-logo" src="../assets/img/logo.png" alt="" onerror="this.style.display='none'">
    <h1>Panel Romantiza</h1>
    <p class="sub">Ingresá con tu usuario y contraseña.</p>

    <?php if ($error): ?><div class="auth-error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($notice && !$error): ?><div class="auth-notice"><?= h($notice) ?></div><?php endif; ?>

    <form method="post" action="login.php" autocomplete="off">
      <?= csrf_field() ?>
      <div class="aform-field">
        <label for="l-user">Usuario</label>
        <input id="l-user" name="username" type="text" required autofocus value="<?= h($_POST['username'] ?? '') ?>">
      </div>
      <div class="aform-field">
        <label for="l-pass">Contraseña</label>
        <div class="pass-field">
          <input id="l-pass" name="password" type="password" required autocomplete="current-password">
          <button type="button" class="pass-toggle" data-toggle-pass="l-pass" aria-label="Mostrar contraseña">
            <span class="icon-on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></span>
            <span class="icon-off"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18"/><path d="M10.6 5.2A10.6 10.6 0 0 1 12 5c6.4 0 10 7 10 7a15.6 15.6 0 0 1-3.3 4.1M6.5 6.6C4 8.3 2 12 2 12s3.6 7 10 7c1.4 0 2.6-.3 3.7-.8"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></span>
          </button>
        </div>
      </div>
      <button class="abtn abtn-primary" style="width:100%;justify-content:center" type="submit">Ingresar</button>
    </form>
  </div>
</div>
<script>
document.querySelectorAll('[data-toggle-pass]').forEach(function(btn){
  var input = document.getElementById(btn.getAttribute('data-toggle-pass'));
  if (!input) return;
  btn.addEventListener('click', function(){
    var showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    btn.classList.toggle('is-showing', !showing);
  });
});
</script>
</body>
</html>
