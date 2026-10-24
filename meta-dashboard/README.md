# Meta Hub — Panel de métricas de Meta (Ads, Facebook e Instagram)

Panel propio, instalable en cualquier hosting con PHP + MySQL (cPanel), conectado a la Graph API de Meta.

| Módulo | Qué muestra |
|---|---|
| **Resumen general** | Todas las cuentas publicitarias: inversión, resultados, costo por resultado, compras, facturación, ROAS, conversaciones de WhatsApp/Messenger, leads, CTR, CPM. Variación vs. período anterior, gráfico diario, distribución de inversión, alertas. Totales separados por moneda (ARS / USD). |
| **Campañas y anuncios** | Cuentas agrupadas por portfolio comercial (Business Manager) → campañas → conjuntos → anuncios con creativo, copy, vista previa y métricas. Desgloses por edad, género, plataforma, ubicación, dispositivo, provincia, país y hora. Exportación CSV. |
| **Páginas de Facebook** | Seguidores, visualizaciones, interacciones, nuevos seguidores, visitas. Cada posteo con alcance, reacciones, comentarios, compartidos, clics, reproducciones y engagement. |
| **Instagram** | Visualizaciones, alcance, interacciones, guardados, compartidos, toques en enlaces, seguidores netos. Posts, reels e historias activas con sus métricas. Demografía de seguidores. |
| **Actividad** | Comentarios, menciones y posteos en tiempo real vía webhook. |
| **Clientes** | Alta de clientes con color de marca, asignación de cuentas/páginas/Instagram, accesos con usuario y contraseña, y publicación de "trabajo en curso". Botón *Ver como cliente* para revisar su portal. |
| **Configuración** | Conexiones (Login con Facebook o token de System User), visibilidad de activos, equipo de la agencia, logs del cron. |

### Portal del cliente

Cada cliente entra con **su usuario y contraseña** y ve una interfaz distinta, con su color de marca:

| Sección | Qué ve |
|---|---|
| **Inicio** | "Hoy, en vivo" (inversión, resultados y costo del día), KPIs del período con tendencia, rendimiento diario, campañas en marcha, redes y su mejor contenido. |
| **Campañas** | Sus cuentas → campañas → conjuntos → anuncios con creativos (sin datos de facturación de la cuenta ni medio de pago). |
| **Redes** | Sus páginas de Facebook e Instagram con métricas de cada posteo, reels e historias. |
| **Trabajo** | Tablero *Planificado / En curso / Hecho* con lo que la agencia está haciendo, fechas de entrega y links a materiales. |
| **Reporte** | Reporte del período listo para **descargar en PDF** (KPIs, campañas, Instagram, contenido destacado, trabajo realizado y próximos pasos). |

El aislamiento se valida en el servidor: un cliente sólo puede consultar los activos asignados, y cada campaña, conjunto o anuncio se verifica contra su cuenta antes de pedir datos a Meta.

**Cómo dar acceso:** Clientes → Nuevo cliente → *Activos asignados* (marcá sus cuentas, páginas e Instagram) → *Accesos* (se genera una contraseña segura; copiala o enviala por WhatsApp) → *Trabajo en curso* (publicá avances).

**"Tiempo real":** el modo *En vivo* refresca el panel solo (cada 2 min por defecto). Los rangos que incluyen *hoy* usan una caché de 2 min y los históricos de 15 min. El botón ⟳ fuerza la consulta a Meta. Si Meta falla o limita las consultas, se muestra el último dato guardado con un aviso.

---

### Ventas y conversiones propias (con o sin campañas)

Para clientes que **no tienen campañas** (ej. VTV) o para cruzar la publicidad con las ventas reales. Se carga por cliente en *Clientes → {cliente} → Ventas / conversiones*:

| Fuente | Cómo funciona |
|---|---|
| **Google Sheets** (recomendado) | Pegás el link de la planilla compartida como "cualquier persona con el enlace puede ver". Se vuelve a leer sola cada 20 min al abrir el tablero y en cada cron. Ideal si el equipo ya carga las ventas del día ahí. |
| **Excel / CSV** | Subís el `.xlsx` o `.csv`. Al volver a subirlo se reemplazan sólo las fechas que trae (sirve para cargar el día o la semana). |
| **Carga manual** | Un formulario para cargar el total de un día. |

- Acepta **una fila por día** (totales) o **una fila por venta/turno** (se cuentan las filas). Fechas `dd/mm/aaaa` o fecha de Excel; números `1.234,56` o `1,234.56`.
- Al importar, el sistema **detecta las columnas** (fecha, cantidad, monto, consultas, sucursal/planta) y te deja corregirlas. Hay una plantilla en `public/assets/plantilla-ventas.csv`.
- Cada cliente tiene su vocabulario (ej. *Verificaciones*, *Consultas*, *Planta*), moneda y **objetivo mensual**.

El tablero muestra: total, facturación, ticket promedio, promedio diario, consultas y tasa de conversión (vs. la misma cantidad de días anteriores); **proyección de cierre del mes** con avance contra el objetivo y ritmo diario necesario; último día cargado y mejor día; serie diaria; **días de la semana que más rinden**; desglose por sucursal/planta; detalle diario exportable a CSV. Si el cliente además tiene campañas asignadas, suma **costo por venta real**, **ROAS real** y cuántas ventas atribuye Meta sobre el total. Todo aparece también en el inicio del portal y en el reporte PDF.

### Presupuestos, impuestos y ventas por campaña

- **Presupuestos** (menú Rendimiento): presupuesto mensual por cuenta (con impuestos), gastado del mes en Meta y **+35%**, gasto de hoy, presupuestos diarios activos (campaña o suma de conjuntos), cierre estimado, disponible y **presupuesto diario sugerido** para cerrar en lo planificado.
- **Impuestos**: el porcentaje (35% por defecto) se edita en *Configuración*. Se aplica a "Inversión + imp.", costo por venta y ROAS real.
- **Ventas por campaña**: agregá en la planilla una columna **"Campaña"** con el nombre de la campaña de Meta (mayúsculas, tildes y signos no importan). El tablero de ventas muestra por campaña: gasto +35%, ventas reales, facturación, costo por venta, ROAS real, resultado neto, ticket y conversión clic→venta, y avisa qué nombres no coinciden (con sugerencia). En *Clientes → Ventas* está la lista de nombres exactos para copiar.
- **Objetivo mensual por campaña** (cantidad y/o facturación): avance del mes, línea de ritmo esperado, cierre estimado y estado (en camino, ajustado, atrasado, cumplido).
- **Usuario de solo lectura** (gerencia): ve todo, incluidos presupuestos y el portal de cada cliente, pero no puede editar ni entrar a Configuración. Se crea en *Configuración → Equipo y gerencia*.

### Varias marcas en una misma cuenta publicitaria

Si una cuenta tiene campañas de distintas marcas (ej. PayLab Gestión con campañas de Go Celular, Osacra, Clover), cada cliente puede tomar **sólo sus campañas**:

- *Clientes → {cliente} → Activos asignados → Cuentas publicitarias*: elegí **"Solo algunas campañas"** y escribí palabras clave separadas por coma (ej. `go celular, gocel`). Se muestra al instante qué campañas toma. Mayúsculas, tildes y signos no importan; un número se toma como ID exacto de campaña. Las campañas nuevas que respeten el nombre entran solas.
- *Clientes → Detectar marcas en campañas*: agrupa las campañas por el nombre (lo que va antes de " - " o " | ") y las asigna a un cliente existente o nuevo con un clic.
- El portal, las métricas, la serie diaria, las ventas por campaña, el ROAS real y los objetivos del cliente usan sólo sus campañas. El acceso se valida en el servidor (también para conjuntos y anuncios), y el cliente ve el nombre de su marca en lugar del de la cuenta compartida.
- *Presupuestos → Por marca / cliente*: presupuesto mensual propio de cada marca, gasto +35%, gasto de hoy, cierre estimado y diario sugerido; cada campaña muestra la etiqueta de su marca.

### Equipo de la agencia: cada persona ve sólo lo suyo

*Configuración → Equipo y gerencia*: al crear un usuario elegís el rol y después, con **Accesos**, qué ve:

| Rol | Ve | Puede editar |
|---|---|---|
| Administrador | Todo | Todo |
| Equipo | Sólo lo asignado | Presupuestos, objetivos, carga de ventas y trabajo en curso de lo asignado |
| Solo lectura | Lo asignado (o todo si no tiene asignaciones) | Nada |

Se puede asignar por **portfolio comercial** (incluye todas sus cuentas publicitarias), **cliente/marca** (sus cuentas, páginas e Instagram), **cuenta publicitaria**, **página** (incluye su Instagram) o **Instagram**. Todo se valida en el servidor.

## 1. Requisitos

- PHP 8.1 o superior con extensiones `curl`, `openssl`, `pdo_mysql` (vienen en cPanel).
- MySQL / MariaDB.
- HTTPS (Meta lo exige para OAuth y webhooks). AutoSSL de cPanel alcanza.

## 2. Crear la app en Meta

1. Entrá a <https://developers.facebook.com/apps> → **Crear app** → tipo **Empresa (Business)** → vinculala a tu portfolio comercial.
2. Agregá los productos **Inicio de sesión con Facebook para empresas** y **API de marketing**.
3. En *Inicio de sesión con Facebook → Configuración*, en **URI de redireccionamiento de OAuth válidos** pegá:
   `https://TU-DOMINIO/index.php?r=oauth-callback` (aparece en el panel, sección Configuración, para copiar).
4. En *Configuración de la app → Básica* copiá el **ID de la app** y la **Clave secreta**.
5. Permisos que usa el panel (todos de **solo lectura**):
   `ads_read`, `business_management`, `pages_show_list`, `pages_read_engagement`, `read_insights`, `instagram_basic`, `instagram_manage_insights`.

> **¿Hace falta revisión de Meta (App Review)?** No, si sólo usan el panel personas con rol en la app (administradores, desarrolladores o evaluadores). Con la app en modo desarrollo tenés acceso estándar a esos permisos sobre los activos a los que tu usuario tiene acceso. Para cuentas de clientes, pediles que agreguen tu portfolio comercial como **socio** con acceso a sus cuentas publicitarias, páginas e Instagram.

### Opción recomendada para agencias: token de System User (no vence)

1. Configuración del negocio → **Usuarios del sistema** → Agregar (rol Administrador).
2. **Asignar activos**: todas las cuentas publicitarias, páginas e Instagram (también los compartidos por clientes).
3. **Generar token** → elegí tu app → marcá los permisos de arriba → vencimiento **Nunca**.
4. En el panel: Configuración → *Agregar token manualmente* → tipo *System User*.

Con *Conectar con Facebook* el token dura 60 días; el panel avisa 10 días antes para reconectar.

## 3. Instalación en cPanel

1. **Base de datos:** cPanel → *Bases de datos MySQL* → creá la base, un usuario y asignale *Todos los privilegios*.
2. **Subdominio:** cPanel → *Dominios* → creá `metricas.tudominio.com` con la raíz en `/home/USUARIO/meta-dashboard/public`.
3. **Archivos:** subí la carpeta `meta-dashboard` a `/home/USUARIO/` (fuera de `public_html`).
   *Si sólo podés usar `public_html`*, subila ahí: los `.htaccess` incluidos bloquean `app/`, `cron/`, `storage/` y `config.php`. La URL quedaría `https://tudominio.com/meta-dashboard/public`.
4. **Configuración:** copiá `config.sample.php` como `config.php` y completá:
   - `app_url`: la URL donde abre `/public`, sin barra final.
   - `app_key`: generala en *Terminal* de cPanel con `php -r "echo base64_encode(random_bytes(32));"`. **No la cambies después**: cifra los tokens guardados.
   - `db`: datos del paso 1.
   - `meta.app_id` y `meta.app_secret`.
5. Abrí la URL: la primera vez aparece el **instalador**, que crea las tablas y tu usuario administrador. Hacelo apenas subas los archivos.
6. Configuración → **Conectar con Facebook** (o pegá el token de System User).

## 4. Cron (seguidores históricos y mantenimiento)

cPanel → *Cron Jobs* → cada hora (así las planillas de Google Sheets quedan al día aunque nadie abra el panel):

```
7 * * * * /usr/local/bin/php /home/USUARIO/meta-dashboard/cron/sync.php > /dev/null 2>&1
```

Sincroniza cuentas, páginas e Instagram nuevos, relee las planillas de ventas de Google Sheets, guarda la foto diaria de seguidores (Meta no da historial largo), avisa tokens por vencer y limpia la caché.
Si tu hosting no permite cron por CLI, definí `cron_key` en `config.php` y llamá a `https://TU-URL/cron.php?key=TU_CLAVE` desde un servicio externo (por ejemplo cron-job.org).

## 5. Webhook (opcional, actividad en vivo)

1. Inventá un texto y ponelo en `meta.webhook_verify_token`.
2. En la app de Meta → **Webhooks**: suscribí *Page* (campos `feed`, `mention`) e *Instagram* (`comments`, `mentions`) con la URL `https://TU-URL/webhook.php` y ese token.
3. Las notificaciones se validan con la firma `X-Hub-Signature-256` (clave secreta de la app).

## 6. Seguridad

- Tokens de Meta guardados **cifrados (AES-256-GCM)**; cada llamada usa `appsecret_proof`.
- Contraseñas hasheadas, sesión `HttpOnly` + `SameSite`, CSRF en todas las acciones.
- Usuarios **cliente** aislados: no ven otras marcas, conexiones, configuración ni datos de facturación.
- El panel sólo **lee** datos: no pausa, edita ni crea campañas.

## 7. Notas sobre los datos

- Meta actualiza las métricas de Ads con ~15 min de retraso; las compras pueden ajustarse hasta 28 días después (atribución).
- **Resultados** se calcula según el objetivo de cada campaña (ventas → compras, clientes potenciales → leads, interacción → conversaciones por mensaje, tráfico → clics, reconocimiento → alcance).
- El **alcance** sumado entre cuentas/campañas es aproximado (Meta no deduplica entre objetos).
- Instagram calcula los totales de cuenta sobre 30 días como máximo; las páginas, sobre 93 días.
- Meta depreca métricas seguido (ej. `impressions` → `views`). El panel detecta las que dejaron de existir y las omite sin romperse. La versión de la API se cambia en `meta.graph_version`.

## 8. Personalización

- Nombre del panel y de la agencia: `app_name` y `agency_name` en `config.php`.
- Tema claro/oscuro: botón en la barra (se recuerda por usuario; por defecto sigue al sistema).
- Buscador rápido: `Ctrl + K` / `Cmd + K`.
- Estilos: Tailwind ya viene compilado en `public/assets/tailwind.css`. Si cambiás clases en las vistas o en `app.js`, recompilalo con:
  `npx tailwindcss@3.4.16 -c tailwind.config.js -i resources/tailwind.css -o public/assets/tailwind.css --minify`
- Umbral de alerta de frecuencia: agregá `'alerts' => ['max_frequency' => 3.5]` en `config.php`.

## Estructura

```
meta-dashboard/
├─ config.sample.php     → copiar como config.php
├─ app/
│  ├─ bootstrap.php
│  ├─ views/             → login/instalador y layout del panel
│  └─ src/
│     ├─ Meta/           → cliente Graph API (batch, paginación, reintentos) y OAuth
│     ├─ Services/       → Ads, Pages, Instagram, sincronización, cron, webhooks
│     └─ Http/Api.php    → endpoints JSON del panel
├─ cron/sync.php         → tarea diaria
└─ public/               → raíz web: index.php, api.php, webhook.php, cron.php, assets/
```
