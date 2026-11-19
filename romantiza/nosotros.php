<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Nosotros — ' . setting($pdo, 'site_title', 'Romantiza');
$pageDescription = setting($pdo, 'site_description');
$activeNav = 'nosotros';
require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <p class="crumb"><a href="index.php">Inicio</a> / Nosotros</p>
  <div class="about-hero pad">
    <div>
      <h1 style="font-size:clamp(2.1rem,3vw + 1rem,3rem)"><?= setting($pdo, 'about_title') ?></h1>
      <p class="lead" style="margin-top:1.2rem"><?= h(setting($pdo, 'about_lead')) ?></p>
    </div>
    <div class="ph"><svg class="motif" style="width:40%"><use href="#mot-sprig"/></svg><span class="ph-tag">Foto pendiente</span></div>
  </div>

  <section class="pad" style="padding-top:0">
    <h2>Así queremos que te sientas</h2>
    <p class="value-line">Romántica &middot; Delicada &middot; Artesanal &middot; Femenina &middot; Cálida &middot; Natural &middot; Atemporal &middot; Cuidada</p>
  </section>

  <section class="pad" style="padding-top:0;padding-bottom:5.5rem">
    <div class="story-block">
      <p><?= h(setting($pdo, 'about_text_1')) ?></p>
      <p><?= h(setting($pdo, 'about_text_2')) ?></p>
    </div>
  </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
