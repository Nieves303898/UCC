<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/admin_ui.php';

$admin = require_login($pdo);

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$product = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $product = $stmt->fetch();
    if (!$product) { header('Location: productos.php'); exit; }
}

$categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order')->fetchAll();
$errors = [];
$values = $product ?: [
    'name' => '', 'category_id' => $categories[0]['id'] ?? '', 'description' => '',
    'flag' => '', 'featured' => 0, 'active' => 1, 'image' => null, 'instagram_video_url' => '',
];

// Galería de fotos (además de la foto de portada): se maneja con sus
// propios botones (Agregar fotos / Usar como portada / Eliminar), cada
// uno en su mini-formulario aparte, para que actúen al toque sin tocar
// el resto del producto. Por eso el guard "!isset($_POST['gallery_action'])"
// de acá abajo: para que un POST de la galería no dispare también el
// guardado del formulario grande de nombre/categoría/variantes.
$galleryFlash = null;
$galleryFlashType = 'ok';
$galleryMigrationNeeded = false;

if ($id && $product && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gallery_action'])) {
    if (!csrf_check()) {
        $galleryFlash = 'La sesión del formulario expiró. Recargá la página e intentá de nuevo.';
        $galleryFlashType = 'error';
    } else {
        try {
            $galleryAction = $_POST['gallery_action'];

            if ($galleryAction === 'add') {
                $uploadErrors = [];
                $addedCount = 0;
                // Guardamos, por posición del archivo elegido, el id y el
                // nombre de archivo ya insertado en la galería: así, si
                // marcó alguna como portada en la vista previa, la
                // podemos promover apenas termina de subir todo.
                $insertedByIndex = [];
                $files = $_FILES['gallery_images'] ?? null;
                if ($files && is_array($files['name'] ?? null)) {
                    $maxOrderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM product_images WHERE product_id = ?');
                    $maxOrderStmt->execute([$id]);
                    $nextOrder = (int)$maxOrderStmt->fetchColumn() + 1;
                    $insertStmt = $pdo->prepare('INSERT INTO product_images (product_id, image, sort_order) VALUES (?, ?, ?)');
                    for ($i = 0; $i < count($files['name']); $i++) {
                        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
                        $singleFile = [
                            'name' => $files['name'][$i], 'type' => $files['type'][$i],
                            'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i],
                        ];
                        try {
                            $saved = handle_product_image_upload($singleFile);
                            if ($saved) {
                                $insertStmt->execute([$id, $saved, $nextOrder]);
                                $insertedByIndex[$i] = ['id' => (int)$pdo->lastInsertId(), 'image' => $saved];
                                $nextOrder++;
                                $addedCount++;
                            }
                        } catch (RuntimeException $e) {
                            $uploadErrors[] = $e->getMessage();
                        }
                    }
                }

                // ¿Eligió portada entre las fotos recién agregadas (desde
                // la vista previa, antes de apretar "Agregar fotos")? La
                // promovemos ahora, con el mismo mecanismo de "no se
                // pierde nada" que usa el botón "Usar como portada".
                $coverPromoted = false;
                $coverIndexRaw = $_POST['new_cover_index'] ?? '';
                if ($coverIndexRaw !== '' && isset($insertedByIndex[(int)$coverIndexRaw])) {
                    $chosen = $insertedByIndex[(int)$coverIndexRaw];
                    $oldCover = (string)($product['image'] ?? '');
                    promote_gallery_image_to_cover($pdo, $id, $oldCover, $chosen['id'], $chosen['image']);
                    $product['image'] = $chosen['image'];
                    $values['image'] = $chosen['image'];
                    $coverPromoted = true;
                }

                if ($uploadErrors) {
                    $galleryFlash = ($addedCount ? $addedCount . ' foto' . ($addedCount === 1 ? '' : 's') . ' agregada' . ($addedCount === 1 ? '' : 's') . '. ' : '') . implode(' ', $uploadErrors);
                    $galleryFlashType = 'error';
                } elseif ($addedCount) {
                    $galleryFlash = ($addedCount === 1 ? 'Foto agregada.' : $addedCount . ' fotos agregadas.') . ($coverPromoted ? ' Se usó como portada.' : '');
                } else {
                    $galleryFlash = 'No se eligió ninguna foto.';
                    $galleryFlashType = 'error';
                }
            } elseif ($galleryAction === 'set_cover') {
                $imgId = (int)($_POST['image_id'] ?? 0);
                $imgStmt = $pdo->prepare('SELECT * FROM product_images WHERE id = ? AND product_id = ?');
                $imgStmt->execute([$imgId, $id]);
                $galleryImg = $imgStmt->fetch();
                if ($galleryImg) {
                    $oldCover = (string)($product['image'] ?? '');
                    promote_gallery_image_to_cover($pdo, $id, $oldCover, $imgId, $galleryImg['image']);
                    // $values ya se había armado a partir de $product más arriba, antes
                    // de este cambio: actualizamos los dos para que la foto de portada
                    // se vea correcta más abajo, en esta misma carga de la página.
                    $product['image'] = $galleryImg['image'];
                    $values['image'] = $galleryImg['image'];
                    $galleryFlash = 'Listo, esa foto ahora es la portada del producto.';
                } else {
                    $galleryFlash = 'No se encontró esa foto.';
                    $galleryFlashType = 'error';
                }
            } elseif ($galleryAction === 'delete') {
                $imgId = (int)($_POST['image_id'] ?? 0);
                $imgStmt = $pdo->prepare('SELECT * FROM product_images WHERE id = ? AND product_id = ?');
                $imgStmt->execute([$imgId, $id]);
                $galleryImg = $imgStmt->fetch();
                if ($galleryImg) {
                    $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imgId]);
                    $file = __DIR__ . '/../uploads/productos/' . $galleryImg['image'];
                    if (is_file($file)) { @unlink($file); }
                    $galleryFlash = 'Foto eliminada.';
                } else {
                    $galleryFlash = 'No se encontró esa foto.';
                    $galleryFlashType = 'error';
                }
            }
        } catch (PDOException $e) {
            $galleryMigrationNeeded = true;
            $galleryFlash = 'Todavía no se puede usar la galería de fotos en este sitio: falta importar migracion_galeria_fotos.sql en la base de datos. El resto del producto funciona igual mientras tanto.';
            $galleryFlashType = 'error';
        }
    }
}

// Fotos de la galería del producto (aparte de la portada), para
// mostrarlas en el formulario. Si la migración todavía no se corrió,
// no rompemos la página: mostramos un aviso y ocultamos esta sección.
$galleryImages = [];
if ($id && $product && !$galleryMigrationNeeded) {
    try {
        $giStmt = $pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
        $giStmt->execute([$id]);
        $galleryImages = $giStmt->fetchAll();
    } catch (PDOException $e) {
        $galleryMigrationNeeded = true;
    }
}

// Variantes: en GET, las del producto (si es edición); en POST, lo que llegó del formulario.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['gallery_action'])) {
    $variantRowsIn = [];
    $vIds = $_POST['variant_id'] ?? [];
    $vLabels = $_POST['variant_label'] ?? [];
    $vPrices = $_POST['variant_price'] ?? [];
    $vStocks = $_POST['variant_stock'] ?? [];
    foreach ($vLabels as $i => $label) {
        $label = trim((string)$label);
        $price = str_replace(',', '.', trim((string)($vPrices[$i] ?? '')));
        $stock = trim((string)($vStocks[$i] ?? ''));
        if ($label === '' && $price === '' && $stock === '') continue; // fila vacía: se ignora
        $variantRowsIn[] = ['id' => (int)($vIds[$i] ?? 0), 'label' => $label, 'price' => $price, 'stock' => $stock];
    }
} else {
    $variantRowsIn = $product
        ? array_map(fn($v) => ['id' => (int)$v['id'], 'label' => $v['label'], 'price' => (string)$v['price'], 'stock' => $v['stock'] === null ? '' : (string)$v['stock']], product_variants_all($pdo, $product['id']))
        : [['id' => 0, 'label' => '', 'price' => '', 'stock' => '']];
    if (!$variantRowsIn) { $variantRowsIn = [['id' => 0, 'label' => '', 'price' => '', 'stock' => '']]; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['gallery_action'])) {
    if (!csrf_check()) {
        $errors[] = 'La sesión del formulario expiró. Recargá la página e intentá de nuevo.';
    } else {
        $values['name'] = trim($_POST['name'] ?? '');
        $values['category_id'] = (int)($_POST['category_id'] ?? 0);
        $values['description'] = trim($_POST['description'] ?? '');
        $values['flag'] = trim($_POST['flag'] ?? '');
        $values['featured'] = isset($_POST['featured']) ? 1 : 0;
        $values['active'] = isset($_POST['active']) ? 1 : 0;
        $values['instagram_video_url'] = trim($_POST['instagram_video_url'] ?? '');

        if ($values['name'] === '') { $errors[] = 'Ingresá el nombre del producto.'; }
        if (!$values['category_id']) { $errors[] = 'Elegí una categoría.'; }
        if ($values['instagram_video_url'] !== '' && (!filter_var($values['instagram_video_url'], FILTER_VALIDATE_URL) || stripos($values['instagram_video_url'], 'instagram.com') === false)) {
            $errors[] = 'El link de Instagram no parece válido (tiene que ser una URL de instagram.com).';
        }

        foreach ($variantRowsIn as $row) {
            if ($row['label'] === '') { $errors[] = 'Cada variante necesita una medida u opción (ej: "1 plaza", "Chico").'; break; }
        }
        foreach ($variantRowsIn as $row) {
            if ($row['price'] === '' || !is_numeric($row['price']) || (float)$row['price'] < 0) {
                $errors[] = 'Ingresá un precio válido para cada variante.'; break;
            }
        }
        foreach ($variantRowsIn as $row) {
            if ($row['stock'] !== '' && (!ctype_digit($row['stock']) || (int)$row['stock'] < 0)) {
                $errors[] = 'El stock tiene que ser un número entero de 0 o más (dejalo vacío para no controlar el stock).'; break;
            }
        }
        if (!$variantRowsIn) { $errors[] = 'Agregá al menos una medida u opción con su precio.'; }

        $newImage = null;
        if (!$errors) {
            try {
                $newImage = handle_product_image_upload($_FILES['image'] ?? []);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (!$errors) {
            $slug = unique_product_slug($pdo, $values['name'], $product['id'] ?? null);
            $imageToSave = $newImage ?: ($product['image'] ?? null);

            if ($product) {
                $stmt = $pdo->prepare('UPDATE products SET category_id=?, name=?, slug=?, description=?, image=?, instagram_video_url=?, flag=?, featured=?, active=? WHERE id=?');
                $stmt->execute([
                    $values['category_id'], $values['name'], $slug, $values['description'],
                    $imageToSave, $values['instagram_video_url'] ?: null, $values['flag'] ?: null,
                    $values['featured'], $values['active'], $product['id'],
                ]);
                if ($newImage && !empty($product['image'])) {
                    $old = __DIR__ . '/../uploads/productos/' . $product['image'];
                    if (is_file($old)) { @unlink($old); }
                }
                $productId = (int)$product['id'];
            } else {
                $stmt = $pdo->prepare('INSERT INTO products (category_id, name, slug, price, description, image, instagram_video_url, flag, featured, active) VALUES (?,?,?,0,?,?,?,?,?,?)');
                $stmt->execute([
                    $values['category_id'], $values['name'], $slug, $values['description'],
                    $imageToSave, $values['instagram_video_url'] ?: null, $values['flag'] ?: null,
                    $values['featured'], $values['active'],
                ]);
                $productId = (int)$pdo->lastInsertId();
            }

            // Guarda las variantes: actualiza las existentes, borra las que ya no vienen, inserta las nuevas.
            $existingIds = $product ? array_column(product_variants_all($pdo, $productId), 'id') : [];
            $sentIds = [];
            foreach ($variantRowsIn as $i => $row) {
                $stockToSave = $row['stock'] === '' ? null : (int)$row['stock'];
                if ($row['id'] > 0) {
                    $sentIds[] = $row['id'];
                    $pdo->prepare('UPDATE product_variants SET label=?, price=?, stock=?, sort_order=? WHERE id=? AND product_id=?')
                        ->execute([$row['label'], $row['price'], $stockToSave, $i, $row['id'], $productId]);
                } else {
                    $pdo->prepare('INSERT INTO product_variants (product_id, label, price, stock, sort_order, active) VALUES (?,?,?,?,?,1)')
                        ->execute([$productId, $row['label'], $row['price'], $stockToSave, $i]);
                }
            }
            $toDelete = array_diff($existingIds, $sentIds);
            if ($toDelete) {
                $ph = implode(',', array_fill(0, count($toDelete), '?'));
                $pdo->prepare("DELETE FROM product_variants WHERE id IN ($ph)")->execute(array_values($toDelete));
            }
            recompute_product_price($pdo, $productId);

            header('Location: productos.php?guardado=1');
            exit;
        }
    }
}

admin_open($admin, 'productos', $product ? 'Editar producto' : 'Cargar producto');
?>

<a href="productos.php" style="font-size:.85rem;color:var(--text-muted);display:inline-block;margin-bottom:1.2rem">&larr; Volver a productos</a>

<?php if ($errors): ?>
<div class="admin-flash admin-flash-error"><?php foreach ($errors as $e) echo h($e) . '<br>'; ?></div>
<?php endif; ?>

<div class="admin-card" style="max-width:760px">
  <form method="post" action="producto_form.php<?= $product ? '?id='.(int)$product['id'] : '' ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <?php if ($product): ?><input type="hidden" name="id" value="<?= (int)$product['id'] ?>"><?php endif; ?>

    <div class="aform-field">
      <label for="p-name">Nombre del producto</label>
      <input id="p-name" name="name" type="text" required value="<?= h($values['name']) ?>">
    </div>

    <div class="aform-field">
      <label for="p-cat">Categoría (línea)</label>
      <select id="p-cat" name="category_id" required>
        <?php
        $catLineas = array_filter($categories, fn($c) => (int)($c['is_linea'] ?? 0) === 1);
        $catComunes = array_filter($categories, fn($c) => (int)($c['is_linea'] ?? 0) !== 1);
        ?>
        <?php if ($catLineas): ?>
        <optgroup label="Líneas">
          <?php foreach ($catLineas as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)$values['category_id']===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <?php endif; ?>
        <?php if ($catComunes): ?>
        <optgroup label="Categorías">
          <?php foreach ($catComunes as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)$values['category_id']===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <?php endif; ?>
      </select>
      <p class="aform-hint">Se administra desde el panel, <a href="lineas.php">Líneas y categorías</a>.</p>
    </div>

    <div class="aform-field">
      <label for="p-desc">Descripción</label>
      <textarea id="p-desc" name="description" rows="4"><?= h($values['description']) ?></textarea>
    </div>

    <div class="aform-field">
      <label>Medidas / opciones, precios y stock</label>
      <p class="aform-hint" style="margin-top:0;margin-bottom:.7rem">Cargá cada medida u opción con su precio (ej: "1 plaza" $18.900, "2 plazas" $23.500). Si tiene una sola opción, dejá una sola fila. En la tienda se va a mostrar el precio "Desde" el más económico si hay más de una. El campo <strong>Stock</strong> es opcional: dejalo vacío si no querés controlar el stock de esa medida (se vende sin límite, como siempre); si cargás un número, se va a descontar solo con cada venta y en 0 se muestra "Sin stock".</p>
      <div id="variant-rows">
        <?php foreach ($variantRowsIn as $v): ?>
        <div class="variant-row">
          <input type="hidden" name="variant_id[]" value="<?= (int)$v['id'] ?>">
          <input type="text" name="variant_label[]" placeholder="Medida / opción (ej: 1 plaza)" value="<?= h($v['label']) ?>">
          <input type="text" name="variant_price[]" inputmode="decimal" placeholder="Precio ARS" value="<?= h((string)$v['price']) ?>">
          <input type="text" name="variant_stock[]" inputmode="numeric" placeholder="Stock (vacío = ilimitado)" value="<?= h((string)$v['stock']) ?>">
          <button type="button" class="abtn abtn-ghost abtn-icon variant-remove" aria-label="Quitar variante"><?= admin_icon('trash') ?></button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" id="variant-add" class="abtn abtn-ghost abtn-sm" style="margin-top:.6rem"><?= admin_icon('plus') ?> Agregar medida / opción</button>
      <template id="variant-row-tpl">
        <div class="variant-row">
          <input type="hidden" name="variant_id[]" value="0">
          <input type="text" name="variant_label[]" placeholder="Medida / opción (ej: 1 plaza)">
          <input type="text" name="variant_price[]" inputmode="decimal" placeholder="Precio ARS">
          <input type="text" name="variant_stock[]" inputmode="numeric" placeholder="Stock (vacío = ilimitado)">
          <button type="button" class="abtn abtn-ghost abtn-icon variant-remove" aria-label="Quitar variante"><?= admin_icon('trash') ?></button>
        </div>
      </template>
    </div>

    <div class="aform-field">
      <label>Foto de portada</label>
      <?php if (!empty($values['image'])): $curImg = product_image_url($values['image']); ?>
      <div class="current-thumb">
        <img src="../<?= h($curImg) ?>" alt="">
        <span class="aform-hint" style="margin:0">Foto actual. Subí una nueva para reemplazarla.</span>
      </div>
      <?php endif; ?>
      <input id="p-image" type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
      <div id="p-image-preview" class="gallery-preview-grid"></div>
      <p class="aform-hint">Es la foto principal: la que se ve en la tienda y arriba de todo en la ficha del producto. Formato: JPG, PNG o WEBP. Elegí la foto tal cual sale de tu celular o cámara: el panel la optimiza solo antes de subirla, así casi nunca vas a ver un error de peso.<br>
      Tamaño recomendado: foto cuadrada o vertical (proporción 4:5), mínimo 1000×1000&nbsp;px. Usá fondo claro y luz natural, como en las fotos que ya subiste.
      <?php if (!$product): ?><br><strong>¿Necesitás cargar varias fotos de este producto?</strong> Guardalo primero con esta foto y volvé a editarlo: ahí vas a poder agregar el resto y elegir cuál usar como portada.<?php endif; ?></p>
    </div>

    <div class="aform-field">
      <label for="p-ig">Video de Instagram (opcional)</label>
      <input id="p-ig" name="instagram_video_url" type="text" placeholder="https://www.instagram.com/reel/..." value="<?= h((string)$values['instagram_video_url']) ?>">
      <p class="aform-hint">Pegá el link de un reel o publicación de Instagram donde se vea este producto. Se muestra embebido en la ficha del producto — no hace falta subir el video.</p>
    </div>

    <div class="aform-field">
      <label for="p-flag">Etiqueta (opcional)</label>
      <input id="p-flag" name="flag" type="text" value="<?= h((string)$values['flag']) ?>" placeholder="ej: Nuevo">
    </div>

    <div class="aform-row">
      <label class="aform-check"><input type="checkbox" name="featured" <?= $values['featured']?'checked':'' ?>> Mostrar en destacados de la portada</label>
      <label class="aform-check"><input type="checkbox" name="active" <?= $values['active']?'checked':'' ?>> Visible en el sitio</label>
    </div>

    <div class="aform-actions">
      <button class="abtn abtn-primary" type="submit"><?= admin_icon('check') ?> Guardar producto</button>
      <a class="abtn abtn-ghost" href="productos.php">Cancelar</a>
    </div>
  </form>
</div>

<?php if ($product): ?>
<div class="admin-card" style="max-width:760px">
  <h2>Fotos adicionales (galería)</h2>

  <?php if ($galleryFlash): ?>
  <div class="admin-flash admin-flash-<?= h($galleryFlashType) ?>"><?= h($galleryFlash) ?></div>
  <?php endif; ?>

  <?php if ($galleryMigrationNeeded): ?>
  <p class="aform-hint" style="margin-top:0">Esta sección todavía no está disponible en este sitio: falta importar <code>migracion_galeria_fotos.sql</code> en la base de datos. El resto del producto funciona igual mientras tanto.</p>
  <?php else: ?>

  <p class="aform-hint" style="margin-top:0">Estas fotos se muestran junto a la de portada en la ficha del producto, para que el cliente pueda pasar de una a otra. No hace falta que sean todas del mismo tamaño o forma: la tienda las acomoda sola para que se vean parejas.</p>

  <?php if ($galleryImages): ?>
  <div class="gallery-manage-grid">
    <?php foreach ($galleryImages as $gi): $giUrl = product_image_url($gi['image']); ?>
    <div class="gallery-manage-item">
      <img src="../<?= h($giUrl) ?>" alt="">
      <div class="gallery-manage-actions">
        <form method="post" action="producto_form.php?id=<?= (int)$id ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="gallery_action" value="set_cover">
          <input type="hidden" name="image_id" value="<?= (int)$gi['id'] ?>">
          <button type="submit" class="abtn abtn-ghost abtn-sm">Usar como portada</button>
        </form>
        <form method="post" action="producto_form.php?id=<?= (int)$id ?>" onsubmit="return confirm('¿Eliminar esta foto?');">
          <?= csrf_field() ?>
          <input type="hidden" name="gallery_action" value="delete">
          <input type="hidden" name="image_id" value="<?= (int)$gi['id'] ?>">
          <button type="submit" class="abtn abtn-danger abtn-sm"><?= admin_icon('trash') ?> Eliminar</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="post" action="producto_form.php?id=<?= (int)$id ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="gallery_action" value="add">
    <input type="hidden" name="new_cover_index" id="p-gallery-cover-index" value="">
    <div class="aform-field">
      <label for="p-gallery">Agregar fotos</label>
      <input id="p-gallery" name="gallery_images[]" type="file" accept=".jpg,.jpeg,.png,.webp" multiple>
      <div id="p-gallery-preview" class="gallery-preview-grid"></div>
      <p class="aform-hint">Podés elegir todas las fotos juntas, de una sola vez (tocá una foto, y con Ctrl o Shift apretado tocá las demás). Al elegirlas vas a ver una vista previa acá arriba — ahí mismo podés marcar cuál usar como portada, antes de apretar "Agregar fotos". JPG, PNG o WEBP: se optimizan solas antes de subirse, así casi nunca vas a ver un error de peso.</p>
    </div>
    <button type="submit" class="abtn abtn-primary abtn-sm"><?= admin_icon('plus') ?> Agregar fotos</button>
  </form>

  <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function(){
  var rows = document.getElementById('variant-rows');
  var addBtn = document.getElementById('variant-add');
  if (!rows || !addBtn) return;

  function bindRemove(row){
    var btn = row.querySelector('.variant-remove');
    if (!btn) return;
    btn.addEventListener('click', function(){
      if (rows.querySelectorAll('.variant-row').length > 1) {
        row.remove();
      } else {
        // Es la última fila: solo la vacía en lugar de borrarla.
        row.querySelectorAll('input[type=text]').forEach(function(i){ i.value = ''; });
        row.querySelector('input[type=hidden]').value = '0';
      }
    });
  }
  rows.querySelectorAll('.variant-row').forEach(bindRemove);

  var tpl = document.getElementById('variant-row-tpl');
  addBtn.addEventListener('click', function(){
    if (!tpl) return;
    var row = tpl.content.firstElementChild.cloneNode(true);
    rows.appendChild(row);
    bindRemove(row);
    row.querySelector('input[type=text]').focus();
  });
})();
</script>

<script>
(function(){
  "use strict";

  // Achica una foto en el navegador antes de subirla (lado más largo a
  // hasta 1800px, comprimida), para que las fotos de celular (que suelen
  // pesar varios MB) casi nunca choquen con el límite del servidor. Si la
  // foto ya es chica, o si el navegador no puede procesarla, se sube tal
  // cual llegó, sin romper nada.
  function resizeImageFile(file, maxDim, quality) {
    return new Promise(function (resolve) {
      try {
        if (!file || !file.type || file.type.indexOf('image/') !== 0) { resolve(file); return; }
        if (file.size <= 900 * 1024) { resolve(file); return; } // ya es chica: no hace falta tocarla
        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function () {
          try {
            var w = img.naturalWidth, h = img.naturalHeight;
            var scale = Math.min(1, maxDim / Math.max(w, h));
            var canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(w * scale));
            canvas.height = Math.max(1, Math.round(h * scale));
            var ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            var outType = (file.type === 'image/png') ? 'image/png' : 'image/jpeg';
            canvas.toBlob(function (blob) {
              URL.revokeObjectURL(url);
              if (!blob || blob.size >= file.size) { resolve(file); return; }
              var newName = file.name.replace(/\.(png|jpe?g|webp)$/i, '') + (outType === 'image/png' ? '.png' : '.jpg');
              try {
                resolve(new File([blob], newName, { type: outType, lastModified: Date.now() }));
              } catch (e) {
                resolve(file);
              }
            }, outType, quality);
          } catch (e) {
            URL.revokeObjectURL(url);
            resolve(file);
          }
        };
        img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
        img.src = url;
      } catch (e) {
        resolve(file);
      }
    });
  }

  // Conecta un <input type=file> con su caja de vista previa: al elegir
  // fotos, las optimiza, las reemplaza en el propio input (así lo que se
  // sube ya viene liviano) y muestra una miniatura de cada una. Si
  // allowCoverPick está activo, cada miniatura tiene una opción "Usar
  // como portada" que carga el índice elegido en coverIndexInput.
  function wireImagePicker(input, previewBox, opts) {
    if (!input || !previewBox || typeof DataTransfer === 'undefined') { return; }
    opts = opts || {};
    var form = input.closest('form');
    var submitBtn = form ? form.querySelector('button[type=submit]') : null;
    var coverIndexInput = opts.coverIndexInput || null;
    var processing = false;

    input.addEventListener('change', function () {
      var files = Array.prototype.slice.call(input.files || []);
      previewBox.innerHTML = '';
      if (coverIndexInput) { coverIndexInput.value = ''; }
      if (!files.length) { return; }

      processing = true;
      if (submitBtn) { submitBtn.disabled = true; }
      previewBox.innerHTML = '<p class="aform-hint" style="margin:.4rem 0">Preparando ' + files.length + ' foto' + (files.length === 1 ? '' : 's') + '...</p>';

      Promise.all(files.map(function (f) { return resizeImageFile(f, 1800, 0.85); })).then(function (processed) {
        try {
          var dt = new DataTransfer();
          processed.forEach(function (f) { dt.items.add(f); });
          input.files = dt.files;
        } catch (e) {
          // Si por algo no se puede reemplazar, seguimos con los
          // archivos originales: no es ideal, pero no rompe la carga.
        }

        previewBox.innerHTML = '';
        processed.forEach(function (f, i) {
          var url = URL.createObjectURL(f);
          var item = document.createElement('div');
          item.className = 'gallery-preview-item';
          var img = document.createElement('img');
          img.src = url;
          item.appendChild(img);
          if (opts.allowCoverPick) {
            var label = document.createElement('label');
            label.className = 'gallery-preview-cover-pick';
            var radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = 'gallery_cover_pick_ui';
            radio.value = String(i);
            radio.addEventListener('change', function () {
              if (coverIndexInput) { coverIndexInput.value = String(i); }
            });
            label.appendChild(radio);
            label.appendChild(document.createTextNode(' Usar como portada'));
            item.appendChild(label);
          }
          previewBox.appendChild(item);
        });

        processing = false;
        if (submitBtn) { submitBtn.disabled = false; }
      });
    });

    if (form) {
      form.addEventListener('submit', function (e) {
        if (processing) {
          e.preventDefault();
        }
      });
    }
  }

  wireImagePicker(document.getElementById('p-image'), document.getElementById('p-image-preview'), { allowCoverPick: false });
  wireImagePicker(document.getElementById('p-gallery'), document.getElementById('p-gallery-preview'), {
    allowCoverPick: true,
    coverIndexInput: document.getElementById('p-gallery-cover-index')
  });
})();
</script>

<?php admin_close(); ?>
