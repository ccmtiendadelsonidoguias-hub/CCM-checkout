# Aviso de recogida local en el checkout — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mostrar un aviso editable bajo las tarjetas de envío que aparezca solo cuando el cliente elige "Recogida local", avisando que debe recoger el pedido en el local de Barranquilla.

**Architecture:** El servidor pinta el aviso una vez (desde el ajuste `pickup_notice`), oculto por CSS, como hermano fuera del fragment AJAX `#ccmck_shipping_methods`. El JS existente `ccmckSyncPickupRequired()` —ya enganchado al cambio de envío/pago, a `updated_checkout` y al arranque— togglea la clase `.is-visible` según si el método elegido es pickup. La lógica de markup es un método puro testeable en `CCMCK_Pickup`.

**Tech Stack:** PHP 8.5 (sin Composer), WooCommerce (checkout clásico), jQuery, PHPUnit vía PHAR con stubs de WP.

## Global Constraints

- **Entorno de tests:** sin Composer/wp-cli. Se corre con `php phpunit.phar` desde la raíz del plugin (`mu-plugins/ccm-checkout/`), que usa `phpunit.xml` → `tests/bootstrap.php` (stubs de WP).
- **Escape de salida:** todo HTML impreso va escapado. `notice_markup()` usa `nl2br( esc_html( $text ) )`; la plantilla imprime su salida ya escapada con el comentario `phpcs:ignore`.
- **Ajuste vacío = sin aviso:** no hay toggle aparte; `pickup_notice` vacío no pinta nada.
- **Texto por defecto (verbatim):** `📍 Recogida en tienda: al elegir esta opción tu pedido no se envía a domicilio. Deberás recogerlo personalmente en nuestro local en Barranquilla. Te escribiremos por WhatsApp cuando esté listo para recoger.`
- **ID de tarifa pickup (ya existente):** `ccmck_local_pickup` (constante `CCMCK_Pickup::RATE_ID`; en JS `CCMCK_PICKUP_ID`).
- **Despliegue:** los PHP (`class-ccmck-settings.php`, `class-ccmck-pickup.php`, `views/settings-page.php`, `templates/checkout/form-checkout.php`) suben por File Manager + **purgar OPcache**. `ccmck-checkout.css`/`.js` auto cache-bust por `filemtime`. **No** hace falta subir `CCMCK_VERSION` (los assets del checkout se bustan solos; no se toca el JS de admin).
- **Commits:** en el repo propio del plugin (`mu-plugins/ccm-checkout/.git`), rama `main` (convención del repo).

---

### Task 1: `CCMCK_Pickup::notice_markup()` — método puro

**Files:**
- Modify: `includes/class-ccmck-pickup.php` (añadir método tras `relax_fields()`)
- Test: `tests/PickupTest.php` (añadir métodos)

**Interfaces:**
- Consumes: nada.
- Produces: `CCMCK_Pickup::notice_markup( string $text ): string` — devuelve `''` si el texto (tras `trim`) queda vacío; si no, `'<div class="ccmck-pickup-notice" data-ccmck-pickup-notice>' . nl2br( esc_html( $text ) ) . '</div>'`.

- [ ] **Step 1: Write the failing tests**

Añadir al final de la clase en `tests/PickupTest.php` (antes de la última `}`):

```php
    public function test_notice_markup_empty_string_returns_empty(): void {
        $this->assertSame( '', CCMCK_Pickup::notice_markup( '' ) );
    }

    public function test_notice_markup_whitespace_returns_empty(): void {
        $this->assertSame( '', CCMCK_Pickup::notice_markup( "   \n  " ) );
    }

    public function test_notice_markup_wraps_text_with_hook_attribute(): void {
        $html = CCMCK_Pickup::notice_markup( 'Recoge en tienda' );
        $this->assertStringContainsString( 'ccmck-pickup-notice', $html );
        $this->assertStringContainsString( 'data-ccmck-pickup-notice', $html );
        $this->assertStringContainsString( 'Recoge en tienda', $html );
    }

    public function test_notice_markup_preserves_line_breaks(): void {
        $html = CCMCK_Pickup::notice_markup( "linea1\nlinea2" );
        $this->assertStringContainsString( '<br', $html );
    }

    public function test_notice_markup_escapes_html(): void {
        $html = CCMCK_Pickup::notice_markup( '<script>alert(1)</script>' );
        $this->assertStringNotContainsString( '<script>', $html );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php phpunit.phar --filter notice_markup`
Expected: FAIL/ERROR — `Call to undefined method CCMCK_Pickup::notice_markup()`.

- [ ] **Step 3: Write minimal implementation**

En `includes/class-ccmck-pickup.php`, añadir el método justo después de `relax_fields()` (antes de `init()`):

```php
    /**
     * Markup del aviso de recogida local. Oculto por CSS por defecto; el JS le
     * añade .is-visible cuando el envío elegido es pickup. Devuelve '' si el
     * texto está vacío (así "vacío = no se muestra"). PURO.
     *
     * @param string $text Texto del ajuste pickup_notice.
     */
    public static function notice_markup( string $text ): string {
        $text = trim( $text );
        if ( '' === $text ) {
            return '';
        }
        return '<div class="ccmck-pickup-notice" data-ccmck-pickup-notice>'
            . nl2br( esc_html( $text ) )
            . '</div>';
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php phpunit.phar --filter notice_markup`
Expected: PASS (5 tests, OK).

- [ ] **Step 5: Commit**

```bash
git add includes/class-ccmck-pickup.php tests/PickupTest.php
git commit -m "feat(pickup): CCMCK_Pickup::notice_markup() para el aviso de recogida local"
```

---

### Task 2: Ajuste `pickup_notice` (default + sanitize) + stub de test

**Files:**
- Modify: `tests/bootstrap.php` (añadir stub `sanitize_textarea_field`)
- Modify: `includes/class-ccmck-settings.php` (default en `defaults()`, línea de saneo en `sanitize()`)
- Test: `tests/SettingsTest.php` (añadir métodos)

**Interfaces:**
- Consumes: nada.
- Produces: clave de ajuste `pickup_notice` (string) presente en `CCMCK_Settings::defaults()` y saneada en `CCMCK_Settings::sanitize()` con `sanitize_textarea_field`. Stub de test `sanitize_textarea_field( $str ): string`.

- [ ] **Step 1: Write the failing tests**

Añadir al final de la clase en `tests/SettingsTest.php` (antes de la última `}`):

```php
    public function test_defaults_include_pickup_notice(): void {
        $d = CCMCK_Settings::defaults();
        $this->assertArrayHasKey( 'pickup_notice', $d );
        $this->assertStringContainsString( 'Barranquilla', $d['pickup_notice'] );
    }

    public function test_sanitize_pickup_notice_strips_tags(): void {
        $out = CCMCK_Settings::sanitize( array( 'pickup_notice' => '<b>Recoge</b> en <script>x</script>tienda' ) );
        $this->assertStringNotContainsString( '<b>', $out['pickup_notice'] );
        $this->assertStringNotContainsString( '<script>', $out['pickup_notice'] );
        $this->assertStringContainsString( 'Recoge', $out['pickup_notice'] );
    }

    public function test_sanitize_pickup_notice_missing_becomes_empty(): void {
        $this->assertSame( '', CCMCK_Settings::sanitize( array() )['pickup_notice'] );
    }

    public function test_sanitize_pickup_notice_is_idempotent(): void {
        $once  = CCMCK_Settings::sanitize( array( 'pickup_notice' => "Recoge\nen tienda <b>x</b>" ) )['pickup_notice'];
        $twice = CCMCK_Settings::sanitize( array( 'pickup_notice' => $once ) )['pickup_notice'];
        $this->assertSame( $once, $twice );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php phpunit.phar --filter pickup_notice`
Expected: FAIL — la clave `pickup_notice` no existe aún en `defaults()`/`sanitize()` (además `sanitize_textarea_field` no está definida).

- [ ] **Step 3a: Add the WP stub to the test bootstrap**

En `tests/bootstrap.php`, justo después del bloque `sanitize_text_field` (tras la línea `}` que cierra ese `if`, ~línea 8), añadir:

```php
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $str ) {
        // Como WP: quita etiquetas pero conserva los saltos de línea.
        return trim( wp_strip_all_tags_stub( (string) $str ) );
    }
}
```

- [ ] **Step 3b: Add the default**

En `includes/class-ccmck-settings.php`, dentro del array de `defaults()`, justo después de la línea `'secure_badge' => 'Pago seguro con encriptación SSL',` añadir:

```php
            // Aviso que se muestra al elegir "Recogida local" (vacío = no se muestra).
            'pickup_notice'     => '📍 Recogida en tienda: al elegir esta opción tu pedido no se envía a domicilio. Deberás recogerlo personalmente en nuestro local en Barranquilla. Te escribiremos por WhatsApp cuando esté listo para recoger.',
```

- [ ] **Step 3c: Add the sanitize line**

En `includes/class-ccmck-settings.php`, en `sanitize()`, justo después de la línea `$out['secure_badge']   = sanitize_text_field( $input['secure_badge'] ?? '' );` añadir:

```php
        $out['pickup_notice']  = sanitize_textarea_field( $input['pickup_notice'] ?? '' );
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php phpunit.phar --filter pickup_notice`
Expected: PASS (4 tests, OK).

- [ ] **Step 5: Commit**

```bash
git add tests/bootstrap.php includes/class-ccmck-settings.php tests/SettingsTest.php
git commit -m "feat(settings): ajuste pickup_notice (default + sanitize) y stub de test"
```

---

### Task 3: Campo del aviso en la página de Ajustes

**Files:**
- Modify: `includes/views/settings-page.php` (textarea en Contenido → Envío, tras el repeater `shipping_cards`)

**Interfaces:**
- Consumes: `$s['pickup_notice']` (el array `$s = CCMCK_Settings::all()` ya disponible en la vista).
- Produces: campo de formulario `ccmck_settings[pickup_notice]` en el panel de Contenido.

- [ ] **Step 1: Insert the textarea field**

En `includes/views/settings-page.php`, entre el `</div>` que cierra el repeater de `shipping_cards` (la línea `</div>` inmediatamente después del botón `+ Añadir tarjeta`) y la línea `<table class="form-table" role="presentation">` del "Texto insignia de seguridad", insertar:

```php
<h3><?php esc_html_e( 'Aviso de recogida local', 'ccm-checkout' ); ?></h3>
<p class="description">
    <?php esc_html_e( 'Se muestra bajo las tarjetas de envío solo cuando el cliente elige "Recogida local". Si lo dejas vacío, no se muestra.', 'ccm-checkout' ); ?>
</p>
<textarea name="ccmck_settings[pickup_notice]" rows="3" class="large-text"><?php echo esc_textarea( $s['pickup_notice'] ); ?></textarea>
```

- [ ] **Step 2: Lint the modified file**

Run: `php -l includes/views/settings-page.php`
Expected: `No syntax errors detected in includes/views/settings-page.php`.

- [ ] **Step 3: Run the full suite (no regressions)**

Run: `php phpunit.phar`
Expected: OK (todos verdes).

- [ ] **Step 4: Commit**

```bash
git add includes/views/settings-page.php
git commit -m "feat(settings-ui): textarea del aviso de recogida local (Contenido -> Envio)"
```

---

### Task 4: Render del aviso en el checkout

**Files:**
- Modify: `templates/checkout/form-checkout.php` (dentro de `.ccmck-shipping-section`, tras el skeleton)

**Interfaces:**
- Consumes: `CCMCK_Pickup::notice_markup()` (Task 1), `CCMCK_Settings::get( 'pickup_notice', '' )` (Task 2).
- Produces: en el DOM del checkout, un `<div class="ccmck-pickup-notice" data-ccmck-pickup-notice>` como hermano de `#ccmck_shipping_methods`, dentro de `.ccmck-shipping-section`.

- [ ] **Step 1: Insert the render call**

En `templates/checkout/form-checkout.php`, entre el `</div>` que cierra `.ccmck-skel-shipping` (el bloque skeleton de envío) y el `</section>` que cierra `.ccmck-shipping-section`, insertar:

```php
				<?php
				// Aviso de recogida local: fuera de #ccmck_shipping_methods (que el
				// fragment AJAX reemplaza) para que persista; el JS lo muestra al
				// elegir pickup. HTML ya escapado dentro de notice_markup().
				echo CCMCK_Pickup::notice_markup( (string) CCMCK_Settings::get( 'pickup_notice', '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
```

- [ ] **Step 2: Lint the modified file**

Run: `php -l templates/checkout/form-checkout.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add templates/checkout/form-checkout.php
git commit -m "feat(checkout): render del aviso de recogida local bajo las tarjetas de envio"
```

---

### Task 5: Toggle en JS + estilo del callout (CSS)

**Files:**
- Modify: `assets/ccmck-checkout.js` (dentro de `ccmckSyncPickupRequired()`)
- Modify: `assets/ccmck-checkout.css` (bloque nuevo al final)

**Interfaces:**
- Consumes: el elemento `[data-ccmck-pickup-notice]` (Task 4) y la variable `pickup` (bool) que `ccmckSyncPickupRequired()` ya calcula.
- Produces: comportamiento — el aviso se muestra/oculta al instante al elegir/cambiar el método de envío; estilo de marca del callout.

- [ ] **Step 1: Add the toggle inside `ccmckSyncPickupRequired`**

En `assets/ccmck-checkout.js`, dentro de la función `ccmckSyncPickupRequired()`, justo **después** del bloque `$.each( CCMCK_ADDR_IDS, function ( i, id ) { ... } );` y **antes** de la `}` que cierra la función, añadir:

```js
        // Aviso de recogida local: visible solo cuando el envío elegido es pickup.
        $( '[data-ccmck-pickup-notice]' ).toggleClass( 'is-visible', pickup );
```

- [ ] **Step 2: Add the CSS block**

Anexar al final de `assets/ccmck-checkout.css`:

```css
/* ------------------------------------------------------------------ *
 *  Aviso de recogida local (bajo las tarjetas de envío).             *
 *  Oculto por defecto; el JS añade .is-visible al elegir pickup.     *
 * ------------------------------------------------------------------ */
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

- [ ] **Step 3: Verify by reading the diff**

Run: `git diff -- assets/ccmck-checkout.js assets/ccmck-checkout.css`
Expected: la línea del `toggleClass( 'is-visible', pickup )` queda dentro de `ccmckSyncPickupRequired()` (después del `$.each` de `CCMCK_ADDR_IDS`), y el bloque CSS `.ccmck-pickup-notice` aparece al final del CSS. (Verificación en vivo del toggle: tras desplegar, elegir "Recogida local" en `/pago/` y confirmar que el aviso aparece/desaparece — fuera del alcance automatizado; el local no es el sitio en ejecución.)

- [ ] **Step 4: Commit**

```bash
git add assets/ccmck-checkout.js assets/ccmck-checkout.css
git commit -m "feat(checkout): toggle y estilo del aviso de recogida local"
```

---

### Task 6: CHANGELOG + verificación final

**Files:**
- Modify: `docs/CHANGELOG.md` (entrada en `[Sin publicar] → Añadido`)

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: entrada de changelog + confirmación de suite verde.

- [ ] **Step 1: Add the changelog entry**

En `docs/CHANGELOG.md`, bajo `## [Sin publicar]` → `### Añadido`, añadir como primer ítem de la lista:

```markdown
- **Aviso de recogida local en el checkout**: al elegir el método de envío "Recogida local", aparece un aviso —editable desde *Ajustes → Checkout CCM → Contenido → Envío* (`pickup_notice`)— **bajo las tarjetas de envío**, recordando que el pedido no se envía a domicilio y que debe recogerse en el local de **Barranquilla**. Se pinta **fuera** del fragment AJAX (`#ccmck_shipping_methods`) para que persista, y el JS existente (`ccmckSyncPickupRequired`) lo muestra/oculta **al instante** al cambiar de método de envío. Estilo de marca (borde + fondo con el color de acento). Si el texto se deja vacío, no se muestra. Método puro `CCMCK_Pickup::notice_markup()`. Tests en `PickupTest` y `SettingsTest`.
```

- [ ] **Step 2: Run the full test suite**

Run: `php phpunit.phar`
Expected: OK — todos los tests verdes (incluye los 5 de `notice_markup` y los 4 de `pickup_notice`).

- [ ] **Step 3: Commit**

```bash
git add docs/CHANGELOG.md
git commit -m "docs: changelog del aviso de recogida local"
```

---

## Self-Review

**1. Spec coverage:**
- Ajuste `pickup_notice` (default + sanitize idempotente) → Task 2. ✔
- UI en Contenido → Envío → Task 3. ✔
- `CCMCK_Pickup::notice_markup()` puro → Task 1. ✔
- Render fuera del fragment en `form-checkout.php` → Task 4. ✔
- Toggle en `ccmckSyncPickupRequired` → Task 5. ✔
- CSS callout de marca (accent + fallback) → Task 5. ✔
- Tests (PickupTest + SettingsTest) → Tasks 1 y 2. ✔
- CHANGELOG → Task 6. ✔
- Despliegue / sin bump de versión → Global Constraints. ✔

**2. Placeholder scan:** sin TBD/TODO; todo el código está escrito. ✔

**3. Type consistency:** `notice_markup( string ): string`, clase `.ccmck-pickup-notice`, atributo/selector `[data-ccmck-pickup-notice]`, clase de estado `.is-visible`, ajuste `pickup_notice`, `CCMCK_PICKUP_ID`/`RATE_ID = 'ccmck_local_pickup'` — usados idénticos en todas las tareas. ✔
