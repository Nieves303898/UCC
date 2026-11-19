<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/admin_ui.php';

// Productos es una sección "editable": tanto el superadmin como los
// editores pueden ver y modificar el catálogo.
$admin = require_login($pdo);

$flash = null;
$flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $flash = 'La sesión del formulario expiró. Intentá de nuevo.';
        $flashType = 'error';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $product = $stmt->fetch();

        if (!$product) {
            $flash = 'No se encontró ese producto.';
            $flashType = 'error';
        } elseif ($action === 'toggle_active') {
            $pdo->prepare('UPDATE products SET active = ? WHERE id = ?')->execute([$product['active'] ? 0 : 1, $id]);
            $flash = 'Se actualizó la visibilidad del producto.';
        } elseif ($action === 'toggle_featured') {
            $pdo->prepare('UPDATE products SET featured = ? WHERE id = ?')->execute([$product['featured'] ? 0 : 1, $id]);
            $flash = 'Se actualizó el producto destacado.';
        } elseif ($action === 'delete') {
            // Antes de borrar el producto, guardamos qué fotos de la
            // galería tenía (si la sección de fotos ya está migrada) para
            // borrar también esos archivos del servidor. Las filas de
            // product_images se borran solas al borrar el producto
            // (ON DELETE CASCADE).
            $galleryFilesToDelete = [];
            try {
                $giStmt = $pdo->prepare('SELECT image FROM product_images WHERE product_id = ?');
                $giStmt->execute([$id]);
                $galleryFilesToDelete = $giStmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (PDOException $e) {
                // Todavía no existe la tabla de galería: no hay nada que limpiar.
            }

            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
            if ($product['image']) {
                $path = __DIR__ . '/../uploads/productos/' . $product['image'];
                if (is_file($path)) { @unlink($path); }
            }
            foreach ($galleryFilesToDelete as $galleryFile) {
                $path = __DIR__ . '/../uploads/productos/' . $galleryFile;
                if (is_file($path)) { @unlink($path); }
            }
            $flash = 'Producto eliminado.';
        }
    }
}

$categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order')->fetchAll();
$catFilter = $_GET['categoria'] ?? '';
$sql = 'SELECT p.*, c.name AS cat_name,
    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.active = 1) AS variant_count,
    (SELECT SUM(v.stock) FROM product_variants v WHERE v.product_id = p.id AND v.active = 1 AND v.stock IS NOT NULL) AS stock_sum,
    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.active = 1 AND v.stock IS NULL) AS stock_unlimited_count
    FROM products p JOIN categories c ON c.id = p.category_id';
$params = [];
if ($catFilter !== '') { $sql .= ' WHERE p.category_id = ?'; $params[] = (int)$catFilter; }
$sql .= ' ORDER BY p.updated_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

admin_open($admin, 'productos', 'Productos', $flash, $flashType);
?>

<div class="admin-actions-bar">
  <form method="get" style="display:flex;gap:.6rem;align-items:center">
    <select name="categoria" onchange="this.form.submit()" style="border:1.5px solid var(--border);padding:.55rem .8rem;border-radius:2px 8px 2px 8px;font-family:inherit">
      <option value="">Todas las categorías</option>
      <?php
      $catFilterLineas = array_filter($categories, fn($c) => (int)($c['is_linea'] ?? 0) === 1);
      $catFilterComunes = array_filter($categories, fn($c) => (int)($c['is_linea'] ?? 0) !== 1);
      ?>
      <?php if ($catFilterLineas): ?>
      <optgroup label="Líneas">
        <?php foreach ($catFilterLineas as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (string)$catFilter===(string)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </optgroup>
      <?php endif; ?>
      <?php if ($catFilterComunes): ?>
      <optgroup label="Categorías">
        <?php foreach ($catFilterComunes as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (string)$catFilter===(string)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </optgroup>
      <?php endif; ?>
    </select>
  </form>
  <a class="abtn abtn-primary" href="producto_form.php"><?= admin_icon('plus') ?> Cargar producto</a>
</div>

<?php if (!$products): ?>
  <div class="admin-card"><p style="color:var(--text-muted)">No hay productos <?= $catFilter?'en esta categoría':'cargados todavía' ?>.</p></div>
<?php else: ?>
<div class="admin-table-wrap">
  <table class="admin-table">
    <thead>
      <tr><th></th><th>Producto</th><th>Categoría</th><th>Precio</th><th>Stock</th><th>Destacado</th><th>Estado</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($products as $p): $img = product_image_url($p['image']); ?>
      <tr>
        <td>
          <?php if ($img): ?><img class="row-thumb" src="../<?= h($img) ?>" alt="">
          <?php else: ?><div class="row-thumb-ph">Sin foto</div><?php endif; ?>
        </td>
        <td><strong><?= h($p['name']) ?></strong><?php if ($p['flag']): ?><br><span class="badge badge-on" style="margin-top:.3rem"><?= h($p['flag']) ?></span><?php endif; ?></td>
        <td><?= h($p['cat_name']) ?></td>
        <td><?= card_price_label($p['price'], (int)$p['variant_count']) ?><?php if ((int)$p['variant_count'] === 0): ?><br><span class="badge" style="background:#F6DCD6;color:#A83B26">Sin medidas cargadas</span><?php endif; ?></td>
        <td>
          <?php
            $stockSum = $p['stock_sum'] !== null ? (int)$p['stock_sum'] : 0;
            $unlimitedCount = (int)$p['stock_unlimited_count'];
            if ((int)$p['variant_count'] === 0) { echo '—'; }
            elseif ($unlimitedCount > 0 && $p['stock_sum'] === null) { echo 'Ilimitado'; }
            elseif ($unlimitedCount > 0) { echo (int)$stockSum . ' + ilimitado'; }
            elseif ($stockSum <= 0) { echo '<span class="badge" style="background:#F6DCD6;color:#A83B26">Sin stock</span>'; }
            else { echo (int)$stockSum . ' unidades'; }
          ?>
        </td>
        <td>
          <form method="post" action="productos.php">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="action" value="toggle_featured">
            <button type="submit" class="badge <?= $p['featured']?'badge-on':'badge-off' ?>" style="border:0;cursor:pointer"><?= $p['featured']?'Sí':'No' ?></button>
          </form>
        </td>
        <td>
          <form method="post" action="productos.php">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="action" value="toggle_active">
            <button type="submit" class="badge <?= $p['active']?'badge-on':'badge-off' ?>" style="border:0;cursor:pointer"><?= $p['active']?'Visible':'Oculto' ?></button>
          </form>
        </td>
        <td class="actions">
          <a class="abtn abtn-ghost abtn-icon" href="producto_form.php?id=<?= (int)$p['id'] ?>" aria-label="Editar"><?= admin_icon('edit') ?></a>
          <form method="post" action="productos.php" data-confirm="¿Eliminar «<?= h($p['name']) ?>»? Esta acción no se puede deshacer.">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="action" value="delete">
            <button type="submit" class="abtn abtn-danger abtn-icon" aria-label="Eliminar"><?= admin_icon('trash') ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php admin_close(); ?>
