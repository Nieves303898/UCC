<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/admin_ui.php';

$admin = require_login($pdo);

$productCount = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$activeCount = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE active = 1')->fetchColumn();
$unreadCount = (int)$pdo->query('SELECT COUNT(*) FROM messages WHERE read_flag = 0')->fetchColumn();
$adminCount = (int)$pdo->query('SELECT COUNT(*) FROM admins WHERE active = 1')->fetchColumn();
$newOrdersCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'nuevo'")->fetchColumn();
$pendingPaymentCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'pendiente'")->fetchColumn();

$recentMessages = $pdo->query('SELECT * FROM messages ORDER BY created_at DESC LIMIT 5')->fetchAll();
$recentProducts = $pdo->query('SELECT p.*, c.name AS cat_name,
    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.active = 1) AS variant_count
    FROM products p JOIN categories c ON c.id = p.category_id ORDER BY p.updated_at DESC LIMIT 5')->fetchAll();
$recentOrders = $pdo->query('SELECT * FROM orders ORDER BY created_at DESC LIMIT 5')->fetchAll();

admin_open($admin, 'dashboard', 'Panel');
?>

<p style="color:var(--text-muted);margin-bottom:1.8rem">Hola, <?= h($admin['full_name']) ?> &mdash; esto es lo que está pasando en el sitio.</p>

<div class="admin-grid-stats">
  <div class="stat-tile"><div class="num"><?= $newOrdersCount ?></div><div class="label">Pedidos nuevos</div></div>
  <div class="stat-tile"><div class="num"><?= $pendingPaymentCount ?></div><div class="label">Pagos pendientes</div></div>
  <div class="stat-tile"><div class="num"><?= $activeCount ?></div><div class="label">Productos visibles en el sitio</div></div>
  <div class="stat-tile"><div class="num"><?= $unreadCount ?></div><div class="label">Mensajes sin leer</div></div>
  <?php if ($admin['role'] === 'superadmin'): ?>
  <div class="stat-tile"><div class="num"><?= $adminCount ?></div><div class="label">Administradores activos</div></div>
  <?php endif; ?>
</div>

<div class="admin-card">
  <h2>Accesos rápidos</h2>
  <div style="display:flex;gap:.8rem;flex-wrap:wrap">
    <a class="abtn abtn-primary" href="pedidos.php"><?= admin_icon('cart') ?> Ver pedidos</a>
    <a class="abtn abtn-ghost" href="producto_form.php"><?= admin_icon('plus') ?> Cargar producto</a>
    <a class="abtn abtn-ghost" href="textos.php"><?= admin_icon('type') ?> Editar textos del sitio</a>
    <a class="abtn abtn-ghost" href="mensajes.php"><?= admin_icon('mail') ?> Ver mensajes</a>
  </div>
</div>

<div class="admin-card">
  <h2>Últimos pedidos</h2>
  <?php if (!$recentOrders): ?>
    <p style="color:var(--text-muted);font-size:.9rem">Todavía no hay pedidos.</p>
  <?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>N° pedido</th><th>Cliente</th><th>Total</th><th>Estado</th><th>Fecha</th></tr></thead>
      <tbody>
        <?php foreach ($recentOrders as $o): ?>
        <tr>
          <td><a href="pedidos.php?id=<?= (int)$o['id'] ?>" style="font-weight:700"><?= h($o['order_number']) ?></a></td>
          <td><?= h($o['customer_name']) ?></td>
          <td><?= money_ars($o['subtotal'] + (float)($o['shipping_cost'] ?? 0)) ?></td>
          <td><span class="badge <?= $o['order_status']==='entregado'?'badge-on':'badge-off' ?>"><?= h(order_status_label($o['order_status'])) ?></span></td>
          <td><?= h(date('d/m/Y H:i', strtotime($o['created_at']))) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p style="margin-top:1rem"><a class="abtn abtn-ghost" href="pedidos.php">Ver todos los pedidos</a></p>
  <?php endif; ?>
</div>

<div class="admin-card">
  <h2>Últimos productos modificados</h2>
  <?php if (!$recentProducts): ?>
    <p style="color:var(--text-muted);font-size:.9rem">Todavía no cargaste productos. <a href="producto_form.php">Cargá el primero</a>.</p>
  <?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Producto</th><th>Categoría</th><th>Precio</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($recentProducts as $p): ?>
        <tr>
          <td><?= h($p['name']) ?></td>
          <td><?= h($p['cat_name']) ?></td>
          <td><?= card_price_label($p['price'], (int)$p['variant_count']) ?></td>
          <td><span class="badge <?= $p['active']?'badge-on':'badge-off' ?>"><?= $p['active']?'Visible':'Oculto' ?></span></td>
          <td class="actions"><a class="abtn abtn-ghost abtn-icon" href="producto_form.php?id=<?= (int)$p['id'] ?>" aria-label="Editar"><?= admin_icon('edit') ?></a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="admin-card">
  <h2>Últimos mensajes</h2>
  <?php if (!$recentMessages): ?>
    <p style="color:var(--text-muted);font-size:.9rem">Todavía no llegaron mensajes.</p>
  <?php else: ?>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Tipo</th><th>Nombre</th><th>Email</th><th>Fecha</th></tr></thead>
      <tbody>
        <?php foreach ($recentMessages as $m): ?>
        <tr>
          <td><?= $m['type']==='contacto'?'Contacto':'Newsletter' ?></td>
          <td><?= h($m['name'] ?: '—') ?></td>
          <td><?= h($m['email']) ?></td>
          <td><?= h(date('d/m/Y H:i', strtotime($m['created_at']))) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p style="margin-top:1rem"><a class="abtn abtn-ghost" href="mensajes.php">Ver todos los mensajes</a></p>
  <?php endif; ?>
</div>

<?php admin_close(); ?>
