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

        try {
            if ($action === 'add_slide' || $action === 'edit_slide') {
                $id = (int)($_POST['id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $subtitle = trim($_POST['subtitle'] ?? '');
                $linkUrl = trim($_POST['link_url'] ?? '');
                $linkText = trim($_POST['link_text'] ?? '');
                $sortOrder = (int)($_POST['sort_order'] ?? 0);
                $active = isset($_POST['active']) ? 1 : 0;
                $newImage = handle_site_image_upload($_FILES['image'] ?? [], 'portada');

                if ($action === 'add_slide') {
                    if (!$newImage) {
                        $flash = 'Subí una foto para el slide nuevo.';
                        $flashType = 'error';
                    } else {
                        $pdo->prepare('INSERT INTO hero_slides (image, title, subtitle, link_url, link_text, sort_order, active) VALUES (?,?,?,?,?,?,1)')
                            ->execute([$newImage, $title ?: null, $subtitle ?: null, $linkUrl ?: null, $linkText ?: null, $sortOrder]);
                        $flash = 'Slide agregado.';
                    }
                } else {
                    $stmt = $pdo->prepare('SELECT * FROM hero_slides WHERE id = ?');
                    $stmt->execute([$id]);
                    $slide = $stmt->fetch();
                    if (!$slide) {
                        $flash = 'No se encontró ese slide.';
                        $flashType = 'error';
                    } else {
                        $imageToSave = $newImage ?: $slide['image'];
                        $pdo->prepare('UPDATE hero_slides SET image=?, title=?, subtitle=?, link_url=?, link_text=?, sort_order=?, active=? WHERE id=?')
                            ->execute([$imageToSave, $title ?: null, $subtitle ?: null, $linkUrl ?: null, $linkText ?: null, $sortOrder, $active, $id]);
                        if ($newImage && $slide['image']) {
                            $old = __DIR__ . '/../uploads/portada/' . $slide['image'];
                            if (is_file($old)) { @unlink($old); }
                        }
                        $flash = 'Slide actualizado.';
                    }
                }
            } elseif ($action === 'delete_slide') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT image FROM hero_slides WHERE id = ?');
                $stmt->execute([$id]);
                $img = $stmt->fetchColumn();
                $pdo->prepare('DELETE FROM hero_slides WHERE id = ?')->execute([$id]);
                if ($img) {
                    $path = __DIR__ . '/../uploads/portada/' . $img;
                    if (is_file($path)) { @unlink($path); }
                }
                $flash = 'Slide eliminado.';
            } elseif ($action === 'save_essence_image') {
                $newImage = handle_site_image_upload($_FILES['essence_image'] ?? [], 'portada');
                if ($newImage) {
                    $old = setting($pdo, 'essence_image');
                    $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
                        ->execute(['essence_image', $newImage]);
                    if ($old) {
                        $oldPath = __DIR__ . '/../uploads/portada/' . $old;
                        if (is_file($oldPath)) { @unlink($oldPath); }
                    }
                    $flash = 'Foto de "Nuestra esencia" actualizada.';
                } else {
                    $flash = 'Elegí una foto para subir.';
                    $flashType = 'error';
                }
            } elseif ($action === 'remove_essence_image') {
                $old = setting($pdo, 'essence_image');
                $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
                    ->execute(['essence_image', '']);
                if ($old) {
                    $oldPath = __DIR__ . '/../uploads/portada/' . $old;
                    if (is_file($oldPath)) { @unlink($oldPath); }
                }
                $flash = 'Se quitó la foto de "Nuestra esencia" (vuelve al diseño de reemplazo).';
            } elseif ($action === 'add_video') {
                $url = trim($_POST['instagram_url'] ?? '');
                $caption = trim($_POST['caption'] ?? '');
                $sortOrder = (int)($_POST['sort_order'] ?? 0);
                if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || stripos($url, 'instagram.com') === false) {
                    $flash = 'Pegá un link válido de Instagram (de un reel o una publicación).';
                    $flashType = 'error';
                } else {
                    $pdo->prepare('INSERT INTO home_videos (instagram_url, caption, sort_order, active) VALUES (?,?,?,1)')
                        ->execute([$url, $caption ?: null, $sortOrder]);
                    $flash = 'Video agregado.';
                }
            } elseif ($action === 'delete_video') {
                $id = (int)($_POST['id'] ?? 0);
                $pdo->prepare('DELETE FROM home_videos WHERE id = ?')->execute([$id]);
                $flash = 'Video eliminado.';
            } elseif ($action === 'toggle_video_active') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT active FROM home_videos WHERE id = ?');
                $stmt->execute([$id]);
                $cur = $stmt->fetchColumn();
                if ($cur !== false) {
                    $pdo->prepare('UPDATE home_videos SET active = ? WHERE id = ?')->execute([$cur ? 0 : 1, $id]);
                    $flash = 'Se actualizó la visibilidad del video.';
                }
            }
        } catch (RuntimeException $e) {
            $flash = $e->getMessage();
            $flashType = 'error';
        }
    }
}

$slides = $pdo->query('SELECT * FROM hero_slides ORDER BY sort_order, id')->fetchAll();
$essenceImage = setting($pdo, 'essence_image');
$videos = $pdo->query('SELECT * FROM home_videos ORDER BY sort_order, id')->fetchAll();

admin_open($admin, 'portada', 'Portada', $flash, $flashType);
?>

<p style="color:var(--text-muted);margin-bottom:1.8rem">Acá manejás las fotos y videos que se ven apenas alguien abre el sitio: el slide principal, la foto de "Nuestra esencia" y los videos de Instagram.</p>

<div class="admin-card">
  <h2>Slide de portada</h2>
  <p class="aform-hint" style="margin-top:0">Se recomienda cargar entre 2 y 3 fotos. Pasan solas cada pocos segundos. Formato JPG, PNG o WEBP, hasta 5&nbsp;MB. Tamaño recomendado: horizontal, mínimo 1600×900&nbsp;px (se ve mejor en pantallas grandes).</p>

  <?php if ($slides): foreach ($slides as $s): $simg = portada_image_url($s['image']); ?>
  <div class="admin-subcard">
    <form method="post" action="portada.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit_slide">
      <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <div class="aform-row">
        <div class="aform-field">
          <?php if ($simg): ?><div class="current-thumb"><img src="../<?= h($simg) ?>" alt=""><span class="aform-hint" style="margin:0">Foto actual.</span></div><?php endif; ?>
          <label>Reemplazar foto (opcional)</label>
          <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
        </div>
        <div class="aform-field">
          <label>Orden <span style="font-weight:400;color:var(--text-muted)">(menor número = aparece primero)</span></label>
          <input type="text" name="sort_order" inputmode="numeric" value="<?= (int)$s['sort_order'] ?>">
        </div>
      </div>
      <div class="aform-row">
        <div class="aform-field">
          <label>Título (opcional)</label>
          <input type="text" name="title" value="<?= h((string)$s['title']) ?>">
        </div>
        <div class="aform-field">
          <label>Texto corto (opcional)</label>
          <input type="text" name="subtitle" value="<?= h((string)$s['subtitle']) ?>">
        </div>
      </div>
      <div class="aform-row">
        <div class="aform-field">
          <label>Link del botón (opcional)</label>
          <input type="text" name="link_url" placeholder="ej: tienda.php" value="<?= h((string)$s['link_url']) ?>">
        </div>
        <div class="aform-field">
          <label>Texto del botón (opcional)</label>
          <input type="text" name="link_text" placeholder="ej: Ver la tienda" value="<?= h((string)$s['link_text']) ?>">
        </div>
      </div>
      <div class="aform-row">
        <label class="aform-check"><input type="checkbox" name="active" <?= $s['active']?'checked':'' ?>> Visible en el sitio</label>
        <div class="aform-actions" style="margin-top:0">
          <button class="abtn abtn-primary" type="submit"><?= admin_icon('check') ?> Guardar</button>
        </div>
      </div>
    </form>
    <form method="post" action="portada.php" data-confirm="¿Eliminar este slide?" style="margin-top:.6rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_slide">
      <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <button type="submit" class="abtn abtn-danger abtn-sm"><?= admin_icon('trash') ?> Eliminar slide</button>
    </form>
  </div>
  <?php endforeach; else: ?>
  <p style="color:var(--text-muted);font-size:.9rem">Todavía no cargaste ningún slide. Mientras tanto, la portada muestra el título y la bajada de "Textos del sitio → Portada — Hero".</p>
  <?php endif; ?>

  <div class="admin-subcard admin-subcard-new">
    <h3 style="margin-top:0">Agregar slide nuevo</h3>
    <form method="post" action="portada.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_slide">
      <div class="aform-field">
        <label>Foto</label>
        <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp" required>
      </div>
      <div class="aform-row">
        <div class="aform-field">
          <label>Título (opcional)</label>
          <input type="text" name="title" placeholder="ej: Nueva colección primavera">
        </div>
        <div class="aform-field">
          <label>Texto corto (opcional)</label>
          <input type="text" name="subtitle" placeholder="ej: Telas naturales, estampadas a mano">
        </div>
      </div>
      <div class="aform-row">
        <div class="aform-field">
          <label>Link del botón (opcional)</label>
          <input type="text" name="link_url" placeholder="ej: tienda.php">
        </div>
        <div class="aform-field">
          <label>Texto del botón (opcional)</label>
          <input type="text" name="link_text" placeholder="ej: Ver la tienda">
        </div>
      </div>
      <input type="hidden" name="sort_order" value="<?= count($slides) ?>">
      <div class="aform-actions">
        <button class="abtn abtn-primary" type="submit"><?= admin_icon('plus') ?> Agregar slide</button>
      </div>
    </form>
  </div>
</div>

<div class="admin-card">
  <h2>Foto de "Nuestra esencia"</h2>
  <p class="aform-hint" style="margin-top:0">Formato JPG, PNG o WEBP, hasta 5&nbsp;MB. Tamaño recomendado: vertical, proporción 4:5, mínimo 1000×1250&nbsp;px.</p>
  <?php if ($essenceImage): $eimg = portada_image_url($essenceImage); ?>
  <div class="current-thumb"><img src="../<?= h($eimg) ?>" alt=""><span class="aform-hint" style="margin:0">Foto actual.</span></div>
  <?php else: ?>
  <p style="color:var(--text-muted);font-size:.9rem">Todavía no subiste una foto — se muestra el diseño de reemplazo.</p>
  <?php endif; ?>
  <form method="post" action="portada.php" enctype="multipart/form-data" style="margin-top:1rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_essence_image">
    <div class="aform-field">
      <input type="file" name="essence_image" accept=".jpg,.jpeg,.png,.webp">
    </div>
    <div class="aform-actions" style="margin-top:0">
      <button class="abtn abtn-primary" type="submit"><?= admin_icon('check') ?> Subir foto</button>
      <?php if ($essenceImage): ?>
      <button type="submit" name="action" value="remove_essence_image" class="abtn abtn-ghost" formnovalidate>Quitar foto</button>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="admin-card">
  <h2>Videos de Instagram en la portada</h2>
  <p class="aform-hint" style="margin-top:0">Pegá el link del reel o la publicación de Instagram (no hace falta subir el archivo de video). Se recomienda 3 a 6 videos.</p>

  <?php if ($videos): ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Link</th><th>Descripción</th><th>Visible</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($videos as $v): ?>
        <tr>
          <td><a href="<?= h($v['instagram_url']) ?>" target="_blank" rel="noopener" style="word-break:break-all"><?= h($v['instagram_url']) ?></a></td>
          <td><?= h((string)$v['caption']) ?></td>
          <td>
            <form method="post" action="portada.php">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><input type="hidden" name="action" value="toggle_video_active">
              <button type="submit" class="badge <?= $v['active']?'badge-on':'badge-off' ?>" style="border:0;cursor:pointer"><?= $v['active']?'Sí':'No' ?></button>
            </form>
          </td>
          <td class="actions">
            <form method="post" action="portada.php" data-confirm="¿Eliminar este video?">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><input type="hidden" name="action" value="delete_video">
              <button type="submit" class="abtn abtn-danger abtn-icon" aria-label="Eliminar"><?= admin_icon('trash') ?></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <p style="color:var(--text-muted);font-size:.9rem">Todavía no cargaste ningún video.</p>
  <?php endif; ?>

  <div class="admin-subcard admin-subcard-new">
    <h3 style="margin-top:0">Agregar video</h3>
    <form method="post" action="portada.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_video">
      <div class="aform-field">
        <label>Link de Instagram</label>
        <input type="text" name="instagram_url" placeholder="https://www.instagram.com/reel/....">
      </div>
      <div class="aform-field">
        <label>Descripción corta (opcional, solo para vos)</label>
        <input type="text" name="caption" placeholder="ej: Mantel Jardín Romantiza en la mesa">
      </div>
      <input type="hidden" name="sort_order" value="<?= count($videos) ?>">
      <div class="aform-actions">
        <button class="abtn abtn-primary" type="submit"><?= admin_icon('plus') ?> Agregar video</button>
      </div>
    </form>
  </div>
</div>

<?php admin_close(); ?>
