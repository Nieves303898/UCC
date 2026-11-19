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
        $flash = 'La sesión del formulario expiró. Intentá de nuevo.';
        $flashType = 'error';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $action = $_POST['action'] ?? '';
        if ($action === 'toggle_read') {
            $stmt = $pdo->prepare('SELECT read_flag FROM messages WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row) {
                $pdo->prepare('UPDATE messages SET read_flag = ? WHERE id = ?')->execute([$row['read_flag'] ? 0 : 1, $id]);
            }
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM messages WHERE id = ?')->execute([$id]);
            $flash = 'Mensaje eliminado.';
        }
    }
}

$typeFilter = $_GET['tipo'] ?? '';
$sql = 'SELECT * FROM messages';
$params = [];
if (in_array($typeFilter, ['contacto', 'newsletter'], true)) { $sql .= ' WHERE type = ?'; $params[] = $typeFilter; }
$sql .= ' ORDER BY created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

admin_open($admin, 'mensajes', 'Mensajes', $flash, $flashType);
?>

<div class="admin-actions-bar">
  <form method="get" style="display:flex;gap:.6rem">
    <select name="tipo" onchange="this.form.submit()" style="border:1.5px solid var(--border);padding:.55rem .8rem;border-radius:2px 8px 2px 8px;font-family:inherit">
      <option value="" <?= $typeFilter===''?'selected':'' ?>>Todos</option>
      <option value="contacto" <?= $typeFilter==='contacto'?'selected':'' ?>>Formulario de contacto</option>
      <option value="newsletter" <?= $typeFilter==='newsletter'?'selected':'' ?>>Newsletter</option>
    </select>
  </form>
</div>

<?php if (!$messages): ?>
  <div class="admin-card"><p style="color:var(--text-muted)">No hay mensajes para mostrar.</p></div>
<?php else: ?>
<div class="admin-table-wrap">
  <table class="admin-table">
    <thead><tr><th></th><th>Tipo</th><th>Nombre</th><th>Email</th><th>Mensaje</th><th>Fecha</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($messages as $m): ?>
      <tr style="<?= $m['read_flag'] ? '' : 'font-weight:700' ?>">
        <td>
          <form method="post" action="mensajes.php">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="action" value="toggle_read">
            <button type="submit" class="badge <?= $m['read_flag']?'badge-off':'badge-on' ?>" style="border:0;cursor:pointer"><?= $m['read_flag']?'Leído':'Nuevo' ?></button>
          </form>
        </td>
        <td><?= $m['type']==='contacto'?'Contacto':'Newsletter' ?></td>
        <td><?= h($m['name'] ?: '—') ?></td>
        <td><a href="mailto:<?= h($m['email']) ?>"><?= h($m['email']) ?></a></td>
        <td style="max-width:280px;white-space:pre-wrap"><?= h($m['message'] ?: '—') ?></td>
        <td><?= h(date('d/m/Y H:i', strtotime($m['created_at']))) ?></td>
        <td class="actions">
          <form method="post" action="mensajes.php" data-confirm="¿Eliminar este mensaje?">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="action" value="delete">
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
