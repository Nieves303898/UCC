<?php /** @var ?array $flash */
$user = App\Auth::user();
$isClient = $user['role'] === 'client';
?><!doctype html>
<html lang="es">
<head>
<?php require __DIR__ . '/_head.php'; ?>
  <title><?= e(App\Config::get('app_name', 'Meta Hub')) ?></title>
  <meta name="csrf" content="<?= e(App\Auth::csrfToken()) ?>">
  <script src="assets/vendor/chart.umd.min.js"></script>
</head>
<body class="<?= $isClient ? 'is-client' : 'is-admin' ?>">
<div id="shell" class="min-h-screen <?= $isClient ? '' : 'lg:pl-[248px]' ?>">

<?php if (!$isClient): ?>
  <aside id="sidebar" class="sidebar fixed inset-y-0 left-0 z-40 w-[248px] -translate-x-full lg:translate-x-0 transition-transform flex flex-col">
    <div class="h-16 px-4 flex items-center gap-3 hair-b">
      <div class="mono-avatar w-8 h-8" style="background:var(--t1);color:var(--bg)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 17V7l7 6 7-6v10"/></svg></div>
      <div class="min-w-0 leading-tight"><div class="font-semibold t1 truncate"><?= e(App\Config::get('app_name', 'Meta Hub')) ?></div><div class="text-xs t3 truncate"><?= e(App\Config::get('agency_name', '')) ?></div></div>
    </div>
    <button id="palette-btn" class="mx-3 mt-3 btn justify-start t3" style="font-weight:400"><span data-icon="search"></span>Buscar…<kbd class="ml-auto">Ctrl K</kbd></button>
    <nav id="nav" class="flex-1 overflow-y-auto px-3 pb-3 text-[13.5px]"></nav>
    <div class="p-3 hair-t flex items-center gap-2">
      <div class="mono-avatar w-8 h-8 text-xs" style="background:var(--sunk);color:var(--t2)" id="user-initials"></div>
      <div class="min-w-0 flex-1 leading-tight"><div class="t1 text-[13px] font-medium truncate" id="user-name"></div><div class="t3 text-xs"><?= ['viewer' => 'Solo lectura', 'member' => 'Equipo'][$user['role']] ?? 'Administrador' ?></div></div>
      <button class="btn btn-ghost btn-icon" data-theme-toggle title="Cambiar tema"></button>
      <a class="btn btn-ghost btn-icon" href="index.php?r=logout" title="Salir" data-icon="logout"></a>
    </div>
  </aside>
  <div id="backdrop" class="fixed inset-0 z-30 hidden lg:hidden" style="background:rgba(0,0,0,.35)"></div>
<?php endif; ?>

  <header class="topbar sticky top-0 z-20">
    <div class="<?= $isClient ? 'max-w-[1320px] mx-auto' : '' ?> px-4 lg:px-8 h-16 flex items-center gap-3">
      <?php if ($isClient): ?>
        <div id="client-brand" class="flex items-center gap-3 shrink-0"></div>
        <nav id="nav" class="topnav hidden md:flex items-center gap-1 ml-4 text-[13.5px]"></nav>
      <?php else: ?>
        <button id="menu-btn" class="lg:hidden btn btn-ghost btn-icon -ml-2" aria-label="Menú" data-icon="menu"></button>
        <div id="breadcrumbs" class="min-w-0 flex-1 text-[13px] t3 truncate"></div>
      <?php endif; ?>
      <div class="flex-1"></div>
      <div id="range-ctl" class="flex items-center gap-2"></div>
      <button id="live-btn" class="btn btn-sm hidden sm:inline-flex" title="Actualización automática"><span class="live-dot"></span><span>En vivo</span></button>
      <button id="refresh-btn" class="btn btn-icon btn-sm" title="Actualizar ahora" data-icon="refresh"></button>
      <?php if ($isClient): ?>
        <button class="btn btn-ghost btn-icon btn-sm" data-theme-toggle title="Cambiar tema"></button>
        <button class="btn btn-ghost btn-sm" id="account-btn"><span data-icon="user"></span><span class="hidden sm:inline" id="user-name"></span></button>
      <?php endif; ?>
    </div>
    <?php if ($isClient): ?>
      <nav id="nav-mobile" class="topnav md:hidden flex gap-1 px-4 pb-2 overflow-x-auto text-[13px]"></nav>
    <?php endif; ?>
  </header>

  <div class="<?= $isClient ? 'max-w-[1320px] mx-auto' : 'max-w-[1600px]' ?> px-4 lg:px-8">
    <div class="pt-3 text-[11.5px] t3 flex flex-wrap gap-x-4 gap-y-1 no-print" id="status-line"><span id="updated-at"></span><span id="usage"></span></div>
    <main id="view" class="py-5"></main>
  </div>
</div>

<div id="toasts" class="fixed bottom-4 right-4 z-[70] space-y-2 w-[340px] max-w-[calc(100vw-2rem)]"></div>
<div id="modal" class="overlay hidden"><div class="modal-card"></div></div>
<div id="palette" class="overlay hidden"></div>

<script>window.FLASH = <?= json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="assets/app.js?v=<?= @filemtime(APP_ROOT . '/public/assets/app.js') ?>"></script>
</body>
</html>
