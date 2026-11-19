<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/cart.php';
require __DIR__ . '/includes/mercadopago.php';

start_shop_session();

$items = cart_items($pdo);
if (!$items) {
    header('Location: carrito.php');
    exit;
}
$subtotal = 0.0;
foreach ($items as $item) { $subtotal += $item['line_total']; }

$errors = [];
$formData = ['name' => '', 'phone' => '', 'email' => '', 'address' => '', 'notes' => '', 'payment_method' => 'transferencia', 'shipping_method' => 'envio'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['name'] = trim($_POST['name'] ?? '');
    $formData['phone'] = trim($_POST['phone'] ?? '');
    $formData['email'] = trim($_POST['email'] ?? '');
    $formData['address'] = trim($_POST['address'] ?? '');
    $formData['notes'] = trim($_POST['notes'] ?? '');
    $formData['payment_method'] = $_POST['payment_method'] ?? '';
    $formData['shipping_method'] = $_POST['shipping_method'] ?? '';

    if ($formData['name'] === '') { $errors[] = 'Ingresá tu nombre y apellido.'; }
    if ($formData['phone'] === '') { $errors[] = 'Ingresá un teléfono de contacto.'; }
    if ($formData['email'] !== '' && !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El email no es válido. Podés dejarlo vacío si preferís.';
    }
    if (!in_array($formData['payment_method'], ['mercadopago', 'transferencia', 'efectivo'], true)) {
        $errors[] = 'Elegí una forma de pago.';
    }
    if (!in_array($formData['shipping_method'], ['envio', 'retiro'], true)) {
        $errors[] = 'Elegí cómo querés recibir tu pedido.';
    } elseif ($formData['shipping_method'] === 'envio' && $formData['address'] === '') {
        $errors[] = 'Ingresá la dirección de envío, o elegí retirar en el local.';
    }

    // Revalidamos el carrito por si algo cambió justo antes de confirmar.
    $items = cart_items($pdo);
    if (!$items) {
        header('Location: carrito.php');
        exit;
    }

    if (!$errors) {
        try {
            $order = create_order($pdo, $formData, $items, $formData['payment_method'], $formData['shipping_method']);
            $orderItemsForMp = $items; // antes de vaciar el carrito
            cart_clear();
            $_SESSION['last_order_number'] = $order['order_number'];
            $_SESSION['last_order_total'] = $order['subtotal'];
            $_SESSION['last_order_payment_method'] = $formData['payment_method'];
            $_SESSION['last_order_shipping_method'] = $formData['shipping_method'];

            // Si eligió Mercado Pago y el sitio ya tiene el Access Token
            // cargado (panel → Textos → Pagos y envíos), lo mandamos a
            // pagar directamente a Mercado Pago. Si todavía no está
            // configurado, o Mercado Pago no responde por cualquier
            // motivo, seguimos con el flujo de siempre (WhatsApp) en vez
            // de romper el checkout.
            if ($formData['payment_method'] === 'mercadopago' && mp_configured($pdo)) {
                try {
                    $mpResult = mp_create_preference($pdo, $order, $orderItemsForMp, $formData);
                    if ($mpResult['ok']) {
                        header('Location: ' . $mpResult['redirect_url']);
                        exit;
                    }
                } catch (Throwable $e) {
                    // seguimos abajo con el flujo de respaldo
                }
            }

            header('Location: pedido-confirmado.php');
            exit;
        } catch (RuntimeException $e) {
            // Mensajes esperados y seguros de mostrar (ej: sin stock suficiente).
            $errors[] = $e->getMessage();
            $items = cart_items($pdo); // puede haber cambiado (ítems sin stock se auto-limpian)
            $subtotal = 0.0;
            foreach ($items as $item) { $subtotal += $item['line_total']; }
            if (!$items) { header('Location: carrito.php'); exit; }
        } catch (Throwable $e) {
            $errors[] = 'No pudimos registrar el pedido. Probá de nuevo en unos minutos.';
        }
    }
}

$shippingNote = setting($pdo, 'shipping_note');
$pickupAddress = setting($pdo, 'pickup_address');

$pageTitle = 'Finalizar compra — ' . setting($pdo, 'site_title', 'Romantiza');
$activeNav = 'tienda';
$noIndex = true;
require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <p class="crumb"><a href="index.php">Inicio</a> / <a href="carrito.php">Carrito</a> / Finalizar compra</p>
  <div class="pad" style="padding-bottom:1rem">
    <h1>Finalizar compra</h1>
  </div>

  <?php if ($errors): ?>
  <div class="checkout-errors">
    <?php foreach ($errors as $e): ?><p><?= h($e) ?></p><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="checkout-layout" style="padding-bottom:5.5rem">
    <form method="post" action="checkout.php" class="checkout-form">
      <div class="contact-card">
        <h3>¿Cómo querés recibirlo?</h3>
        <div class="payment-options">
          <label class="payment-option">
            <input type="radio" name="shipping_method" value="envio" <?= $formData['shipping_method']==='envio'?'checked':'' ?>>
            <span class="payment-option-label">
              <strong>Envío a domicilio</strong>
              <span><?= h($shippingNote) ?></span>
            </span>
          </label>
          <label class="payment-option">
            <input type="radio" name="shipping_method" value="retiro" <?= $formData['shipping_method']==='retiro'?'checked':'' ?>>
            <span class="payment-option-label">
              <strong>Retiro en el local</strong>
              <span><?= h($pickupAddress) ?></span>
            </span>
          </label>
        </div>
      </div>

      <div class="contact-card">
        <h3>Tus datos</h3>
        <div class="form-field">
          <label for="ck-name">Nombre y apellido</label>
          <input id="ck-name" name="name" required value="<?= h($formData['name']) ?>">
        </div>
        <div class="form-field">
          <label for="ck-phone">Teléfono (WhatsApp)</label>
          <input id="ck-phone" name="phone" type="tel" required placeholder="Ej: 3644 550258" value="<?= h($formData['phone']) ?>">
        </div>
        <div class="form-field">
          <label for="ck-email">Email (opcional)</label>
          <input id="ck-email" name="email" type="email" value="<?= h($formData['email']) ?>">
        </div>
        <div class="form-field">
          <label for="ck-address">Dirección de envío</label>
          <input id="ck-address" name="address" placeholder="Calle, número, localidad" value="<?= h($formData['address']) ?>">
          <p class="aform-hint" style="margin:.4rem 0 0">Necesaria si elegís envío a domicilio. Si vas a retirar en el local, podés dejarla vacía.</p>
        </div>
        <div class="form-field">
          <label for="ck-notes">Notas del pedido (opcional)</label>
          <textarea id="ck-notes" name="notes" rows="3"><?= h($formData['notes']) ?></textarea>
        </div>
      </div>

      <div class="contact-card">
        <h3>Forma de pago</h3>
        <div class="payment-options">
          <label class="payment-option">
            <input type="radio" name="payment_method" value="mercadopago" <?= $formData['payment_method']==='mercadopago'?'checked':'' ?>>
            <span class="payment-option-label">
              <strong>Mercado Pago</strong>
              <span>Te contactamos por WhatsApp para enviarte el link de pago.</span>
            </span>
          </label>
          <label class="payment-option">
            <input type="radio" name="payment_method" value="transferencia" <?= $formData['payment_method']==='transferencia'?'checked':'' ?>>
            <span class="payment-option-label">
              <strong>Transferencia bancaria</strong>
              <span>Te pasamos los datos para transferir al confirmar el pedido.</span>
            </span>
          </label>
          <label class="payment-option">
            <input type="radio" name="payment_method" value="efectivo" <?= $formData['payment_method']==='efectivo'?'checked':'' ?>>
            <span class="payment-option-label">
              <strong>Efectivo</strong>
              <span>Contra entrega o a coordinar por WhatsApp.</span>
            </span>
          </label>
        </div>
      </div>

      <?= trust_badges_html($pdo) ?>

      <button class="btn btn-primary btn-block" type="submit">Confirmar pedido</button>
    </form>

    <div class="cart-summary">
      <h2>Tu pedido</h2>
      <?= free_shipping_banner_html($pdo, $subtotal) ?>
      <?php foreach ($items as $item): ?>
      <div class="cart-summary-item">
        <span><?= (int)$item['qty'] ?>&times; <?= h($item['product']['name']) ?><?php if ($item['variant']['label']): ?> — <?= h($item['variant']['label']) ?><?php endif; ?></span>
        <span><?= money_ars($item['line_total']) ?></span>
      </div>
      <?php endforeach; ?>
      <div class="cart-summary-row cart-summary-total"><span>Total</span><span><?= money_ars($subtotal) ?></span></div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
