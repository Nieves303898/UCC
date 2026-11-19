# Romantiza — Sitio + Panel de Administración

Guía paso a paso para subir el sitio a tu hosting. No hace falta saber programar, solo seguir los pasos en orden.

## Lo que incluye este sitio

- **Sitio público**: Inicio, Tienda, Nosotros, Contacto — con URLs propias para cada producto (bueno para Google).
- **Panel de administración** (`/admin`): para cambiar textos, fotos y productos sin tocar código.
  - Un **administrador principal** que puede modificar todo, incluidos los demás administradores y las credenciales de Mercado Pago.
  - Administradores **editores**, que solo pueden cambiar textos, imágenes y productos. No ven la sección de Administradores ni las credenciales de Mercado Pago (Access Token, Public Key, clave del webhook) — esos campos están ocultos para ellos por seguridad, ya que dan acceso a cobrar en nombre del negocio.
- **Carrito de compras propio**: el visitante elige medida/opción y cantidad, agrega al carrito, completa sus datos, elige cómo recibirlo (envío a domicilio o retiro en el local) y la forma de pago (Mercado Pago, transferencia bancaria o efectivo) sin salir del sitio. El pedido queda guardado en el panel, en la sección **Pedidos**.
- **Cobro online con Mercado Pago**: si cargás tu Access Token en el panel (Textos → Mercado Pago), el que elige pagar con Mercado Pago va directo a pagar (tarjeta, dinero en cuenta, etc.) y el pedido se marca "Confirmado" solo cuando el pago se acredita. Si todavía no cargaste el token, el sitio sigue funcionando igual que antes (se coordina el link de pago por WhatsApp). Ver la guía completa más abajo.
- **Productos con medidas y precios por variante**: cada producto puede tener una o varias medidas/opciones (ej: "1 plaza" y "2 plazas"), cada una con su propio precio. En la tienda se muestra "Desde $X" cuando hay más de una.
- **Líneas y categorías, editables desde el panel**: además de las 3 líneas propias (Línea Jardín Romantiza, Línea Mediterránea y Línea Tierra & Algodón, cada una con su foto), podés agregar nuevas líneas o categorías comunes (Mantelería, Puntillas, etc.) cuando quieras, desde el panel → **Líneas y categorías**. Las líneas se muestran con foto en la portada; las categorías comunes solo se usan para ordenar y filtrar en la tienda.
- **Slide de portada, foto de "Nuestra esencia" y videos de Instagram**: todo se carga desde el panel, sección **Portada**, sin tocar código.
- **Video de Instagram por producto**: cada ficha de producto puede mostrar un reel o publicación de Instagram embebido (se pega el link, no hace falta subir el archivo).
- **Buscador**: el ícono de lupa en el menú abre un buscador que encuentra productos por nombre/descripción, y también por el nombre de la línea (ej: buscar "Mediterránea" muestra todos los productos de esa línea).
- **Stock por medida/opción (opcional)**: en cada producto, además del precio, se puede cargar cuántas unidades hay disponibles de cada medida. Si se deja vacío, esa medida se sigue vendiendo sin límite (como hasta ahora). El stock se descuenta solo con cada venta confirmada, y en 0 la medida se muestra como "Sin stock" y no se puede comprar.

---

## Paso 1 — Crear la base de datos en tu hosting

1. Entrá al panel de tu hosting (cPanel o similar) → **Bases de datos MySQL**.
2. Creá una base de datos nueva (ej: `turusuario_romantiza`).
3. Creá un usuario de MySQL con contraseña segura, y asignalo a esa base de datos con **todos los permisos**.
4. Anotá estos 4 datos, los vas a necesitar en el Paso 3:
   - Host (generalmente `localhost`)
   - Nombre de la base de datos
   - Usuario
   - Contraseña

## Paso 2 — Importar la estructura de la base de datos

1. En cPanel, abrí **phpMyAdmin** y entrá a la base de datos que creaste.
2. Andá a la pestaña **Importar**.
3. Elegí el archivo `database/schema.sql` (está dentro de esta carpeta) y confirmá.
4. Deberías ver 10 tablas nuevas: `admins`, `settings`, `categories`, `hero_slides`, `home_videos`, `products`, `product_variants`, `messages`, `orders`, `order_items`.
5. Andá de nuevo a la pestaña **Importar**, elegí el archivo `database/cargar_productos_romantiza.sql` y confirmá. Esto carga el catálogo real de Romantiza (los 20 productos del listado, con sus medidas y precios). Las fotos quedan pendientes — se suben después desde el panel, producto por producto.

Esto ya carga las categorías, los textos de ejemplo y el catálogo. **No** carga ningún usuario administrador todavía — eso se hace solo, en el sitio, en el Paso 5, por seguridad.

## Paso 3 — Configurar la conexión a la base de datos

1. Dentro de la carpeta `includes/`, buscá el archivo `config.sample.php`.
2. Hacé una copia y renombrala a `config.php` (sin "sample").
3. Abrí `config.php` con un editor de texto simple y completá los 4 datos del Paso 1:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'tu_base_de_datos');
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_contraseña');
define('SITE_URL', 'https://www.tudominio.com.ar');
```

4. Guardá el archivo. **Nunca compartas este archivo** — tiene la contraseña de tu base de datos.

## Paso 4 — Subir todo al hosting

1. Conectate a tu hosting por **FTP** o usá el **Administrador de archivos** de cPanel.
2. Subí **todo el contenido** de esta carpeta (no la carpeta en sí, sino lo que está adentro) a `public_html` (o la carpeta donde quieras que viva el sitio).
3. Verificá que quedaron subidas las carpetas `admin/`, `assets/`, `includes/`, `uploads/`, `database/` y los archivos `index.php`, `tienda.php`, etc.
4. Las carpetas `uploads/productos/`, `uploads/lineas/` y `uploads/portada/` necesitan permisos de escritura. Si al subir una foto da error, andá al Administrador de archivos, clic derecho sobre `uploads` → Permisos, y poné **755** (o 775 si 755 no alcanza) — puede hacerse en la carpeta `uploads` con la opción de aplicar a subcarpetas.

## Paso 5 — Crear tu usuario administrador principal

1. Andá a `https://www.tudominio.com.ar/admin/instalar.php`.
2. Completá tu nombre, un usuario y una contraseña (mínimo 8 caracteres). Podés tocar el ícono del ojito para ver lo que estás escribiendo.
3. Al confirmar, se crea tu cuenta de **administrador principal** y esa página se borra sola (por seguridad, para que nadie más pueda usarla).
4. Si por algún motivo el hosting no la deja borrar sola, el sitio te va a avisar — en ese caso borrá manualmente el archivo `admin/instalar.php` desde el Administrador de archivos.

## Paso 6 — Usar el panel

Entrá en cualquier momento a `https://www.tudominio.com.ar/admin/login.php` con tu usuario y contraseña.

Desde el panel podés:

- **Pedidos**: ver los pedidos que hacen los clientes desde el carrito, con sus datos, productos (con la medida/opción elegida), forma de entrega (envío o retiro) y forma de pago. Se puede cargar el costo de envío una vez cotizado por WhatsApp, marcar el pago como confirmado y actualizar el estado (nuevo, en preparación, enviado, entregado).
- **Productos**: cargar, editar, cargar medidas/opciones con su precio y stock (ej: "1 plaza" $18.900, "2 plazas" $23.500), subir foto, cargar un video de Instagram (opcional), marcar como destacado o visible/oculto, eliminar. Al cargar la foto, el panel te muestra el formato y tamaño recomendado.
- **Líneas y categorías**: agregar, renombrar o eliminar líneas de marca (con su foto — se ven en la portada, en "Cada rincón, con su textil") y categorías comunes (sin foto — solo ordenan y filtran en la tienda). No se puede eliminar una línea o categoría mientras tenga productos asignados: primero hay que cambiarles la línea/categoría desde Productos.
- **Portada**: acá se maneja todo lo que se ve apenas alguien abre el sitio —
  - **Slide principal**: cargá entre 2 y 5 fotos, cada una con título, texto corto y un botón opcional. Pasan solas cada pocos segundos; si no cargás ninguna, se muestra el diseño de reemplazo con el título y la bajada de "Textos del sitio → Portada — Hero".
  - **Foto de "Nuestra esencia"**: reemplaza el diseño de reemplazo de esa sección por una foto real.
  - **Videos de Instagram**: pegás el link de un reel o publicación (no hace falta subir el archivo) y aparece embebido en una sección "Mirá Romantiza en Instagram" en la portada.
- **Textos del sitio**: cambiar el título, la bajada de la portada, los textos de "Nosotros", los datos de contacto, los datos para pagos por transferencia, el monto de **envío gratis** (grupo "Pagos y envíos" — dejalo vacío para desactivarlo) y los 4 textos de los sellos de **"Compra segura"** que se ven en cada producto y en el checkout.
- **Mensajes**: ver los mensajes que llegan por el formulario de contacto y las suscripciones al newsletter.
- **Administradores** (solo visible para el administrador principal): crear otros administradores, elegir si van a ser "principal" o "editor", desactivarlos o eliminarlos.

### Sobre los dos tipos de administrador

- **Administrador principal**: puede modificar absolutamente todo, incluida la sección de Administradores. Tu primera cuenta (creada en el Paso 5) queda como principal. El sistema no te deja desactivarte ni quitarte ese rol a vos mismo, ni dejar el sitio sin ningún administrador principal activo — así nunca te quedás afuera del panel por error.
- **Editor**: solo ve y puede modificar Pedidos, Productos, Textos y Mensajes. No ve la sección de Administradores, y dentro de Textos del sitio no ve el grupo "Mercado Pago" (Access Token, Public Key, clave del webhook) — esos 3 campos solo los ve y edita el administrador principal.

### Cómo funciona el carrito de compras

El visitante elige la medida/opción (si el producto tiene más de una) desde la ficha de producto, agrega al carrito con "Agregar al carrito" o "Comprar ahora", revisa su carrito, y en **Finalizar compra** elige cómo recibirlo (envío a domicilio, indicando la dirección, o retiro en el local en Sáenz Peña), completa nombre y teléfono, y elige la forma de pago: Mercado Pago, transferencia bancaria o efectivo.

- **Envío a domicilio**: el costo varía según la zona, así que no se cobra automático — Romantiza cotiza y coordina por WhatsApp, y lo carga en el panel (**Pedidos** → abrir el pedido → "Costo de envío") para que quede el total completo registrado.
- **Retiro en el local**: sin costo de envío; se coordina por WhatsApp el horario de retiro.

- **Mercado Pago**: por ahora se muestra como opción y el pedido queda registrado, pero el cobro todavía no es automático — Romantiza se contacta por WhatsApp con el link de pago. Cuando se integre Mercado Pago, el cobro va a poder hacerse automáticamente desde acá mismo.
- **Transferencia bancaria**: se le muestran al comprador el alias/CBU que cargues en **Textos del sitio → Pagos y envíos**.
- **Efectivo**: se coordina por WhatsApp o contra entrega.

Todos los pedidos quedan guardados en el panel, en **Pedidos**, con un número único (por ejemplo `RM-260912-A3F1`) para que el cliente lo mencione al escribir por WhatsApp.

---

## Preguntas frecuentes

**¿Puedo tener más de un administrador principal?** Sí, no hay límite. Se recomienda que solo la persona a cargo del negocio tenga ese rol.

**Se me olvidó la contraseña, ¿qué hago?** Otro administrador principal puede entrar a **Administradores → editar tu cuenta** y ponerte una contraseña nueva. Si solo hay un administrador principal y perdió el acceso, hay que restablecer la contraseña directamente desde la base de datos (contactanos si te pasa esto).

**¿Puedo cambiar el logo?** Sí: reemplazá el archivo `assets/img/logo.png` por el tuyo (mismo nombre, formato PNG) usando FTP o el Administrador de archivos de tu hosting.

**Las fotos que subo, ¿dónde quedan guardadas?** Las de productos en `uploads/productos/`, las de líneas en `uploads/lineas/` y las de portada (slide y "Nuestra esencia") en `uploads/portada/`, todas dentro de tu hosting. Conviene hacer una copia de seguridad de esas carpetas (y de la base de datos) de vez en cuando.

**¿Por qué los videos piden pegar un link de Instagram en vez de subir el archivo?** Para no ocupar espacio ni ancho de banda de tu hosting con archivos de video, que pesan mucho. Al pegar el link, el video se reproduce directo desde Instagram, igual que cuando lo compartís en cualquier otro lado.

**¿El sitio tiene carrito de compras propio?** Sí. El catálogo, el carrito, el checkout y el registro de pedidos son propios del sitio — no dependen de ninguna plataforma externa. Mercado Pago se puede cobrar automáticamente (ver la guía "Cómo integrar Mercado Pago" más abajo) o, mientras no cargues el Access Token, se coordina el link de pago por WhatsApp.

**¿Qué pasa si cargo mal el Access Token de Mercado Pago, o Mercado Pago está caído?** Nada se rompe: si Mercado Pago no responde por cualquier motivo, el pedido igual se guarda y el comprador ve el mismo mensaje de "te contactamos por WhatsApp con el link de pago" que ya existía antes de tener Mercado Pago integrado.

---

## Si ya tenías el sitio instalado (agregar medidas/precios por variante, envío o retiro, y categorías por línea)

Si tu sitio ya estaba funcionando antes de esta actualización (ya tenés productos, textos, pedidos y administradores cargados), **no vuelvas a importar `database/schema.sql`** — eso puede fallar porque las tablas ya existen. En cambio:

1. Entrá a **phpMyAdmin** → tu base de datos → pestaña **SQL**.
2. Abrí el archivo `database/migracion_variantes.sql` (está en esta carpeta), copiá todo su contenido y pegalo ahí. Tocá **Continuar** / **Go**.
   - Este archivo ya incluye todo lo de `migracion_ecommerce.sql` (por si nunca lo habías corrido) más las tablas y columnas nuevas para medidas/precios por variante, envío/retiro y las categorías por línea. **No hace falta correr `migracion_ecommerce.sql` aparte.**
   - Tus categorías anteriores (Mantelería, Almohadones, etc.) no se borran, pero el sitio deja de mostrarlas apenas no tengan productos activos.
3. Si querés reemplazar tu catálogo actual por el catálogo real que armamos juntos, abrí también `database/cargar_productos_romantiza.sql`, copiá su contenido en la pestaña **SQL** y tocá **Continuar** / **Go**. Si preferís mantener tus productos actuales y cargar las medidas vos misma, no hace falta correr este archivo — simplemente entrá a **Productos → editar cada uno** y completá la sección "Medidas / opciones y precios" (todo producto necesita al menos una).
4. Abrí también el archivo `database/migracion_media.sql`, copiá su contenido en la pestaña **SQL** y tocá **Continuar** / **Go**. Esto agrega el slide de portada, la foto de "Nuestra esencia", las fotos de las líneas y los videos de Instagram. **Si te olvidás este paso, el sitio sigue funcionando igual** — simplemente no vas a ver todavía las secciones nuevas hasta que lo corras.
5. Abrí también el archivo `database/migracion_mercadopago.sql` de la misma forma. Esto prepara el sitio para poder cobrar automáticamente con Mercado Pago. **Si te olvidás este paso, el sitio también sigue funcionando igual** — la opción "Mercado Pago" del checkout sigue coordinando el pago por WhatsApp hasta que corras esta migración y cargues el Access Token (ver la guía "Cómo integrar Mercado Pago" más abajo).
6. Abrí también el archivo `database/migracion_stock.sql` de la misma forma. Esto habilita el control de stock por medida en **Productos**. **Si te olvidás este paso, el sitio también sigue funcionando igual** — todas las medidas se siguen vendiendo sin límite, como hasta ahora, hasta que cargues stock en algún producto.
7. Abrí también el archivo `database/migracion_lineas_categorias.sql` de la misma forma. Esto separa tus líneas de marca (que se muestran con foto en la portada) de las categorías comunes que ya tenías (Mantelería, Puntillas, etc.), que dejan de aparecer mezcladas ahí. **Si te olvidás este paso, el sitio también sigue funcionando igual** — la portada va a seguir mostrando todas las categorías juntas hasta que corras esta migración.
8. Abrí también el archivo `database/migracion_envio_gratis_y_confianza.sql` de la misma forma. Esto agrega los textos de "Envío gratis a partir de un monto" y los sellos de "Compra segura" (panel → Textos del sitio). **Si te olvidás este paso, el sitio también sigue funcionando igual** — la ficha de producto y el checkout muestran los sellos con sus textos de siempre, y el envío gratis queda desactivado hasta que cargues un monto.
9. Abrí también el archivo `database/migracion_galeria_fotos.sql` de la misma forma. Esto habilita cargar varias fotos por producto (además de la portada) desde **Productos → editar un producto**. **Si te olvidás este paso, el sitio también sigue funcionando igual** — cada producto sigue mostrando su foto de portada como siempre, solo que no vas a poder agregar fotos adicionales hasta correrlo.
10. Abrí también el archivo `database/migracion_captacion_whatsapp.sql` de la misma forma. Esto agrega el **popup de WhatsApp** para juntar contactos desde la web (pensado para campañas, como el Día de la Madre) y la sección **WhatsApp (campañas)** del panel, donde se ven los contactos que va dejando la gente. **Si te olvidás este paso, el sitio también sigue funcionando igual** — el popup directamente no aparece hasta que corras esta migración y lo actives desde Textos del sitio.
11. Subí los archivos del sitio actualizados (por FTP o el Administrador de archivos), **sin sobrescribir** tu `includes/config.php` ni volver a subir `admin/instalar.php`.
12. **Muy importante:** purgá el caché de tu hosting (en Hostinger: hPanel → **Caché** → "Vaciar caché") y hacé un refresco forzado del navegador (`Ctrl+Shift+R` en Windows o `Cmd+Shift+R` en Mac) antes de revisar los cambios. El sitio ahora agrega automáticamente un código a cada archivo de diseño para evitar este problema a futuro, pero **esta primera vez** conviene vaciar el caché igual.
13. Entrá al panel → **Textos del sitio → Pagos y envíos** y revisá que el texto de envío/retiro y la dirección de retiro sean los correctos. Ahí mismo podés cargar el monto de **envío gratis**, en **Textos del sitio → Compra segura** podés cambiar el texto de los 4 sellos, y en **Textos del sitio → Popup de WhatsApp** podés escribir los textos y activar el popup cuando quieras usarlo.
14. Entrá a **Portada** para cargar el slide y los videos, y a **Líneas y categorías** para cargar la foto de cada línea y revisar que las categorías comunes hayan quedado del lado correcto.
15. Si querés cobrar automáticamente con Mercado Pago (en vez de coordinar el pago por WhatsApp), seguí la guía **"Cómo integrar Mercado Pago"** más abajo.

Se puede correr `migracion_variantes.sql`, `migracion_media.sql`, `migracion_mercadopago.sql`, `migracion_stock.sql`, `migracion_lineas_categorias.sql`, `migracion_envio_gratis_y_confianza.sql`, `migracion_galeria_fotos.sql` y `migracion_captacion_whatsapp.sql` más de una vez sin problema, por si tenés dudas de si ya los aplicaste. `cargar_productos_romantiza.sql` también es seguro de correr más de una vez: actualiza los mismos 22 productos en lugar de duplicarlos.

---

## Cómo integrar Mercado Pago (cobro online)

Con esto activado, el que elige pagar con Mercado Pago en el checkout va directo a la página de pago de Mercado Pago (tarjeta, dinero en cuenta, etc.), y el pedido se marca solo como "Confirmado" apenas se acredita el pago. **No es obligatorio activarlo**: mientras no completes estos pasos, la opción "Mercado Pago" sigue funcionando como coordinación de pago por WhatsApp, sin romper nada.

### 1. Conseguir tus credenciales

1. Entrá a [mercadopago.com.ar/developers/panel](https://www.mercadopago.com.ar/developers/panel) con la cuenta de Mercado Pago de Romantiza (la misma que ya usás para cobrar) y logueate.
2. Si es la primera vez, te va a pedir crear una **aplicación** ("Tus integraciones" → **Crear aplicación**). Ponele un nombre, por ejemplo "Romantiza Web", y cuando te pregunte para qué la vas a usar elegí **Pagos online** / **Checkout Pro** (la opción de "cobrar en tu sitio web").
3. Entrá a esa aplicación → pestaña **Credenciales de producción** (o **Credenciales de prueba**, ver el punto siguiente). Ahí vas a ver dos datos:
   - **Public Key** — no es obligatoria para que funcione el cobro, pero la podés guardar igual.
   - **Access Token** — este es el dato importante, es el que hace que el sitio pueda cobrar en tu nombre. Empieza con `APP_USR-...`.
4. **Recomendado antes de usar el de producción:** probá primero con las **Credenciales de prueba** (misma pantalla, pestaña de al lado). Ese Access Token empieza con `TEST-...` y te deja simular compras completas sin mover plata real (Mercado Pago te da tarjetas de prueba para eso, ver el paso 3 más abajo).

### 2. Cargar el Access Token en el panel

1. Entrá al panel del sitio (`/admin`) → **Textos del sitio** → sección **Mercado Pago**.
2. Pegá el Access Token (de prueba o de producción) en el campo **Access Token**. Tocá el botón "Mostrar" si querés verificar que se pegó completo.
3. (Opcional pero recomendado) Cargá también la **Clave secreta del webhook** — ver el punto 4.
4. Guardá los cambios, al final de la página.

Con esto ya alcanza para que el checkout empiece a mandar a la gente a pagar a Mercado Pago de verdad.

### 3. Probar antes de pasar a producción

Con el Access Token de **prueba** (`TEST-...`) cargado:

1. Entrá al sitio como cualquier comprador, agregá un producto al carrito y llegá al checkout.
2. Elegí **Mercado Pago** como forma de pago y confirmá el pedido. Te va a mandar a una página de Mercado Pago (de prueba, se nota porque dice "Sandbox" o tiene un cartel naranja).
3. Pagá con una [tarjeta de prueba de Mercado Pago](https://www.mercadopago.com.ar/developers/es/docs/checkout-pro/additional-content/your-integrations/test/cards) (las vas a encontrar en esa misma sección de "Tus integraciones" → **Cuentas de prueba y tarjetas**, o buscando "tarjetas de prueba Mercado Pago Argentina"). Podés probar tanto una que aprueba el pago como una que lo rechaza, para ver los dos mensajes.
4. Volvé al sitio y fijate: (a) que la pantalla de "Pedido confirmado" muestre el mensaje correcto según el resultado, y (b) en el panel → **Pedidos**, que ese pedido tenga el "Estado del pago" actualizado solo (Confirmado si aprobaste, Cancelado si rechazaste).

Cuando estés conforme, repetí el paso 2 pero pegando el Access Token de **producción** (`APP_USR-...`) en vez del de prueba. A partir de ahí los cobros son reales.

### 4. (Recomendado) Configurar el webhook con clave secreta

Esto es un paso extra de seguridad: sin él, el sitio igual funciona (recibe los avisos de pago sin verificar firma), pero con él el sitio puede confirmar que un aviso de pago realmente vino de Mercado Pago y no de otro lado.

1. En el panel de developers de Mercado Pago, entrá a tu aplicación → **Webhooks** → **Configurar notificaciones**.
2. En **URL de producción**, pegá: `https://romantiza.com.ar/mp_webhook.php`
3. Elegí el evento **Pagos** (`payment`).
4. Guardá. Mercado Pago te va a mostrar una **Clave secreta** (un código largo) — copiala.
5. Pegala en el panel del sitio → **Textos del sitio → Mercado Pago → Clave secreta del webhook**, y guardá.

### Cosas para tener en cuenta

- **No hace falta tocar nada más del sitio.** Todo esto se carga desde el panel; no hay que subir ni cambiar archivos.
- El campo **Access Token** y la **Clave secreta del webhook** se guardan ocultos (como una contraseña) — usá el botón "Mostrar" si necesitás revisarlos.
- Si en algún momento cambian la contraseña de Mercado Pago o regeneran las credenciales desde el panel de developers, hay que volver a pegar el Access Token nuevo acá.
- El estado del pago que se ve en **Pedidos** también se puede corregir a mano si hiciera falta (por ejemplo, si el comprador pagó por otro medio).

---

## Si subís cambios y no se ven en el sitio

A veces el navegador o el hosting guardan una copia vieja de las páginas ("caché") y no muestran los cambios recién subidos al toque. Si eso pasa:

1. Purgá el caché del hosting: en Hostinger, hPanel → **Caché** → "Vaciar caché".
2. Hacé un refresco forzado del navegador: `Ctrl+Shift+R` (Windows/Linux) o `Cmd+Shift+R` (Mac).
3. Si todavía se ve viejo, probá abrir el sitio en una ventana de incógnito.

---

Sitio desarrollado por **Tres Dos Agency**.
