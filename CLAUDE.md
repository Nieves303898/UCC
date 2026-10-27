# Paylab — plataforma de métricas (Tres Dos Agency)

Contexto para seguir el proyecto desde otra cuenta de Claude. Hablale a la usuaria en español rioplatense (vos), directo y sin saludos.

## Qué es

Panel web de métricas que integra **Meta Ads** (campañas, Facebook, Instagram) con las **ventas reales** que cada marca carga en Google Sheets. Lo usa la agencia (Tres Dos Agency) y se lo muestra a sus clientes con la identidad **Paylab**. Todo en español (Argentina), con impuestos de Meta del 35% sumados al gasto.

- Código: `meta-dashboard/` (el archivo `Nieves` en la raíz es un HTML de ejemplo ajeno al proyecto).
- Documentación de funciones e instalación: `meta-dashboard/README.md` (leerlo primero).
- Producción: hosting compartido **Hostinger** (PHP + MySQL). Se actualiza subiendo un zip y descomprimiéndolo encima; después Ctrl+F5.

## Stack

- PHP 8.1 sin framework, autoload `App\` (`app/bootstrap.php`). Entrada: `public/index.php` (HTML) y `public/api.php` (JSON, `App\Http\Api::handle`).
- Frontend: SPA en JavaScript puro, **un solo archivo** `public/assets/app.js` (router por hash, vistas `view*()` que devuelven `{ html, mount() }`). Estilos en `public/assets/app.css` (tokens CSS, temas claro/oscuro) + Tailwind compilado en `public/assets/tailwind.css`.
- Chart.js en `public/assets/vendor/`. Fuente Geist.
- Base MySQL (o SQLite). Esquema en `app/src/Schema.php`: `Schema::VERSION` + migraciones idempotentes; `Schema::ensure()` corre al inicio de cada llamada a la API (Hostinger cachea páginas y a veces no corre el index).

### Reglas de trabajo

- **`meta-dashboard/config.php` nunca se commitea ni va en zips** (está en `.gitignore`). Tiene claves de la base y de la app de Meta. Usar `config.sample.php` como modelo. Jamás pegar claves en commits, código ni chats.
- Si cambiás clases de Tailwind en `app.js` o en las vistas, recompilar desde `meta-dashboard/`:
  `npx tailwindcss@3.4.16 -c tailwind.config.js -i resources/tailwind.css -o public/assets/tailwind.css --minify`
- Zip de entrega: `git archive --format=zip -o actualizacion.zip HEAD` ejecutado dentro de `meta-dashboard/` (sale sin `config.php`).
- Probar con datos simulados antes de entregar (la usuaria lo presenta a clientes y a su jefe: los números tienen que cerrar).
- Estilo de código: seguir el del archivo (comentarios cortos en español, funciones chicas, sin dependencias nuevas).

## Modelo de datos clave

- **Clientes y marcas**: tabla `clients` con `kind` (`cliente` | `marca`), `parent_id`, `gestion` (ventas, cobranzas, recuperos, turnos…) y `aliases` (palabras de la marca).
  - Ej. real: cliente **PayLab Gestión** con marcas **Go Celular, GetNet/GetPos, Osacra, Clover** (y futuras).
- **Cuentas publicitarias → marcas** (los nombres de campaña NO son confiables):
  - Cuenta terminada en 13 → GetNet.
  - Cuenta PayLab Gestión → Go Celular + una campaña de Osacra.
  - Cuenta ActivaPost → Clover.
  - Se resuelve con el **mapa de marcas** (`client_campaign_rules`): patrón numérico = ID exacto de campaña; texto = palabra clave. Si un ID está reclamado por regla numérica, las palabras clave no lo toman (`Clients::matches`). Campañas nuevas se asignan solas por las palabras de la marca.
- **Usuarios**: `users.role` = `admin` | `member` (equipo) | `viewer` (gerencia, solo lectura) | `client`.
  - Usuarios de cliente tienen `client_role`: `dueno`, `gerente_ops`, `gerente` ven todas las marcas (`Clients::ALL_BRANDS_ROLES`); `supervisor`, `asesor`, `usuario` solo las marcas asignadas en `user_scopes` (kind `client`).
  - `users.can_manage_users`: el cliente puede administrar los accesos de su empresa desde su portal (caso David, gerente de operaciones) sin ver el panel general de la agencia ni editar contenido. No puede dar ese permiso, ni tocar dueños/gerentes ni a sí mismo.
- **Ventas**: `sales_sources` (planilla conectada; `tab_urls`, `report_json`) y `sales_daily` (por día: ventas, facturación, leads, gasto).
  - `SalesImport` lee el export xlsx de Google con todas las pestañas (una por mes); si falla, cae a CSV por pestaña.
  - Detección automática de columnas por pestaña.
  - `Sales::dedupe` evita contar dos veces el mismo archivo (bug real: GoCelular aparecía ×4).
  - Se resincroniza cada 5 minutos.
- **Presupuestos y objetivos**:
  - `ad_budgets`: presupuesto asignado por mes.
  - `brand_goals`: objetivo mensual por marca, ej. Go Celular 300 equipos.
  - `Planner::plan`: cuánto invertir por mes, semana y día según costo por venta real.
  - `Org::overview` / `Org::sales`: consolidado del cliente con todas sus marcas.
- **Configuración global**: tabla `settings`, vía `App\Settings` (impuesto, `platform_name`, `platform_logo`, logo de reportes).

## Portal del cliente (lo que ven ellos)

- Barra de marcas fija debajo del menú (`renderBrandStrip` en `app.js`): un toque cambia de marca y mantiene la sección. `state.brand` se envía como `client` en cada llamada a la API.
- Secciones: Inicio (todas las marcas o una), Ventas (mes a mes, cotejo planilla vs Meta, detalle día por día), Presupuesto (proyección según objetivos), Campañas, Redes, Planillas, Trabajo, Reporte, Accesos.
- Identidad Paylab: degradé `#2E3192` → `#00AEEF`; logo propio subible en Configuración → Marca de la plataforma. El color de cada marca solo se usa en sus chips y avatares.

## Panel de la agencia

Resumen, Presupuestos (plan por objetivos), Campañas (incluye ranking de creativos), Facebook, Instagram, Reportes (formato presentación/PDF), Biblioteca de anuncios por nicho (links a la Ad Library oficial + referencias guardadas; la API de Meta no da anuncios de Argentina), Clientes (marcas, planillas, accesos, mapa de marcas), Accesos, Actividad, Configuración.

## Pendientes / ideas no confirmadas

Se le preguntó a la usuaria y no respondió aún; no implementar sin que lo pida:

- Números de WhatsApp por marca o por asesor.
- Ranking por asesor.
- Si los supervisores pueden editar.
- Logos por cliente en el portal.

## Datos de prueba

`gaston.pereira` es el dueño de PayLab Gestión en producción (usuario de cliente). No usar credenciales reales en pruebas; armar datos simulados localmente.
