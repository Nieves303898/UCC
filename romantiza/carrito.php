<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/cart.php';

start_shop_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'buy_now') {
        $variantId = (int)($_POST['variant_id'] ?? 0);
        $qty = max(1, min(99, (int)($_POST['qty'] ?? 1)));
        cart_add($variantId, $qty);
        if ($action === 'buy_now') {
            header('Location: checkout.php');
            exit;
        }
        $_SESSION['cart_flash'] = 'Se agregó el producto al carrito.';
        header('Location: carrito.php');
        exit;
    }

    if ($action === 'set_qty') {
        $variantId = (int)($_POST['variant_id'] ?? 0);
        $qty = (int)($_POST['qty'] ?? 1);
        cart_set_qty($variantId, $qty);
        header('Location: carrito.php');
        exit;
    }

    if ($action === 'remove') {
        $variantId = (int)($_POST['variant_id'] ?? 0);
        cart_remove($variantId);
        header('Location: carrito.php');
        exit;
    }

    if ($action === 'clear') {
        cart_clear();
        header('Location: carrito.php');
        exit;
    }

    // Acción no reconocida: no hacemos nada, mostramos el carrito igual.
    header('Location: carrito.php');
    exit;
}

$items = cart_items($pdo);
$subtotal = 0.0;
foreach ($items as $item) { $subtotal += $item['line_total']; }

$flash = $_SESSION['cart_flash'] ?? null;
unset($_SESSION['cart_flash']);

$pageTitle = 'Tu carrito — ' . setting($pdo, 'site_title', 'Romantiza');
$activeNav = 'tienda';
$noIndex = true;
require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <p class="crumb"><a href="index.php">Inicio</a> / Carrito</p>
  <div class="pad" style="padding-bottom:1rem">
    <h1>Tu carrito</h1>
  </div>

  <?php if ($flash): ?>
  <p class="cart-flash"><?= h($flash) ?></p>
  <?php endif; ?>

  <?php if (!$items): ?>
  <div class="cart-empty" style="padding-bottom:5.5rem">
    <p>Todavía no agregaste productos.</p>
    <a class="btn btn-primary" href="tienda.php">Ver la tienda</a>
  </div>
  <?php else: ?>
  <div class="cart-layout" style="padding-bottom:5.5rem">
    <div class="cart-list">
      <?php foreach ($items as $item):
        $p = $item['product'];
        $v = $item['variant'];
        $img = product_image_url($p['image']);
      ?>
      <div class="cart-row">
        <a href="producto.php?slug=<?= h($p['slug']) ?>" class="cart-row-media">
          <div class="ph">
            <?php if ($img): ?><img src="<?= h($img) ?>" alt="<?= h($p['name']) ?>">
            <?php else: ?><svg class="motif" style="width:40%"><use href="#mot-bloom"/></svg><?php endif; ?>
          </div>
        </a>
        <div class="cart-row-body">
          <a href="producto.php?slug=<?= h($p['slug']) ?>" class="cart-row-title"><?= h($p['name']) ?></a>
          <?php if ($v['label']): ?><p class="cart-row-variant" style="font-size:.82rem;color:var(--text-muted);margin:.15rem 0 0"><?= h($v['label']) ?></p><?php endif; ?>
          <p class="cart-row-price"><?= money_ars($v['price']) ?> c/u</p>
          <?php if (!empty($item['stock_limited'])): ?><p style="font-size:.8rem;color:var(--accent);margin:.2rem 0 0">Ajustamos la cantidad: solo queda<?= (int)$item['qty']===1?'':'n' ?> <?= (int)$item['qty'] ?> disponible<?= (int)$item['qty']===1?'':'s' ?>.</p><?php endif; ?>
          <div class="cart-row-actions">
            <form method="post" action="carrito.php" class="cart-qty-form">
              <input type="hidden" name="action" value="set_qty">
              <input type="hidden" name="variant_id" value="<?= (int)$v['id'] ?>">
              <label class="visually-hidden" for="qty-<?= (int)$v['id'] ?>">Cantidad</label>
              <input id="qty-<?= (int)$v['id'] ?>" name="qty" type="number" inputmode="numeric" min="1" max="99" value="<?= (int)$item['qty'] ?>">
              <button class="btn btn-ghost btn-sm" type="submit">Actualizar</button>
            </form>
            <form method="post" action="carrito.php">
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="variant_id" value="<?= (int)$v['id'] ?>">
              <button class="cart-remove-btn" type="submit">Quitar</button>
            </form>
          </div>
        </div>
        <p class="cart-row-total"><?= money_ars($item['line_total']) ?></p>
      </div>
      <?php endforeach; ?>

      <form method="post" action="carrito.php" style="margin-top:.5rem">
        <input type="hidden" name="action" value="clear">
        <button class="cart-remove-btn" type="submit">Vaciar carrito</button>
      </form>
    </div>

    <div class="cart-summary">
      <h2>Resumen</h2>
      <?= free_shipping_banner_html($pdo, $subtotal) ?>
      <div class="cart-summary-row"><span>Subtotal</span><span><?= money_ars($subtotal) ?></span></div>
      <p class="aform-hint">El envío se coordina por WhatsApp una vez confirmado el pedido.</p>
      <a class="btn btn-primary btn-block" href="checkout.php">Finalizar compra</a>
      <a class="btn btn-ghost btn-block" href="tienda.php" style="margin-top:.7rem">Seguir comprando</a>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
