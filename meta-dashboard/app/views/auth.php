<?php
/** @var string $mode install|login */
/** @var ?string $error */
$title = $mode === 'install' ? 'Instalación' : 'Ingresar';
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · <?= e(App\Config::get('app_name', 'Meta Hub')) ?></title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/tailwind.css?v=1">
  <style>body{font-family:Inter,system-ui,sans-serif}</style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center p-4">
  <div class="w-full max-w-md">
    <div class="flex items-center gap-3 mb-8 justify-center">
      <div class="h-10 w-10 rounded-xl bg-gradient-to-br from-blue-500 to-fuchsia-500 grid place-items-center font-bold">M</div>
      <span class="text-xl font-semibold"><?= e(App\Config::get('app_name', 'Meta Hub')) ?></span>
    </div>
    <form method="post" class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4 shadow-2xl">
      <h1 class="text-lg font-semibold"><?= $mode === 'install' ? 'Crear usuario administrador' : 'Ingresá a tu panel' ?></h1>
      <?php if ($mode === 'install'): ?>
        <p class="text-sm text-slate-400">Primera ejecución: se crean las tablas en la base de datos y tu usuario.</p>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="rounded-lg bg-red-500/10 border border-red-500/30 text-red-300 text-sm px-3 py-2"><?= e($error) ?></div>
      <?php endif; ?>
      <input type="hidden" name="csrf" value="<?= e(App\Auth::csrfToken()) ?>">
      <?php if ($mode === 'install'): ?>
        <label class="block"><span class="text-sm text-slate-400">Nombre</span>
          <input name="name" class="mt-1 w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" required></label>
      <?php endif; ?>
      <label class="block"><span class="text-sm text-slate-400">Email</span>
        <input name="email" type="email" autocomplete="username" class="mt-1 w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" required></label>
      <label class="block"><span class="text-sm text-slate-400">Contraseña</span>
        <input name="password" type="password" autocomplete="<?= $mode === 'install' ? 'new-password' : 'current-password' ?>" minlength="8" class="mt-1 w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" required></label>
      <button class="w-full rounded-lg bg-blue-600 hover:bg-blue-500 font-medium py-2.5 transition"><?= $mode === 'install' ? 'Instalar y entrar' : 'Ingresar' ?></button>
    </form>
  </div>
</body>
</html>
