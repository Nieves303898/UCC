<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = setting($pdo, 'site_title');
$pageDescription = setting($pdo, 'site_description');
$activeNav = 'inicio';
require __DIR__ . '/includes/header.php';

/*
 * PORTADA — sigue el orden de una charla con el cliente:
 *  1 Portada · 2 Problema · 3 Solución · 4 Para quién es · 5 Oferta
 *  6 Cómo comprar · 7 Pruebas · 8 Quiénes somos · 9 Preguntas · 10 Cierre
 * Un solo botón principal en toda la página ($cta), que se repite.
 */
$navidad = tema_navidad($pdo);

$navidadCat = null;
try {
    $navidadCat = $pdo->query("SELECT * FROM categories WHERE slug LIKE '%navid%' ORDER BY sort_order LIMIT 1")->fetch() ?: null;
} catch (Throwable $e) {
    $navidadCat = null;
}
if ($navidad && $navidadCat) {
    $cta = ['url' => 'tienda.php?categoria=' . $navidadCat['slug'], 'text' => 'Ver la colección de Navidad'];
} else {
    $cta = ['url' => 'tienda.php', 'text' => 'Ver la tienda'];
}

// ---- 1. Portada: textos del primer slide con título (panel → Portada);
// las fotos de todos los slides con título van rotando detrás.
try {
    $slides = $pdo->query('SELECT * FROM hero_slides WHERE active = 1 ORDER BY sort_order, id')->fetchAll();
} catch (Throwable $e) {
    $slides = [];
}
$heroSlides = array_values(array_filter($slides, fn($s) => !empty($s['title'])));
if ($heroSlides) {
    $heroTitle = $heroSlides[0]['title'];
    $heroLead = (string) $heroSlides[0]['subtitle'];
    $heroImages = array_map(fn($s) => portada_image_url($s['image']), $heroSlides);
} else {
    // Sin slides con título: textos por defecto y fotos de campaña si están subidas.
    $heroTitle = $navidad ? 'Tu mesa de Navidad, lista para recibir' : 'Textiles para una casa linda todos los días';
    $heroLead = $navidad
        ? 'Manteles, caminos e individuales de algodón, estampados a mano en Chaco. Envíos a todo el país.'
        : 'Manteles, caminos e individuales de algodón y lino, estampados a mano en Chaco. Envíos a todo el país.';
    $heroImages = [];
    if ($navidad) {
        foreach (['navidad-1-capsula.jpg', 'navidad-2-mesa.jpg', 'navidad-3-regalos.jpg'] as $f) {
            if (is_file(__DIR__ . '/uploads/portada/' . $f)) $heroImages[] = portada_image_url($f);
        }
    }
    if (!$heroImages) {
        $ess = portada_image_url(setting($pdo, 'essence_image'));
        if ($ess) $heroImages[] = $ess;
    }
}
$heroImages = array_values(array_filter($heroImages));

// ---- 4. Para quién es: tres caminos según lo que busca cada cliente.
$caminos = [];
$caminoSlugs = $navidad
    ? ['navid' => 'Para la mesa de Navidad', 'manteleria' => 'Para la mesa de todos los días', 'cocina' => 'Para la cocina']
    : ['manteleria' => 'Para la mesa de todos los días', 'cocina' => 'Para la cocina', 'cortinas' => 'Para las ventanas'];
foreach ($caminoSlugs as $slug => $label) {
    $st = $pdo->prepare("SELECT c.*, (SELECT p.image FROM products p WHERE p.category_id = c.id AND p.active = 1 AND p.image <> '' ORDER BY p.featured DESC, p.sort_order LIMIT 1) AS first_image,
        (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.active = 1) AS n
        FROM categories c WHERE c.slug " . ($slug === 'navid' ? "LIKE '%navid%'" : "= ?") . " LIMIT 1");
    $st->execute($slug === 'navid' ? [] : [$slug]);
    $c = $st->fetch();
    if (!$c || !$c['n']) continue;
    $img = category_image_url($c['image'] ?? null) ?: product_image_url($c['first_image'] ?? null);
    $caminos[] = ['label' => $label, 'url' => 'tienda.php?categoria=' . $c['slug'], 'img' => $img, 'n' => (int) $c['n']];
}

// ---- 5. Oferta: en Navidad, la colección navideña; el resto del año, los destacados.
$prodSql = "SELECT p.*, c.name AS cat_name, c.slug AS cat_slug,
    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.active = 1) AS variant_count,
    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.active = 1 AND (v.stock IS NULL OR v.stock > 0)) AS has_stock
    FROM products p JOIN categories c ON c.id = p.category_id WHERE p.active = 1";
$oferta = [];
if ($navidad && $navidadCat) {
    $st = $pdo->prepare($prodSql . " AND p.category_id = ? ORDER BY p.featured DESC, p.sort_order, p.created_at DESC LIMIT 5");
    $st->execute([$navidadCat['id']]);
    $oferta = $st->fetchAll();
}
if (!$oferta) {
    $oferta = $pdo->query($prodSql . " ORDER BY p.featured DESC, p.sort_order, p.created_at DESC LIMIT 5")->fetchAll();
}

try {
    $homeVideos = $pdo->query('SELECT * FROM home_videos WHERE active = 1 ORDER BY sort_order, id')->fetchAll();
} catch (Throwable $e) {
    $homeVideos = [];
}
$essenceImage = portada_image_url(setting($pdo, 'essence_image'));
$waNumber = setting($pdo, 'contact_whatsapp');
$address = setting($pdo, 'contact_address');
$hours = setting($pdo, 'contact_hours');
$igUrl = setting($pdo, 'contact_instagram_url');
$igUser = setting($pdo, 'contact_instagram');

function product_card_html(array $p): string {
    $img = product_image_url($p['image']);
    $outOfStock = !empty($p['has_stock']) ? false : true;
    $flagHtml = '';
    if ($outOfStock) {
        $flagHtml = '<span class="card-flag agotado">Sin stock</span>';
    } elseif ($p['flag']) {
        $flagClass = ($p['flag'] === 'Nuevo') ? 'nuevo' : '';
        $flagHtml = '<span class="card-flag ' . h($flagClass) . '">' . h($p['flag']) . '</span>';
    }
    $mediaInner = $img
        ? '<img src="' . h($img) . '" alt="' . h($p['name']) . '" loading="lazy">'
        : '<svg class="motif" style="width:40%"><use href="#mot-bloom"/></svg><span class="ph-tag">Foto pendiente</span>';
    return '
      <a class="card' . ($outOfStock ? ' card-outofstock' : '') . '" href="producto.php?slug=' . h($p['slug']) . '">
        <div class="card-media">' . $flagHtml . '
          <div class="ph">' . $mediaInner . '</div>
          <span class="quick-add" aria-hidden="true"><svg><use href="#ic-arrow"/></svg></span>
        </div>
        <div class="card-body">
          <p class="card-cat">' . h($p['cat_name']) . '</p>
          <h3 class="card-title">' . h($p['name']) . '</h3>
          <p class="card-price">' . card_price_label($p['price'], (int)$p['variant_count']) . '</p>
        </div>
      </a>';
}
?>

<!-- 1. PORTADA — ¿Qué hacen y para quién? -->
<section class="h-hero" id="hHero">
  <div class="h-hero-text">
    <h1><?= h($heroTitle) ?></h1>
    <?php if ($heroLead): ?><p><?= h($heroLead) ?></p><?php endif; ?>
    <a class="btn h-cta" href="<?= h($cta['url']) ?>"><?= h($cta['text']) ?></a>
    <ul class="h-hero-facts">
      <li>Envíos a todo el país</li>
      <li>Mercado Pago</li>
      <li>Cambios sin cargo</li>
    </ul>
  </div>
  <div class="h-hero-media">
    <?php foreach ($heroImages as $i => $img): ?>
    <img class="h-hero-img <?= $i === 0 ? 'is-active' : '' ?>" src="<?= h('/' . ltrim($img, '/')) ?>" alt="" <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
    <?php endforeach; ?>
  </div>
</section>

<!-- 2 + 3. PROBLEMA Y SOLUCIÓN — ¿Me entienden? ¿Cómo me ayudan? -->
<section class="h-why">
  <div class="container h-why-grid">
    <div class="h-why-text">
      <p class="h-why-problem"><?= $navidad
        ? 'Se viene la cena del 24 y querés una mesa linda. Pero en todos lados encontrás lo mismo: manteles sintéticos y diseños repetidos.'
        : 'Querés una mesa linda para todos los días. Pero en todos lados encontrás lo mismo: telas sintéticas y diseños repetidos.' ?></p>
      <h2>Por eso hacemos textiles de verdad, a mano, en Chaco</h2>
      <ol class="h-points">
        <li><strong>Telas naturales</strong><span>Algodón o lino, según la pieza.</span></li>
        <li><strong>Hechos a mano</strong><span>Estampados, puntillas y terminaciones, una por una.</span></li>
        <li><strong>Todo combina</strong><span>Manteles, caminos e individuales de la misma línea.</span></li>
      </ol>
    </div>
    <?php if ($essenceImage): ?><img class="h-why-img" src="<?= h($essenceImage) ?>" alt="Textiles Romantiza estampados a mano" loading="lazy"><?php endif; ?>
  </div>
</section>

<?php if ($caminos): ?>
<!-- 4. PARA QUIÉN ES — ¿Es para mí? -->
<section class="h-section h-paths-wrap">
  <div class="container">
    <h2>¿Qué estás buscando?</h2>
    <div class="h-paths">
      <?php foreach ($caminos as $c): ?>
      <a class="h-path" href="<?= h($c['url']) ?>">
        <?php if ($c['img']): ?><img src="<?= h($c['img']) ?>" alt="" loading="lazy"><?php endif; ?>
        <span><?= h($c['label']) ?> <svg><use href="#ic-arrow"/></svg></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($oferta): ?>
<!-- 5. OFERTA — ¿Cuánto sale? -->
<section class="h-section h-offer">
  <div class="container">
    <div class="h-head">
      <h2><?= ($navidad && $navidadCat) ? 'La colección de Navidad' : 'Los más pedidos' ?></h2>
      <a class="h-link" href="<?= h($cta['url']) ?>">Ver todo <svg><use href="#ic-arrow"/></svg></a>
    </div>
    <div class="h-products"><?php foreach ($oferta as $p) echo product_card_html($p); ?></div>
  </div>
</section>
<?php endif; ?>

<!-- 6. CÓMO COMPRAR — ¿Qué pasa si compro? -->
<section class="h-steps-wrap">
  <div class="container">
    <h2>Cómo es comprar en Romantiza</h2>
    <ol class="h-steps">
      <li><strong>Elegís y pagás</strong><span>Mercado Pago, transferencia o efectivo.</span></li>
      <li><strong>Coordinamos el envío</strong><span>Te escribimos por WhatsApp al confirmar el pedido.</span></li>
      <li><strong>Lo despachamos</strong><span>Desde Sáenz Peña a todo el país.</span></li>
      <li><strong>Si algo no va, lo cambiás</strong><span>Sin cargo, dentro de los 30 días.</span></li>
    </ol>
  </div>
</section>

<?php if ($homeVideos): ?>
<!-- 7. PRUEBAS — ¿Les puedo creer? -->
<section class="h-section">
  <div class="container">
    <div class="h-head">
      <h2>Así se ven en una mesa de verdad</h2>
      <?php if ($igUrl): ?><a class="h-link" href="<?= h($igUrl) ?>" target="_blank" rel="noopener">Instagram <svg><use href="#ic-arrow"/></svg></a><?php endif; ?>
    </div>
    <div class="home-videos-grid">
      <?php foreach ($homeVideos as $v): ?>
      <div class="home-video-item"><?= instagram_embed_html($v['instagram_url'], '360px') ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- 8 + 9. QUIÉNES SOMOS Y PREGUNTAS — ¿Quién está detrás? ¿Y si…? -->
<section class="h-section h-about-faq">
  <div class="container h-af-grid">
    <div class="h-about">
      <h2>Somos Romantiza, de Sáenz Peña</h2>
      <p><?= h(setting($pdo, 'about_lead')) ?></p>
      <ul class="h-facts">
        <?php if ($address): ?><li><?= h($address) ?></li><?php endif; ?>
        <?php if ($hours): ?><li>Atención: <?= h($hours) ?></li><?php endif; ?>
        <?php if ($igUrl): ?><li><a href="<?= h($igUrl) ?>" target="_blank" rel="noopener">Instagram <?= h($igUser) ?></a></li><?php endif; ?>
      </ul>
      <a class="h-link" href="nosotros.php">Conocé nuestra historia <svg><use href="#ic-arrow"/></svg></a>
    </div>
    <div class="h-faq">
      <h2>Preguntas frecuentes</h2>
      <div class="h-faq-list">
        <?php if ($navidad): ?>
        <details><summary>¿Llega antes de Navidad?</summary><p>Depende de tu ciudad. Escribinos por WhatsApp antes de comprar y te decimos si llega a tiempo.</p></details>
        <?php endif; ?>
        <details><summary>¿Envían a mi ciudad?</summary><p>Sí, despachamos a todo el país. Una vez confirmado el pedido, coordinamos el envío por WhatsApp.</p></details>
        <details><summary>¿Cómo puedo pagar?</summary><p>Con Mercado Pago, transferencia o efectivo.</p></details>
        <details><summary>¿Y si la medida no me va?</summary><p>Lo cambiás sin cargo dentro de los 30 días de recibido el producto.</p></details>
        <details><summary>¿De qué tela son y cómo se lavan?</summary><p>Son 100% algodón o lino natural, según la pieza. Lavar a mano o en ciclo suave con agua fría, sin lavandina. Planchar del revés a temperatura media.</p></details>
        <?php if ($waNumber): ?>
        <details><summary>Tengo otra pregunta</summary><p><a href="https://wa.me/<?= h($waNumber) ?>" target="_blank" rel="noopener">Escribinos por WhatsApp</a><?= $hours ? ' y te respondemos (' . h($hours) . ').' : '.' ?></p></details>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- 10. CIERRE — ¿Cómo empiezo? -->
<section class="h-close">
  <div class="container h-close-inner">
    <h2><?= $navidad ? 'Armá tu mesa de Navidad' : 'Armá tu mesa' ?></h2>
    <a class="btn h-cta h-cta-lg" href="<?= h($cta['url']) ?>"><?= h($cta['text']) ?></a>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
