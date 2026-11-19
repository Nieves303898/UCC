<?php
define('ROMANTIZA_APP', true);
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/admin_ui.php';

$admin = require_login($pdo);
$isSuper = $admin['role'] === 'superadmin';

// Definición de los campos editables: clave => [etiqueta, tipo, ayuda]
// tipo: 'text' (input corto) o 'area' (textarea)
$fields = [
    'Información general del sitio' => [
        'site_title' => ['Título del sitio', 'text', 'Aparece en la pestaña del navegador y en buscadores.'],
        'site_description' => ['Descripción del sitio (SEO)', 'area', 'Aparece en los resultados de Google. Ideal: 1 o 2 frases.'],
    ],
    'Portada — Hero' => [
        'hero_title' => ['Título principal', 'area', 'Podés usar <em>texto</em> para resaltar una parte en negrita, como ya está en el ejemplo.'],
        'hero_lead' => ['Bajada del título', 'area', ''],
    ],
    'Página Nosotros' => [
        'about_title' => ['Título', 'area', 'Podés usar <em>texto</em> para resaltar una parte en negrita.'],
        'about_lead' => ['Bajada', 'area', ''],
        'about_text_1' => ['Primer párrafo de la historia', 'area', ''],
        'about_text_2' => ['Segundo párrafo de la historia', 'area', ''],
    ],
    'Contacto' => [
        'contact_address' => ['Dirección / zona de envíos', 'text', ''],
        'contact_phone' => ['Teléfono para mostrar', 'text', ''],
        'contact_whatsapp' => ['WhatsApp (solo números, con código de país)', 'text', 'Ejemplo: 5493644550258 — sin espacios ni signos.'],
        'contact_instagram' => ['Usuario de Instagram (para mostrar)', 'text', 'Ejemplo: @romantiza1974'],
        'contact_instagram_url' => ['Link de Instagram', 'text', ''],
        'contact_hours' => ['Horario de atención', 'text', ''],
    ],
    'Pagos y envíos' => [
        'payment_transfer_info' => ['Datos para transferencia bancaria', 'area', 'Alias, CBU y titular. Se muestra al comprador cuando elige pagar por transferencia.'],
        'shipping_note' => ['Nota de envío', 'area', 'Se muestra en la confirmación del pedido y en la ficha de producto.'],
        'free_shipping_threshold' => ['Envío gratis a partir de qué monto', 'text', 'Cuando el subtotal del carrito llega a este monto, el envío a domicilio queda gratis automáticamente (se avisa en el carrito y en el pedido). Escribilo en números, sin puntos ni el signo $ — por ejemplo: 50000. Dejalo vacío o en 0 para desactivar esta función.'],
    ],
    'Compra segura' => [
        'trust_badge_1' => ['Sello 1', 'text', 'Se muestra con un candado/escudo, cerca del botón "Comprar" y en el pago. Dejalo corto, 2 a 4 palabras.'],
        'trust_badge_2' => ['Sello 2', 'text', ''],
        'trust_badge_3' => ['Sello 3', 'text', ''],
        'trust_badge_4' => ['Sello 4', 'text', 'Si dejás alguno de los 4 vacío, simplemente no se muestra.'],
    ],
    'Popup de WhatsApp (campañas)' => [
        'wa_popup_enabled' => ['Mostrar el popup en la web', 'checkbox', 'Aparece como un cartel chico abajo a la izquierda, a los pocos segundos o al hacer scroll. No se muestra en el carrito ni en el pago, para no interrumpir una compra. Usalo para juntar WhatsApp de gente interesada — por ejemplo, durante una campaña como el Día de la Madre — y apagalo cuando termine.'],
        'wa_popup_title' => ['Título del popup', 'text', 'Ejemplo: "¡Regalo especial para el Día de la Madre! 🎁"'],
        'wa_popup_text' => ['Texto del popup', 'area', 'La frase de abajo del título, invitando a dejar el WhatsApp.'],
        'wa_popup_button_text' => ['Texto del botón', 'text', 'Ejemplo: "Quiero mi regalo"'],
        'wa_popup_whatsapp_message' => ['Mensaje con el que se abre WhatsApp', 'area', 'Apenas alguien deja su WhatsApp, se le abre una conversación con este mensaje ya escrito, lista para mandar. Así arranca la charla al toque, y vos ves quién escribió por la campaña.'],
    ],
];

// Las credenciales de Mercado Pago son sensibles (dan acceso a cobrar en
// nombre del negocio), así que solo el administrador principal las ve y
// las edita. Al armar $fields condicionalmente (en vez de esconder solo
// el HTML) nos aseguramos además de que un editor nunca pueda borrarlas
// sin querer: el guardado más abajo solo toca las claves que están en
// $fields, así que si no es superadmin, mp_* ni siquiera se procesa.
if ($isSuper) {
    $fields['Mercado Pago'] = [
        'mp_access_token' => ['Access Token', 'secret', 'Lo sacás del panel de developers de Mercado Pago (Tus integraciones → tu aplicación → Credenciales). Empieza con APP_USR- (producción, cobra en serio) o TEST- (prueba, no cobra de verdad). Mientras este campo esté vacío, la opción "Mercado Pago" del checkout sigue funcionando como antes: se coordina el link de pago por WhatsApp.'],
        'mp_public_key' => ['Public Key (opcional)', 'text', 'También está en Credenciales, al lado del Access Token. No hace falta para cobrar con este sitio, pero la guardamos por si se necesita más adelante.'],
        'mp_webhook_secret' => ['Clave secreta del webhook (opcional, recomendado)', 'secret', 'Mercado Pago te la muestra cuando configurás la notificación webhook (Tus integraciones → tu aplicación → Webhooks → Configurar notificaciones → copiá la "Clave secreta"). Si la cargás acá, el sitio verifica que los avisos de pago sean realmente de Mercado Pago.'],
    ];
}

$flash = null;
$flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $flash = 'La sesión del formulario expiró. Recargá la página e intentá de nuevo.';
        $flashType = 'error';
    } else {
        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($fields as $group) {
            foreach ($group as $key => $meta) {
                $type = $meta[1];
                if ($type === 'checkbox') {
                    // Un checkbox no manda nada en el POST si está
                    // destildado, así que lo guardamos como '1' o ''.
                    $value = isset($_POST[$key]) ? '1' : '';
                    $stmt->execute([$key, $value]);
                    continue;
                }
                $value = trim($_POST[$key] ?? '');
                // El monto de envío gratis tiene que quedar guardado como un
                // número limpio (sin $, sin puntos de miles) para poder
                // compararlo con el subtotal del carrito. Si escriben algo
                // que no es un número válido, lo dejamos vacío (la función
                // queda desactivada) en vez de guardar basura.
                if ($key === 'free_shipping_threshold' && $value !== '') {
                    $value = preg_replace('/[^0-9,.]/', '', $value);
                    $value = str_replace(',', '.', $value);
                    $value = (is_numeric($value) && (float)$value > 0) ? (string)(float)$value : '';
                }
                $stmt->execute([$key, $value]);
            }
        }
        $flash = 'Los textos se guardaron correctamente.';
    }
}

$current = get_all_settings($pdo);
// Si el sitio todavía no tiene cargados los sellos de "Compra segura"
// (por ejemplo, recién importaste el SQL que los agrega), mostramos acá
// los mismos textos por defecto que ya se ven en el sitio público
// (ver trust_badges_html() en includes/functions.php), para que el
// formulario no aparezca vacío y confunda.
foreach (['trust_badge_1' => 'Compra 100% segura', 'trust_badge_2' => 'Envío a todo el país', 'trust_badge_3' => 'Cambios sin cargo', 'trust_badge_4' => 'Pago protegido'] as $tbKey => $tbDefault) {
    if (!isset($current[$tbKey])) { $current[$tbKey] = $tbDefault; }
}
// Si hubo POST, refrescamos el caché en memoria para mostrar lo recién guardado.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flashType === 'ok') {
    foreach ($fields as $group) {
        foreach ($group as $key => $meta) {
            $current[$key] = $meta[1] === 'checkbox' ? (isset($_POST[$key]) ? '1' : '') : trim($_POST[$key] ?? '');
        }
    }
}

admin_open($admin, 'textos', 'Textos del sitio', $flash, $flashType);
?>

<p style="color:var(--text-muted);margin-bottom:1.8rem">Estos textos se muestran directamente en el sitio público. Guardá los cambios al final de la página.</p>

<form method="post" action="textos.php">
  <?= csrf_field() ?>
  <?php foreach ($fields as $groupTitle => $group): ?>
  <div class="admin-card">
    <h2><?= h($groupTitle) ?></h2>
    <?php foreach ($group as $key => $meta): [$label, $type, $hint] = $meta; ?>
    <div class="aform-field">
      <label for="s-<?= h($key) ?>"><?= h($label) ?></label>
      <?php if ($type === 'area'): ?>
        <textarea id="s-<?= h($key) ?>" name="<?= h($key) ?>" rows="3"><?= h($current[$key] ?? '') ?></textarea>
      <?php elseif ($type === 'checkbox'): ?>
        <label class="aform-check"><input id="s-<?= h($key) ?>" name="<?= h($key) ?>" type="checkbox" <?= !empty($current[$key]) ? 'checked' : '' ?>> Activado</label>
      <?php elseif ($type === 'secret'): ?>
        <div style="display:flex;gap:.6rem">
          <input id="s-<?= h($key) ?>" name="<?= h($key) ?>" type="password" autocomplete="off" spellcheck="false" value="<?= h($current[$key] ?? '') ?>" style="flex:1">
          <button type="button" class="abtn abtn-ghost abtn-sm" onclick="var i=document.getElementById('s-<?= h($key) ?>');i.type=(i.type==='password'?'text':'password');this.textContent=(i.type==='password'?'Mostrar':'Ocultar');">Mostrar</button>
        </div>
      <?php else: ?>
        <input id="s-<?= h($key) ?>" name="<?= h($key) ?>" type="text" value="<?= h($current[$key] ?? '') ?>">
      <?php endif; ?>
      <?php if ($hint): ?><p class="aform-hint"><?= $hint ?></p><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <div class="aform-actions">
    <button class="abtn abtn-primary" type="submit"><?= admin_icon('check') ?> Guardar textos</button>
  </div>
</form>

<?php admin_close(); ?>
