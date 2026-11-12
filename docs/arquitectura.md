# El Jardín de Sele — Backend: Etapa 2 (diseño de arquitectura)

Estado: **propuesta para aprobar**. No se ha modificado ningún archivo del repositorio ni la base de datos.

## 0. Decisiones ya tomadas

| Tema | Decisión |
| --- | --- |
| Base de datos | MySQL |
| Hosting | Hostinger Unlimited (Cloudflare delante) |
| PHP / Laravel | Los del proyecto: PHP ^8.3, Laravel 13.17 |
| Imágenes | Archivos en el servidor, solo la ruta en la base de datos (sección 5) |
| Credenciales | El `.env` real vive solo en tu computador y en el servidor; a GitHub solo va `.env.example` sin secretos |

## 1. Qué cambio respecto a tu lista de módulos (y por qué)

El documento maestro manda sobre la lista inicial. Propongo estos ajustes; si no estás de acuerdo, los revisamos.

| Tu lista | Propuesta | Razón (documento maestro) |
| --- | --- | --- |
| Usuarios y autenticación, Roles y permisos | **Administración**: un solo rol Administrador con doble factor | "Un solo panel Administrador; sin roles por ahora" |
| Inventario | **Capacidad** (ciclos de producción por día) en el módulo Calendario | El producto es personalizado y se fabrica por capacidad diaria, no por stock |
| Carrito de compras | **Cotización** (Laravel valida el carrito y devuelve precio, envío y fechas) | El carrito vive en el navegador; guardarlo en el servidor es "al crecer" |
| Variantes de productos | **Opciones y accesorios** del producto | Es como se modela en el Paso 5 |
| Direcciones de entrega | Dentro de **Ubicación y envíos** y del pedido | Cantones, zonas y tarifas (Paso 10) |
| Notificaciones | **Comunicaciones**: correos del pedido y avisos al administrador | Pasos 17 y 18 |
| Pagos | **Pagos**: PayPhone y transferencia con comprobante | Pasos 13 y 14 |

## 2. Módulos (bounded contexts)

Carpeta: `app/Core/{Modulo}/{Dominio|Aplicacion|Infraestructura|Presentacion}` y `app/Shared`. Nombres de módulos y clases en español; tablas y campos de la API en inglés (contrato del Paso 0).

| # | Módulo | Responsabilidad | Tablas principales |
| --- | --- | --- | --- |
| 1 | **Configuracion** | Parámetros del negocio editables (`settings`) | `settings` |
| 2 | **Administracion** | Login del panel, 2FA, cierre de sesiones | `users` (+ 2FA) |
| 3 | **Catalogo** | Categorías, subcategorías, productos, opciones, campañas | `categories`, `subcategories`, `products`, `options`, `product_option`, `campaigns`, `campaign_products` |
| 4 | **Medios** | Subida, variantes WebP, rutas | `media` |
| 5 | **Precios** | Precio final, promociones, recargo de tarjeta (centavos) | `promotions` |
| 6 | **Calendario** | Días no laborables, ciclos, capacidad | `non_working_days`, `cycles` |
| 7 | **Promesa** | Fecha de entrega, gracia y ventanas | (usa Calendario) |
| 8 | **Ubicacion** | Provincias, cantones, zonas y tarifas | `provinces`, `cantons`, `localities`, `shipping_zones`, `zone_assignments` |
| 9 | **Cotizacion** | Valida el carrito y calcula todo | (usa Catálogo, Precios, Promesa, Ubicación) |
| 10 | **Personalizador** | Fotos temporales y reglas de personalización | `uploads` |
| 11 | **Clientes** | Clientes, consentimientos, aceptación de términos | `customers`, `consents`, `policies`, `terms_acceptances` |
| 12 | **Pedidos** | Creación del pedido, estados, historial | `orders`, `order_items`, `order_addresses`, `order_attribution`, `order_events` |
| 13 | **Pagos** | PayPhone, transferencia, comprobantes, webhooks | `payment_attempts` |
| 14 | **Comunicaciones** | Correos y avisos | (colas) |
| 15 | **Testimonios** | Testimonios aprobados | `testimonials` |

Regla de dependencia: Dominio no conoce Laravel ni Eloquent; Aplicación orquesta; Infraestructura implementa (Eloquent, archivos, PayPhone); Presentación expone la API. Los módulos solo se hablan por interfaces de Aplicación.

## 3. Orden de construcción propuesto

El mismo orden del documento maestro:

1. **Base técnica** (Paso 4): estructura DDD, `/api/v1`, errores en JSON, colas, `settings`, login del panel.
2. **Catálogo + Medios** (Pasos 5 y 6): lo que alimenta las fichas y el guardado de imágenes. Aquí Josué sube fotos desde el panel.
3. Precios, Calendario, Promesa, Ubicación.
4. Cotización, Pedidos, Pagos.
5. Comunicaciones, seguimiento, tracking.

Un módulo a la vez, con pruebas, y espero tu aprobación antes del siguiente.

## 4. Reglas de base de datos

- MySQL con migraciones en `database/migrations`.
- Dinero en **centavos enteros** (`unsignedInteger`), no `decimal`: así lo exige el contrato del documento maestro (tu instrucción decía decimal; lo ajusto al documento para no mezclar dos formas).
- Fechas del negocio en America/Guayaquil; instantes en UTC.
- Claves foráneas, índices y restricciones únicas desde la primera migración.
- Transacciones en crear pedido, reservar capacidad y confirmar pago.
- Nunca `migrate:fresh`, `migrate:refresh` ni `db:wipe`. Ninguna migración contra la base de Hostinger sin tu aprobación expresa. En mi entorno uso una base local de pruebas.
- Soft deletes solo en productos y categorías (para no romper pedidos antiguos).

## 5. Guardado de imágenes

**Dos zonas, dos discos de Laravel.**

| Zona | Disco | Ruta base | Qué guarda |
| --- | --- | --- | --- |
| Pública | `media` | `public/media/` | Fotos y videos ya procesados |
| Privada | `private` | `storage/app/private/` | Originales, fotos de clientes, comprobantes |

**Carpetas públicas (organizadas por dueño):**

```
public/media/
├── products/{sku}/            a1b2c3-600.webp, a1b2c3-1080.webp, video-9f8e7d.mp4, poster-9f8e7d.webp
├── accessories/{producto}/{accesorio}/   d4e5f6-160.webp, d4e5f6-large.webp
├── categories/{slug}/         banner y portada
├── campaigns/{slug}/
├── home/
└── social/                    1200×630 JPEG
```

**Carpetas privadas:**

```
storage/app/private/
├── originals/products/{sku}/        originales sin tocar
├── uploads/{año}/{mes}/{uuid}.ext   fotos de clientes (se borran a los 14 días si no se compra)
└── receipts/{pedido}/{uuid}.ext     comprobantes de transferencia
```

**Reglas:**
- Nombres con código en el nombre (hash) para caché permanente en Cloudflare. Nunca se usa el nombre original del archivo.
- Las fotos de clientes y los comprobantes nunca están en `public`; se entregan por un controlador que verifica permisos.
- Validación de tipo real (no solo extensión), peso máximo y límite de intentos.
- En la base de datos se guarda la **ruta relativa**, nunca la URL completa ni el archivo. La API arma la URL; así se puede cambiar de dominio o de servidor.
- La tabla `media` guarda un registro por archivo subido, con todas sus variantes en un campo JSON (por ejemplo `{"600": ruta, "1080": ruta}`), la ruta del original privado, ancho, alto, tipo, `alt` y rol (`front`, `video`, `angle`, `detail`, `scale`, `social`), ligada al producto, accesorio o categoría.
- Si algún día se pasa a Cloudflare R2 o S3, solo cambia el disco.
- Backups: la carpeta privada se copia fuera de Hostinger cada semana (Paso 25).

## 6. API REST

- Prefijo `/api/v1`, rutas por módulo, controladores delgados, Form Requests, API Resources.
- Respuestas JSON consistentes con los nombres del contrato del Paso 0 (snake_case, centavos).
- Errores con códigos estables (`capacity_full`, `window_unavailable`, `terms_required`, etc.).
- Paginación y filtros en listados.
- CORS limitado al dominio de Angular. Rate limiting en login, subida de fotos, comprobantes y "Consultar mi pedido".
- Webhooks de PayPhone con verificación de autenticidad y control para no procesar dos veces el mismo evento.

## 7. Seguridad

- Solo `public/` accesible desde la web; `/.env` y `storage` devuelven 403 o 404.
- Doble factor para el administrador.
- Ningún secreto en Angular ni en GitHub.
- Asignación masiva controlada (`$fillable` / atributos `#[Fillable]` en cada modelo).
- La respuesta de "Consultar mi pedido" es siempre la misma.

## 8. Qué necesito aprobar contigo

1. Los ajustes de la sección 1 (sin Inventario, sin Roles, Cotización en lugar de Carrito).
2. Dinero en centavos enteros en lugar de `decimal`.
3. El orden de la sección 3.
4. La forma de guardar imágenes de la sección 5.

Con tu "aprobado" empiezo la **Etapa 3**: crear la estructura DDD y `/api/v1` en una rama nueva del repositorio `Ecommerce-Backend` (sin tocar tu base de datos real), con pruebas.

## 9. Convenciones de la API

- Los textos de los mensajes de la API (`message`) van **en español**, para que Angular los muestre directamente. Los nombres de campos (snake_case) y los `code` estables (`not_found`, `category_has_products`, ...) siguen en inglés porque son el contrato del Paso 0: Angular decide por `code`, nunca por el texto.
- Errores: `{"code": "not_found", "message": "..."}` con el código HTTP correspondiente.
- Los textos para el cliente (nombres, descripciones) salen en español porque son datos del negocio, no mensajes del sistema.

## 10. Cuentas y administración desde Angular (API)

Un solo login para clientes y administradores (módulo `Cuentas`). La respuesta trae el `role` (`customer` o `admin`) y Angular decide a dónde llevar al usuario: el cliente a su cuenta, el administrador al panel. Laravel nunca confía en el rol que diga el navegador: lo lee de la base de datos en cada petición. El panel es Angular y consume `/api/v1/admin/*`; no hay pantallas en Laravel.

- **Tokens sin paquetes extra** (Sanctum no se pudo instalar): token Bearer opaco de 64 caracteres; la tabla `api_tokens` solo guarda su SHA-256; la sesión del **administrador vence a las 2 horas** del ingreso (`ACCESS_TOKEN_ADMIN_MINUTES`, 120 por defecto; no se renueva con el uso) y la del **cliente no vence** (`expires_at` null) hasta que cierre sesión (en un dispositivo o en todos); `last_used_at` se actualiza cada 5 min.
- `users.role`: `customer` (por defecto) o `admin`. La migración marca como `admin` a los usuarios que ya existían.
- `POST /api/v1/auth/register` `{name, email, password, password_confirmation, device?}` → 201 con la sesión. Siempre crea un **cliente**; mandar `role` no sirve. Límite: 5 por minuto por IP.
- `POST /api/v1/auth/login` `{email, password, device?}` → `{token, token_type, expires_at, expires_in, user:{name,email,role}}` (`expires_in` en segundos: Angular calcula el vencimiento con su propio reloj; `expires_at` y `expires_in` son `null` para un cliente, cuya sesión no vence). Errores: `422 validation_error`, `401 invalid_credentials` (misma respuesta exista o no el correo), `429` tras 5 intentos por minuto.
- `GET /api/v1/auth/me`, `POST /api/v1/auth/logout` (esta sesión), `POST /api/v1/auth/logout-all` (todas). Header: `Authorization: Bearer <token>`. Sin sesión: `401 unauthenticated`.
- Middleware `auth.token` (cualquier sesión) y `admin` (solo administrador: `401` sin sesión, `403 forbidden` si es cliente). Fallan cerrado.
- Crear administrador: `php artisan admin:crear correo@dominio.com --nombre="Sele"` (pide la contraseña por consola, mínimo 12 caracteres; si el correo ya existe, lo convierte en administrador y cambia su clave).
- Pendiente: doble factor (2FA) para el administrador, con recordar dispositivo.
- CORS: solo los orígenes de `FRONTEND_URL` (varios separados por coma).
- Documento maestro: las "cuentas de cliente" figuran en "solo con mucho volumen"; se adelantan por decisión del desarrollador (2026-11-07).

### Rutas de administración (solo rol `admin`)
Errores comunes: `401 unauthenticated`, `403 forbidden` (cliente), `422 validation_error` con `errors` por campo en español, `404 not_found`.

- `GET /api/v1/admin/categories` → `{data:[{slug,name,headline,intro_html,sort_order,is_active,seo_title,seo_description,products_count,subcategories_count,updated_at}]}` (incluye las inactivas).
- `POST /api/v1/admin/categories` `{name, headline?, intro_html?, sort_order?, is_active?, seo_title?, seo_description?}` → `201`. El `slug` **siempre** se genera del nombre (minúsculas, sin tildes, espacios como `-`: "Día de la Madre" → `dia-de-la-madre`); si se envía, se ignora. Nombre repetido o parecido: `422`.
- `PUT /api/v1/admin/categories/{slug}`: edita; el `slug` no se puede cambiar (rompería los enlaces publicados). Una categoría inactiva desaparece de la tienda pública.
- `DELETE /api/v1/admin/categories/{slug}`: nunca borra productos. Si la categoría tiene productos (activos o no) responde `409`:
  `{"code":"category_has_products","message":"...","products_count":4,"products":[{"sku","slug","name"}]}` para que Angular muestre cuáles son. Sin productos: `204` (borrado lógico).
- API pública del catálogo (sin sesión): `GET /api/v1/categories` (cada categoría con `image` de portada, `short_description` y sus subcategorías), `GET /api/v1/categories/{slug}?subcategory=`, `GET /api/v1/products?limit=` (productos activos, los más nuevos primero, hasta 100; cada uno con `category`, `subcategory` y `search_terms` para la búsqueda) y `GET /api/v1/products/{slug}`. Nunca devuelven el costo interno.
- Campañas (hecho, solo administrador): `GET|POST /api/v1/admin/campaigns`, `GET|PUT|DELETE /api/v1/admin/campaigns/{slug}`. El `slug` sale del nombre; si se cambia el nombre cambia la dirección y la anterior queda guardada en `campaign_slug_redirects` (migración `2026_11_11_110001_direcciones_anteriores_de_campanas`): `GET /campaigns/{slug-anterior}` responde con la campaña, cuyo `slug` es el actual, y Angular redirige. Volver a un nombre anterior libera esa dirección; eliminar la campaña borra también sus direcciones anteriores. Datos: nombre, fechas anuales `start_md`, `end_md`, `event_md` (`MM-DD`, se acepta 02-29), lema, frase del banner, activa, título y descripción para Google, y `products` (SKU en el orden elegido). Cada campaña devuelve además `state` (`active`/`upcoming`) y `season_year`, calculados por Laravel con la fecha de Guayaquil. Una campaña no pertenece a ninguna categoría (migración `2026_11_11_120001_campanas_sin_categoria`): solo muestra, en temporada, los productos que elige el administrador.
- Portada con video y mensaje de cierre de las campañas (hecho): la portada de una campaña puede ser una foto (`POST …/media`) y/o un video MP4 de hasta 1 minuto y 100 MB (`POST /api/v1/admin/campaigns/{slug}/video`, campos `video` y `poster` opcional); la API pública los entrega en `theme.cover` y `theme.video`. Al terminar la temporada se puede mostrar un mensaje de despedida: `closing_message` (máx. 160) y `closing_until_md` (`MM-DD`, obligatoria si hay mensaje, debe caer después del fin y antes del siguiente inicio; la valida `TemporadaDeCampana::despedidaValida`). Entre el día siguiente al fin y esa fecha el `state` es `closing` y `GET /campaigns/current` (banner de la página principal) incluye `closing_message`; pasada la fecha el banner desaparece (`state` vuelve a `upcoming`). La página de la campaña (`GET /campaigns/{slug}`) devuelve siempre `closing_message` y sigue existiendo: Angular muestra ahí el mensaje de cierre siempre que la campaña no esté en temporada. `GET /campaigns/current` devuelve la campaña en temporada o, si no hay ninguna, la que se está despidiendo. Migración `2026_11_11_100001_cierre_de_campanas`.
- Diseño de cada campaña (hecho): `theme_color` (`#RRGGBB`) y `emoji` opcionales en el formulario, y la portada es la primera imagen de la campaña (`GET|POST /api/v1/admin/campaigns/{slug}/media`, carpeta `campaigns/{slug}/`; se borra con la campaña). La API pública agrega `theme {color, emoji, cover}` a `GET /campaigns/{slug}`. `GET /api/v1/campaigns/current` (público) devuelve siempre 200 con `data`: la campaña activa que está en temporada hoy —si hay varias, la del evento más próximo— o `null`; es lo que usa el banner de la página principal.
- `GET|POST /api/v1/admin/categories/{categoria}/subcategories`, `PUT|DELETE .../subcategories/{slug}`: igual que las categorías. El `slug` se genera del nombre (con guiones, único dentro de la categoría) y no cambia. `DELETE` con productos responde `409`:
  `{"code":"subcategory_has_products","message":"...","products_count":2,"products":[{"sku","slug","name"}]}`; los productos ya eliminados no bloquean.
- Productos: `GET /api/v1/admin/products?category=&q=` (todos, activos o no, con `cost_cents`; `q` busca en nombre y SKU), `GET .../products/{sku}`, `POST .../products`, `PUT .../products/{sku}`, `DELETE .../products/{sku}` (borrado lógico).
  Campos: `name`, `category` (slug), `subcategory` (slug, debe pertenecer a la categoría), `type` (`personalizable`|`complemento`), `base_price_cents`, `cost_cents?`, `size_text?`, `material_text?`, `includes[]?`, `description_html?`, `is_available?`, `is_active?`, `search_synonyms[]?`, `sku?`.
  El `slug` siempre sale del nombre (guiones) y el `sku` no cambia nunca ni se reutiliza (ni el de un producto eliminado); sin `sku` se genera `JDS-XXXXXX`. Cambiar de categoría sin indicar subcategoría deja la subcategoría vacía. Dinero siempre en centavos enteros.
- Productos (reglas del 8 nov):
  - **Código (SKU) automático**, solo lectura: `PREFIJO-NNN` (ejemplo `JDS-001`). Prefijo y dígitos en `config/catalogo.php` (`CATALOGO_SKU_PREFIJO`, `CATALOGO_SKU_DIGITOS`, por defecto `JDS` y 3). Para pasar a 4 dígitos basta cambiar la variable: los códigos viejos no cambian y, si el número supera los dígitos, el código se alarga (`JDS-1000`). Toma el número más alto que existió (incluidos eliminados) y suma uno, así un código nunca se reutiliza. Si el cliente envía `sku`, se ignora. `GET /admin/products/next-sku` muestra cuál sería el siguiente.
  - **Categoría y subcategoría obligatorias** (la subcategoría debe pertenecer a la categoría). Una categoría sin subcategorías no admite productos hasta que se le cree una. Al cambiar de categoría hay que elegir también una subcategoría de la nueva.
  - **Descuento** `discount_percent` (0 a 90, hasta 2 decimales; vacío = 0). Laravel calcula `discount_cents` y `final_price_cents` (centavos enteros, mitad hacia arriba) y la tienda pública ya muestra ese precio. `GET /admin/products/price-preview?base_price_cents=&discount_percent=` devuelve el cálculo para mostrarlo mientras se escribe. Es el descuento fijo del producto; el motor de promociones con vigencia (Paso 7) lo tomará como una regla más.
- Imágenes (hecho, solo administrador; solo una puede ser "principal" (`front`): si ya hay una, se rechaza con 422 hasta cambiarle el tipo a la actual; la principal sale primera en la ficha; la descripción `alt` es obligatoria al subir y al editar): `GET/POST /admin/products/{sku}/media` y `GET/POST /admin/categories/{slug}/media` (multipart, campo `image`, opcionales `role` y `alt`); `PUT/DELETE /admin/media/{id}`.
  - Laravel valida por el **contenido real** (JPG, PNG o WebP; no GIF ni SVG), máximo 8 MB, entre 300 × 300 y 40 megapíxeles, y hasta 12 imágenes por producto o categoría. Corrige la orientación de las fotos de celular.
  - Genera WebP de 600 y 1080 px de ancho (nunca agranda). Las versiones van al disco público `media` (`products/{sku}/{nombre-aleatorio}-{ancho}.webp`) y el original al disco privado (`originals/...`). En la base de datos solo quedan rutas relativas. El nombre del archivo subido nunca se usa.
  - Al eliminar una imagen se borran sus archivos. La API pública arma las URL con el disco `media`.
- Videos (hecho, solo administrador; MP4 H.264, sin transcodificar ni FFmpeg: el servidor lee el archivo en PHP y valida formato, códec, duración y peso; se guarda tal cual en `media`):
  - `POST /admin/products/{sku}/video` y `POST /admin/categories/{slug}/video` (banner): máx. **1 minuto** y 100 MB cada uno. Multipart: `video` y, opcional, `poster` (imagen que se convierte a WebP y se usa de portada); `alt` opcional. Sin límite de cantidad de videos por producto o categoría (el tope de 12 es solo para imágenes). Se elimina con `DELETE /admin/media/{id}` (borra el MP4 y su portada). Una imagen no puede pasar a video ni al revés.
  - Tolerancia de 0,25 s sobre el máximo. Si el MP4 no tiene portada, la tienda muestra el primer cuadro (`preload="metadata"`).
  - API pública: `GET /categories/{slug}` agrega `hero_videos` (lista de `{type:'video', url, poster, duration_s}`), `hero_video` (el primero o null) y `hero` sigue siendo la foto; la ficha del producto lleva el video dentro de `media`.
- Encabezado de la página /categorias (hecho): editable como la portada de inicio. `GET /api/v1/categories-page` (público: `title` y `subtitle`, null = textos de siempre; `items` imágenes y videos en orden, vacío = las fotos de las categorías). Administrador: `GET|POST /api/v1/admin/categories-page/hero`, `DELETE …/hero/{id}` y `GET|PUT …/text` (título máx. 80, subtítulo máx. 160). Se guarda en `settings` (`categorias_hero`, `categorias_texto`) y los archivos en `media/categories-page/`.
- Portada de la página principal (hecho): lista SIN límite de imágenes y videos (cada video máx. **1 minuto**, 100 MB) que reemplaza la foto de ejemplo; la tienda los muestra uno tras otro. Se guarda en `settings` (clave `home_hero`, lista con `id` por elemento; se sigue leyendo el formato anterior de un solo objeto) y sus archivos en `media/home/`. `GET /api/v1/home/hero` (público, siempre 200; `items` lista en orden, vacía si no hay y la tienda usa su imagen de siempre; `data` el primero) y, solo administrador, `GET|POST /api/v1/admin/home/hero` (multipart: `image` + `alt`, o `video` + `poster` opcional) y `DELETE /api/v1/admin/home/hero/{id}` (borra sus archivos).
- Límites de subida de PHP (`upload_max_filesize`, `post_max_size`) deben permitir los pesos de arriba en el servidor.
  - Servidor: el límite de PHP `upload_max_filesize` (2 MB por defecto) y `post_max_size` deben subirse a 10 MB y 12 MB en hPanel (Avanzado → Configuración de PHP).
- Pendiente (cuando termine la subida en el panel): campos de Google (`seo_title`, `seo_description`, `intro_html`) en categorías y productos.
- **Pendiente de avisar:** campos de Google (`seo_title`, `seo_description`, `intro_html`) en el formulario de categorías y productos del panel; ya existen en la base de datos.

### Vista previa de campañas (administrador)
`GET /api/v1/admin/campaigns/{slug}/preview` (solo rol administrador, caso de uso `PrevisualizarCampana`) devuelve la campaña con el mismo contrato público pero siempre como `active` y con los productos elegidos, aunque esté inactiva o fuera de fechas. Angular la usa para que solo el administrador entre a la URL de una campaña no publicada; el público es llevado a la página principal.

## 11. Configurador (accesorios y ubicaciones; todo sale de la base de datos)

Módulo `app/Core/Configurador`. El configurador (dónde va cada accesorio sobre la maceta) deja de leer un archivo fijo: accesorios, ubicaciones y configuraciones viven en MySQL con llaves foráneas reales. Todo lo que se configura es **solo del administrador** (`/api/v1/admin/*`, middleware `admin`); el cliente solo lee.

**Tablas** (migración `2026_11_12_100001_create_configurador_tables`):

| Tabla | Qué guarda |
| --- | --- |
| `accessory_categories` | Categorías de accesorios (Moda, Personalización, Objetos, Animales, Flores…). |
| `accessories` | Accesorio: nombre, categoría (FK), `type` `imagen` o `texto`, `behavior` (comportamiento especial en el personalizador: `lentes`, `gafas`, `marco_foto`, `marco_frase` o nulo; lo tiene como máximo un accesorio, así el personalizador no depende del número del accesorio), texto de ejemplo, `image_variants` (rutas relativas de la imagen grande de 1080 px y la miniatura de 160 px, ambas WebP con transparencia), `alt`, `is_active`. |
| `locations` | Ubicaciones (slots): Cabeza, Pie izquierdo… |
| `location_category_rules` | Qué categorías admite cada ubicación: `allow` (solo esas) o `exclude` (todas menos esas). |
| `configurator_configs` | Una configuración por **categoría de producto** (`category_id`) o **por producto** (`product_id`). |
| `configurator_config_locations` | Por ubicación en esa configuración: `state` (libre, fija, deshabilitada), `priority` (1 = la primera que se propone al cliente), `layer` (z-index), accesorio fijo (FK) y los valores por defecto (`x`, `y`, `width`, `height` en % del visor; `rot_x`, `rot_y`, `rot_z` en grados; `mirror`). Sin valores = nulos. |
| `configurator_config_accessories` | Accesorios marcados (los que el cliente puede elegir). |
| `configurator_config_exceptions` | Valores propios de UN accesorio en UNA ubicación. |
| `products.uses_own_configurator` | `false` = el producto usa la configuración de su categoría; `true` = tiene la suya. |

**Regla de resolución:** el producto usa la configuración de su categoría hasta que el administrador marca «configuración propia». Al marcarla se puede partir de una copia de la de la categoría (`copy_from_category`). Volver a la categoría no borra la propia: queda guardada y deja de usarse. Si el producto es propio y aún no se guardó nada, el cliente ve el configurador vacío (`configured: false`).

**API del administrador**
- `GET|POST /admin/accessory-categories`, `PUT|DELETE /admin/accessory-categories/{id}` (con accesorios no se elimina: 409 `in_use`).
- `GET|POST /admin/accessories`, `PUT|DELETE /admin/accessories/{id}`, `POST /admin/accessories/{id}/image` (multipart `image` + `alt` obligatorio; valida el contenido real, PNG/JPG/WebP, de 64 × 64 px a 8 MB; reemplaza y borra la anterior). Un accesorio que está en alguna configuración no se elimina (409): se desactiva (`is_active=false`) y deja de ofrecerse.
- `GET|POST /admin/locations`, `PUT|DELETE /admin/locations/{id}` con `allowed_category_ids` y `excluded_category_ids` (una categoría no puede estar en ambas). Una ubicación usada en alguna configuración no se elimina.
- `GET|PUT /admin/configurator/categories/{slug}` y `GET|PUT /admin/configurator/products/{sku}`. El `PUT` reemplaza todo: `{locations:[{location_id,state,priority,layer,fixed_accessory_id,preset}], accessory_ids:[], exceptions:[{accessory_id,location_id,x,y,width,height,rot_x,rot_y,rot_z,mirror}]}`. Reglas (422 en español): una ubicación `fija` necesita elemento fijo marcado y valores; una excepción es de un accesorio marcado, no repetida y no en una ubicación deshabilitada; rangos: x/y 0–100, ancho/alto 1–100, `rot_x` ±90, `rot_y`/`rot_z` ±180, prioridad 1–99, capa 0–99. Guardar la configuración propia de un producto que usa la de su categoría responde 409.
- `PUT /admin/configurator/products/{sku}/mode` con `{uses_own_configurator, copy_from_category?}`.

**API pública:** `GET /api/v1/products/{slug}/configurator` →
`{source:'product'|'category', configured, locations:[{location_id,name,allowed_categories,excluded_categories,state,priority,layer,fixed_accessory_id,preset}], accessories:[{id,name,type,behavior,text,category,image,thumb,alt}], exceptions:[…]}`. Solo accesorios marcados y activos. Caché de 60 s.

**Imagen para personalizar** (migración `2026_11_13_100001`): cada producto personalizable tiene la suya (`products.personalization_image`, rutas WebP de 1080 y 160 px) y es **obligatoria**. Se sube solo desde el producto: `POST /api/v1/admin/products/{sku}/personalization-image` (multipart, campo `image`; reemplaza la anterior; el configurador no sube nada). El producto la devuelve como `personalization_image` (URL o `null`). Regla: un producto personalizable solo puede estar activo si ya tiene la imagen (si no, 422 en `is_active`), así que al crear un producto se crea inactivo, se sube la imagen y luego se activa; los complementos no la necesitan. El configurador y el personalizador la leen como `base_image` (en la configuración del producto, en cada producto de la categoría y en `GET /products/{slug}/configurator`). **Ajuste del encuadre** (migración `2026_11_13_110001`): si la foto de un producto está más cerca, más lejos o corrida respecto a las demás de su categoría, se mueve la foto y no los accesorios: `PUT /api/v1/admin/configurator/products/{sku}/base-adjust` con `scale` (50 a 200 %, 100 = igual), `x` e `y` (-50 a 50, % del visor). Se guarda en `products` (`personalization_scale`, `personalization_offset_x`, `personalization_offset_y`) y se entrega como `base_adjust {scale, x, y}` junto a `base_image`. Compensa pequeñas diferencias de encuadre, no un cambio de ángulo.

**Sin datos de muestra:** el administrador crea todo desde el panel (categorías de accesorios, accesorios, ubicaciones y configuraciones). El cliente recibe **todos los accesorios activos**; los «marcados» y las excepciones solo definen dónde se coloca cada uno.
