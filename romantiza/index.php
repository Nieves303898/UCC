<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newsletter_email'])) {
    $email = filter_var(trim($_POST['newsletter_email']), FILTER_VALIDATE_EMAIL);
    if ($email) {
        save_message($pdo, 'newsletter', '', $email, '');
        $notice = '¡Gracias por sumarte a Romantiza!';
    } else {
        $notice = 'Ese email no parece válido, ¿podés revisarlo?';
    }
}

$pageTitle = setting($pdo, 'site_title');
$pageDescription = setting($pdo, 'site_description');
$activeNav = 'inicio';
require __DIR__ . '/includes/header.php';

// Solo las categorías marcadas como "línea" (colecciones de diseño propias)
// aparecen en la sección editorial de la portada — las categorías comunes
// (Mantelería, Puntillas, etc.) se filtran acá y solo se usan para
// filtrar en la tienda. Ver panel → Líneas y categorías. Si todavía no se
// corrió la migración que agrega is_linea, se muestra como antes (todas
// las categorías con productos activos) en lugar de romper la portada.
try {
    $categories = $pdo->query("SELECT c.* FROM categories c WHERE c.is_linea = 1 AND EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.active = 1) ORDER BY c.sort_order")->fetchAll();
} catch (Throwable $e) {
    $categories = $pdo->query('SELECT c.* FROM categories c WHERE EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.active = 1) ORDER BY c.sort_order')->fetchAll();
}

// Slides y videos son funcionalidad nueva: si todavía no se corrió la
// migración de base de datos correspondiente, la portada sigue andando
// igual (con el diseño de reemplazo) en lugar de romperse.
try {
    $slides = $pdo->query('SELECT * FROM hero_slides WHERE active = 1 ORDER BY sort_order, id')->fetchAll();
} catch (Throwable $e) {
    $slides = [];
}
try {
    $homeVideos = $pdo->query('SELECT * FROM home_videos WHERE active = 1 ORDER BY sort_order, id')->fetchAll();
} catch (Throwable $e) {
    $homeVideos = [];
}
try {
    $essenceImage = portada_image_url(setting($pdo, 'essence_image'));
} catch (Throwable $e) {
    $essenceImage = null;
}
$featured = $pdo->query("SELECT p.*, c.name AS cat_name, c.slug AS cat_slug,
    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.active = 1) AS variant_count,
    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.active = 1 AND (v.stock IS NULL OR v.stock > 0)) AS has_stock
    FROM products p JOIN categories c ON c.id = p.category_id WHERE p.active = 1 AND p.featured = 1 ORDER BY p.sort_order LIMIT 3")->fetchAll();
if (count($featured) < 3) {
    // completa con los productos más recientes si hay pocos destacados marcados
    $extra = $pdo->prepare("SELECT p.*, c.name AS cat_name, c.slug AS cat_slug,
        (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.active = 1) AS variant_count,
        (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.active = 1 AND (v.stock IS NULL OR v.stock > 0)) AS has_stock
        FROM products p JOIN categories c ON c.id = p.category_id WHERE p.active = 1 ORDER BY p.created_at DESC LIMIT ?");
    $extra->execute([8]);
    $seen = array_column($featured, 'id');
    foreach ($extra->fetchAll() as $p) {
        if (count($featured) >= 3) break;
        if (!in_array($p['id'], $seen, true)) { $featured[] = $p; $seen[] = $p['id']; }
    }
}

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

<?php if ($slides): ?>
<?php
  // En pantallas angostas (celular/tablet) el recuadro del slider no
  // puede tener un alto fijo cualquiera: si no coincide con la forma
  // real de la foto, o se recorta contenido de los costados (se puede
  // perder texto de diseño) o sobran franjas vacías arriba/abajo. Para
  // evitar las dos cosas, calculamos la proporción real de la primera
  // foto del slider (ancho x alto del archivo) y el recuadro se ajusta
  // a esa forma exacta — así la foto entra siempre completa y sin
  // espacios de sobra. Si no se puede leer el archivo, usamos una
  // proporción panorámica típica como respaldo.
  $heroRatio = '16 / 9';
  $firstImg = $slides[0]['image'] ?? '';
  if ($firstImg) {
      $firstPath = __DIR__ . '/uploads/portada/' . $firstImg;
      $dims = @getimagesize($firstPath);
      if ($dims && $dims[0] > 0 && $dims[1] > 0) {
          $heroRatio = $dims[0] . ' / ' . $dims[1];
      }
  }
?>
<section class="hero-slider" id="heroSlider" style="--hero-ratio:<?= h($heroRatio) ?>">
  <?php foreach ($slides as $i => $s): $simg = portada_image_url($s['image']); ?>
  <?php
    // Ojo: esta imagen se usa como variable CSS (--slide-img). Un
    // detalle poco conocido de las variables CSS: una ruta relativa
    // dentro de una variable se resuelve tomando como base la hoja de
    // estilos que la usa (assets/css/style.css), no esta página — por
    // eso acá se arma con "/" adelante, para que apunte siempre a la
    // carpeta uploads/ de la raíz del sitio sin importar desde dónde
    // se la use.
    $simgAbs = $simg ? '/' . ltrim($simg, '/') : '';
  ?>
  <div class="hero-slide <?= $i===0?'is-active':'' ?>" style="--slide-img:url('<?= h($simgAbs) ?>')">
    <div class="hero-slide-overlay">
      <div class="container hero-slide-inner">
        <?php if ($s['title']): ?><h1><?= h($s['title']) ?></h1><?php endif; ?>
        <?php if ($s['subtitle']): ?><p class="lead"><?= h($s['subtitle']) ?></p><?php endif; ?>
        <?php if ($s['link_url'] && $s['link_text']): ?><a class="btn btn-primary" href="<?= h($s['link_url']) ?>"><?= h($s['link_text']) ?></a><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (count($slides) > 1): ?>
  <button type="button" class="hero-slider-arrow hero-slider-prev" aria-label="Foto anterior"><svg style="width:14px;height:14px"><use href="#ic-arrow"/></svg></button>
  <button type="button" class="hero-slider-arrow hero-slider-next" aria-label="Foto siguiente"><svg style="width:14px;height:14px"><use href="#ic-arrow"/></svg></button>
  <div class="hero-slider-dots">
    <?php foreach ($slides as $i => $s): ?>
    <button type="button" class="hero-dot <?= $i===0?'is-active':'' ?>" data-slide="<?= $i ?>" aria-label="Ver foto <?= $i+1 ?>"></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php else: ?>
<div class="hero container">
  <div class="hero-grid">
    <div>
      <h1><?= setting($pdo, 'hero_title') ?></h1>
      <p class="lead"><?= h(setting($pdo, 'hero_lead')) ?></p>
      <div class="hero-ctas">
        <a class="btn btn-primary" href="tienda.php">Ver el catálogo <svg style="width:14px;height:14px"><use href="#ic-arrow"/></svg></a>
        <a class="btn btn-ghost" href="carrito.php">Ir al carrito</a>
      </div>
    </div>
    <div class="hero-collage">
      <div class="ph hc1"><svg class="motif" style="width:60%"><use href="#mot-bloom"/></svg><span class="ph-tag">Foto pendiente</span></div>
      <div class="ph hc2"><svg class="motif" style="width:50%"><use href="#mot-sprig"/></svg></div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($categories): ?>
<section class="pad container">
  <div class="section-head-center">
    <span class="eyebrow">Nuestras líneas</span>
    <h2>Cada rincón, con su textil</h2>
    <p class="section-lead">Cada línea tiene identidad propia: elegí la que mejor combine con tu casa y descubrí los textiles pensados para ese rincón.</p>
  </div>
  <div class="cat-grid">
    <?php foreach ($categories as $c): $cimg = category_image_url($c['image'] ?? null); ?>
    <a class="cat-card" href="tienda.php?categoria=<?= h($c['slug']) ?>">
      <div class="ph">
        <?php if ($cimg): ?><img src="<?= h($cimg) ?>" alt="<?= h($c['name']) ?>" loading="lazy">
        <?php else: ?><svg class="motif" style="width:44%"><use href="#mot-sprig"/></svg><?php endif; ?>
      </div>
      <div class="cat-label"><?= h($c['name']) ?> <svg><use href="#ic-arrow"/></svg></div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="essence">
  <div class="container pad">
    <div class="essence-layout">
      <div class="essence-visual">
        <div class="ph">
          <?php if ($essenceImage): ?><img src="<?= h($essenceImage) ?>" alt="Nuestra esencia">
          <?php else: ?><svg class="motif"><use href="#mot-bloom"/></svg><span class="ph-tag">Foto pendiente</span><?php endif; ?>
        </div>
      </div>
      <div class="essence-content">
        <h2>Nuestra esencia</h2>
        <p class="essence-lead">Elegimos cada tela y cuidamos cada estampado pensando en las manos que los van a usar todos los días. Por eso, cuando algo sale de Romantiza, se nota la diferencia.</p>
        <div class="essence-grid">
          <div class="essence-card">
            <span class="essence-icon essence-icon-rosa"><svg class="motif"><use href="#mot-sprig"/></svg></span>
            <h3>Romántica</h3>
            <p>Diseños delicados, sin caer en lo excesivo.</p>
          </div>
          <div class="essence-card">
            <span class="essence-icon essence-icon-salvia"><svg class="motif"><use href="#mot-bloom"/></svg></span>
            <h3>Artesanal</h3>
            <p>Estampados y terminaciones hechas a mano.</p>
          </div>
          <div class="essence-card">
            <span class="essence-icon essence-icon-arena"><svg class="motif"><use href="#mot-sprig"/></svg></span>
            <h3>Natural</h3>
            <p>Algodón y lino en tonos crudos y suaves.</p>
          </div>
          <div class="essence-card">
            <span class="essence-icon essence-icon-rosa"><svg class="motif"><use href="#mot-bloom"/></svg></span>
            <h3>Atemporal</h3>
            <p>Piezas que acompañan tu casa por años.</p>
          </div>
        </div>
        <a class="btn btn-primary" href="tienda.php">Descubrí la colección <svg style="width:14px;height:14px"><use href="#ic-arrow"/></svg></a>
      </div>
    </div>
  </div>
</section>

<?php if ($featured): ?>
<section class="pad container">
  <div class="section-head">
    <div><span class="eyebrow">Lo más elegido</span><h2>Elegidos para empezar</h2></div>
    <a class="btn btn-ghost btn-sm" href="tienda.php">Ver toda la tienda</a>
  </div>
  <div class="product-grid"><?php foreach ($featured as $p) echo product_card_html($p); ?></div>
</section>
<?php endif; ?>

<?php if ($homeVideos): ?>
<section class="pad container">
  <div class="section-head">
    <div><h2>Mirá Romantiza en Instagram</h2></div>
    <?php $igUrl = setting($pdo, 'contact_instagram_url'); if ($igUrl): ?>
    <a class="btn btn-ghost btn-sm" href="<?= h($igUrl) ?>" target="_blank" rel="noopener">Seguinos en Instagram</a>
    <?php endif; ?>
  </div>
  <div class="home-videos-grid">
    <?php foreach ($homeVideos as $v): ?>
    <div class="home-video-item"><?= instagram_embed_html($v['instagram_url'], '360px') ?></div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="container" style="padding-bottom:5rem">
  <div class="trust-bar">
    <div class="trust-item"><svg><use href="#ic-truck"/></svg><div><strong>Envío a todo el país</strong><span>Despachos desde Chaco</span></div></div>
    <div class="trust-item"><svg><use href="#ic-shield"/></svg><div><strong>Varias formas de pago</strong><span>Mercado Pago, transferencia o efectivo</span></div></div>
    <div class="trust-item"><svg><use href="#ic-repeat"/></svg><div><strong>Cambios sin cargo</strong><span>Hasta 30 días</span></div></div>
    <div class="trust-item"><svg><use href="#ic-heart"/></svg><div><strong>Hecho a mano</strong><span>Piezas únicas y cuidadas</span></div></div>
  </div>
</section>

<section class="container" style="padding-bottom:5.5rem">
  <div class="newsletter">
    <div><h2>Sumate al mundo Romantiza</h2><p>Recibí novedades, lanzamientos y descuentos exclusivos antes que nadie.</p></div>
    <form class="nl-form" method="post" action="index.php#suscribite">
      <input type="email" name="newsletter_email" required placeholder="tu@email.com">
      <button class="btn btn-accent" type="submit">Suscribirme</button>
    </form>
  </div>
  <?php if ($notice): ?><p id="suscribite" style="text-align:center;margin-top:1rem;font-weight:700"><?= h($notice) ?></p><?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
