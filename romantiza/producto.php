<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$slug = $_GET['slug'] ?? '';
$stmt = $pdo->prepare("SELECT p.*, c.name AS cat_name, c.slug AS cat_slug FROM products p JOIN categories c ON c.id = p.category_id WHERE p.slug = ? AND p.active = 1 LIMIT 1");
$stmt->execute([$slug]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    $pageTitle = 'Producto no encontrado — Romantiza';
    $activeNav = 'tienda';
    require __DIR__ . '/includes/header.php';
    echo '<div class="container pad"><h1>No encontramos ese producto</h1><p class="lead">Puede que ya no esté disponible.</p><a class="btn btn-primary" href="tienda.php">Volver a la tienda</a></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$variants = product_variants($pdo, $product['id']);
if (!$variants) {
    // Producto sin variantes cargadas todavía: no se puede comprar.
    http_response_code(404);
    $pageTitle = 'Producto no disponible — Romantiza';
    $activeNav = 'tienda';
    require __DIR__ . '/includes/header.php';
    echo '<div class="container pad"><h1>Este producto todavía no está disponible</h1><p class="lead">Le falta cargar al menos una medida u opción. Volvé pronto.</p><a class="btn btn-primary" href="tienda.php">Volver a la tienda</a></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$related = $pdo->prepare("SELECT p.*, c.name AS cat_name, c.slug AS cat_slug,
    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.active = 1) AS variant_count
    FROM products p JOIN categories c ON c.id = p.category_id WHERE p.category_id = ? AND p.id <> ? AND p.active = 1 ORDER BY p.sort_order LIMIT 4");
$related->execute([$product['category_id'], $product['id']]);
$relatedProducts = $related->fetchAll();

$pageTitle = $product['name'] . ' — ' . money_ars($product['price']) . ' — Romantiza';
$pageDescription = $product['description'] ? mb_substr(strip_tags($product['description']), 0, 155) : setting($pdo, 'site_description');
$activeNav = 'tienda';
require __DIR__ . '/includes/header.php';

$img = product_image_url($product['image']);

// Todas las fotos del producto para la galería: primero la de
// portada, después las adicionales (panel → Productos → editar →
// "Fotos adicionales"), en el orden en que se cargaron. Si el
// producto todavía no tiene ninguna foto adicional (o la migración
// no se corrió), simplemente se ve una sola foto, como siempre.
$galleryExtra = product_gallery_images($pdo, $product['id']);
$allPhotos = [];
if ($img) { $allPhotos[] = $img; }
foreach ($galleryExtra as $gi) {
    $gUrl = product_image_url($gi['image']);
    if ($gUrl) { $allPhotos[] = $gUrl; }
}
?>

<div class="container">
  <p class="crumb"><a href="index.php">Inicio</a> / <a href="tienda.php">Tienda</a> / <a href="tienda.php?categoria=<?= h($product['cat_slug']) ?>"><?= h($product['cat_name']) ?></a> / <?= h($product['name']) ?></p>
  <div class="product-detail pad">
    <div class="gallery">
      <div class="gallery-main">
        <div class="ph" id="galleryMain">
          <?php if ($allPhotos): ?>
            <img src="<?= h($allPhotos[0]) ?>" alt="<?= h($product['name']) ?>" id="galleryMainImg">
          <?php else: ?>
            <svg class="motif" style="width:40%"><use href="#mot-bloom"/></svg><span class="ph-tag">Foto pendiente</span>
          <?php endif; ?>
        </div>
      </div>
      <?php if (count($allPhotos) > 1): ?>
      <div class="gallery-thumbs" id="galleryThumbs">
        <?php foreach ($allPhotos as $i => $photoUrl): ?>
        <div class="ph<?= $i===0?' active':'' ?>" role="button" tabindex="0" data-full="<?= h($photoUrl) ?>" aria-label="Ver foto <?= $i+1 ?> de <?= (int)count($allPhotos) ?>">
          <img src="<?= h($photoUrl) ?>" alt="">
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <div>
      <h1 class="pd-title"><?= h($product['name']) ?></h1>
      <p class="pd-price"><?= count($variants) > 1 ? 'Desde ' : '' ?><?= money_ars($product['price']) ?></p>
      <?php if ($product['description']): ?>
      <p class="pd-desc"><?= nl2br(h($product['description'])) ?></p>
      <?php endif; ?>

      <?php
      $anyInStock = false;
      $firstInStockId = null;
      foreach ($variants as $v) {
          if (variant_in_stock($v)) { $anyInStock = true; if ($firstInStockId === null) $firstInStockId = (int)$v['id']; }
      }
      ?>
      <?php if (!$anyInStock): ?>
      <div class="pd-out-of-stock">
        <p><strong>Sin stock por el momento.</strong> Escribinos y te avisamos apenas repongamos.</p>
        <?php $waNumberPd = setting($pdo, 'contact_whatsapp', ''); if ($waNumberPd): ?>
        <a class="btn btn-ghost btn-block" href="https://wa.me/<?= h($waNumberPd) ?>?text=<?= rawurlencode('Hola! Quería consultar por «' . $product['name'] . '», que veo sin stock.') ?>" target="_blank" rel="noopener">Avisenme cuando haya stock</a>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <form method="post" action="carrito.php" class="pd-ctas">
        <?php if (count($variants) > 1): ?>
        <div class="pd-field">
          <label for="pd-variant">Medida / opción</label>
          <select id="pd-variant" name="variant_id">
            <?php foreach ($variants as $v): $vInStock = variant_in_stock($v); ?>
            <option value="<?= (int)$v['id'] ?>" <?= !$vInStock?'disabled':'' ?> <?= (int)$v['id']===$firstInStockId?'selected':'' ?>><?= h($v['label']) ?> — <?= money_ars($v['price']) ?><?= !$vInStock?' (Sin stock)':'' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
        <input type="hidden" name="variant_id" value="<?= (int)$variants[0]['id'] ?>">
        <p class="pd-field-static"><?= h($variants[0]['label']) ?></p>
        <?php endif; ?>
        <label class="pd-qty" for="pd-qty">
          <span>Cantidad</span>
          <input id="pd-qty" name="qty" type="number" inputmode="numeric" min="1" max="99" value="1">
        </label>
        <div class="pd-cta-buttons">
          <button class="btn btn-ghost btn-block" type="submit" name="action" value="add">Agregar al carrito</button>
          <button class="btn btn-primary btn-block" type="submit" name="action" value="buy_now">Comprar ahora</button>
        </div>
      </form>
      <?php endif; ?>

      <?= trust_badges_html($pdo) ?>

      <div id="accordion">
        <div class="accordion-item open">
          <button class="accordion-btn" type="button">Composición y cuidado<svg style="width:11px;height:11px"><use href="#ic-arrow"/></svg></button>
          <div class="accordion-panel" style="display:block">100% algodón o lino natural, según la pieza. Lavar a mano o en ciclo suave con agua fría. No usar lavía. Planchar del revés a temperatura media.</div>
        </div>
        <div class="accordion-item">
          <button class="accordion-btn" type="button">Envíos y cambios<svg style="width:11px;height:11px"><use href="#ic-arrow"/></svg></button>
          <div class="accordion-panel">Despachamos a todo el país. Coordinamos el envío por WhatsApp una vez confirmado el pedido. Cambios sin cargo dentro de los 30 días de recibido el producto.</div>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($product['instagram_video_url'])): ?>
  <section style="padding-bottom:5.5rem">
    <div class="section-head"><div><h2>Este producto en Instagram</h2></div></div>
    <?= instagram_embed_html($product['instagram_video_url']) ?>
  </section>
  <?php endif; ?>

  <?php if ($relatedProducts): ?>
  <section style="padding-bottom:5.5rem">
    <div class="section-head"><div><h2>También te puede interesar</h2></div></div>
    <div class="product-grid">
      <?php foreach ($relatedProducts as $p):
        $rimg = product_image_url($p['image']);
        $flagHtml = $p['flag'] ? '<span class="card-flag ' . ($p['flag']==='Nuevo'?'nuevo':'') . '">' . h($p['flag']) . '</span>' : '';
      ?>
      <a class="card" href="producto.php?slug=<?= h($p['slug']) ?>">
        <div class="card-media"><?= $flagHtml ?>
          <div class="ph">
            <?php if ($rimg): ?><img src="<?= h($rimg) ?>" alt="<?= h($p['name']) ?>" loading="lazy">
            <?php else: ?><svg class="motif" style="width:40%"><use href="#mot-bloom"/></svg><span class="ph-tag">Foto pendiente</span><?php endif; ?>
          </div>
        </div>
        <div class="card-body">
          <p class="card-cat"><?= h($p['cat_name']) ?></p>
          <h3 class="card-title"><?= h($p['name']) ?></h3>
          <p class="card-price"><?= card_price_label($p['price'], (int)$p['variant_count']) ?></p>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
