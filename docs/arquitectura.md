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

- **Tokens sin paquetes extra** (Sanctum no se pudo instalar): token Bearer opaco de 64 caracteres; la tabla `api_tokens` solo guarda su SHA-256; vence a las `ACCESS_TOKEN_HOURS` (12 por defecto); `last_used_at` se actualiza cada 5 min.
- `users.role`: `customer` (por defecto) o `admin`. La migración marca como `admin` a los usuarios que ya existían.
- `POST /api/v1/auth/register` `{name, email, password, password_confirmation, device?}` → 201 con la sesión. Siempre crea un **cliente**; mandar `role` no sirve. Límite: 5 por minuto por IP.
- `POST /api/v1/auth/login` `{email, password, device?}` → `{token, token_type, expires_at, expires_in, user:{name,email,role}}` (`expires_in` en segundos: Angular calcula el vencimiento con su propio reloj). Errores: `422 validation_error`, `401 invalid_credentials` (misma respuesta exista o no el correo), `429` tras 5 intentos por minuto.
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
  - Servidor: el límite de PHP `upload_max_filesize` (2 MB por defecto) y `post_max_size` deben subirse a 10 MB y 12 MB en hPanel (Avanzado → Configuración de PHP).
- Pendiente (cuando termine la subida en el panel): campos de Google (`seo_title`, `seo_description`, `intro_html`) en categorías y productos.
- **Pendiente de avisar:** campos de Google (`seo_title`, `seo_description`, `intro_html`) en el formulario de categorías y productos del panel; ya existen en la base de datos.
