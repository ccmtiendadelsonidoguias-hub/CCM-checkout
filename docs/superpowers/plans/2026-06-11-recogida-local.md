# Recogida local (pickup sin dirección) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que "Recogida local" sea una opción de envío gratis siempre seleccionable en el checkout, y que al elegirla no se exija dirección de entrega.

**Architecture:** Clase nueva `CCMCK_Pickup` inyecta una tarifa gratis vía `woocommerce_package_rates` (Opción A: autocontenida, sin depender de Zonas de WC) y relaja la obligatoriedad de los campos de dirección filtrando `woocommerce_checkout_fields` cuando el método elegido es pickup. `CCMCK_Shipping::render()` pasa a un render mixto: cards reales seleccionables + placeholders disabled solo para los métodos de la lista fija que aún no tienen tarifa real. Un bloque JS refleja en el cliente la no-obligatoriedad.

**Tech Stack:** PHP 8.5 (WordPress/WooCommerce), PHPUnit 11 (phar) con stubs de WP en `tests/bootstrap.php`, jQuery (assets).

**Entorno de test:** desde `mu-plugins/ccm-checkout/` correr `php phpunit.phar --no-coverage`. Despliegue: File Manager del hosting + **purgar OPcache** (la carpeta local NO sincroniza).

---

## File Structure

- **Create** `includes/class-ccmck-pickup.php` — inyección de la tarifa pickup + relajación de campos. Métodos puros (`is_pickup_rate`, `chosen_is_pickup`, `relax_fields`) + wrappers WC (`inject`, `current_is_pickup`, `relax_checkout_fields`, `init`).
- **Modify** `includes/class-ccmck-shipping.php` — añadir `missing_placeholder_labels()` (puro) y reescribir `render()` a render mixto.
- **Modify** `ccm-checkout.php` — `require` + `CCMCK_Pickup::init()` en `ccmck_boot`.
- **Modify** `tests/bootstrap.php` — `require` de la clase nueva + stub `WC_Shipping_Rate`.
- **Create** `tests/PickupTest.php` — tests de `CCMCK_Pickup`.
- **Modify** `tests/ShippingTest.php` — tests de `missing_placeholder_labels`.
- **Modify** `assets/ccmck-checkout.js` — toggle de obligatoriedad de los campos de dirección según el método elegido.

---

## Task 1: `CCMCK_Pickup` — esqueleto + lógica pura de identificación

**Files:**
- Create: `includes/class-ccmck-pickup.php`
- Modify: `tests/bootstrap.php`
- Test: `tests/PickupTest.php`

- [ ] **Step 1: Registrar la clase nueva en el bootstrap de tests**

En `tests/bootstrap.php`, tras la línea `require_once dirname( __DIR__ ) . '/includes/class-ccmck-shipping.php';`, añadir:

```php
require_once dirname( __DIR__ ) . '/includes/class-ccmck-pickup.php';
```

- [ ] **Step 2: Escribir el test que falla**

Crear `tests/PickupTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

final class PickupTest extends TestCase {
    public function test_is_pickup_rate_matches_own_id(): void {
        $this->assertTrue( CCMCK_Pickup::is_pickup_rate( 'ccmck_local_pickup' ) );
    }

    public function test_is_pickup_rate_rejects_other_methods(): void {
        $this->assertFalse( CCMCK_Pickup::is_pickup_rate( 'coordinadora:3' ) );
        $this->assertFalse( CCMCK_Pickup::is_pickup_rate( '' ) );
    }

    public function test_chosen_is_pickup_true_when_any_package_is_pickup(): void {
        $this->assertTrue( CCMCK_Pickup::chosen_is_pickup( array( 0 => 'ccmck_local_pickup' ) ) );
        $this->assertTrue( CCMCK_Pickup::chosen_is_pickup( array( 0 => 'coordinadora:3', 1 => 'ccmck_local_pickup' ) ) );
    }

    public function test_chosen_is_pickup_false_otherwise(): void {
        $this->assertFalse( CCMCK_Pickup::chosen_is_pickup( array( 0 => 'coordinadora:3' ) ) );
        $this->assertFalse( CCMCK_Pickup::chosen_is_pickup( array() ) );
    }
}
```

- [ ] **Step 3: Correr el test y verificar que falla**

Run: `php phpunit.phar --no-coverage --filter PickupTest`
Expected: FAIL — `Class "CCMCK_Pickup" not found`.

- [ ] **Step 4: Implementación mínima**

Crear `includes/class-ccmck-pickup.php`:

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Recogida local (pickup en tienda): inyecta una tarifa de envío gratis siempre
 * disponible y relaja la obligatoriedad de los campos de dirección cuando el
 * cliente elige recoger en tienda. No depende de las Zonas de Envío de WC.
 */
final class CCMCK_Pickup {
    const RATE_ID = 'ccmck_local_pickup';
    const LABEL   = 'Recogida local';

    /** Campos de dirección de entrega que se vuelven opcionales al elegir pickup. */
    const ADDRESS_FIELDS = array( 'billing_address_1', 'billing_city', 'billing_state', 'billing_postcode' );

    /** ¿El id de tarifa es el de pickup? PURO. */
    public static function is_pickup_rate( string $rate_id ): bool {
        return self::RATE_ID === $rate_id;
    }

    /** ¿Alguno de los métodos elegidos (por paquete) es pickup? PURO. */
    public static function chosen_is_pickup( array $chosen ): bool {
        foreach ( $chosen as $rate_id ) {
            if ( self::is_pickup_rate( (string) $rate_id ) ) {
                return true;
            }
        }
        return false;
    }
}
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `php phpunit.phar --no-coverage --filter PickupTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/class-ccmck-pickup.php tests/bootstrap.php tests/PickupTest.php
git commit -m "feat(pickup): CCMCK_Pickup con lógica pura de identificación"
```

---

## Task 2: Inyectar la tarifa de pickup

**Files:**
- Modify: `includes/class-ccmck-pickup.php`
- Modify: `tests/bootstrap.php`
- Test: `tests/PickupTest.php`

- [ ] **Step 1: Añadir un stub de `WC_Shipping_Rate` al bootstrap de tests**

En `tests/bootstrap.php`, antes de los `require_once` de las clases del plugin, añadir:

```php
if ( ! class_exists( 'WC_Shipping_Rate' ) ) {
    class WC_Shipping_Rate {
        public $id; public $label; public $cost; public $taxes; public $method_id;
        public function __construct( $id = '', $label = '', $cost = 0, $taxes = array(), $method_id = '' ) {
            $this->id = $id; $this->label = $label; $this->cost = $cost;
            $this->taxes = $taxes; $this->method_id = $method_id;
        }
        public function get_id() { return $this->id; }
        public function get_label() { return $this->label; }
        public function get_cost() { return $this->cost; }
    }
}
```

- [ ] **Step 2: Escribir el test que falla**

Añadir a `tests/PickupTest.php` dentro de la clase:

```php
    public function test_inject_adds_free_pickup_rate(): void {
        $rates = CCMCK_Pickup::inject( array(), array() );
        $this->assertArrayHasKey( CCMCK_Pickup::RATE_ID, $rates );
        $rate = $rates[ CCMCK_Pickup::RATE_ID ];
        $this->assertSame( 'Recogida local', $rate->get_label() );
        $this->assertSame( 0.0, (float) $rate->get_cost() );
    }

    public function test_inject_is_idempotent(): void {
        $rates = CCMCK_Pickup::inject( array(), array() );
        $again = CCMCK_Pickup::inject( $rates, array() );
        $this->assertCount( 1, $again );
    }

    public function test_inject_preserves_existing_rates(): void {
        $existing = array( 'coordinadora:3' => new WC_Shipping_Rate( 'coordinadora:3', 'Coordinadora', 19350 ) );
        $rates = CCMCK_Pickup::inject( $existing, array() );
        $this->assertArrayHasKey( 'coordinadora:3', $rates );
        $this->assertArrayHasKey( CCMCK_Pickup::RATE_ID, $rates );
    }
```

- [ ] **Step 3: Correr el test y verificar que falla**

Run: `php phpunit.phar --no-coverage --filter PickupTest`
Expected: FAIL — `Call to undefined method CCMCK_Pickup::inject()`.

- [ ] **Step 4: Implementación mínima**

Añadir a `includes/class-ccmck-pickup.php` dentro de la clase:

```php
    /**
     * Inyecta la tarifa gratis de pickup en las tarifas del paquete. Idempotente.
     * Filtro woocommerce_package_rates.
     *
     * @param array $rates   rate_id => WC_Shipping_Rate
     * @param array $package Paquete de envío (no usado; firma del filtro).
     * @return array
     */
    public static function inject( $rates, $package = array() ): array {
        $rates = is_array( $rates ) ? $rates : array();
        if ( isset( $rates[ self::RATE_ID ] ) || ! class_exists( 'WC_Shipping_Rate' ) ) {
            return $rates;
        }
        $rates[ self::RATE_ID ] = new WC_Shipping_Rate( self::RATE_ID, self::LABEL, 0.0, array(), 'local_pickup' );
        return $rates;
    }
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `php phpunit.phar --no-coverage --filter PickupTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/class-ccmck-pickup.php tests/bootstrap.php tests/PickupTest.php
git commit -m "feat(pickup): inyecta tarifa gratis Recogida local en package_rates"
```

---

## Task 3: Relajar campos de dirección (lógica pura)

**Files:**
- Modify: `includes/class-ccmck-pickup.php`
- Test: `tests/PickupTest.php`

- [ ] **Step 1: Escribir el test que falla**

Añadir a `tests/PickupTest.php`:

```php
    public function test_relax_fields_makes_address_optional_when_pickup(): void {
        $fields = array( 'billing' => array(
            'billing_address_1' => array( 'required' => true,  'label' => 'Dirección' ),
            'billing_city'      => array( 'required' => true,  'label' => 'Ciudad' ),
            'billing_state'     => array( 'required' => true,  'label' => 'Departamento' ),
            'billing_email'     => array( 'required' => true,  'label' => 'Email' ),
        ) );
        $out = CCMCK_Pickup::relax_fields( $fields, true );
        $this->assertFalse( $out['billing']['billing_address_1']['required'] );
        $this->assertFalse( $out['billing']['billing_city']['required'] );
        $this->assertFalse( $out['billing']['billing_state']['required'] );
        // El email NO es un campo de dirección: sigue obligatorio.
        $this->assertTrue( $out['billing']['billing_email']['required'] );
    }

    public function test_relax_fields_noop_when_not_pickup(): void {
        $fields = array( 'billing' => array(
            'billing_address_1' => array( 'required' => true ),
        ) );
        $out = CCMCK_Pickup::relax_fields( $fields, false );
        $this->assertTrue( $out['billing']['billing_address_1']['required'] );
    }
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php phpunit.phar --no-coverage --filter PickupTest`
Expected: FAIL — `Call to undefined method CCMCK_Pickup::relax_fields()`.

- [ ] **Step 3: Implementación mínima**

Añadir a `includes/class-ccmck-pickup.php`:

```php
    /**
     * Marca como NO obligatorios los campos de dirección cuando $is_pickup. PURO.
     *
     * @param array $fields Estructura de woocommerce_checkout_fields.
     * @param bool  $is_pickup
     * @return array
     */
    public static function relax_fields( array $fields, bool $is_pickup ): array {
        if ( ! $is_pickup || empty( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
            return $fields;
        }
        foreach ( self::ADDRESS_FIELDS as $key ) {
            if ( isset( $fields['billing'][ $key ] ) && is_array( $fields['billing'][ $key ] ) ) {
                $fields['billing'][ $key ]['required'] = false;
            }
        }
        return $fields;
    }
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php phpunit.phar --no-coverage --filter PickupTest`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/class-ccmck-pickup.php tests/PickupTest.php
git commit -m "feat(pickup): relax_fields vuelve opcional la dirección en pickup"
```

---

## Task 4: `missing_placeholder_labels` en `CCMCK_Shipping`

**Files:**
- Modify: `includes/class-ccmck-shipping.php`
- Test: `tests/ShippingTest.php`

- [ ] **Step 1: Escribir el test que falla**

Añadir a `tests/ShippingTest.php` (dentro de la clase, junto a los tests de placeholder):

```php
    public function test_missing_placeholder_labels_drops_present_case_insensitive(): void {
        $real        = array( 'Recogida local' );
        $placeholder = array( 'Coordinadora', 'Recogida local' );
        $this->assertSame(
            array( 'Coordinadora' ),
            CCMCK_Shipping::missing_placeholder_labels( $real, $placeholder )
        );
    }

    public function test_missing_placeholder_labels_none_missing(): void {
        $real        = array( 'Coordinadora', 'Recogida local' );
        $placeholder = array( 'Coordinadora', 'Recogida local' );
        $this->assertSame( array(), CCMCK_Shipping::missing_placeholder_labels( $real, $placeholder ) );
    }

    public function test_missing_placeholder_labels_all_missing_when_no_real(): void {
        $this->assertSame(
            array( 'Coordinadora', 'Recogida local' ),
            CCMCK_Shipping::missing_placeholder_labels( array(), array( 'Coordinadora', 'Recogida local' ) )
        );
    }
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php phpunit.phar --no-coverage --filter ShippingTest`
Expected: FAIL — `Call to undefined method CCMCK_Shipping::missing_placeholder_labels()`.

- [ ] **Step 3: Implementación mínima**

En `includes/class-ccmck-shipping.php`, añadir tras `placeholder_labels()`:

```php
    /**
     * Devuelve los labels de placeholder que NO están entre las tarifas reales
     * (comparación case-insensitive + trim). PURO.
     *
     * @param array<int,string> $real_labels        Labels de tarifas reales presentes.
     * @param array<int,string> $placeholder_labels  Lista fija de placeholders.
     * @return array<int,string>
     */
    public static function missing_placeholder_labels( array $real_labels, array $placeholder_labels ): array {
        $norm = array();
        foreach ( $real_labels as $l ) {
            $norm[] = strtolower( trim( (string) $l ) );
        }
        $missing = array();
        foreach ( $placeholder_labels as $label ) {
            if ( ! in_array( strtolower( trim( (string) $label ) ), $norm, true ) ) {
                $missing[] = $label;
            }
        }
        return $missing;
    }
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php phpunit.phar --no-coverage --filter ShippingTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ccmck-shipping.php tests/ShippingTest.php
git commit -m "feat(shipping): missing_placeholder_labels para render mixto"
```

---

## Task 5: Render mixto en `CCMCK_Shipping::render()`

**Files:**
- Modify: `includes/class-ccmck-shipping.php`

No lleva test unitario nuevo: `render()` está acoplado a WC (`WC()`, `get_packages`) y el proyecto deja esos wrappers sin test; la lógica ya está cubierta por `build_methods`, `render_cards`, `render_placeholder_cards` y `missing_placeholder_labels`. Se verifica en vivo (Task 8).

- [ ] **Step 1: Reescribir `render()`**

En `includes/class-ccmck-shipping.php`, reemplazar el cuerpo actual de `render()` (desde `$packages = WC()->shipping()->get_packages();` hasta el `return` final) por:

```php
        $packages = WC()->shipping()->get_packages();
        $chosen   = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : array();
        $methods  = self::build_methods( $packages, $chosen );

        // Labels de las tarifas reales presentes (para no duplicar como placeholder).
        $real_labels = array();
        $has_real    = false;
        foreach ( $methods as $package ) {
            foreach ( $package['rates'] as $rate ) {
                $has_real      = true;
                $real_labels[] = (string) $rate['label'];
            }
        }

        $missing = self::missing_placeholder_labels( $real_labels, self::placeholder_labels() );

        $html  = $has_real ? self::render_cards( $methods ) : '';
        $html .= self::render_placeholder_cards( $missing );

        // Sin nada que mostrar (ni reales ni placeholders): aviso fallback.
        return '' !== $html ? $html : self::render_cards( array() );
```

- [ ] **Step 2: Lint + correr toda la batería**

Run: `php -l includes/class-ccmck-shipping.php && php phpunit.phar --no-coverage`
Expected: sin errores de sintaxis; todos los tests PASS.

- [ ] **Step 3: Commit**

```bash
git add includes/class-ccmck-shipping.php
git commit -m "feat(shipping): render mixto (reales seleccionables + placeholders faltantes)"
```

---

## Task 6: Wrappers WC de `CCMCK_Pickup` + `init()` + boot

**Files:**
- Modify: `includes/class-ccmck-pickup.php`
- Modify: `ccm-checkout.php`

Los wrappers leen `$_POST`/sesión de WC; no se testean unitariamente (consistente con el resto de wrappers WC del proyecto). Se verifican en vivo.

- [ ] **Step 1: Añadir wrappers e `init()` a `CCMCK_Pickup`**

Añadir a `includes/class-ccmck-pickup.php` dentro de la clase:

```php
    public static function init(): void {
        add_filter( 'woocommerce_package_rates', array( __CLASS__, 'inject' ), 10, 2 );
        add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'relax_checkout_fields' ), 9999 );
    }

    /** ¿El método elegido ahora mismo es pickup? Lee POST (submit/AJAX) o sesión. */
    public static function current_is_pickup(): bool {
        if ( isset( $_POST['shipping_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $posted = wp_unslash( $_POST['shipping_method'] ); // phpcs:ignore
            $posted = is_array( $posted ) ? array_map( 'sanitize_text_field', $posted ) : array( sanitize_text_field( (string) $posted ) );
            return self::chosen_is_pickup( $posted );
        }
        if ( function_exists( 'WC' ) && WC()->session ) {
            return self::chosen_is_pickup( (array) WC()->session->get( 'chosen_shipping_methods' ) );
        }
        return false;
    }

    /** Filtro woocommerce_checkout_fields: relaja la dirección si pickup. */
    public static function relax_checkout_fields( $fields ) {
        return self::relax_fields( is_array( $fields ) ? $fields : array(), self::current_is_pickup() );
    }
```

- [ ] **Step 2: Registrar la clase en el bootstrap del plugin**

En `ccm-checkout.php`, tras la línea `require_once CCMCK_DIR . 'includes/class-ccmck-shipping.php';` añadir:

```php
require_once CCMCK_DIR . 'includes/class-ccmck-pickup.php';
```

Y en `ccmck_boot()`, tras `CCMCK_Shipping::init();` añadir:

```php
    CCMCK_Pickup::init();
```

- [ ] **Step 3: Lint + batería completa**

Run: `php -l includes/class-ccmck-pickup.php && php -l ccm-checkout.php && php phpunit.phar --no-coverage`
Expected: sin errores; todos los tests PASS.

- [ ] **Step 4: Commit**

```bash
git add includes/class-ccmck-pickup.php ccm-checkout.php
git commit -m "feat(pickup): init (inject + relax_checkout_fields) y boot"
```

---

## Task 7: Toggle de obligatoriedad en el cliente (JS)

**Files:**
- Modify: `assets/ccmck-checkout.js`

Sin test unitario (JS de UI; se verifica en vivo). El servidor (Task 6) es la fuente de verdad; este JS es solo UX e impide el bloqueo de validación cliente de `checkout.js`.

- [ ] **Step 1: Añadir el bloque de toggle**

En `assets/ccmck-checkout.js`, antes de la línea final `} )( jQuery );`, insertar:

```javascript
    /* ------------------------------------------------------------------ */
    /*  Recogida local: al elegir pickup, los campos de dirección dejan    */
    /*  de ser obligatorios (UX; el servidor es la fuente de verdad).      */
    /* ------------------------------------------------------------------ */
    var CCMCK_PICKUP_ID = 'ccmck_local_pickup';
    var CCMCK_ADDR_IDS  = [ 'billing_address_1', 'billing_city', 'billing_state', 'billing_postcode' ];

    function ccmckSyncPickupRequired() {
        var chosen = $( 'input[name^="shipping_method"]:checked' ).val() || '';
        var pickup = chosen === CCMCK_PICKUP_ID;
        $.each( CCMCK_ADDR_IDS, function ( i, id ) {
            var $row = $( '#' + id ).closest( '.form-row' );
            if ( ! $row.length ) { return; }
            $row.toggleClass( 'validate-required', ! pickup );
            $row.toggleClass( 'ccmck-optional-pickup', pickup );
        } );
    }

    $( document ).on( 'change', 'input[name^="shipping_method"]', ccmckSyncPickupRequired );
    $( document.body ).on( 'updated_checkout', ccmckSyncPickupRequired );
    $( ccmckSyncPickupRequired );
```

- [ ] **Step 2: Verificar sintaxis del JS**

Run (si hay node disponible): `node --check assets/ccmck-checkout.js`
Expected: sin salida (OK). Si no hay node, revisar manualmente que el bloque cierra llaves/paréntesis.

- [ ] **Step 3: Commit**

```bash
git add assets/ccmck-checkout.js
git commit -m "feat(pickup): JS toggle de obligatoriedad de dirección según método"
```

---

## Task 8: Despliegue y verificación en vivo

**Files:** ninguno (despliegue + verificación).

- [ ] **Step 1: Actualizar el CHANGELOG**

En `docs/CHANGELOG.md`, bajo `## [Sin publicar] → ### Añadido`, añadir una línea:

```markdown
- **Recogida local (pickup en tienda)**: opción de envío gratis siempre seleccionable (`CCMCK_Pickup` inyecta la tarifa en `woocommerce_package_rates`, sin depender de Zonas). Al elegirla, los campos de dirección dejan de ser obligatorios (filtro `woocommerce_checkout_fields` + JS). La sección de envío pasa a render mixto: cards reales seleccionables + placeholders disabled solo de los métodos aún sin tarifa.
```

```bash
git add docs/CHANGELOG.md
git commit -m "docs: changelog Recogida local"
git push origin main
```

- [ ] **Step 2: Desplegar por File Manager + purgar OPcache**

Subir al servidor (sobrescribiendo):
- `includes/class-ccmck-pickup.php` (nuevo)
- `includes/class-ccmck-shipping.php`
- `ccm-checkout.php`
- `assets/ccmck-checkout.js`

Luego **purgar OPcache** (los PHP no aplican sin purgar). El JS auto-busta por `?ver=filemtime`.

- [ ] **Step 3: Verificar en vivo (chrome-devtools MCP) en `/pago/`**

Recargar `/pago/` ignorando caché. Confirmar:
1. **Sin dirección**: `Recogida local` aparece como card **seleccionable** ($0 / sin coste) y `Coordinadora` como card **disabled** con la nota.
2. **Elegir Recogida local**: los campos de dirección pierden el marcado obligatorio; el `innerHTML` de `#ccmck_shipping_methods` y el toggle de `.validate-required` lo confirman. Intentar colocar el pedido con email + nombre + documento + teléfono pero **sin** dirección → el servidor lo acepta (no exige dirección). *(Probar con un método de pago que no abra pasarela externa, o detener antes del redirect.)*
3. **Poner ciudad (Barranquilla)** → aparece `Coordinadora $19.350` seleccionable junto a pickup; al elegir Coordinadora los campos de dirección vuelven a ser obligatorios.
4. El total cuadra (pickup sin coste de envío).

- [ ] **Step 4: Si algo falla, depurar antes de cerrar**

Riesgos conocidos a vigilar:
- El plugin `wc-departamentos-y-ciudades-colombia` puede re-forzar `billing_state`/`billing_city` como obligatorios por su cuenta. Si tras elegir pickup el servidor SIGUE exigiendo ciudad/depto, revisar si ese plugin valida en `woocommerce_checkout_process`; en tal caso, desactivar su error para pickup en un `woocommerce_after_checkout_validation` que use `CCMCK_Pickup::current_is_pickup()`.
- Selección por defecto: si sin dirección pickup queda elegido por defecto y luego el cliente llena dirección para envío, debe poder cambiar a Coordinadora (verificar que al hacerlo se re-exige la dirección). Si el default de pickup molesta, considerar ordenar la tarifa pickup después de las de transportadora.

---

## Self-Review

- **Cobertura del spec:** tarifa siempre disponible → Task 2/6; render mixto → Task 4/5; dirección opcional (servidor) → Task 3/6; dirección opcional (cliente) → Task 7; tests → Tasks 1-4; despliegue/verificación → Task 8. Sin huecos.
- **Sin placeholders:** todos los pasos llevan código/comando concreto.
- **Consistencia de tipos:** `RATE_ID`, `LABEL`, `ADDRESS_FIELDS`, `is_pickup_rate`, `chosen_is_pickup`, `inject`, `relax_fields`, `current_is_pickup`, `relax_checkout_fields`, `missing_placeholder_labels` usados con la misma firma en todas las tareas.
