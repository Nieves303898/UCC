<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/admin_ui.php';

// Contactos de WhatsApp: tanto el superadmin como los editores pueden
// verlos y administrarlos (son datos de campaña, no algo sensible
// como las credenciales de cobro).
$admin = require_login($pdo);

$flash = null;
$flashType = 'ok';
$migrationNeeded = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $flash = 'La sesión del formulario expiró. Intentá de nuevo.';
        $flashType = 'error';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'toggle_contacted') {
                $stmt = $pdo->prepare('SELECT contacted FROM whatsapp_leads WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if ($row) {
                    $pdo->prepare('UPDATE whatsapp_leads SET contacted = ? WHERE id = ?')->execute([$row['contacted'] ? 0 : 1, $id]);
                }
            } elseif ($action === 'delete') {
                $pdo->prepare('DELETE FROM whatsapp_leads WHERE id = ?')->execute([$id]);
                $flash = 'Contacto eliminado.';
            }
        } catch (PDOException $e) {
            $migrationNeeded = true;
        }
    }
}

$leads = [];
try {
    $leads = $pdo->query('SELECT * FROM whatsapp_leads ORDER BY created_at DESC')->fetchAll();
} catch (PDOException $e) {
    $migrationNeeded = true;
}

admin_open($admin, 'whatsapp_leads', 'WhatsApp (campañas)', $flash, $flashType);
?>

<?php if ($migrationNeeded): ?>
  <div class="admin-card">
    <p class="aform-hint" style="margin-top:0">Esta sección todavía no está disponible en este sitio: falta importar <code>migracion_captacion_whatsapp.sql</code> en la base de datos. El resto del panel funciona igual mientras tanto.</p>
  </div>
<?php else: ?>

<p style="color:var(--text-muted);margin-bottom:1.4rem">Acá aparece cada persona que dejó su WhatsApp en el popup de la web (Panel → Textos del sitio → "Popup de WhatsApp" lo prende/apaga y edita los textos). Tocá el número para abrir la conversación directo.</p>

<?php if (!$leads): ?>
  <div class="admin-card"><p style="color:var(--text-muted)">Todavía no se cargó ningún contacto.</p></div>
<?php else: ?>
<div class="admin-table-wrap">
  <table class="admin-table">
    <thead><tr><th></th><th>Nombre</th><th>WhatsApp</th><th>Fecha</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($leads as $l): ?>
      <tr style="<?= $l['contacted'] ? '' : 'font-weight:700' ?>">
        <td>
          <form method="post" action="whatsapp_leads.php">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="action" value="toggle_contacted">
            <button type="submit" class="badge <?= $l['contacted']?'badge-off':'badge-on' ?>" style="border:0;cursor:pointer"><?= $l['contacted']?'Contactado':'Nuevo' ?></button>
          </form>
        </td>
        <td><?= h($l['name'] ?: '—') ?></td>
        <td><a href="https://wa.me/<?= h($l['whatsapp']) ?>" target="_blank" rel="noopener"><?= h($l['whatsapp']) ?></a></td>
        <td><?= h(date('d/m/Y H:i', strtotime($l['created_at']))) ?></td>
        <td class="actions">
          <form method="post" action="whatsapp_leads.php" data-confirm="¿Eliminar este contacto?">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="action" value="delete">
            <button type="submit" class="abtn abtn-danger abtn-icon" aria-label="Eliminar"><?= admin_icon('trash') ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="aform-hint" style="margin-top:1rem">Son <?= count($leads) ?> contacto<?= count($leads)===1?'':'s' ?> en total.</p>
<?php endif; ?>
<?php endif; ?>

<?php admin_close(); ?>
