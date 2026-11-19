<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/admin_ui.php';

$admin = require_login($pdo);

$flash = null;
$flashType = 'ok';

$orderStatuses = ['nuevo', 'en_proceso', 'enviado', 'entregado', 'cancelado'];
$paymentStatuses = ['pendiente', 'confirmado', 'cancelado'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $flash = 'La sesión del formulario expiró. Intentá de nuevo.';
        $flashType = 'error';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $action = $_POST['action'] ?? '';
        if ($action === 'update' && $id) {
            $orderStatus = $_POST['order_status'] ?? '';
            $paymentStatus = $_POST['payment_status'] ?? '';
            $adminNotes = trim($_POST['admin_notes'] ?? '');
            $shippingCostRaw = str_replace(',', '.', trim($_POST['shipping_cost'] ?? ''));
            $shippingCost = ($shippingCostRaw === '' || !is_numeric($shippingCostRaw)) ? null : (float)$shippingCostRaw;
            if (in_array($orderStatus, $orderStatuses, true) && in_array($paymentStatus, $paymentStatuses, true)) {
                $stmt = $pdo->prepare('UPDATE orders SET order_status = ?, payment_status = ?, admin_notes = ?, shipping_cost = ? WHERE id = ?');
                $stmt->execute([$orderStatus, $paymentStatus, $adminNotes, $shippingCost, $id]);
                $flash = 'Pedido actualizado.';
            } else {
                $flash = 'Datos no válidos.';
                $flashType = 'error';
            }
        }
    }
}

$viewId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($viewId) {
    // ---- Vista de detalle de un pedido ----
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$viewId]);
    $order = $stmt->fetch();
    if (!$order) {
        header('Location: pedidos.php');
        exit;
    }
    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $itemsStmt->execute([$viewId]);
    $orderItems = $itemsStmt->fetchAll();

    admin_open($admin, 'pedidos', 'Pedido ' . $order['order_number'], $flash, $flashType);
    ?>
    <p style="margin-bottom:1.4rem"><a href="pedidos.php" style="font-weight:700;color:var(--marron)">&larr; Volver a pedidos</a></p>

    <div class="admin-card">
      <h2>Datos del comprador</h2>
      <p><strong><?= h($order['customer_name']) ?></strong></p>
      <p><?= h($order['customer_phone']) ?><?php if ($order['customer_email']): ?> &middot; <?= h($order['customer_email']) ?><?php endif; ?></p>
      <?php if ($order['customer_address']): ?><p><?= h($order['customer_address']) ?></p><?php endif; ?>
      <?php if ($order['customer_notes']): ?><p style="margin-top:.6rem;color:var(--text-muted)">Notas: <?= h($order['customer_notes']) ?></p><?php endif; ?>
      <p style="margin-top:.6rem"><span class="badge badge-on"><?= h(shipping_method_label($order['shipping_method'])) ?></span></p>
      <p style="margin-top:.6rem;color:var(--text-muted)">Pedido realizado el <?= h(date('d/m/Y H:i', strtotime($order['created_at']))) ?></p>
    </div>

    <div class="admin-card">
      <h2>Productos</h2>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead><tr><th>Producto</th><th>Cantidad</th><th>Precio</th><th>Subtotal</th></tr></thead>
          <tbody>
            <?php foreach ($orderItems as $it): ?>
            <tr>
              <td><?= h($it['product_name']) ?><?php if (!empty($it['variant_label'])): ?><br><span style="color:var(--text-muted);font-size:.82rem"><?= h($it['variant_label']) ?></span><?php endif; ?></td>
              <td><?= (int)$it['quantity'] ?></td>
              <td><?= money_ars($it['unit_price']) ?></td>
              <td><?= money_ars($it['line_total']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p style="margin-top:1rem">Subtotal productos: <?= money_ars($order['subtotal']) ?></p>
      <p>Envío: <?= $order['shipping_cost'] !== null ? money_ars($order['shipping_cost']) : ($order['shipping_method']==='retiro' ? 'Sin costo (retiro en el local)' : 'A cotizar') ?><?php if ($order['shipping_method'] === 'envio' && (float)($order['shipping_cost'] ?? -1) === 0.0): ?> <span style="color:var(--accent-2);font-weight:700">(envío gratis)</span><?php endif; ?></p>
      <p style="margin-top:.4rem;font-weight:700;font-size:1.1rem">Total: <?= money_ars($order['subtotal'] + (float)($order['shipping_cost'] ?? 0)) ?></p>
    </div>

    <?php if (!empty($order['mp_payment_id']) || !empty($order['mp_status']) || !empty($order['mp_preference_id'])): ?>
    <div class="admin-card">
      <h2>Mercado Pago</h2>
      <?php if (!empty($order['mp_payment_id'])): ?><p>ID de pago: <strong><?= h($order['mp_payment_id']) ?></strong></p><?php endif; ?>
      <?php if (!empty($order['mp_status'])): ?><p>Último estado informado: <strong><?= h($order['mp_status']) ?></strong></p><?php endif; ?>
      <p class="aform-hint">Este dato lo actualiza Mercado Pago automáticamente. El estado de pago que importa para el pedido es el de abajo ("Estado del pago"), que también podés corregir a mano si hace falta.</p>
    </div>
    <?php endif; ?>

    <div class="admin-card">
      <h2>Estado y pago</h2>
      <form method="post" action="pedidos.php?id=<?= (int)$order['id'] ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
        <input type="hidden" name="action" value="update">
        <div class="aform-row">
          <div class="aform-field">
            <label for="ord-status">Estado del pedido</label>
            <select id="ord-status" name="order_status">
              <?php foreach ($orderStatuses as $s): ?>
              <option value="<?= h($s) ?>" <?= $order['order_status']===$s?'selected':'' ?>><?= h(order_status_label($s)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="aform-field">
            <label for="pay-status">Método de pago</label>
            <input type="text" value="<?= h(payment_method_label($order['payment_method'])) ?>" disabled style="opacity:.7">
          </div>
        </div>
        <div class="aform-row">
          <div class="aform-field">
            <label for="pay-status-2">Estado del pago</label>
            <select id="pay-status-2" name="payment_status">
              <?php foreach ($paymentStatuses as $s): ?>
              <option value="<?= h($s) ?>" <?= $order['payment_status']===$s?'selected':'' ?>><?= h(payment_status_label($s)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="aform-field">
            <label for="ship-cost">Costo de envío (ARS)<?php if ($order['shipping_method']==='retiro'): ?> — no aplica, es retiro<?php endif; ?></label>
            <input id="ship-cost" name="shipping_cost" type="text" inputmode="decimal" placeholder="Cotizalo por WhatsApp y cargalo acá" value="<?= $order['shipping_cost'] !== null ? h((string)$order['shipping_cost']) : '' ?>">
          </div>
        </div>
        <div class="aform-field">
          <label for="admin-notes">Notas internas</label>
          <textarea id="admin-notes" name="admin_notes" rows="3"><?= h($order['admin_notes'] ?? '') ?></textarea>
        </div>
        <div class="aform-actions">
          <button class="abtn abtn-primary" type="submit"><?= admin_icon('check') ?> Guardar cambios</button>
        </div>
      </form>
    </div>
    <?php
    admin_close();
    exit;
}

// ---- Listado de pedidos ----
$statusFilter = $_GET['estado'] ?? '';
$sql = 'SELECT * FROM orders';
$params = [];
if (in_array($statusFilter, $orderStatuses, true)) { $sql .= ' WHERE order_status = ?'; $params[] = $statusFilter; }
$sql .= ' ORDER BY created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

admin_open($admin, 'pedidos', 'Pedidos', $flash, $flashType);
?>

<div class="admin-actions-bar">
  <form method="get" style="display:flex;gap:.6rem">
    <select name="estado" onchange="this.form.submit()" style="border:1.5px solid var(--border);padding:.55rem .8rem;border-radius:2px 8px 2px 8px;font-family:inherit">
      <option value="" <?= $statusFilter===''?'selected':'' ?>>Todos los estados</option>
      <?php foreach ($orderStatuses as $s): ?>
      <option value="<?= h($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= h(order_status_label($s)) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (!$orders): ?>
  <div class="admin-card"><p style="color:var(--text-muted)">Todavía no hay pedidos.</p></div>
<?php else: ?>
<div class="admin-table-wrap">
  <table class="admin-table">
    <thead><tr><th>N° pedido</th><th>Cliente</th><th>Entrega</th><th>Pago</th><th>Estado pago</th><th>Estado pedido</th><th>Total</th><th>Fecha</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
      <tr>
        <td><a href="pedidos.php?id=<?= (int)$o['id'] ?>" style="font-weight:700"><?= h($o['order_number']) ?></a></td>
        <td><?= h($o['customer_name']) ?></td>
        <td><?= h(shipping_method_label($o['shipping_method'])) ?></td>
        <td><?= h(payment_method_label($o['payment_method'])) ?></td>
        <td><span class="badge <?= $o['payment_status']==='confirmado'?'badge-on':'badge-off' ?>"><?= h(payment_status_label($o['payment_status'])) ?></span></td>
        <td><span class="badge <?= in_array($o['order_status'],['entregado'],true)?'badge-on':'badge-off' ?>"><?= h(order_status_label($o['order_status'])) ?></span></td>
        <td><?= money_ars($o['subtotal'] + (float)($o['shipping_cost'] ?? 0)) ?></td>
        <td><?= h(date('d/m/Y H:i', strtotime($o['created_at']))) ?></td>
        <td class="actions"><a class="abtn abtn-ghost abtn-icon" href="pedidos.php?id=<?= (int)$o['id'] ?>" aria-label="Ver"><?= admin_icon('edit') ?></a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php admin_close(); ?>
