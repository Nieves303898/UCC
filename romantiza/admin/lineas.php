<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/admin_ui.php';

$admin = require_login($pdo);

$flash = null;
$flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $flash = 'La sesión del formulario expiró. Recargá la página e intentá de nuevo.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        $kind = ($_POST['kind'] ?? '') === 'linea' ? 'linea' : 'categoria';
        $kindLabel = $kind === 'linea' ? 'Línea' : 'Categoría';

        try {
            if ($action === 'add') {
                $name = trim($_POST['name'] ?? '');
                if ($name === '') {
                    $flash = 'Escribí un nombre.';
                    $flashType = 'error';
                } else {
                    $isLinea = $kind === 'linea' ? 1 : 0;
                    $slug = unique_category_slug($pdo, $name);
                    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE is_linea = ?');
                    $countStmt->execute([$isLinea]);
                    $sortOrder = (int)$countStmt->fetchColumn() + 1;
                    $newImage = $isLinea ? handle_site_image_upload($_FILES['image'] ?? [], 'lineas') : null;
                    $pdo->prepare('INSERT INTO categories (slug, name, image, is_linea, sort_order) VALUES (?,?,?,?,?)')
                        ->execute([$slug, $name, $newImage, $isLinea, $sortOrder]);
                    $flash = $kindLabel . ' «' . $name . '» agregada.';
                }
            } elseif ($action === 'edit') {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $sortOrder = (int)($_POST['sort_order'] ?? 0);
                $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
                $stmt->execute([$id]);
                $cat = $stmt->fetch();
                if (!$cat) {
                    $flash = 'No se encontró ese registro.';
                    $flashType = 'error';
                } elseif ($name === '') {
                    $flash = 'El nombre no puede quedar vacío.';
                    $flashType = 'error';
                } else {
                    $newImage = $cat['is_linea'] ? handle_site_image_upload($_FILES['image'] ?? [], 'lineas') : null;
                    $imageToSave = $newImage ?: $cat['image'];
                    $slug = unique_category_slug($pdo, $name, $id);
                    $pdo->prepare('UPDATE categories SET name=?, slug=?, image=?, sort_order=? WHERE id=?')
                        ->execute([$name, $slug, $imageToSave, $sortOrder, $id]);
                    if ($newImage && $cat['image']) {
                        $old = __DIR__ . '/../uploads/lineas/' . $cat['image'];
                        if (is_file($old)) { @unlink($old); }
                    }
                    $flash = 'Cambios guardados.';
                }
            } elseif ($action === 'remove_image') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
                $stmt->execute([$id]);
                $cat = $stmt->fetch();
                if (!$cat) {
                    $flash = 'No se encontró ese registro.';
                    $flashType = 'error';
                } else {
                    if ($cat['image']) {
                        $old = __DIR__ . '/../uploads/lineas/' . $cat['image'];
                        if (is_file($old)) { @unlink($old); }
                    }
                    $pdo->prepare('UPDATE categories SET image = NULL WHERE id = ?')->execute([$id]);
                    $flash = 'Se quitó la foto de «' . $cat['name'] . '».';
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
                $stmt->execute([$id]);
                $cat = $stmt->fetch();
                if (!$cat) {
                    $flash = 'No se encontró ese registro.';
                    $flashType = 'error';
                } else {
                    $useStmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
                    $useStmt->execute([$id]);
                    $inUse = (int)$useStmt->fetchColumn();
                    if ($inUse > 0) {
                        $label = $cat['is_linea'] ? 'línea' : 'categoría';
                        $flash = 'No se puede eliminar «' . $cat['name'] . '» porque tiene ' . $inUse . ' producto' . ($inUse === 1 ? '' : 's') . ' asignado' . ($inUse === 1 ? '' : 's') . '. Cambiales la ' . $label . ' desde Productos antes de eliminarla.';
                        $flashType = 'error';
                    } else {
                        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
                        if ($cat['image']) {
                            $old = __DIR__ . '/../uploads/lineas/' . $cat['image'];
                            if (is_file($old)) { @unlink($old); }
                        }
                        $flash = ($cat['is_linea'] ? 'Línea' : 'Categoría') . ' «' . $cat['name'] . '» eliminada.';
                    }
                }
            }
        } catch (PDOException $e) {
            $flash = 'Todavía no se corrió la actualización de la base de datos que necesita esta sección. Importá database/migracion_lineas_categorias.sql desde phpMyAdmin e intentá de nuevo.';
            $flashType = 'error';
        } catch (RuntimeException $e) {
            $flash = $e->getMessage();
            $flashType = 'error';
        }
    }
}

// is_linea es una columna nueva: si todavía no se corrió
// migracion_lineas_categorias.sql, mostramos un aviso claro en vez de
// romper la página con un error de SQL.
$migrationNeeded = false;
try {
    $lineas = $pdo->query('SELECT * FROM categories WHERE is_linea = 1 ORDER BY sort_order, id')->fetchAll();
    $categorias = $pdo->query('SELECT * FROM categories WHERE is_linea = 0 ORDER BY sort_order, id')->fetchAll();
} catch (Throwable $e) {
    $migrationNeeded = true;
    $lineas = [];
    $categorias = [];
}

admin_open($admin, 'lineas', 'Líneas y categorías', $flash, $flashType);
?>

<?php if ($migrationNeeded): ?>
<div class="admin-flash admin-flash-error" style="margin-bottom:1.8rem">
  Para usar esta sección primero hay que correr una actualización de la base de datos: importá el archivo <code>database/migracion_lineas_categorias.sql</code> desde phpMyAdmin (pestaña "Importar") y volvé a entrar acá. El resto del sitio sigue funcionando normalmente mientras tanto.
</div>
<?php else: ?>
<p style="color:var(--text-muted);margin-bottom:1.8rem">Las <strong>líneas</strong> son las colecciones de diseño propias de Romantiza (ej. Línea Mediterránea): tienen foto y se muestran en la portada, en "Cada rincón, con su textil". Las <strong>categorías</strong> son tipos de producto más generales (ej. Mantelería, Puntillas): no llevan foto y solo sirven para ordenar y filtrar en la tienda. Cada producto se asigna a una sola línea o categoría desde Productos.</p>
<?php endif; ?>

<div class="admin-card">
  <h2>Líneas</h2>
  <p class="aform-hint" style="margin-top:0">La foto se muestra en la portada. Formato JPG, PNG o WEBP, hasta 5&nbsp;MB. Tamaño recomendado: cuadrada o vertical, mínimo 1000×1000&nbsp;px.</p>

  <?php if ($lineas): foreach ($lineas as $c): $cimg = category_image_url($c['image']); ?>
  <div class="admin-subcard">
    <form method="post" action="lineas.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="kind" value="linea">
      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <div class="aform-row">
        <div class="aform-field">
          <?php if ($cimg): ?><div class="current-thumb"><img src="../<?= h($cimg) ?>" alt=""><span class="aform-hint" style="margin:0">Foto actual.</span></div>
          <?php else: ?><p style="color:var(--text-muted);font-size:.9rem;margin:0 0 .6rem">Todavía no tiene foto — se muestra el ícono de reemplazo.</p><?php endif; ?>
          <label>Reemplazar foto (opcional)</label>
          <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
        </div>
        <div class="aform-field">
          <label>Nombre</label>
          <input type="text" name="name" value="<?= h($c['name']) ?>" required>
          <label style="margin-top:.8rem">Orden <span style="font-weight:400;color:var(--text-muted)">(menor número = aparece primero)</span></label>
          <input type="text" name="sort_order" inputmode="numeric" value="<?= (int)$c['sort_order'] ?>">
        </div>
      </div>
      <div class="aform-actions" style="margin-top:0">
        <button class="abtn abtn-primary" type="submit"><?= admin_icon('check') ?> Guardar</button>
      </div>
    </form>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.6rem">
      <?php if ($cimg): ?>
      <form method="post" action="lineas.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove_image">
        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <button type="submit" class="abtn abtn-ghost abtn-sm">Quitar foto</button>
      </form>
      <?php endif; ?>
      <form method="post" action="lineas.php" data-confirm="¿Eliminar la línea «<?= h($c['name']) ?>»?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <button type="submit" class="abtn abtn-danger abtn-sm"><?= admin_icon('trash') ?> Eliminar línea</button>
      </form>
    </div>
  </div>
  <?php endforeach; else: ?>
  <p style="color:var(--text-muted);font-size:.9rem">Todavía no hay líneas cargadas.</p>
  <?php endif; ?>

  <div class="admin-subcard admin-subcard-new">
    <h3 style="margin-top:0">Agregar línea nueva</h3>
    <form method="post" action="lineas.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="kind" value="linea">
      <div class="aform-row">
        <div class="aform-field">
          <label>Nombre</label>
          <input type="text" name="name" placeholder="ej: Línea Bosque Nativo" required>
        </div>
        <div class="aform-field">
          <label>Foto (opcional, se puede cargar después)</label>
          <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
        </div>
      </div>
      <div class="aform-actions">
        <button class="abtn abtn-primary" type="submit"><?= admin_icon('plus') ?> Agregar línea</button>
      </div>
    </form>
  </div>
</div>

<div class="admin-card">
  <h2>Categorías</h2>
  <p class="aform-hint" style="margin-top:0">No llevan foto. Se usan para ordenar y filtrar productos en la tienda (ej. Mantelería, Puntillas, Almohadones).</p>

  <?php if ($categorias): foreach ($categorias as $c): ?>
  <div class="admin-subcard">
    <form method="post" action="lineas.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="kind" value="categoria">
      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <div class="aform-row">
        <div class="aform-field">
          <label>Nombre</label>
          <input type="text" name="name" value="<?= h($c['name']) ?>" required>
        </div>
        <div class="aform-field">
          <label>Orden <span style="font-weight:400;color:var(--text-muted)">(menor número = aparece primero)</span></label>
          <input type="text" name="sort_order" inputmode="numeric" value="<?= (int)$c['sort_order'] ?>">
        </div>
      </div>
      <div class="aform-actions" style="margin-top:0">
        <button class="abtn abtn-primary" type="submit"><?= admin_icon('check') ?> Guardar</button>
      </div>
    </form>
    <form method="post" action="lineas.php" data-confirm="¿Eliminar la categoría «<?= h($c['name']) ?>»?" style="margin-top:.6rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <button type="submit" class="abtn abtn-danger abtn-sm"><?= admin_icon('trash') ?> Eliminar categoría</button>
    </form>
  </div>
  <?php endforeach; else: ?>
  <p style="color:var(--text-muted);font-size:.9rem">Todavía no hay categorías cargadas.</p>
  <?php endif; ?>

  <div class="admin-subcard admin-subcard-new">
    <h3 style="margin-top:0">Agregar categoría nueva</h3>
    <form method="post" action="lineas.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="kind" value="categoria">
      <div class="aform-field">
        <label>Nombre</label>
        <input type="text" name="name" placeholder="ej: Cortinas" required>
      </div>
      <div class="aform-actions">
        <button class="abtn abtn-primary" type="submit"><?= admin_icon('plus') ?> Agregar categoría</button>
      </div>
    </form>
  </div>
</div>

<?php admin_close(); ?>
