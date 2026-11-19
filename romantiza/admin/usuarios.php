<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/admin_ui.php';

$admin = require_login($pdo);
require_superadmin($admin);

$flash = null;
$flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $flash = 'La sesión del formulario expiró. Intentá de nuevo.';
        $flashType = 'error';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $action = $_POST['action'] ?? '';

        if ($id === (int)$admin['id'] && in_array($action, ['toggle_active', 'delete'], true)) {
            $flash = 'No podés desactivarte ni eliminarte a vos mismo desde acá.';
            $flashType = 'error';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = ?');
            $stmt->execute([$id]);
            $target = $stmt->fetch();

            if (!$target) {
                $flash = 'No se encontró ese administrador.';
                $flashType = 'error';
            } elseif ($action === 'toggle_active') {
                // No permitir desactivar al último superadmin activo.
                if ($target['role'] === 'superadmin' && $target['active']) {
                    $activeSupers = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role='superadmin' AND active=1")->fetchColumn();
                    if ($activeSupers <= 1) {
                        $flash = 'No podés desactivar al único administrador principal activo.';
                        $flashType = 'error';
                    }
                }
                if (!$flash) {
                    $pdo->prepare('UPDATE admins SET active = ? WHERE id = ?')->execute([$target['active'] ? 0 : 1, $id]);
                    $flash = 'Se actualizó el estado de la cuenta.';
                }
            } elseif ($action === 'delete') {
                if ($target['role'] === 'superadmin') {
                    $activeSupers = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role='superadmin' AND active=1")->fetchColumn();
                    if ($activeSupers <= 1) {
                        $flash = 'No podés eliminar al único administrador principal.';
                        $flashType = 'error';
                    }
                }
                if (!$flash) {
                    $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
                    $flash = 'Cuenta eliminada.';
                }
            }
        }
    }
}

$admins = $pdo->query('SELECT * FROM admins ORDER BY role = "superadmin" DESC, full_name')->fetchAll();

admin_open($admin, 'usuarios', 'Administradores', $flash, $flashType);
?>

<div class="admin-actions-bar">
  <p style="color:var(--text-muted);font-size:.88rem;margin:0;max-width:34rem">
    El administrador principal puede modificar todo el sitio, incluida esta sección.
    Los editores solo pueden cambiar textos, imágenes y productos.
  </p>
  <a class="abtn abtn-primary" href="usuario_form.php"><?= admin_icon('plus') ?> Nuevo administrador</a>
</div>

<div class="admin-table-wrap">
  <table class="admin-table">
    <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Estado</th><th>Último ingreso</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($admins as $a): $isSelf = (int)$a['id'] === (int)$admin['id']; ?>
      <tr>
        <td><?= h($a['full_name']) ?><?= $isSelf ? ' <span style="color:var(--text-muted);font-size:.78rem">(vos)</span>' : '' ?></td>
        <td><?= h($a['username']) ?></td>
        <td><span class="badge <?= $a['role']==='superadmin'?'badge-role-super':'badge-role-editor' ?>"><?= $a['role']==='superadmin'?'Principal':'Editor' ?></span></td>
        <td>
          <?php if ($isSelf): ?>
            <span class="badge <?= $a['active']?'badge-on':'badge-off' ?>"><?= $a['active']?'Activo':'Inactivo' ?></span>
          <?php else: ?>
          <form method="post" action="usuarios.php">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="action" value="toggle_active">
            <button type="submit" class="badge <?= $a['active']?'badge-on':'badge-off' ?>" style="border:0;cursor:pointer"><?= $a['active']?'Activo':'Inactivo' ?></button>
          </form>
          <?php endif; ?>
        </td>
        <td><?= $a['last_login'] ? h(date('d/m/Y H:i', strtotime($a['last_login']))) : '—' ?></td>
        <td class="actions">
          <a class="abtn abtn-ghost abtn-icon" href="usuario_form.php?id=<?= (int)$a['id'] ?>" aria-label="Editar"><?= admin_icon('edit') ?></a>
          <?php if (!$isSelf): ?>
          <form method="post" action="usuarios.php" data-confirm="¿Eliminar la cuenta de «<?= h($a['full_name']) ?>»?">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="action" value="delete">
            <button type="submit" class="abtn abtn-danger abtn-icon" aria-label="Eliminar"><?= admin_icon('trash') ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php admin_close(); ?>
