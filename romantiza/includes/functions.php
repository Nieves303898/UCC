<?php
if (!defined('ROMANTIZA_APP')) { http_response_code(403); exit('Acceso directo no permitido.'); }

/**
 * Devuelve la fecha de modificación de un archivo (para usar como
 * ?v=... en CSS/JS y así evitar que el navegador o el hosting sirvan
 * una versión vieja en caché después de actualizar el sitio).
 */
function asset_version(string $absolutePath): int {
    $mtime = @filemtime($absolutePath);
    return $mtime ?: 1;
}

/** Escapa texto para imprimir seguro en HTML (evita XSS). */
function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Formatea un precio en pesos argentinos: 22900 -> "$22.900" */
function money_ars($value): string {
    return '$' . number_format((float)$value, 0, ',', '.');
}

/** Precio para mostrar en una tarjeta de producto: "$X" o "Desde $X" si tiene más de una variante. */
function card_price_label($price, int $variantCount): string {
    return ($variantCount > 1 ? 'Desde ' : '') . money_ars($price);
}

/** Devuelve todos los settings como array clave => valor (con caché simple por request). */
function get_all_settings(PDO $pdo): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache;
}

/** Devuelve un setting puntual, con valor por defecto si no existe. */
function setting(PDO $pdo, string $key, string $default = ''): string {
    $all = get_all_settings($pdo);
    return $all[$key] ?? $default;
}

/**
 * HTML de la fila de "sellos de confianza" (Compra segura, envío,
 * cambios, pago protegido). Los 4 textos son editables desde el panel
 * (Textos del sitio → Compra segura); el ícono de cada uno es fijo.
 * Si se deja un texto vacío, ese sello simplemente no se muestra.
 */
function trust_badges_html(PDO $pdo): string {
    $badges = [
        ['icon' => 'ic-shield', 'key' => 'trust_badge_1', 'default' => 'Compra 100% segura'],
        ['icon' => 'ic-truck',  'key' => 'trust_badge_2', 'default' => 'Envío a todo el país'],
        ['icon' => 'ic-repeat', 'key' => 'trust_badge_3', 'default' => 'Cambios sin cargo'],
        ['icon' => 'ic-lock',   'key' => 'trust_badge_4', 'default' => 'Pago protegido'],
    ];
    $items = '';
    foreach ($badges as $b) {
        $text = trim(setting($pdo, $b['key'], $b['default']));
        if ($text === '') { continue; }
        $items .= '<div><svg><use href="#' . $b['icon'] . '"/></svg>' . h($text) . '</div>';
    }
    return $items === '' ? '' : '<div class="pd-trust">' . $items . '</div>';
}

/**
 * Monto configurado para envío gratis (panel → Textos → Pagos y
 * envíos). Devuelve 0.0 si está vacío, en 0, o con un valor inválido
 * (la función queda desactivada, no rompe nada).
 */
function free_shipping_threshold(PDO $pdo): float {
    $value = (float) setting($pdo, 'free_shipping_threshold', '');
    return $value > 0 ? $value : 0.0;
}

/**
 * HTML del cartel de envío gratis para el carrito/checkout, según el
 * subtotal actual. Devuelve '' si la función está desactivada. Si el
 * pedido ya llega al monto, muestra un cartel de "conseguido"; si no,
 * cuánto falta.
 */
function free_shipping_banner_html(PDO $pdo, float $subtotal): string {
    $threshold = free_shipping_threshold($pdo);
    if ($threshold <= 0) { return ''; }
    if ($subtotal >= $threshold) {
        return '<p class="cart-flash" style="margin:0 0 1.2rem">✓ ¡Tenés envío gratis en este pedido!</p>';
    }
    $missing = $threshold - $subtotal;
    return '<p class="shipping-progress">Te faltan ' . money_ars($missing) . ' para el envío gratis.</p>';
}

/**
 * HTML del popup de captación de WhatsApp (para juntar contactos en
 * campañas, por ejemplo el Día de la Madre). Se arma desde el panel
 * → Textos del sitio → "Popup de WhatsApp" y se prende/apaga con un
 * check (por defecto está apagado). No se muestra en carrito,
 * checkout ni en la confirmación del pedido, para no interrumpir una
 * compra en curso, ni si falta el WhatsApp del negocio o el título.
 * Si la migración todavía no se corrió, get_all_settings() ya
 * devuelve algo vacío para estas claves, así que simplemente no se
 * muestra: no rompe nada.
 */
function whatsapp_popup_html(PDO $pdo, string $currentPage): string {
    if (!setting($pdo, 'wa_popup_enabled', '')) { return ''; }
    $skip = ['carrito.php', 'checkout.php', 'pedido-confirmado.php'];
    if (in_array($currentPage, $skip, true)) { return ''; }
    $waNumber = setting($pdo, 'contact_whatsapp', '');
    if ($waNumber === '') { return ''; }
    $title = trim(setting($pdo, 'wa_popup_title', ''));
    if ($title === '') { return ''; }
    $text = setting($pdo, 'wa_popup_text', '');
    $buttonText = setting($pdo, 'wa_popup_button_text', '') ?: 'Quiero mi regalo';

    ob_start();
    ?>
    <div id="waPopup" class="wa-popup" hidden>
      <button type="button" class="wa-popup-close" id="waPopupClose" aria-label="Cerrar">&times;</button>
      <div class="wa-popup-body">
        <strong><?= h($title) ?></strong>
        <?php if ($text !== ''): ?><p><?= h($text) ?></p><?php endif; ?>
        <form method="post" action="whatsapp_lead.php">
          <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="wa-popup-hp" aria-hidden="true">
          <input type="text" name="name" placeholder="Tu nombre (opcional)">
          <input type="tel" name="whatsapp" placeholder="Tu WhatsApp, con código de área" required inputmode="tel">
          <button type="submit" class="btn btn-primary btn-block"><?= h($buttonText) ?></button>
        </form>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

/** Genera un slug simple a partir de un texto (para URLs de producto). */
function slugify(string $text): string {
    $text = trim($text);
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    return $text !== '' ? $text : 'producto';
}

/** Asegura que un slug de producto sea único, agregando -2, -3, etc. si hace falta. */
function unique_product_slug(PDO $pdo, string $base, ?int $excludeId = null): string {
    $slug = slugify($base);
    $i = 2;
    while (true) {
        $sql = 'SELECT id FROM products WHERE slug = ?' . ($excludeId ? ' AND id <> ?' : '');
        $params = $excludeId ? [$slug, $excludeId] : [$slug];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) return $slug;
        $slug = slugify($base) . '-' . $i;
        $i++;
    }
}

/** Asegura que un slug de línea/categoría sea único, agregando -2, -3, etc. si hace falta. */
function unique_category_slug(PDO $pdo, string $base, ?int $excludeId = null): string {
    $slug = slugify($base);
    $i = 2;
    while (true) {
        $sql = 'SELECT id FROM categories WHERE slug = ?' . ($excludeId ? ' AND id <> ?' : '');
        $params = $excludeId ? [$slug, $excludeId] : [$slug];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) return $slug;
        $slug = slugify($base) . '-' . $i;
        $i++;
    }
}

/**
 * Procesa la subida de una imagen a una subcarpeta de uploads/.
 * Devuelve el nombre de archivo guardado, o null si no se subió nada,
 * o lanza una excepción con un mensaje claro si algo está mal.
 */
function handle_site_image_upload(array $file, string $subdir): ?string {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // no se seleccionó ningún archivo, está bien
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error al subir la imagen (código ' . $file['error'] . ').');
    }
    // El panel ya optimiza (achica) las fotos en el navegador antes de
    // subirlas, así que casi nunca deberían llegar acá pesando mucho.
    // Este límite queda como red de seguridad para cuando eso no pasa
    // (navegador viejo, foto ya muy grande, etc.).
    $maxBytes = 12 * 1024 * 1024; // 12 MB
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('La imagen pesa demasiado (máximo 12 MB), incluso después de optimizarla. Probá con otra foto.');
    }
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Formato de imagen no permitido. Usá JPG, PNG o WEBP.');
    }
    $ext = $allowed[$mime];
    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    $destDir = __DIR__ . '/../uploads/' . $subdir . '/';
    if (!is_dir($destDir)) { mkdir($destDir, 0755, true); }
    $dest = $destDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('No se pudo guardar la imagen en el servidor.');
    }
    return $filename;
}

/** Procesa la subida de una imagen de producto (atajo de handle_site_image_upload). */
function handle_product_image_upload(array $file): ?string {
    return handle_site_image_upload($file, 'productos');
}

/**
 * Promueve una foto de la galería de un producto (tabla product_images)
 * a foto de portada (products.image). La portada anterior no se pierde:
 * pasa a formar parte de la galería, al final de todo. Se usa tanto
 * desde el botón "Usar como portada" como desde la elección de portada
 * al agregar varias fotos juntas.
 */
function promote_gallery_image_to_cover(PDO $pdo, int $productId, string $oldCoverImage, int $galleryImageId, string $galleryImageFile): void {
    $pdo->prepare('UPDATE products SET image = ? WHERE id = ?')->execute([$galleryImageFile, $productId]);
    $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$galleryImageId]);
    if ($oldCoverImage !== '') {
        $maxOrderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM product_images WHERE product_id = ?');
        $maxOrderStmt->execute([$productId]);
        $nextOrder = (int)$maxOrderStmt->fetchColumn() + 1;
        $pdo->prepare('INSERT INTO product_images (product_id, image, sort_order) VALUES (?, ?, ?)')->execute([$productId, $oldCoverImage, $nextOrder]);
    }
}

/** URL pública de un archivo dentro de una subcarpeta de uploads/, o null si no tiene. */
function media_url(?string $file, string $subdir): ?string {
    if (!$file) return null;
    return 'uploads/' . $subdir . '/' . rawurlencode($file);
}

/** URL pública de la imagen de un producto, o null si no tiene. */
function product_image_url(?string $image): ?string {
    return media_url($image, 'productos');
}

/** URL pública de la foto de una línea/categoría, o null si no tiene. */
function category_image_url(?string $image): ?string {
    return media_url($image, 'lineas');
}

/** URL pública de una imagen de portada (slide o foto de esencia), o null si no tiene. */
function portada_image_url(?string $image): ?string {
    return media_url($image, 'portada');
}

/**
 * Devuelve el HTML embebido de un video/publicación de Instagram a
 * partir de su link (reel o post). Si el link está vacío, devuelve ''.
 * Requiere el script de Instagram (incluido una sola vez en el footer).
 */
function instagram_embed_html(string $url, string $maxWidth = '540px'): string {
    $url = trim($url);
    if ($url === '') return '';
    return '<blockquote class="instagram-media" data-instgrm-permalink="' . h($url) . '" data-instgrm-version="14" style="margin:0 auto;max-width:' . h($maxWidth) . ';width:100%;min-width:236px;"></blockquote>';
}

/** Guarda un mensaje de contacto o alta de newsletter. */
function save_message(PDO $pdo, string $type, string $name, string $email, string $message): void {
    $stmt = $pdo->prepare('INSERT INTO messages (type, name, email, message) VALUES (?,?,?,?)');
    $stmt->execute([$type, $name, $email, $message]);
}

/** Genera un número de pedido único y legible, ej: RM-260912-A3F1 */
function next_order_number(PDO $pdo): string {
    do {
        $number = 'RM-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $stmt = $pdo->prepare('SELECT id FROM orders WHERE order_number = ? LIMIT 1');
        $stmt->execute([$number]);
    } while ($stmt->fetch());
    return $number;
}

/** Etiqueta legible para el estado del pedido (envío/entrega). */
function order_status_label(string $status): string {
    $labels = [
        'nuevo' => 'Nuevo',
        'en_proceso' => 'En preparación',
        'enviado' => 'Enviado',
        'entregado' => 'Entregado',
        'cancelado' => 'Cancelado',
    ];
    return $labels[$status] ?? ucfirst($status);
}

/** Etiqueta legible para el estado del pago. */
function payment_status_label(string $status): string {
    $labels = [
        'pendiente' => 'Pendiente',
        'confirmado' => 'Confirmado',
        'cancelado' => 'Cancelado',
    ];
    return $labels[$status] ?? ucfirst($status);
}

/** Etiqueta legible para el método de pago. */
function payment_method_label(string $method): string {
    $labels = [
        'mercadopago' => 'Mercado Pago',
        'transferencia' => 'Transferencia bancaria',
        'efectivo' => 'Efectivo (contra entrega / a coordinar)',
    ];
    return $labels[$method] ?? ucfirst($method);
}

/** Etiqueta legible para el método de entrega. */
function shipping_method_label(string $method): string {
    $labels = [
        'envio' => 'Envío a domicilio',
        'retiro' => 'Retiro en el local',
    ];
    return $labels[$method] ?? ucfirst($method);
}

/**
 * True si una variante todavía se puede vender: stock NULL significa
 * que no se controla el stock (se vende sin límite, como siempre),
 * y un número significa que hay que tener unidades disponibles.
 */
function variant_in_stock(array $variant): bool {
    return $variant['stock'] === null || (int)$variant['stock'] > 0;
}

/** Texto corto para mostrar el stock de una variante en el admin. */
function variant_stock_label(array $variant): string {
    if ($variant['stock'] === null) return 'Ilimitado';
    $n = (int)$variant['stock'];
    return $n > 0 ? $n . ' unidad' . ($n === 1 ? '' : 'es') : 'Sin stock';
}

/**
 * Devuelve las variantes activas de un producto (medida/opción + precio),
 * ordenadas para mostrar. Se usa en la ficha de producto (selector) y
 * en el panel de administración.
 */
function product_variants(PDO $pdo, int $productId): array {
    $stmt = $pdo->prepare('SELECT * FROM product_variants WHERE product_id = ? AND active = 1 ORDER BY sort_order, id');
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}

/** Todas las variantes de un producto (activas e inactivas), para editarlas en el admin. */
function product_variants_all(PDO $pdo, int $productId): array {
    $stmt = $pdo->prepare('SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort_order, id');
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}

/**
 * Fotos adicionales de un producto (además de la de portada, que
 * sigue en products.image), en el orden en que se muestran. Si la
 * tabla todavía no existe (falta correr migracion_galeria_fotos.sql),
 * devuelve una lista vacía en vez de romper la página: el producto
 * se sigue viendo igual que siempre, solo con una foto.
 */
function product_gallery_images(PDO $pdo, int $productId): array {
    try {
        $stmt = $pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Recalcula products.price como el menor precio entre las variantes
 * activas del producto (el precio "desde" que se muestra en las
 * tarjetas y listados). Se llama después de guardar variantes.
 */
function recompute_product_price(PDO $pdo, int $productId): void {
    $stmt = $pdo->prepare('SELECT MIN(price) FROM product_variants WHERE product_id = ? AND active = 1');
    $stmt->execute([$productId]);
    $min = $stmt->fetchColumn();
    $pdo->prepare('UPDATE products SET price = ? WHERE id = ?')->execute([$min ?: 0, $productId]);
}

/**
 * Crea un pedido a partir de los datos del comprador, los ítems del
 * carrito (ya validados con cart_items(), con 'product'/'variant'/'qty'/
 * 'line_total'), el método de pago y el método de entrega elegidos.
 * Inserta el pedido + sus líneas dentro de una transacción y devuelve
 * el array del pedido recién creado (con 'order_number' incluido).
 */
function create_order(PDO $pdo, array $customer, array $cartItems, string $paymentMethod, string $shippingMethod): array {
    if (empty($cartItems)) {
        throw new RuntimeException('El carrito está vacío.');
    }
    $validMethods = ['mercadopago', 'transferencia', 'efectivo'];
    if (!in_array($paymentMethod, $validMethods, true)) {
        throw new RuntimeException('Método de pago no válido.');
    }
    $validShipping = ['envio', 'retiro'];
    if (!in_array($shippingMethod, $validShipping, true)) {
        throw new RuntimeException('Método de entrega no válido.');
    }

    $subtotal = 0.0;
    foreach ($cartItems as $item) { $subtotal += $item['line_total']; }

    // Envío gratis a partir de un monto (panel → Textos → Pagos y envíos):
    // si el pedido es "envío a domicilio" y el subtotal ya llega al monto
    // configurado, cargamos el costo de envío en $0 directamente al crear
    // el pedido, para que no haga falta cotizarlo a mano desde el panel.
    // Si es "retiro en el local" ya se muestra sin costo aparte (ver
    // admin/pedidos.php), y si no llega al monto, queda "a cotizar" como
    // siempre.
    $shippingCost = null;
    $freeShippingThreshold = free_shipping_threshold($pdo);
    if ($shippingMethod === 'envio' && $freeShippingThreshold > 0 && $subtotal >= $freeShippingThreshold) {
        $shippingCost = 0.00;
    }

    $orderNumber = next_order_number($pdo);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO orders (order_number, customer_name, customer_phone, customer_email, customer_address, customer_notes, payment_method, shipping_method, shipping_cost, subtotal)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $orderNumber,
            $customer['name'],
            $customer['phone'],
            $customer['email'] ?: null,
            $customer['address'] ?: null,
            $customer['notes'] ?: null,
            $paymentMethod,
            $shippingMethod,
            $shippingCost,
            $subtotal,
        ]);
        $orderId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, variant_id, product_name, variant_label, unit_price, quantity, line_total)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        // SELECT ... FOR UPDATE bloquea la fila de la variante hasta que
        // termine la transacción, para que dos compras al mismo tiempo no
        // puedan descontar del mismo stock dos veces.
        $stockStmt = $pdo->prepare('SELECT stock FROM product_variants WHERE id = ? FOR UPDATE');
        $decrementStmt = $pdo->prepare('UPDATE product_variants SET stock = stock - ? WHERE id = ?');
        foreach ($cartItems as $item) {
            $stockStmt->execute([$item['variant']['id']]);
            $currentStock = $stockStmt->fetchColumn();
            if ($currentStock !== false && $currentStock !== null && (int)$currentStock < (int)$item['qty']) {
                throw new RuntimeException('Ya no queda stock suficiente de "' . $item['product']['name'] . '" (' . $item['variant']['label'] . '). Revisá tu carrito.');
            }
            if ($currentStock !== false && $currentStock !== null) {
                $decrementStmt->execute([$item['qty'], $item['variant']['id']]);
            }

            $itemStmt->execute([
                $orderId,
                $item['product']['id'],
                $item['variant']['id'],
                $item['product']['name'],
                $item['variant']['label'],
                $item['variant']['price'],
                $item['qty'],
                $item['line_total'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'id' => $orderId,
        'order_number' => $orderNumber,
        'subtotal' => $subtotal,
    ];
}

/**
 * Tema navideño: se activa solo entre el 1 de noviembre y el 30 de
 * diciembre (hora de Argentina, ver config.php) y se apaga solo el 31.
 * Se puede forzar desde la base con el setting "tema_navidad":
 *   'on'  → siempre prendido · 'off' → siempre apagado · vacío/'auto' → por fecha.
 */
function tema_navidad(PDO $pdo): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    $modo = strtolower(trim(setting($pdo, 'tema_navidad', 'auto')));
    if ($modo === 'on') return $cache = true;
    if ($modo === 'off') return $cache = false;
    $md = (int) date('md'); // ej: 1119 = 19 de noviembre
    return $cache = ($md >= 1101 && $md <= 1230);
}

/**
 * Dirección pública de la web (para Google: canonical y sitemap).
 * Si config.php todavía tiene el dominio temporal de Hostinger, se usa
 * igual el dominio real, así no hace falta tocar config.php.
 */
function public_site_url(): string {
    $url = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
    if ($url === '' || stripos($url, 'hostingersite.com') !== false) {
        $url = 'https://www.romantiza.com.ar';
    }
    return $url;
}
