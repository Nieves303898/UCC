<?php /** Encabezado común: fuentes, CSS y tema antes de pintar (evita parpadeo). */ ?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css?v=<?= @filemtime(APP_ROOT . '/public/assets/app.css') ?>">
  <link rel="stylesheet" href="assets/tailwind.css?v=<?= @filemtime(APP_ROOT . '/public/assets/tailwind.css') ?>">
  <link rel="icon" type="image/svg+xml" href="assets/paylab-mark.svg">
  <meta name="theme-color" content="#2e3192">
  <script>
    (function () {
      var t = null;
      try { t = JSON.parse(localStorage.getItem('mh.theme')); } catch (e) {}
      if (t !== 'light' && t !== 'dark') t = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', t);
    })();
  </script>
