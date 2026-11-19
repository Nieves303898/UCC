<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $msg = trim($_POST['message'] ?? '');
    if ($name !== '' && $email && $msg !== '') {
        save_message($pdo, 'contacto', $name, $email, $msg);
        $notice = '¡Gracias! Te vamos a responder a la brevedad.';
    } else {
        $notice = 'Revisá el formulario: falta completar algún campo o el email no es válido.';
    }
}

$pageTitle = 'Contacto — ' . setting($pdo, 'site_title', 'Romantiza');
$pageDescription = setting($pdo, 'site_description');
$activeNav = 'contacto';
require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <p class="crumb"><a href="index.php">Inicio</a> / Contacto</p>
  <div class="pad" style="padding-bottom:2.4rem">
    <h1 style="font-size:clamp(2rem,3vw + 1rem,2.8rem)">Hablemos</h1>
  </div>

  <div class="contact-grid" style="padding-bottom:5.5rem">
    <div>
      <div class="contact-card">
        <div class="contact-row"><svg><use href="#ic-pin"/></svg><div><strong>Dónde estamos</strong><span><?= h(setting($pdo, 'contact_address')) ?></span></div></div>
        <?php $phone = setting($pdo, 'contact_phone'); if ($phone): ?>
        <div class="contact-row"><svg><use href="#ic-phone"/></svg><div><strong>Teléfono / WhatsApp</strong><span><?= h($phone) ?></span></div></div>
        <?php endif; ?>
        <?php $ig = setting($pdo, 'contact_instagram'); if ($ig): ?>
        <div class="contact-row"><svg><use href="#ic-wa" style="width:20px;height:20px"/></svg><div><strong>Instagram</strong><span><?= h($ig) ?></span></div></div>
        <?php endif; ?>
        <?php $hours = setting($pdo, 'contact_hours'); if ($hours): ?>
        <div class="contact-row"><svg><use href="#ic-clock"/></svg><div><strong>Horario de atención</strong><span><?= h($hours) ?></span></div></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="contact-card">
      <h3 style="font-size:1.3rem;margin-bottom:1.4rem">Escribinos</h3>
      <?php if ($notice): ?><p style="font-weight:700;margin-bottom:1rem"><?= h($notice) ?></p><?php endif; ?>
      <form method="post" action="contacto.php">
        <div class="form-field"><label for="cf-name">Nombre</label><input id="cf-name" name="name" required placeholder="Tu nombre" value="<?= h($_POST['name'] ?? '') ?>"></div>
        <div class="form-field"><label for="cf-email">Email</label><input id="cf-email" name="email" type="email" required placeholder="tu@email.com" value="<?= h($_POST['email'] ?? '') ?>"></div>
        <div class="form-field"><label for="cf-msg">Mensaje</label><textarea id="cf-msg" name="message" rows="4" required placeholder="Contanos qué estás buscando..."><?= h($_POST['message'] ?? '') ?></textarea></div>
        <button class="btn btn-primary btn-block" type="submit">Enviar mensaje</button>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
