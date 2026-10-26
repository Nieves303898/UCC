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

### Clientes, marcas y roles (ej. PayLab Gestión)

- **Cliente** = la empresa que contrata (ej. *PayLab Gestión*). **Marcas** = sus unidades o gestiones (Go Celular, GetNet, Osacra, Clover, VTV Buenos Aires, ITV Córdoba…), cada una con su **tipo de gestión** (ventas, cobranzas, recupero, turnos, consultas) que define cómo se llama su resultado.
- En *Clientes* se ven sólo los clientes; las marcas se crean y se abren desde **Cliente → Marcas**. Cada marca tiene sus cuentas, ventas, planillas, objetivos y portal.
- **Accesos con rol** (Cliente → Accesos): *Dueño*, *Gerente de operaciones* y *Gerente* ven **todas** las marcas (también las nuevas) y un **resumen consolidado** (inversión +35%, conversaciones, ventas/gestiones, costo por venta, avance del objetivo de cada marca). *Supervisor*, *Asesor* y *Usuario* ven sólo las marcas tildadas; no ven las cuentas completas del cliente porque mezclan marcas. Arriba del portal hay un selector de marca.
- **Planillas** (Cliente o Marca → Planillas): cualquier Google Sheets (cobranzas, turnos, leads, asesores…) se ve dentro del portal tal cual, con todas sus pestañas y un buscador, y se vuelve a leer cada 5 minutos. Las ventas cargadas en planillas también se releen cada 5 minutos.

### Mes a mes y cotejo planilla vs Meta

- **Varias planillas a la vez**: en Ventas → Conectar se pueden pegar varios links (uno por renglón) cuando los meses están en **archivos distintos**. Si los meses son **pestañas de un mismo archivo**, alcanza con un link: se leen todas. Si se pega el mismo archivo más de una vez (por ejemplo, el link de cada pestaña), se actualiza la misma fuente y no se duplica; las fuentes duplicadas que hubieran quedado se limpian solas al abrir el panel.
- **Cada pestaña se lee con sus propios títulos**: si cada mes tiene campañas distintas (ej. "Día de la Madre" en septiembre, "Primavera Saldo x4" en octubre), se toman todas sus columnas de interacciones y costo. Las pestañas sin carga diaria (ej. "Histórico") se ignoran.
- **Día por día**: tocando un mes en *Mes a mes* se ve cada día con ventas, interacciones anotadas, conversaciones de Meta, costo anotado, gasto de Meta (con y sin impuestos) y costo por venta, y avisa si algún día viene de dos planillas.
- Además de las ventas, se leen solas las columnas de **interacciones** y de **costo de campaña** que anota el equipo (se suman todas, ej. una por campaña).
- **Mes a mes** (en Ventas de cada marca): ventas, facturación, inversión real de Meta +35%, costo por venta, conversaciones y conversión, con gráfico.
- **Cotejo**: compara el costo anotado (sin impuestos) y las interacciones anotadas con lo que informa Meta para las campañas de la marca, en los mismos días (del 1 al último día cargado). Verde = coincide (±5%), amarillo = hasta 15%, rojo = revisar la carga.

### Campañas nuevas que se asignan solas

Cada marca tiene **palabras para reconocer sus campañas** (en *Marcas y cuentas*; siempre incluye su nombre — ej. Clover: "activa pos"). En las cuentas compartidas entre marcas (ej. PayLab Gestión), una campaña nueva cuyo nombre contenga esas palabras se suma sola a la marca (y por lo tanto a su cliente). Las campañas asignadas a mano siempre mandan sobre las palabras. Las que no coincidan con ninguna aparecen en el plan como "sin marca".

### Portal de un cliente con varias marcas

- Arriba de **Inicio** y **Ventas** hay una **barra de marcas** siempre visible ("Todas las marcas", Go Celular, GetPos, Osacra, Clover…): un clic y se cambia de marca en la misma pantalla.
- **Ventas → Todas las marcas**: totales del cliente (ventas, facturación, inversión +35%, costo por venta, conversaciones), tabla por marca y mes a mes por marca con el costo por venta de todo el cliente.
- **Presupuesto**: promedio diario real del mes (con impuestos), cierre del mes a ese ritmo, asignado este mes, **asignado para el mes siguiente** (lo que se carga en Presupuestos) y el **sugerido** según los objetivos de cada marca.

### Accesos (panel general y del cliente)

- **Agencia → Accesos** (sólo administradores): todas las personas del sistema —tu equipo y los usuarios de cada cliente— con filtros por tipo y cliente. Desde ahí se crea cualquier acceso, se cambia rol y marcas, se genera una clave nueva o se da de baja. Los clientes no ven esta pantalla ni saben quién más tiene acceso.
- **Permiso "Puede gestionar los accesos"** (ej. David, *Gerente de operaciones* de PayLab): en su portal aparece **Accesos**, donde crea, edita y da de baja personas **de su empresa** y elige qué marcas ve cada una. No ve tu panel, ni a tu equipo, ni a otros clientes; no puede dar este permiso a nadie, ni asignar el rol Dueño, ni tocar al Dueño, a otros gestores o a sí mismo. Todo su acceso al contenido es de lectura.
- **Clientes → Seleccionar**: elegir varios clientes y eliminarlos juntos (útil para limpiar clientes de prueba). "Todos menos los que tienen marcas" deja a salvo a los clientes como PayLab.

### Plan de presupuesto por objetivos

*Presupuestos → Plan por objetivos*: cargás cuántas ventas querés por marca en el mes (ej. Go Celular 300) y el panel calcula cuánto invertir.

- **Costo por venta real** = gasto en Meta +35% ÷ ventas de la planilla. Se puede tomar del promedio de los últimos 3 meses, del mes anterior o del mes en curso.
- **Inversión necesaria** = objetivo × costo por venta, vista **por mes, por semana o por día**, con impuestos y "en Meta" (sin el 35%, lo que se carga en el Administrador de anuncios).
- **Mes siguiente**: compara con el ritmo actual (presupuestos diarios activos) y dice cuánto subir o bajar por día. Con **Usar** se guarda como presupuesto planificado de la marca.
- **Mes en curso**: vendidas, % de avance contra el ritmo esperado, proyección al cierre, cuántas faltan, cuánto falta invertir y el **diario necesario** para llegar.
- Las marcas que **superan el objetivo con menos presupuesto** (o que este mes venden más barato que la base) se destacan en verde.
- **Clientes y marcas**: el *cliente* es la empresa (ej. PayLab Gestión) y sus *marcas* son lo que vende (Go Celular, GetNet, Osacra, Clover). En **Marcas y cuentas** (en el plan o en Clientes) cargás las marcas, elegís de qué cliente son ("Todas son de…") y de qué marca es cada cuenta publicitaria: **toda la cuenta** (ej. ActivaPost → Clover; si la cuenta se llama como la marca se sugiere sola), **ninguna**, o **varias marcas**, eligiendo la marca campaña por campaña (ej. PayLab Gestión). No depende de cómo se llamen las campañas. Las campañas nuevas de una cuenta compartida aparecen como "sin marca" para asignarlas. El plan se filtra por cliente; sólo las marcas entran al plan, a *Presupuestos → Por marca* y vienen tildadas en *Reportes*. En *Clientes* cada cliente aparece seguido de sus marcas.
- Si una marca no tiene objetivo del mes, se usa el objetivo fijo de su ficha. El objetivo del mes siguiente también alimenta la diapositiva de objetivos de la *Proyección*.

### Creativos que más traccionan

*Campañas → Creativos que más traccionan* (y dentro de cada cuenta): ranking de los **anuncios activos** con inversión en el período, ordenados por resultados, menor costo por resultado, CTR, ROAS, compras, conversaciones, clics, inversión o menor CPM.

- Gráfico comparativo del top, el creativo #1 destacado, y en cada tarjeta la métrica elegida con su **mini gráfico diario** más inversión, resultados, costo, CTR, CPM y ROAS.
- Al tocar un creativo se abre el detalle con el gráfico **día a día** (inversión vs. la métrica que elijas), vista previa y links a Facebook / Instagram.
- Los clientes ven sólo los creativos de sus campañas.

### Biblioteca de anuncios por nicho

Menú *Estrategia → Biblioteca de anuncios*. Meta sólo entrega por API los anuncios comerciales que se muestran en Europa (para Argentina, sólo los políticos), así que la biblioteca funciona así:

- **Nichos** con palabras clave y país: cada palabra abre la Biblioteca oficial de Meta ya filtrada (país + anuncios activos) en una pestaña nueva. Hay nichos sugeridos para empezar.
- **Competidores** por nicho: pegás el link de su Biblioteca (o el ID de la página) y "Ver sus anuncios" abre todos sus anuncios activos.
- **Referencias guardadas** (swipe file): captura, link, anunciante, formato, gancho, por qué funciona, nicho y marca. Se filtran por nicho, marca y formato.
- Equipo y administradores editan; solo lectura ve todo; los clientes no acceden.

### Planillas mensuales (una pestaña por mes)

**Conectar en un paso**: en la marca → Ventas → pegás el link de Google Sheets y tocás **Conectar**. La fecha y las ventas se detectan solas (aunque la fecha no tenga título y aunque haya una pestaña "Resumen" adelante), se importa y se muestra cuántas ventas entraron. Si no se pueden reconocer, se ofrece elegir las columnas una sola vez. En cada actualización (cada 5 minutos) se vuelven a detectar, así una pestaña o columna nueva no rompe nada.

Las ventas se conectan **por marca** (Clientes → {marca} → Ventas → Google Sheets). Se lee el **archivo completo**: si llevás una pestaña por mes (Octubre, Noviembre…), al agregar la pestaña nueva entra sola, sin volver a pegar el link. Las pestañas se alinean por el título de cada columna. La fecha se detecta aunque la columna no tenga título; se prioriza la columna de **ventas** (ej. "Ventas Brutas WSP") y se ignoran costos, interacciones, impuestos y totales; los días todavía vacíos no cuentan. Los datos de Meta (conversaciones, gasto, costo por resultado) no hace falta cargarlos: se leen de Meta.

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

### Reportes en formato presentación (PDF)

Menú *Reportes*: diapositivas 16:9 con el estilo de la agencia (azul marino y celeste, logo arriba a la derecha y pie con la empresa), listas para descargar en PDF.

- **Avances del equipo** (semanal, quincenal o mensual): presencia y visualizaciones por marca (Facebook + Instagram contra el período anterior), conversaciones y campañas activas, inversión +35% y ventas con costo por venta y por conversación, lo que se hizo (Trabajo en curso), qué ajustar y objetivos.
- **Proyección de inversión y ventas** (con impuestos): cómo venimos, inversión proyectada a fin de mes (invertido + presupuesto diario × días que faltan), comparación con los 3 meses anteriores, ventas y costo por venta, cierre estimado del mes, próxima semana, escenarios del mes siguiente (+15% / +30%) y objetivos con presupuesto (se editan en la diapositiva y el presupuesto se recalcula).
- Se elige qué marcas entran. Todos los textos se editan con un clic y se guardan solos.
- Logos: el del reporte se sube en *Reportes*; el de cada cliente, en *Clientes → Editar → Subir logo* (PNG, JPG o WebP, hasta 2 MB; se guardan en `public/uploads`).

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
