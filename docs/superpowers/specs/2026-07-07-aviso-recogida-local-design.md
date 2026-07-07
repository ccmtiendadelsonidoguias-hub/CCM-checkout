# Aviso de recogida local en el checkout — Diseño

## Contexto y objetivo

Cuando el cliente elige el método de envío **"Recogida local"** (`ccmck_local_pickup`,
inyectado por `CCMCK_Pickup` vía `woocommerce_package_rates`), hoy no hay ningún
aviso que le indique que el pedido **no se envía a domicilio** y que debe recogerlo
en el local físico en Barranquilla. El cliente puede elegir pickup sin entender esa
implicación.

**Objetivo:** mostrar un aviso —editable desde Ajustes— que aparezca **solo** cuando
"Recogida local" está seleccionado, ubicado justo debajo de las tarjetas de método de
envío, con el estilo de marca de la tienda.

## Decisiones (aprobadas con el usuario)

- **Ubicación:** dentro de la sección "Métodos de envío" del checkout, justo debajo del
  contenedor de tarjetas (`#ccmck_shipping_methods`).
- **Texto:** editable desde *Ajustes → Checkout CCM → Contenido → Envío*. Vacío = no se
  muestra el aviso.
- **Texto por defecto:**
  > 📍 Recogida en tienda: al elegir esta opción tu pedido no se envía a domicilio.
  > Deberás recogerlo personalmente en nuestro local en Barranquilla. Te escribiremos
  > por WhatsApp cuando esté listo para recoger.
- **Estilo:** callout "info de marca" — borde izquierdo + fondo teñido con el color de
  acento (`var(--ccmck-accent)`, rojo CCM). El pin 📍 vive en el texto (no se añade un
  icono aparte, para no duplicarlo si el usuario lo conserva en su redacción).
- **Visibilidad instantánea** vía JS (no depende del round-trip AJAX de WooCommerce).

## Enfoque técnico

**El servidor pinta el aviso una vez (desde el ajuste), oculto por CSS; el JS lo
muestra/oculta según el método de envío elegido.**

Motivo: el contenedor `#ccmck_shipping_methods` se **reemplaza por completo** en cada
`updated_checkout` (fragment `woocommerce_update_order_review_fragments` →
`CCMCK_Shipping::fragments()`). Si el aviso viviera dentro, se borraría en cada refresco
y solo reaparecería tras el round-trip AJAX (con el retraso del skeleton). Colocándolo
como **hermano fuera del fragment** (dentro de `.ccmck-shipping-section` pero fuera de
`#ccmck_shipping_methods`), persiste en el DOM y el JS lo togglea al instante al pulsar
el radio de envío.

La detección de "¿es pickup?" ya existe en `ccmckSyncPickupRequired()`
(`assets/ccmck-checkout.js`), que corre en: `change` de `input[name^="shipping_method"]`,
`change` de `input[name="payment_method"]`, `updated_checkout` y en el arranque. Se
reutiliza esa misma función para togglear la clase de visibilidad del aviso — sin nuevos
bindings ni una segunda fuente de verdad.

**Alternativa considerada y descartada:** renderizar el aviso dentro de
`CCMCK_Shipping::render()` solo cuando el servidor detecta pickup elegido (sin JS nuevo,
anidado literalmente bajo la card). Se descarta por el retraso del AJAX (skeleton) y
porque duplicaría la lógica de "¿es pickup?" que el JS ya calcula.

## Componentes

### 1. Ajuste `pickup_notice` — `CCMCK_Settings`

- `defaults()`: añadir
  ```php
  'pickup_notice' => '📍 Recogida en tienda: al elegir esta opción tu pedido no se envía a domicilio. Deberás recogerlo personalmente en nuestro local en Barranquilla. Te escribiremos por WhatsApp cuando esté listo para recoger.',
  ```
- `sanitize()`: añadir
  ```php
  $out['pickup_notice'] = sanitize_textarea_field( $input['pickup_notice'] ?? '' );
  ```
  `sanitize_textarea_field` conserva saltos de línea, elimina tags/HTML y es
  **idempotente** (WP llama el callback dos veces por `register_setting`; reejecutar
  sobre la salida da el mismo resultado). Ver [[wp-sanitize-callback-idempotent]].

### 2. UI en Ajustes — `includes/views/settings-page.php`

En la pestaña **Contenido** (`data-tab="contenido"`), sección **"Envío"** (después del
repeater `shipping_cards`, antes de cerrar el panel):

- Subtítulo `<h3>Aviso de recogida local</h3>` + `<p class="description">` explicando que
  aparece solo al elegir "Recogida local" y que, si se deja vacío, no se muestra.
- Un `<textarea>`:
  ```php
  <textarea name="ccmck_settings[pickup_notice]" rows="3" class="large-text"><?php
      echo esc_textarea( $s['pickup_notice'] );
  ?></textarea>
  ```

### 3. `CCMCK_Pickup::notice_markup( string $text ): string` — método puro

- Devuelve `''` si `'' === trim( $text )` (así "vacío = no se muestra" y el template no
  pinta nada).
- Si hay texto, devuelve el elemento (oculto por CSS por defecto):
  ```html
  <div class="ccmck-pickup-notice" data-ccmck-pickup-notice>{nl2br(esc_html($text))}</div>
  ```
- Escapado: `nl2br( esc_html( $text ) )` — respeta los saltos de línea del ajuste sin
  permitir HTML. El emoji del texto se conserva (`esc_html` no lo altera).
- Sin atributo `hidden`: la ocultación inicial la hace el CSS (`display:none`), de modo
  que sin JS el aviso simplemente no aparece y no hay parpadeo en la carga.

Se ubica en `CCMCK_Pickup` (dueño de la UI de pickup) y es puro → testeable sin WP.

### 4. Render en la plantilla — `templates/checkout/form-checkout.php`

Dentro de `<section class="ccmck-shipping-section">`, tras el bloque skeleton
`.ccmck-skel-shipping` y antes de `</section>`:

```php
<?php
// HTML ya escapado dentro de CCMCK_Pickup::notice_markup().
echo CCMCK_Pickup::notice_markup( (string) CCMCK_Settings::get( 'pickup_notice', '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
```

### 5. Toggle en JS — `assets/ccmck-checkout.js`

Al final de `ccmckSyncPickupRequired()` (que ya calcula el bool `pickup`):

```js
$( '[data-ccmck-pickup-notice]' ).toggleClass( 'is-visible', pickup );
```

No requiere nuevos listeners: la función ya está enganchada a los cambios de envío/pago,
a `updated_checkout` y al arranque. En la carga inicial, si la sesión recordaba pickup,
el aviso se muestra correctamente.

### 6. Estilo — `assets/ccmck-checkout.css`

Callout de marca, oculto por defecto, visible con `.is-visible`:

```css
.ccmck-pickup-notice {
    display: none;
    margin-top: 12px;
    padding: 12px 14px;
    border-radius: 8px;
    border-left: 3px solid var(--ccmck-accent, #e63946);
    background: #fdeff0; /* fallback rojo CCM muy claro */
    background: color-mix(in srgb, var(--ccmck-accent, #e63946) 7%, #fff);
    font-size: 14px;
    line-height: 1.45;
    color: #333;
}
.ccmck-pickup-notice.is-visible { display: block; }
```

El borde usa la variable de acento (sigue el color de la tienda si se cambia). El fondo
teñido usa `color-mix` con fallback fijo para navegadores sin soporte.

## Testing

**`tests/PickupTest.php`** (método puro `notice_markup`):
- `''` y `'   '` (solo espacios) → devuelven `''`.
- Texto normal → contiene la clase `ccmck-pickup-notice`, el atributo
  `data-ccmck-pickup-notice` y el texto.
- Texto con salto de línea (`"a\nb"`) → contiene `<br` (nl2br aplicado).
- Escape XSS: `'<script>alert(1)</script>'` → la salida **no** contiene `<script>` crudo.

**`tests/SettingsTest.php`**:
- `defaults()` incluye `pickup_notice` con el texto por defecto.
- `sanitize(['pickup_notice' => '<b>hola</b>'])` → sin tags.
- Idempotencia: `sanitize(sanitize($x))['pickup_notice'] === sanitize($x)['pickup_notice']`.
- Ausente en input → cae a `''` (no rompe).

## Archivos afectados

| Archivo | Cambio | Deploy |
|---|---|---|
| `includes/class-ccmck-settings.php` | default + sanitize de `pickup_notice` | PHP → OPcache |
| `includes/views/settings-page.php` | textarea en Contenido → Envío | PHP → OPcache |
| `includes/class-ccmck-pickup.php` | `notice_markup()` | PHP → OPcache |
| `templates/checkout/form-checkout.php` | render del aviso | PHP → OPcache |
| `assets/ccmck-checkout.js` | toggle en `ccmckSyncPickupRequired` | auto cache-bust |
| `assets/ccmck-checkout.css` | estilo del callout | auto cache-bust |
| `tests/PickupTest.php`, `tests/SettingsTest.php` | tests | — |
| `docs/CHANGELOG.md` | entrada en *[Sin publicar] → Añadido* | — |

## Despliegue

- PHP → subir por File Manager + **purgar OPcache**.
- CSS/JS → auto cache-bust por `filemtime` (`CCMCK_Assets::asset_version()` + `force_version`),
  sin OPcache.
- Ver [[deploy-dev-server]].

## Fuera de alcance (YAGNI)

- Integración con proveedor de email/notificaciones al elegir pickup.
- Dirección/mapa embebido (el texto editable puede incluir la dirección exacta).
- Comportamiento sin JavaScript (el aviso queda oculto; el checkout ya depende del AJAX
  de WooCommerce).
- Un toggle "activar/desactivar" aparte: dejar el texto vacío ya desactiva el aviso.
