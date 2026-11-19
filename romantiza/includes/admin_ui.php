<?php
if (!defined('ROMANTIZA_APP')) { http_response_code(403); exit('Acceso directo no permitido.'); }
/**
 * Chrome compartido del panel de administración (sidebar + header).
 * Uso:
 *   admin_open($admin, 'productos', 'Productos');
 *   ... contenido ...
 *   admin_close();
 */

function admin_open(array $admin, string $active, string $title, ?string $flash = null, string $flashType = 'ok'): void {
    $isSuper = $admin['role'] === 'superadmin';
    ?>
<!doctype html>
<html lang="es-AR">
<head>
<meta charset="UTF-8">
<title><?= h($title) ?> — Panel Romantiza</title>
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
<div class="admin-shell">
  <aside class="admin-sidebar">
    <a href="index.php" class="admin-brand">
      <img src="../assets/img/logo.png" alt="" onerror="this.style.display='none'">
      <span>Romantiza <em>admin</em></span>
    </a>
    <nav class="admin-nav">
      <a href="index.php" class="<?= $active==='dashboard'?'active':'' ?>"><?= admin_icon('home') ?> Panel</a>
      <a href="productos.php" class="<?= $active==='productos'?'active':'' ?>"><?= admin_icon('box') ?> Productos</a>
      <a href="lineas.php" class="<?= $active==='lineas'?'active':'' ?>"><?= admin_icon('layers') ?> Líneas y categorías</a>
      <a href="portada.php" class="<?= $active==='portada'?'active':'' ?>"><?= admin_icon('image') ?> Portada</a>
      <a href="pedidos.php" class="<?= $active==='pedidos'?'active':'' ?>"><?= admin_icon('cart') ?> Pedidos</a>
      <a href="textos.php" class="<?= $active==='textos'?'active':'' ?>"><?= admin_icon('type') ?> Textos del sitio</a>
      <a href="mensajes.php" class="<?= $active==='mensajes'?'active':'' ?>"><?= admin_icon('mail') ?> Mensajes</a>
      <a href="whatsapp_leads.php" class="<?= $active==='whatsapp_leads'?'active':'' ?>"><?= admin_icon('wa') ?> WhatsApp (campañas)</a>
      <?php if ($isSuper): ?>
      <div class="admin-nav-sep">Solo administrador principal</div>
      <a href="usuarios.php" class="<?= $active==='usuarios'?'active':'' ?>"><?= admin_icon('users') ?> Administradores</a>
      <?php endif; ?>
    </nav>
    <div class="admin-sidebar-foot">
      <div class="admin-whoami">
        <span class="who-name"><?= h($admin['full_name']) ?></span>
        <span class="who-role"><?= $isSuper ? 'Administrador principal' : 'Editor' ?></span>
      </div>
      <a href="logout.php" class="admin-logout"><?= admin_icon('logout') ?> Salir</a>
    </div>
  </aside>

  <div class="admin-main">
    <button class="admin-mobile-toggle" id="adminMobileToggle" type="button" aria-label="Abrir menú"><?= admin_icon('menu') ?></button>
    <header class="admin-topbar">
      <h1><?= h($title) ?></h1>
      <a href="../index.php" target="_blank" class="admin-view-site"><?= admin_icon('external') ?> Ver el sitio</a>
    </header>
    <main class="admin-content">
      <?php if ($flash): ?>
      <div class="admin-flash admin-flash-<?= h($flashType) ?>"><?= h($flash) ?></div>
      <?php endif; ?>
<?php
}

function admin_close(): void {
    ?>
    </main>
  </div>
</div>
<script>
(function(){
  var t = document.getElementById('adminMobileToggle');
  if (t) t.addEventListener('click', function(){ document.querySelector('.admin-shell').classList.toggle('nav-open'); });

  // Ojito: mostrar/ocultar contraseña en cualquier campo con data-toggle-pass
  document.querySelectorAll('[data-toggle-pass]').forEach(function(btn){
    var input = document.getElementById(btn.getAttribute('data-toggle-pass'));
    if (!input) return;
    btn.addEventListener('click', function(){
      var showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      btn.classList.toggle('is-showing', !showing);
      btn.setAttribute('aria-label', showing ? 'Mostrar contraseña' : 'Ocultar contraseña');
    });
  });

  // Confirmación simple para acciones destructivas
  document.querySelectorAll('[data-confirm]').forEach(function(el){
    el.addEventListener('submit', function(e){
      if (!confirm(el.getAttribute('data-confirm'))) e.preventDefault();
    });
  });
})();
</script>
</body>
</html>
<?php
}

/** Ícono SVG inline chico para el panel (evita depender del sprite público). */
function admin_icon(string $name): string {
    $paths = [
        'home'     => '<path d="M4 11.5 12 5l8 6.5"/><path d="M6 10v9h5v-5h2v5h5v-9"/>',
        'box'      => '<path d="M3 8l9-4 9 4-9 4-9-4Z"/><path d="M3 8v8l9 4 9-4V8"/><path d="M12 12v8"/>',
        'type'     => '<path d="M5 6h14M12 6v13M9 19h6"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="M4 6.5 12 13l8-6.5"/>',
        'users'    => '<circle cx="9" cy="8" r="3.2"/><path d="M2.8 19c.6-3.4 3-5.2 6.2-5.2s5.6 1.8 6.2 5.2"/><circle cx="17" cy="8.5" r="2.6"/><path d="M16 13.4c2.6.3 4.3 2 4.8 4.8"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'menu'     => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'external' => '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M19 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h5"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'edit'     => '<path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/>',
        'trash'    => '<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
        'eye'      => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'  => '<path d="M3 3l18 18"/><path d="M10.6 5.2A10.6 10.6 0 0 1 12 5c6.4 0 10 7 10 7a15.6 15.6 0 0 1-3.3 4.1M6.5 6.6C4 8.3 2 12 2 12s3.6 7 10 7c1.4 0 2.6-.3 3.7-.8"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'check'    => '<path d="M4 12l6 6L20 6"/>',
        'cart'     => '<circle cx="10" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2.5 3h2.4l2 12.2a2 2 0 0 0 2 1.7h8.4a2 2 0 0 0 2-1.6L21 8H6"/>',
        'image'    => '<rect x="3" y="4" width="18" height="16" rx="1.5"/><circle cx="8.3" cy="9.5" r="1.6"/><path d="M3 16l5.5-5.2a1.5 1.5 0 0 1 2 0L15 15l2-2a1.5 1.5 0 0 1 2 0l2 2"/>',
        'layers'   => '<path d="M12 3 3 8l9 5 9-5-9-5Z"/><path d="M3 12l9 5 9-5"/><path d="M3 16l9 5 9-5"/>',
        'wa'       => '<path d="M4 5h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9l-5 4V6a1 1 0 0 1 1-1Z"/><path d="M8 9h8M8 12.5h5"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}
