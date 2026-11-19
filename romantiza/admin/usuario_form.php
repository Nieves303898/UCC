<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/admin_ui.php';

$admin = require_login($pdo);
require_superadmin($admin);

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$target = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) { header('Location: usuarios.php'); exit; }
}
$isSelf = $target && (int)$target['id'] === (int)$admin['id'];

$errors = [];
$values = $target ?: ['username' => '', 'full_name' => '', 'role' => 'editor', 'active' => 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'La sesión del formulario expiró. Recargá la página e intentá de nuevo.';
    } else {
        $values['username'] = trim($_POST['username'] ?? '');
        $values['full_name'] = trim($_POST['full_name'] ?? '');
        $values['role'] = ($_POST['role'] ?? 'editor') === 'superadmin' ? 'superadmin' : 'editor';
        $values['active'] = isset($_POST['active']) ? 1 : 0;
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        // Protecciones para no quedarse sin acceso al panel.
        if ($isSelf && $values['role'] !== 'superadmin') {
            $errors[] = 'No podés quitarte a vos mismo el rol de administrador principal.';
        }
        if ($isSelf && !$values['active']) {
            $errors[] = 'No podés desactivar tu propia cuenta.';
        }

        if ($values['username'] === '' || !preg_match('/^[a-zA-Z0-9_.\-]{3,60}$/', $values['username'])) {
            $errors[] = 'El usuario debe tener entre 3 y 60 caracteres (letras, números, guiones o punto).';
        }
        if ($values['full_name'] === '') {
            $errors[] = 'Completá el nombre y apellido.';
        }
        if (!$target && strlen($password) < 8) {
            $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
        }
        if ($password !== '' && strlen($password) < 8) {
            $errors[] = 'La nueva contraseña debe tener al menos 8 caracteres.';
        }
        if ($password !== $password2) {
            $errors[] = 'Las contraseñas no coinciden.';
        }

        if (!$errors) {
            $dup = $pdo->prepare('SELECT id FROM admins WHERE username = ?' . ($target ? ' AND id <> ?' : ''));
            $dup->execute($target ? [$values['username'], $target['id']] : [$values['username']]);
            if ($dup->fetch()) {
                $errors[] = 'Ese nombre de usuario ya está en uso.';
            }
        }

        // Si se está desactivando o degradando al último superadmin activo, frenar.
        if (!$errors && $target && $target['role'] === 'superadmin') {
            $willStaySuper = $values['role'] === 'superadmin' && $values['active'];
            if (!$willStaySuper) {
                $activeSupers = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role='superadmin' AND active=1")->fetchColumn();
                if ($activeSupers <= 1) {
                    $errors[] = 'No podés quitarle el rol de administrador principal (o desactivar) al único que queda activo.';
                }
            }
        }

        if (!$errors) {
            if ($target) {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('UPDATE admins SET username=?, full_name=?, role=?, active=?, password_hash=? WHERE id=?');
                    $stmt->execute([$values['username'], $values['full_name'], $values['role'], $values['active'], $hash, $target['id']]);
                } else {
                    $stmt = $pdo->prepare('UPDATE admins SET username=?, full_name=?, role=?, active=? WHERE id=?');
                    $stmt->execute([$values['username'], $values['full_name'], $values['role'], $values['active'], $target['id']]);
                }
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash, full_name, role, active) VALUES (?,?,?,?,?)');
                $stmt->execute([$values['username'], $hash, $values['full_name'], $values['role'], $values['active']]);
            }
            header('Location: usuarios.php?guardado=1');
            exit;
        }
    }
}

admin_open($admin, 'usuarios', $target ? 'Editar administrador' : 'Nuevo administrador');
?>

<a href="usuarios.php" style="font-size:.85rem;color:var(--text-muted);display:inline-block;margin-bottom:1.2rem">&larr; Volver a administradores</a>

<?php if ($errors): ?>
<div class="admin-flash admin-flash-error"><?php foreach ($errors as $e) echo h($e) . '<br>'; ?></div>
<?php endif; ?>

<div class="admin-card" style="max-width:560px">
  <form method="post" action="usuario_form.php<?= $target ? '?id='.(int)$target['id'] : '' ?>" autocomplete="off">
    <?= csrf_field() ?>
    <?php if ($target): ?><input type="hidden" name="id" value="<?= (int)$target['id'] ?>"><?php endif; ?>

    <div class="aform-field">
      <label for="u-fullname">Nombre y apellido</label>
      <input id="u-fullname" name="full_name" type="text" required value="<?= h($values['full_name']) ?>">
    </div>
    <div class="aform-field">
      <label for="u-username">Usuario para iniciar sesión</label>
      <input id="u-username" name="username" type="text" required value="<?= h($values['username']) ?>">
    </div>
    <div class="aform-field">
      <label for="u-role">Rol</label>
      <select id="u-role" name="role" <?= $isSelf ? 'disabled' : '' ?>>
        <option value="editor" <?= $values['role']==='editor'?'selected':'' ?>>Editor (solo textos, imágenes y productos)</option>
        <option value="superadmin" <?= $values['role']==='superadmin'?'selected':'' ?>>Administrador principal (control total)</option>
      </select>
      <?php if ($isSelf): ?>
        <input type="hidden" name="role" value="superadmin">
        <p class="aform-hint">No podés cambiar tu propio rol.</p>
      <?php endif; ?>
    </div>
    <div class="aform-field">
      <label class="aform-check"><input type="checkbox" name="active" <?= $values['active']?'checked':'' ?> <?= $isSelf ? 'disabled' : '' ?>> Cuenta activa</label>
      <?php if ($isSelf): ?><input type="hidden" name="active" value="1"><?php endif; ?>
    </div>

    <div class="aform-field">
      <label for="u-pass"><?= $target ? 'Nueva contraseña (opcional)' : 'Contraseña' ?></label>
      <div class="pass-field">
        <input id="u-pass" name="password" type="password" <?= $target ? '' : 'required' ?> minlength="8" autocomplete="new-password">
        <button type="button" class="pass-toggle" data-toggle-pass="u-pass" aria-label="Mostrar contraseña">
          <span class="icon-on"><?= admin_icon('eye') ?></span>
          <span class="icon-off"><?= admin_icon('eye-off') ?></span>
        </button>
      </div>
      <?php if ($target): ?><p class="aform-hint">Dejá este campo vacío para mantener la contraseña actual.</p><?php endif; ?>
    </div>
    <div class="aform-field">
      <label for="u-pass2">Repetir contraseña</label>
      <div class="pass-field">
        <input id="u-pass2" name="password2" type="password" <?= $target ? '' : 'required' ?> minlength="8" autocomplete="new-password">
        <button type="button" class="pass-toggle" data-toggle-pass="u-pass2" aria-label="Mostrar contraseña">
          <span class="icon-on"><?= admin_icon('eye') ?></span>
          <span class="icon-off"><?= admin_icon('eye-off') ?></span>
        </button>
      </div>
    </div>

    <div class="aform-actions">
      <button class="abtn abtn-primary" type="submit"><?= admin_icon('check') ?> Guardar</button>
      <a class="abtn abtn-ghost" href="usuarios.php">Cancelar</a>
    </div>
  </form>
</div>

<?php admin_close(); ?>
