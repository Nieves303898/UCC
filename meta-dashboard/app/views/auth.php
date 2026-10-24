<?php
/** @var string $mode install|login */
/** @var ?string $error */
$appName = App\Config::get('app_name', 'Meta Hub');
$agency = App\Config::get('agency_name', $appName);
?><!doctype html>
<html lang="es">
<head>
<?php require __DIR__ . '/_head.php'; ?>
  <title><?= $mode === 'install' ? 'Instalación' : 'Ingresar' ?> · <?= e($appName) ?></title>
</head>
<body class="min-h-screen">
  <div class="min-h-screen grid lg:grid-cols-[1fr_520px]">
    <section class="hidden lg:flex flex-col justify-between p-12 hair-r" style="background:var(--panel)">
      <div class="flex items-center gap-3">
        <div class="mono-avatar w-9 h-9" style="background:var(--t1);color:var(--bg)">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 17V7l7 6 7-6v10"/></svg>
        </div>
        <span class="h2"><?= e($appName) ?></span>
      </div>
      <div class="max-w-lg">
        <div class="eyebrow mb-4">Performance · Meta Ads · Facebook · Instagram</div>
        <h1 class="text-4xl font-semibold tracking-tight t1 leading-tight">Tus resultados, en vivo y en un solo lugar.</h1>
        <p class="t2 mt-4 text-base leading-relaxed">Inversión, ventas, conversaciones y contenido actualizados en tiempo real, junto con todo lo que estamos trabajando para tu marca.</p>
        <div class="mt-10 grid grid-cols-3 gap-px rounded-xl overflow-hidden" style="background:var(--line);border:1px solid var(--line)">
          <?php foreach ([['Actualización', 'cada 2 min'], ['Fuentes', 'Ads · FB · IG'], ['Acceso', 'privado']] as [$k, $v]): ?>
            <div class="p-4" style="background:var(--bg)"><div class="eyebrow"><?= e($k) ?></div><div class="t1 font-medium mt-1"><?= e($v) ?></div></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="t3 text-xs"><?= e($agency) ?></div>
    </section>

    <section class="flex items-center justify-center p-6">
      <form method="post" class="w-full max-w-sm space-y-5">
        <div>
          <h2 class="h1"><?= $mode === 'install' ? 'Crear administrador' : 'Ingresar' ?></h2>
          <p class="t2 mt-1"><?= $mode === 'install' ? 'Primera ejecución: se crean las tablas y tu usuario.' : 'Usá el usuario y la contraseña que te dio la agencia.' ?></p>
        </div>
        <?php if ($error): ?>
          <div class="pill red plain" style="height:auto;padding:9px 12px;border-radius:10px;white-space:normal"><?= e($error) ?></div>
        <?php endif; ?>
        <input type="hidden" name="csrf" value="<?= e(App\Auth::csrfToken()) ?>">
        <?php if ($mode === 'install'): ?>
          <label class="field"><span>Tu nombre</span><input class="input" name="name" required></label>
        <?php endif; ?>
        <label class="field"><span>Usuario o email</span><input class="input" name="login" autocomplete="username" autocapitalize="none" required autofocus></label>
        <label class="field"><span>Contraseña</span><input class="input" name="password" type="password" minlength="8" autocomplete="<?= $mode === 'install' ? 'new-password' : 'current-password' ?>" required></label>
        <button class="btn btn-primary w-full" style="height:40px"><?= $mode === 'install' ? 'Instalar y entrar' : 'Ingresar' ?></button>
        <p class="t3 text-xs text-center lg:hidden"><?= e($agency) ?></p>
      </form>
    </section>
  </div>
</body>
</html>
