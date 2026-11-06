# Base de datos (MySQL en Hostinger)

## Relaciones

Todas las tablas tienen `id` como llave primaria y sus relaciones con llave foránea (verificado por pruebas).

```
categories 1─┬─* subcategories
             ├─* products *─┬─1 subcategories (opcional)
             └─* campaigns (related_category_id)
campaigns *─*  products   (tabla campaign_products, con orden)
media: cada foto o video pertenece a un producto (product_id) o a una categoría (category_id), ambos con llave foránea;
       si se borra el producto o la categoría, se borran sus registros de medios (los archivos se limpian aparte).
settings: parámetros del negocio (clave única).
```

Las llaves foráneas usan `restrictOnDelete` (no se puede borrar una categoría con productos) salvo `campaign_products`,
que se borra junto con su campaña.

## Crear las tablas en la base de Hostinger

Las tablas se crean con las migraciones (`database/migrations`). Nunca se escriben a mano.

1. **Credenciales:** copia `.env.example` a `.env` en tu computador y completa `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
   `DB_USERNAME` y `DB_PASSWORD` con los datos de tu base en hPanel. El `.env` no se sube a GitHub (está en `.gitignore`).
2. **Acceso remoto:** para conectarte desde tu computador, en hPanel → Bases de datos → *MySQL remoto* agrega tu IP.
   Si prefieres, corre los comandos por SSH dentro del servidor, donde el host es `localhost`.
3. **Crear las tablas:** `php artisan migrate` (solo crea lo que falta; no borra nada).
4. **Datos iniciales (opcional):**
   - `php artisan db:seed --class=CategoriasIniciales` — categorías y subcategorías del documento maestro.
   - `php artisan db:seed --class=AjustesIniciales` — parámetros del negocio.
   - `php artisan db:seed --class=CampanasIniciales` — San Valentín.
   - `ProductosDePrueba` es solo para desarrollo: **no** se ejecuta en producción.

Nunca ejecutar `migrate:fresh`, `migrate:refresh` ni `db:wipe`.
