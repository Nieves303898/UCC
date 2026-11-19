<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$categories = $pdo->query('SELECT c.* FROM categories c WHERE EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.active = 1) ORDER BY c.sort_order')->fetchAll();
$catBySlug = [];
foreach ($categories as $c) { $catBySlug[$c['slug']] = $c; }

$activeCat = $_GET['categoria'] ?? '';
$activeCat = isset($catBySlug[$activeCat]) ? $activeCat : '';
$sort = $_GET['orden'] ?? 'destacados';
$q = trim($_GET['q'] ?? '');

$sql = "SELECT p.*, c.name AS cat_name, c.slug AS cat_slug,
    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.active = 1) AS variant_count,
    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.active = 1 AND (v.stock IS NULL OR v.stock > 0)) AS has_stock
    FROM products p JOIN categories c ON c.id = p.category_id WHERE p.active = 1";
$params = [];
if ($q !== '') {
    // La búsqueda encuentra productos por nombre/descripción, y también
    // por el nombre de su línea (ej: buscar "Mediterránea" muestra todos
    // los productos de esa línea), ignorando el filtro de categoría.
    $sql .= " AND (p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
} elseif ($activeCat !== '') {
    $sql .= " AND c.slug = ?"; $params[] = $activeCat;
}
if ($sort === 'menor') { $sql .= " ORDER BY p.price ASC"; }
elseif ($sort === 'mayor') { $sql .= " ORDER BY p.price DESC"; }
else { $sql .= " ORDER BY p.featured DESC, p.sort_order ASC"; }
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$pageTitle = ($q !== '' ? 'Resultados para «' . $q . '» — ' : ($activeCat ? $catBySlug[$activeCat]['name'] . ' — ' : '')) . 'Tienda — ' . setting($pdo, 'site_title', 'Romantiza');
$pageDescription = setting($pdo, 'site_description');
$activeNav = 'tienda';
require __DIR__ . '/includes/header.php';

function tienda_card_html(array $p): string {
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

<div class="container">
  <p class="crumb"><a href="index.php">Inicio</a> / Tienda</p>
  <div class="pad" style="padding-bottom:1rem">
    <h1><?= $q !== '' ? 'Resultados para «' . h($q) . '»' : 'Textiles para cada rincón' ?></h1>
  </div>

  <div class="shop-layout" style="padding-bottom:5rem">
    <aside>
      <div class="filter-block">
        <h4>Categoría</h4>
        <label class="filter-row"><a href="tienda.php?orden=<?= h($sort) ?>" style="font-weight:<?= $activeCat===''?'800':'600' ?>">Todas</a></label>
        <?php foreach ($categories as $c): ?>
        <label class="filter-row"><a href="tienda.php?categoria=<?= h($c['slug']) ?>&orden=<?= h($sort) ?>" style="font-weight:<?= $activeCat===$c['slug']?'800':'600' ?>"><?= h($c['name']) ?></a></label>
        <?php endforeach; ?>
      </div>
    </aside>

    <div>
      <div class="shop-toolbar">
        <span class="shop-count"><?= count($products) ?> producto<?= count($products)===1?'':'s' ?></span>
        <form method="get">
          <?php if ($activeCat) : ?><input type="hidden" name="categoria" value="<?= h($activeCat) ?>"><?php endif; ?>
          <?php if ($q !== '') : ?><input type="hidden" name="q" value="<?= h($q) ?>"><?php endif; ?>
          <select name="orden" onchange="this.form.submit()">
            <option value="destacados" <?= $sort==='destacados'?'selected':'' ?>>Destacados</option>
            <option value="menor" <?= $sort==='menor'?'selected':'' ?>>Precio: menor a mayor</option>
            <option value="mayor" <?= $sort==='mayor'?'selected':'' ?>>Precio: mayor a menor</option>
          </select>
        </form>
      </div>
      <?php if ($products): ?>
      <div class="product-grid"><?php foreach ($products as $p) echo tienda_card_html($p); ?></div>
      <?php elseif ($q !== ''): ?>
      <p style="color:var(--text-muted)">No encontramos productos ni líneas que coincidan con «<?= h($q) ?>». Probá con otra palabra.</p>
      <?php else: ?>
      <p style="color:var(--text-muted)">Todavía no hay productos cargados en esta categoría.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
