# Spec: Estado disabled de Métodos de envío (cards grises sin dirección)

**Fecha:** 2026-06-11
**Plugin:** ccm-checkout (mu-plugin)
**Estado:** Aprobado (diseño)

## Contexto

Tras desplegar el checkout en secciones (ver `2026-06-10-checkout-secciones-envio-design.md`),
la sección **Métodos de envío** vive en la columna principal y se refresca por el sistema de
fragments de WooCommerce. Hoy, cuando el cliente todavía no tiene una dirección que WooCommerce
pueda cotizar (sin ciudad, o dirección incompleta), `CCMCK_Shipping::render_cards()` devuelve el
texto plano *"No hay envíos disponibles para tu dirección."* (línea 65 de
`includes/class-ccmck-shipping.php`).

El usuario quiere que la sección **muestre los métodos enseguida**, incluso antes de tener
dirección, pero en **estado disabled** (gris, no seleccionable), en lugar de ese mensaje.

## Decisiones tomadas en brainstorming (2026-06-11)

- **Qué se ve en disabled:** las **cards reales de los métodos**, en gris, **sin precio**, con
  una nota tipo *"Ingresa tu dirección para ver el costo"*. El radio va deshabilitado (no
  seleccionable).
- **Fuente de los nombres:** **automática desde las Zonas de Envío de WooCommerce**
  (`WC_Shipping_Zones`). Cero configuración manual; siempre en sync con WC. Sin campo nuevo en
  Ajustes.
- **Sin JS nuevo:** el contenedor `#ccmck_shipping_methods` ya se reemplaza por fragments cuando
  el cliente completa la dirección; las cards reales (con precio y radios activos) sustituyen a
  las placeholder automáticamente.

## Resultado esperado

Sección "Métodos de envío" siempre visible, con tres estados:

```
Con tarifas reales        → cards con radio activo + precio (comportamiento actual, sin cambios)
Sin dirección / sin tarifa → nota "Ingresa tu dirección para ver el costo"
                             + cards grises disabled (Coordinadora, Recoger en tienda, …),
                               sin precio, radio deshabilitado
Sin zonas/métodos config. → fallback al texto actual "No hay envíos disponibles…"
```

## Arquitectura

Todo el cambio vive en `includes/class-ccmck-shipping.php` (lógica) y
`assets/ccmck-checkout.css` (estilo), respetando el estilo existente de la clase: métodos
**puros y testeables** separados de los wrappers acoplados a WooCommerce.

### 1. Recolectar labels de las zonas (PURO)
**Método nuevo:** `collect_zone_method_labels( array $zones ): array`

- Entrada: data normalizada de zonas — lista de paquetes de la forma
  `[ ['methods' => [ ['title' => 'Coordinadora', 'enabled' => true], … ] ], … ]`.
- Salida: lista de **títulos únicos** de los métodos **habilitados**, preservando el orden de
  aparición (dedup por título, descarta deshabilitados y títulos vacíos).
- Sin globals → testeable con datos de ejemplo.

### 2. Wrapper acoplado a WC
**Método nuevo:** `get_zone_method_labels(): array`

- Lee `WC_Shipping_Zones::get_zones()` **más** la zona 0 ("Resto del mundo",
  `WC_Shipping_Zones::get_zone( 0 )`).
- Para cada zona, recorre sus `get_shipping_methods( true )` (sólo habilitados) y toma
  `$method->get_title()` (fallback `$method->title`).
- Normaliza a la forma que espera `collect_zone_method_labels()` y delega en él.
- Devuelve `array()` si WC no está disponible o no hay zonas.

### 3. Render de placeholders (PURO)
**Método nuevo:** `render_placeholder_cards( array $labels ): string`

- Si `$labels` está vacío → devuelve `''` (el caller decide el fallback).
- Si hay labels → pinta:
  - Una nota: `<p class="ccmck-shipping-hint">Ingresa tu dirección para ver el costo</p>`.
  - Un `<ul class="ccmck-shipping-list ccmck-shipping-list--disabled">` con un
    `<li class="ccmck-shipping-method ccmck-shipping-method--disabled">` por label:
    - radio `disabled` (sin `name`, para no postear) con su `<label>`,
    - `<span class="ccmck-ship-label">` con el título,
    - **sin** `<span class="ccmck-ship-cost">` (no hay precio).
- Strings traducibles y escapados (`esc_html__`, `esc_attr`), igual que `render_cards()`.

### 4. Decisión de estado
**Método editado:** `render()`

Lógica nueva:

1. Construye los métodos reales con `build_methods( get_packages, chosen )` (igual que hoy).
2. Si hay al menos una `rate` real → `render_cards( $methods )` (sin cambios).
3. Si **no** hay rates reales → `render_placeholder_cards( get_zone_method_labels() )`.
4. Si eso devuelve `''` (no hay labels) → mensaje fallback actual
   *"No hay envíos disponibles para tu dirección."*

`render_cards()` deja de ser responsable del mensaje "no hay envíos" para el caso sin
dirección (ese caso ahora lo cubren placeholders); se conserva su `<p class="ccmck-no-shipping">`
sólo como fallback final invocado desde `render()`. (Se puede extraer ese `<p>` a una constante/
helper para no duplicarlo.)

### 5. Estilos
**Archivo:** `assets/ccmck-checkout.css` (editado)

- `.ccmck-shipping-hint`: nota tenue (color atenuado, tamaño pequeño, margen inferior).
- `.ccmck-shipping-method--disabled`: gris/atenuado (`opacity` reducida, `cursor:not-allowed`,
  `pointer-events:none`), reusando el lenguaje visual de las cards de envío reales pero sin el
  estado seleccionable.

## Archivos

| Acción | Archivo |
|--------|---------|
| Editar | `includes/class-ccmck-shipping.php` |
| Editar | `assets/ccmck-checkout.css` |
| Nuevo/editar | tests del envío (PHPUnit PHAR) |
| Sin tocar | templates, `ccm-checkout.php`, JS, sidebar |

## Tests (PHPUnit PHAR + stubs)

- `collect_zone_method_labels`: dedup de títulos repetidos, descarta deshabilitados, descarta
  títulos vacíos, preserva orden, lista vacía → `[]`.
- `render_placeholder_cards`: incluye cada label, marca `disabled`, **no** incluye costo, incluye
  la nota; lista vacía → `''`.
- (Regresión) `render_cards` con rates reales sigue intacto.

## Fuera de alcance (YAGNI)
- Campo de configuración manual de métodos en Ajustes (se descartó a favor de auto-zonas).
- Cotizar precios estimados con la dirección base de la tienda.
- Cambios en JS o en el flujo de fragments (ya cubre el reemplazo).

## Verificación (en vivo, chrome-devtools MCP)
Despliegue por File Manager (ver memoria `deploy-dev-server`). Tras subir:

1. `/pago/` con carrito y **sin ciudad** → la sección muestra la nota + cards grises disabled
   (Coordinadora, Recoger en tienda), sin precio, radios no seleccionables.
2. Seleccionar una ciudad (ej. Barranquilla) → vía AJAX, las placeholder se reemplazan por las
   cards reales con precio y radio activo (Coordinadora cotiza).
3. Confirmar que las cards disabled **no** se pueden seleccionar ni postean `shipping_method`.
4. Captura desktop.

## Notas de despliegue
- CSS auto-busta por `?ver=<filemtime>` (force_version ya activo).
- El PHP editado requiere **purgar OPcache** tras subir por File Manager.
