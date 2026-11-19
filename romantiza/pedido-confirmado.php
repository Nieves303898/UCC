<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/cart.php';
require __DIR__ . '/includes/mercadopago.php';

start_shop_session();

// Leemos el pedido desde la SESIÓN (no desde la URL), para que nadie
// pueda ver la confirmación de otra persona cambiando un número en el link.
$orderNumber = $_SESSION['last_order_number'] ?? null;
$orderTotal = $_SESSION['last_order_total'] ?? null;
$paymentMethod = $_SESSION['last_order_payment_method'] ?? null;
$shippingMethod = $_SESSION['last_order_shipping_method'] ?? null;

if (!$orderNumber) {
    header('Location: index.php');
    exit;
}

// Se muestra una sola vez: al recargar la página ya no se repite el pedido,
// pero mantenemos el dato mientras el visitante esté en esta pantalla.
unset($_SESSION['last_order_number'], $_SESSION['last_order_total'], $_SESSION['last_order_payment_method'], $_SESSION['last_order_shipping_method']);

// Si el comprador vuelve desde Mercado Pago, estos parámetros vienen
// pegados en la URL (los agrega Mercado Pago solo, no hace falta armarlos
// nosotros). Aprovechamos para reflejar el resultado al toque y, si
// podemos, refrescar el estado del pedido sin esperar al webhook.
$mpStatus = $_GET['collection_status'] ?? $_GET['status'] ?? null;
if ($paymentMethod === 'mercadopago' && $mpStatus) {
    $mpPaymentId = $_GET['collection_id'] ?? $_GET['payment_id'] ?? null;
    if ($mpPaymentId) {
        try {
            $payment = mp_get_payment($pdo, (string)$mpPaymentId);
            if ($payment) { mp_update_order_from_payment($pdo, $payment); }
        } catch (Throwable $e) {
            // si falla, igual mostramos el estado que informó Mercado Pago en la URL
        }
    }
}

$waNumber = setting($pdo, 'contact_whatsapp', '');
$waText = rawurlencode("Hola! Quería confirmar mi pedido $orderNumber de Romantiza.");
$transferInfo = setting($pdo, 'payment_transfer_info', '');
$pickupAddress = setting($pdo, 'pickup_address');

$pageTitle = 'Pedido confirmado — ' . setting($pdo, 'site_title', 'Romantiza');
$activeNav = 'tienda';
$noIndex = true;
require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div class="pad order-confirm" style="padding-bottom:5.5rem">
    <div class="order-confirm-icon"><svg style="width:40px;height:40px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 12l5 5L20 6"/></svg></div>
    <h1>¡Gracias! Tu pedido fue recibido</h1>
    <p class="lead">Número de pedido: <strong><?= h($orderNumber) ?></strong></p>
    <?php if ($orderTotal !== null): ?><p class="lead">Total: <strong><?= money_ars($orderTotal) ?></strong></p><?php endif; ?>

    <div class="order-confirm-card">
      <?php if ($shippingMethod === 'retiro'): ?>
        <h3>Retiro en el local</h3>
        <p><?= h($pickupAddress) ?></p>
      <?php elseif ($shippingMethod === 'envio'): ?>
        <h3>Envío a domicilio</h3>
        <p>Te contactamos por WhatsApp para coordinar el costo y el tiempo de envío según tu zona.</p>
      <?php endif; ?>
    </div>

    <div class="order-confirm-card">
      <?php if ($paymentMethod === 'transferencia'): ?>
        <h3>Datos para transferir</h3>
        <p style="white-space:pre-line"><?= h($transferInfo) ?></p>
        <p class="aform-hint">Enviá el comprobante por WhatsApp mencionando el número de pedido.</p>
      <?php elseif ($paymentMethod === 'mercadopago'): ?>
        <h3>Pago con Mercado Pago</h3>
        <?php if ($mpStatus === 'approved'): ?>
          <p>¡Tu pago fue aprobado! Ya estamos preparando tu pedido.</p>
        <?php elseif ($mpStatus === 'pending' || $mpStatus === 'in_process'): ?>
          <p>Tu pago quedó pendiente de acreditación. Te avisamos por WhatsApp apenas se confirme.</p>
        <?php elseif ($mpStatus === 'rejected' || $mpStatus === 'failure'): ?>
          <p>El pago no pudo procesarse. Podés volver a intentarlo desde tu carrito o escribirnos por WhatsApp para coordinar otra forma de pago.</p>
        <?php else: ?>
          <p>En breve te contactamos por WhatsApp con el link de pago de Mercado Pago para completar la compra.</p>
        <?php endif; ?>
      <?php elseif ($paymentMethod === 'efectivo'): ?>
        <h3>Pago en efectivo</h3>
        <p>Coordinamos por WhatsApp el pago contra entrega o el punto de encuentro.</p>
      <?php endif; ?>
    </div>

    <?php if ($waNumber): ?>
    <a class="btn btn-primary" href="https://wa.me/<?= h($waNumber) ?>?text=<?= $waText ?>" target="_blank" rel="noopener">Escribinos por WhatsApp</a>
    <?php endif; ?>
    <a class="btn btn-ghost" href="tienda.php" style="margin-left:.8rem">Seguir comprando</a>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
