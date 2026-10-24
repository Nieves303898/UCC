<?php /** @var ?array $flash */ ?><!doctype html>
<html lang="es" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(App\Config::get('app_name', 'Meta Hub')) ?></title>
  <meta name="robots" content="noindex,nofollow">
  <meta name="csrf" content="<?= e(App\Auth::csrfToken()) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/tailwind.css?v=1">
  <script src="assets/vendor/chart.umd.min.js"></script>
  <link rel="stylesheet" href="assets/app.css?v=1">
</head>
<body class="h-full bg-slate-950 text-slate-200">
<div id="shell" class="min-h-full lg:pl-64">

  <!-- Sidebar -->
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full lg:translate-x-0 transition-transform bg-slate-900 border-r border-slate-800 flex flex-col">
    <div class="h-16 px-5 flex items-center gap-3 border-b border-slate-800">
      <div class="h-9 w-9 rounded-xl bg-gradient-to-br from-blue-500 to-fuchsia-500 grid place-items-center font-bold text-white">M</div>
      <div class="min-w-0">
        <div class="font-semibold text-white truncate"><?= e(App\Config::get('app_name', 'Meta Hub')) ?></div>
        <div class="text-xs text-slate-500">Métricas Meta en vivo</div>
      </div>
    </div>
    <nav class="flex-1 overflow-y-auto p-3 space-y-1 text-sm" id="nav">
      <a href="#/" data-nav="overview" class="nav-link"><span>📊</span> Resumen general</a>
      <a href="#/ads" data-nav="ads" class="nav-link"><span>🎯</span> Campañas y anuncios <b class="badge" data-count="ad_account"></b></a>
      <a href="#/pages" data-nav="pages" class="nav-link"><span>📘</span> Páginas de Facebook <b class="badge" data-count="page"></b></a>
      <a href="#/ig" data-nav="ig" class="nav-link"><span>📸</span> Instagram <b class="badge" data-count="ig_account"></b></a>
      <a href="#/activity" data-nav="activity" class="nav-link"><span>🔔</span> Actividad</a>
      <a href="#/settings" data-nav="settings" class="nav-link"><span>⚙️</span> Configuración</a>
    </nav>
    <div class="p-4 border-t border-slate-800 text-xs text-slate-500 flex items-center justify-between">
      <span id="user-name" class="truncate"></span>
      <a href="index.php?r=logout" class="text-slate-400 hover:text-white">Salir</a>
    </div>
  </aside>
  <div id="backdrop" class="fixed inset-0 z-30 bg-black/50 hidden lg:hidden"></div>

  <!-- Topbar -->
  <header class="sticky top-0 z-20 bg-slate-950/85 backdrop-blur border-b border-slate-800">
    <div class="px-4 lg:px-8 h-16 flex items-center gap-3">
      <button id="menu-btn" class="lg:hidden p-2 -ml-2 rounded-lg hover:bg-slate-800" aria-label="Menú">☰</button>
      <div id="breadcrumbs" class="min-w-0 flex-1 text-sm text-slate-400 truncate"></div>
      <div class="flex items-center gap-2">
        <select id="preset" class="ctl"></select>
        <div id="custom-range" class="hidden items-center gap-1">
          <input type="date" id="since" class="ctl w-36">
          <input type="date" id="until" class="ctl w-36">
        </div>
        <button id="live-btn" class="ctl flex items-center gap-2" title="Actualización automática">
          <span class="live-dot"></span><span class="hidden sm:inline">En vivo</span>
        </button>
        <button id="refresh-btn" class="ctl" title="Forzar actualización desde Meta">⟳</button>
      </div>
    </div>
    <div class="px-4 lg:px-8 pb-2 -mt-1 text-[11px] text-slate-500 flex gap-3">
      <span id="updated-at"></span><span id="usage"></span>
    </div>
  </header>

  <main id="view" class="px-4 lg:px-8 py-6 max-w-[1600px]"></main>
</div>

<div id="toasts" class="fixed bottom-4 right-4 z-50 space-y-2 w-80 max-w-[calc(100vw-2rem)]"></div>
<div id="modal" class="fixed inset-0 z-50 hidden bg-black/60 p-4 overflow-y-auto"><div class="modal-card"></div></div>

<script>window.FLASH = <?= json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="assets/app.js?v=1"></script>
</body>
</html>
