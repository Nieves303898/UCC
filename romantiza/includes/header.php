<?php
if (!defined('ROMANTIZA_APP')) { http_response_code(403); exit('Acceso directo no permitido.'); }
require_once __DIR__ . '/cart.php';
/**
 * Header público compartido. Antes de incluir este archivo, la página
 * puede definir: $pageTitle, $pageDescription, $canonicalUrl, $activeNav, $noIndex.
 */
$siteTitle = setting($pdo, 'site_title', 'Romantiza');
$siteDescription = setting($pdo, 'site_description', '');
$pageTitle = $pageTitle ?? $siteTitle;
$pageDescription = $pageDescription ?? $siteDescription;
$canonicalUrl = $canonicalUrl ?? (public_site_url() . '/' . basename($_SERVER['PHP_SELF']));
$activeNav = $activeNav ?? '';
$noIndex = $noIndex ?? false;
$waNumber = setting($pdo, 'contact_whatsapp', '');
$cartCount = cart_raw_count();
$navidad = tema_navidad($pdo);
?>
<!doctype html>
<html lang="es-AR">
<head>
<meta charset="UTF-8">
<title><?= h($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="<?= h($pageDescription) ?>">
<link rel="icon" href="assets/img/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/img/favicon-16x16.png">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<?php if ($noIndex): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<?php if ($canonicalUrl): ?><link rel="canonical" href="<?= h($canonicalUrl) ?>"><?php endif; ?>
<meta property="og:type" content="website">
<meta property="og:site_name" content="Romantiza">
<meta property="og:title" content="<?= h($pageTitle) ?>">
<meta property="og:description" content="<?= h($pageDescription) ?>">
<meta property="og:locale" content="es_AR">
<meta name="twitter:card" content="summary">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,450;0,9..144,560;0,9..144,650;1,9..144,400;1,9..144,500&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=<?= h((string) asset_version(__DIR__ . '/../assets/css/style.css')) ?>">
</head>
<body<?= $navidad ? ' class="tema-navidad"' : '' ?>>
<a href="#main" class="skip-link">Saltar al contenido</a>

<svg style="display:none" aria-hidden="true">
  <symbol id="ic-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></symbol>
  <symbol id="ic-menu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 7h16M4 12h16M4 17h16"/></symbol>
  <symbol id="ic-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6 6l12 12M18 6L6 18"/></symbol>
  <symbol id="ic-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
  <symbol id="ic-truck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 7h11v9H2zM13 10h5l3 3v3h-8z"/><circle cx="6" cy="18.5" r="1.7"/><circle cx="17" cy="18.5" r="1.7"/></symbol>
  <symbol id="ic-shield" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3Z"/></symbol>
  <symbol id="ic-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="5" y="11" width="14" height="9" rx="1.5"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></symbol>
  <symbol id="ic-repeat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 9a8 8 0 0 1 14-4M20 15a8 8 0 0 1-14 4M4 5v4h4M20 19v-4h-4"/></symbol>
  <symbol id="ic-heart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 20s-7-4.3-9.5-8.8C.8 7.6 2.6 4 6.3 4c2 0 3.4 1 5.7 3.4C14.3 5 15.7 4 17.7 4c3.7 0 5.5 3.6 3.8 7.2C19 15.7 12 20 12 20Z"/></symbol>
  <symbol id="ic-pin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 21s7-6.3 7-11.5A7 7 0 0 0 5 9.5C5 14.7 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.4"/></symbol>
  <symbol id="ic-phone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 3h3l2 5-2.5 1.6a11 11 0 0 0 5 5L15 12l5 2v3a2 2 0 0 1-2.2 2A16 16 0 0 1 4 5.2 2 2 0 0 1 6 3Z"/></symbol>
  <symbol id="ic-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></symbol>
  <symbol id="ic-cart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="10" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2.5 3h2.4l2 12.2a2 2 0 0 0 2 1.7h8.4a2 2 0 0 0 2-1.6L21 8H6"/></symbol>
  <symbol id="ic-wa" viewBox="0 0 32 32" fill="currentColor"><path d="M16 3C9 3 3.3 8.7 3.3 15.7c0 2.5.7 4.9 2 7L3 29l6.5-2.2c2 .9 4.2 1.4 6.5 1.4 7 0 12.7-5.7 12.7-12.7C28.7 8.7 23 3 16 3zm0 22.9c-2 0-4-.5-5.7-1.5l-.4-.2-4 1.3 1.3-3.9-.3-.4a10.4 10.4 0 0 1-1.7-5.8C5.2 9.6 10 4.8 16 4.8c5.9 0 10.7 4.8 10.7 10.7S21.9 25.9 16 25.9zm5.9-8c-.3-.2-1.9-1-2.2-1.1-.3-.1-.5-.1-.7.1-.2.3-.8 1-1 1.2-.2.2-.4.2-.6.1a8.7 8.7 0 0 1-4.4-3.8c-.3-.5.3-.5.9-1.6.1-.2 0-.4 0-.5l-.9-2.2c-.2-.5-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1 1-1 2.5s1.1 2.9 1.2 3.1c.1.2 2.2 3.3 5.2 4.6.7.3 1.3.5 1.8.6.7.2 1.4.2 1.9.1.6-.1 1.9-.8 2.1-1.5.3-.7.3-1.4.2-1.5-.1-.1-.3-.2-.6-.4z"/></symbol>
  <symbol id="mot-sprig" viewBox="0 0 60 100"><path d="M30 96 30 20"/><path d="M30 55c0-14-16-18-20-30 16 2 22 12 20 22"/><path d="M30 40c0-12 14-16 17-27-15 2-20 11-17 20"/><circle cx="30" cy="16" r="6"/><circle cx="20" cy="8" r="2"/><circle cx="40" cy="10" r="1.6"/></symbol>
  <symbol id="mot-bloom" viewBox="0 0 100 100"><circle cx="50" cy="50" r="9"/><path d="M50 41c-4-10-16-12-22-6 6 8 16 8 22 6Z"/><path d="M59 45c8-8 6-20-2-24-4 9 0 18 2 24Z"/><path d="M60 55c11 1 17-9 15-18-9 2-15 10-15 18Z"/><path d="M52 60c2 11 13 15 21 10-5-8-14-11-21-10Z"/><path d="M43 59c-3 10-14 12-21 6 6-8 14-9 21-6Z"/></symbol>
  <symbol id="nav-star" viewBox="0 0 24 24"><path fill="currentColor" d="M12 1.5l1.9 7.1 7.1 1.9-7.1 1.9L12 19.5l-1.9-7.1L3 10.5l7.1-1.9z"/></symbol>
</svg>

<?php if ($navidad): ?>
<div class="announce announce-navidad">Navidad: envíos a todo el país &middot; Cambios sin cargo dentro de los 30 días</div>
<?php else: ?>
<div class="announce">Envíos a todo el país &middot; Cambios sin cargo dentro de los 30 días</div>
<?php endif; ?>

<header class="site-header">
  <div class="header-inner container">
    <button class="menu-toggle" id="menuToggle" aria-label="Abrir menú" aria-expanded="false"><svg><use href="#ic-menu"/></svg></button>
    <a href="index.php" class="brand">
      <span class="brand-word">Romantiza</span>
    </a>
    <nav class="main-nav" id="mainNav">
      <a href="index.php" class="<?= $activeNav==='inicio'?'active':'' ?>">Inicio</a>
      <a href="tienda.php" class="<?= $activeNav==='tienda'?'active':'' ?>">Tienda</a>
      <a href="nosotros.php" class="<?= $activeNav==='nosotros'?'active':'' ?>">Nosotros</a>
      <a href="contacto.php" class="<?= $activeNav==='contacto'?'active':'' ?>">Contacto</a>
    </nav>
    <div class="header-actions">
      <button type="button" class="icon-btn" id="searchToggle" aria-label="Buscar productos" aria-expanded="false">
        <svg><use href="#ic-search"/></svg>
      </button>
      <a class="icon-btn" href="carrito.php" aria-label="Ver carrito de compras">
        <svg><use href="#ic-cart"/></svg>
        <?php if ($cartCount > 0): ?><span class="cart-badge"><?= $cartCount > 9 ? '9+' : $cartCount ?></span><?php endif; ?>
      </a>
      <?php if ($waNumber): ?>
      <a class="btn btn-ghost btn-sm" href="https://wa.me/<?= h($waNumber) ?>" target="_blank" rel="noopener">Escribinos</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="search-panel" id="searchPanel">
    <form class="search-inner" action="tienda.php" method="get">
      <svg style="width:18px;height:18px;color:var(--marron-suave);flex:none"><use href="#ic-search"/></svg>
      <input type="text" name="q" id="searchInput" placeholder="Buscar productos o líneas (ej: mantel, Jardín Romantiza)" value="<?= h($_GET['q'] ?? '') ?>">
      <button type="button" class="search-close" id="searchClose">Cerrar</button>
    </form>
  </div>
</header>

<main id="main">
