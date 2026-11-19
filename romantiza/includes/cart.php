<?php
if (!defined('ROMANTIZA_APP')) { http_response_code(403); exit('Acceso directo no permitido.'); }

/**
 * Carrito de compras del sitio público.
 * Usa su PROPIA sesión (romantiza_shop_sid), separada de la sesión del
 * panel de administración (romantiza_admin_sid), para que nunca se
 * mezclen ni se pisen entre sí.
 *
 * El carrito se guarda en $_SESSION['cart'] como [ variant_id => cantidad ].
 * Cada producto tiene una o más variantes (medida/opción con su propio
 * precio) — el carrito siempre compra una variante puntual, nunca el
 * producto "en general", para que el precio sea siempre exacto.
 */

/** Inicia (una sola vez por request) la sesión de compras. */
function start_shop_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('romantiza_shop_sid');
    session_start();
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
}

/** Agrega una variante al carrito (suma cantidad si ya estaba). */
function cart_add(int $variantId, int $qty = 1): void {
    start_shop_session();
    if ($variantId <= 0) return;
    $qty = max(1, min(99, $qty));
    $current = $_SESSION['cart'][$variantId] ?? 0;
    $_SESSION['cart'][$variantId] = max(1, min(99, $current + $qty));
}

/** Fija la cantidad exacta de una variante (la quita si es 0 o menos). */
function cart_set_qty(int $variantId, int $qty): void {
    start_shop_session();
    if ($qty <= 0) {
        unset($_SESSION['cart'][$variantId]);
        return;
    }
    $_SESSION['cart'][$variantId] = max(1, min(99, $qty));
}

/** Quita una variante del carrito. */
function cart_remove(int $variantId): void {
    start_shop_session();
    unset($_SESSION['cart'][$variantId]);
}

/** Vacía el carrito por completo. */
function cart_clear(): void {
    start_shop_session();
    $_SESSION['cart'] = [];
}

/** Cantidad total de unidades en el carrito (rápido, sin consultar la base). */
function cart_raw_count(): int {
    start_shop_session();
    $total = 0;
    foreach ($_SESSION['cart'] as $qty) { $total += (int)$qty; }
    return $total;
}

/**
 * Devuelve los ítems del carrito ya validados contra la base de datos:
 * variante + producto + imagen, con el precio actual de la variante.
 * Si una variante o su producto fue eliminado o desactivado, se saca
 * sola del carrito (auto-limpieza).
 * Cada ítem: ['product' => array, 'variant' => array, 'qty' => int, 'line_total' => float]
 */
function cart_items(PDO $pdo): array {
    start_shop_session();
    if (empty($_SESSION['cart'])) return [];

    $ids = array_map('intval', array_keys($_SESSION['cart']));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT v.*, p.name AS product_name, p.slug AS product_slug, p.image AS product_image, p.active AS product_active
         FROM product_variants v
         JOIN products p ON p.id = v.product_id
         WHERE v.id IN ($placeholders) AND v.active = 1 AND p.active = 1"
    );
    $stmt->execute($ids);
    $variants = [];
    foreach ($stmt->fetchAll() as $row) {
        $variants[(int)$row['id']] = $row;
    }

    $items = [];
    foreach ($_SESSION['cart'] as $vid => $qty) {
        $vid = (int)$vid;
        if (!isset($variants[$vid])) {
            unset($_SESSION['cart'][$vid]); // variante ya no disponible: se saca sola
            continue;
        }
        $variant = $variants[$vid];
        if (!variant_in_stock($variant)) {
            unset($_SESSION['cart'][$vid]); // sin stock: se saca sola del carrito
            continue;
        }
        $qty = max(1, min(99, (int)$qty));
        $stockLimited = false;
        if ($variant['stock'] !== null && $qty > (int)$variant['stock']) {
            $qty = (int)$variant['stock'];
            $stockLimited = true;
            $_SESSION['cart'][$vid] = $qty; // persiste el ajuste
        }
        $lineTotal = (float)$variant['price'] * $qty;
        $items[] = [
            'stock_limited' => $stockLimited,
            'product' => [
                'id' => (int)$variant['product_id'],
                'name' => $variant['product_name'],
                'slug' => $variant['product_slug'],
                'image' => $variant['product_image'],
            ],
            'variant' => [
                'id' => (int)$variant['id'],
                'label' => $variant['label'],
                'price' => (float)$variant['price'],
            ],
            'qty' => $qty,
            'line_total' => $lineTotal,
        ];
    }
    return $items;
}

/** Subtotal del carrito (suma de todos los ítems ya validados). */
function cart_subtotal(PDO $pdo): float {
    $total = 0.0;
    foreach (cart_items($pdo) as $item) {
        $total += $item['line_total'];
    }
    return $total;
}
