# Checkout en secciones + métodos de envío — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reorganizar el formulario del checkout ccmck en secciones Contacto / Entrega / Métodos de envío / Pago (manteniendo las 2 columnas) y mover el selector de métodos de envío al cuerpo principal como cards, usando los fragments nativos de WooCommerce.

**Architecture:** Una clase nueva `CCMCK_Shipping` con métodos puros y testeables (`build_methods`, `render_cards`) más un wrapper acoplado a WC (`render`) y un hook de fragments. Dos templates nuevos/editados parten los campos en secciones y colocan el contenedor de envío. El sidebar deja de mostrar el selector y conserva el costo como línea de total.

**Tech Stack:** PHP 8.5, WooCommerce (checkout clásico), PHPUnit (phpunit.phar + stubs en `tests/bootstrap.php`), CSS plano. Sin Composer/wp-cli. `wp-content` no es repo; el plugin sí (`mu-plugins/ccm-checkout`, rama `main`).

**Spec:** `docs/superpowers/specs/2026-06-10-checkout-secciones-envio-design.md`

---

## File Structure

| Acción | Archivo | Responsabilidad |
|--------|---------|-----------------|
| Crear | `includes/class-ccmck-shipping.php` | Normalizar paquetes de envío de WC y renderizar las cards; registrar el fragment. |
| Crear | `tests/ShippingTest.php` | Tests unitarios de `build_methods` y `render_cards`. |
| Crear | `templates/checkout/form-billing.php` | Override de billing: parte los campos en secciones Contacto + Entrega. |
| Editar | `tests/bootstrap.php` | Añadir stubs `esc_attr/esc_html/esc_html__/esc_attr__` y `require` de la clase de envío. |
| Editar | `ccm-checkout.php` | Cargar e inicializar `CCMCK_Shipping`. |
| Editar | `templates/checkout/form-checkout.php` | Reestructurar la columna principal en secciones + contenedor de envío. |
| Editar | `templates/checkout/review-order.php` | Reemplazar el selector de envío por una línea de total "Envío". |
| Editar | `assets/ccmck-checkout.css` | Estilos de `.ccmck-section`, encabezados `h2`, cards de envío y checkboxes. |
| Sin tocar | `templates/checkout/payment.php`, plugin `coordinadora` | — |

---

## Task 1: Clase CCMCK_Shipping (lógica pura, TDD)

**Files:**
- Create: `includes/class-ccmck-shipping.php`
- Create: `tests/ShippingTest.php`
- Modify: `tests/bootstrap.php`

- [ ] **Step 1: Añadir stubs y require en el bootstrap de tests**

Editar `tests/bootstrap.php`. Tras el bloque de `absint`/`__` (línea 32) y antes de los `require_once` de clases (línea 34), añadir:

```php
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $s, $d = 'default' ) { return $s; }
}
if ( ! function_exists( 'esc_attr__' ) ) {
    function esc_attr__( $s, $d = 'default' ) { return $s; }
}
```

Y al final del archivo (tras la línea 37) añadir:

```php
require_once dirname( __DIR__ ) . '/includes/class-ccmck-shipping.php';
```

- [ ] **Step 2: Escribir el test que falla**

Crear `tests/ShippingTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

final class ShippingTest extends TestCase {
    /** Crea un stub de WC_Shipping_Rate con get_id/get_label/get_cost. */
    private function rate( string $id, string $label, float $cost ): object {
        return new class( $id, $label, $cost ) {
            public $id; public $label; public $cost;
            public function __construct( $i, $l, $c ) { $this->id = $i; $this->label = $l; $this->cost = $c; }
            public function get_id() { return $this->id; }
            public function get_label() { return $this->label; }
            public function get_cost() { return $this->cost; }
        };
    }

    public function test_build_marks_the_chosen_rate(): void {
        $packages = array( array( 'rates' => array(
            $this->rate( 'coordinadora:1', 'Coordinadora', 12900 ),
            $this->rate( 'local_pickup:2', 'Recoger en tienda', 0 ),
        ) ) );
        $out = CCMCK_Shipping::build_methods( $packages, array( 0 => 'local_pickup:2' ) );
        $this->assertFalse( $out[0]['rates'][0]['checked'] );
        $this->assertTrue( $out[0]['rates'][1]['checked'] );
        $this->assertSame( 0, $out[0]['index'] );
    }

    public function test_build_defaults_to_first_when_none_chosen(): void {
        $packages = array( array( 'rates' => array( $this->rate( 'a', 'A', 100 ) ) ) );
        $out = CCMCK_Shipping::build_methods( $packages, array() );
        $this->assertTrue( $out[0]['rates'][0]['checked'] );
    }

    public function test_render_cards_emits_radio_label_and_cost(): void {
        $methods = array( array( 'index' => 0, 'rates' => array(
            array( 'id' => 'coordinadora:1', 'label' => 'Coordinadora', 'cost' => 12900.0, 'checked' => true ),
        ) ) );
        $html = CCMCK_Shipping::render_cards( $methods );
        $this->assertStringContainsString( 'name="shipping_method[0]"', $html );
        $this->assertStringContainsString( 'value="coordinadora:1"', $html );
        $this->assertStringContainsString( 'Coordinadora', $html );
        $this->assertStringContainsString( 'checked', $html );
        $this->assertStringContainsString( '12.900', $html ); // fallback de formato sin wc_price
    }

    public function test_render_cards_empty_shows_notice(): void {
        $this->assertStringContainsString( 'ccmck-no-shipping', CCMCK_Shipping::render_cards( array() ) );
    }
}
```

- [ ] **Step 3: Correr el test para verque falla**

Run: `php phpunit.phar --bootstrap tests/bootstrap.php tests/ShippingTest.php`
Expected: FAIL — `Error: Class "CCMCK_Shipping" not found` (la clase aún no existe).

- [ ] **Step 4: Implementar la clase mínima**

Crear `includes/class-ccmck-shipping.php`:

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Renderiza los métodos de envío de WooCommerce como cards en la columna
 * principal del checkout y los mantiene actualizados vía el sistema de
 * fragments nativo de WC. NO integra transportadoras: sólo presenta los
 * métodos que las Zonas de Envío de WooCommerce ya ofrecen.
 */
final class CCMCK_Shipping {
    const CONTAINER_ID = 'ccmck_shipping_methods';

    public static function init(): void {
        add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'fragments' ) );
    }

    /**
     * Normaliza los paquetes de WC()->shipping()->get_packages() a una lista
     * plana y predecible. Método PURO (sin globals) para poder testearlo.
     *
     * @param array $packages Paquetes de envío de WooCommerce.
     * @param array $chosen   chosen_shipping_methods de la sesión (index => rate_id).
     * @return array<int,array{index:int,rates:array<int,array{id:string,label:string,cost:float,checked:bool}>}>
     */
    public static function build_methods( array $packages, array $chosen ): array {
        $out = array();
        foreach ( $packages as $i => $package ) {
            $rates     = ( isset( $package['rates'] ) && is_array( $package['rates'] ) ) ? $package['rates'] : array();
            $chosen_id = isset( $chosen[ $i ] ) ? (string) $chosen[ $i ] : '';
            $methods   = array();

            foreach ( $rates as $rate ) {
                $id    = is_object( $rate ) && method_exists( $rate, 'get_id' ) ? (string) $rate->get_id() : '';
                $label = is_object( $rate ) && method_exists( $rate, 'get_label' ) ? (string) $rate->get_label() : '';
                $cost  = is_object( $rate ) && method_exists( $rate, 'get_cost' ) ? (float) $rate->get_cost() : 0.0;
                $methods[] = array(
                    'id'      => $id,
                    'label'   => $label,
                    'cost'    => $cost,
                    'checked' => ( '' !== $id && $id === $chosen_id ),
                );
            }

            // Si ninguno está marcado y hay opciones, marca la primera (estado inicial).
            if ( $methods && ! in_array( true, array_column( $methods, 'checked' ), true ) ) {
                $methods[0]['checked'] = true;
            }

            $out[] = array( 'index' => (int) $i, 'rates' => $methods );
        }
        return $out;
    }

    /**
     * Genera el HTML de las cards a partir de la salida de build_methods().
     * Método PURO. Cada rate es un radio name="shipping_method[$index]" para
     * que la selección postee y dispare el recálculo nativo de WC.
     */
    public static function render_cards( array $methods ): string {
        $has_rate = false;
        foreach ( $methods as $p ) {
            if ( ! empty( $p['rates'] ) ) { $has_rate = true; break; }
        }
        if ( ! $has_rate ) {
            return '<p class="ccmck-no-shipping">' . esc_html__( 'No hay envíos disponibles para tu dirección.', 'ccm-checkout' ) . '</p>';
        }

        $html = '';
        foreach ( $methods as $package ) {
            $index = (int) $package['index'];
            $html .= '<ul class="ccmck-shipping-list" data-package="' . esc_attr( (string) $index ) . '">';
            foreach ( $package['rates'] as $rate ) {
                $safe   = preg_replace( '/[^a-z0-9_-]/i', '_', (string) $rate['id'] );
                $dom_id = 'ccmck_ship_' . $index . '_' . $safe;
                $sel    = $rate['checked'] ? ' is-selected' : '';
                $chk    = $rate['checked'] ? ' checked' : '';
                $html  .= '<li class="ccmck-shipping-method' . $sel . '">';
                $html  .= '<input type="radio" class="shipping_method" name="shipping_method[' . esc_attr( (string) $index ) . ']" data-index="' . esc_attr( (string) $index ) . '" id="' . esc_attr( $dom_id ) . '" value="' . esc_attr( (string) $rate['id'] ) . '"' . $chk . ' />';
                $html  .= '<label for="' . esc_attr( $dom_id ) . '">';
                $html  .= '<span class="ccmck-ship-label">' . esc_html( (string) $rate['label'] ) . '</span>';
                $html  .= '<span class="ccmck-ship-cost">' . self::format_cost( (float) $rate['cost'] ) . '</span>';
                $html  .= '</label></li>';
            }
            $html .= '</ul>';
        }
        return $html;
    }

    /** Formatea el costo: wc_price() en producción, fallback predecible en tests. */
    private static function format_cost( float $cost ): string {
        if ( $cost <= 0 ) {
            return esc_html__( 'Gratis', 'ccm-checkout' );
        }
        if ( function_exists( 'wc_price' ) ) {
            return wp_kses_post( wc_price( $cost ) );
        }
        return '$' . number_format( $cost, 0, ',', '.' );
    }

    /** Render acoplado a WC para el template y el fragment. */
    public static function render(): string {
        if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->cart->needs_shipping() ) {
            return '';
        }
        $packages = WC()->shipping()->get_packages();
        $chosen   = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : array();
        return self::render_cards( self::build_methods( $packages, $chosen ) );
    }

    /**
     * Inyecta el contenedor de envío como fragment para que el AJAX
     * update_order_review de WooCommerce lo refresque automáticamente.
     */
    public static function fragments( $fragments ): array {
        $fragments = is_array( $fragments ) ? $fragments : array();
        $fragments[ '#' . self::CONTAINER_ID ] =
            '<div id="' . self::CONTAINER_ID . '" class="ccmck-shipping-methods">' . self::render() . '</div>';
        return $fragments;
    }
}
```

- [ ] **Step 5: Correr los tests hasta verde**

Run: `php phpunit.phar --bootstrap tests/bootstrap.php tests/ShippingTest.php`
Expected: PASS (4 tests, OK).

- [ ] **Step 6: Lint del archivo nuevo**

Run: `php -l includes/class-ccmck-shipping.php`
Expected: `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add includes/class-ccmck-shipping.php tests/ShippingTest.php tests/bootstrap.php
git commit -m "feat(checkout): CCMCK_Shipping renderiza metodos de envio como cards (fragment WC)"
```

---

## Task 2: Cablear CCMCK_Shipping en el bootstrap

**Files:**
- Modify: `ccm-checkout.php`

- [ ] **Step 1: Añadir el require**

En `ccm-checkout.php`, tras la línea 24 (`require_once CCMCK_DIR . 'includes/class-ccmck-thankyou.php';`) añadir:

```php
require_once CCMCK_DIR . 'includes/class-ccmck-shipping.php';
```

- [ ] **Step 2: Añadir la inicialización**

En la función `ccmck_boot()`, tras la línea 45 (`CCMCK_Thankyou::init();`) añadir:

```php
    CCMCK_Shipping::init();
```

- [ ] **Step 3: Lint**

Run: `php -l ccm-checkout.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add ccm-checkout.php
git commit -m "feat(checkout): inicializar CCMCK_Shipping en el bootstrap"
```

---

## Task 3: Override form-billing.php (secciones Contacto + Entrega)

**Files:**
- Create: `templates/checkout/form-billing.php`

> Nota: `CCMCK_Templates` ya engancha `woocommerce_locate_template`, así que WooCommerce
> usará este override automáticamente para `checkout/form-billing.php`. Verificar en Task 7
> que el método de localización del template cubre form-billing (si sólo mapea ciertos
> archivos, añadir `form-billing.php` a su lista — revisar `includes/class-ccmck-templates.php`).

- [ ] **Step 1: Crear el template**

Crear `templates/checkout/form-billing.php`:

```php
<?php
/**
 * Billing fields override — secciones Contacto + Entrega (mockup CCM).
 *
 * Parte los campos de facturación: billing_email va en "Contacto"; el resto
 * (ordenado por CCMCK_Document::finalize_fields) va en "Entrega". Conserva los
 * hooks woocommerce_before/after_checkout_billing_form. Checkout sólo invitado:
 * NO renderiza los campos de creación de cuenta (login es visual por ahora).
 *
 * @see WooCommerce templates/checkout/form-billing.php
 * @package CCM_Checkout
 *
 * @var WC_Checkout $checkout
 */

defined( 'ABSPATH' ) || exit;

$fields    = $checkout->get_checkout_fields( 'billing' );
$login_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '#';

do_action( 'woocommerce_before_checkout_billing_form', $checkout );
?>
<div class="woocommerce-billing-fields">

	<section class="ccmck-section ccmck-contacto">
		<div class="ccmck-section-head">
			<h2><?php esc_html_e( 'Contacto', 'ccm-checkout' ); ?></h2>
			<a class="ccmck-login-link" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Iniciar sesión', 'ccm-checkout' ); ?></a>
		</div>
		<div class="woocommerce-billing-fields__field-wrapper">
			<?php
			if ( isset( $fields['billing_email'] ) ) {
				woocommerce_form_field( 'billing_email', $fields['billing_email'], $checkout->get_value( 'billing_email' ) );
			}
			?>
		</div>
		<label class="ccmck-check ccmck-news">
			<input type="checkbox" name="ccmck_newsletter" value="1" />
			<span><?php esc_html_e( 'Enviarme novedades y ofertas por correo electrónico.', 'ccm-checkout' ); ?></span>
		</label>
	</section>

	<section class="ccmck-section ccmck-entrega">
		<h2><?php esc_html_e( 'Entrega', 'ccm-checkout' ); ?></h2>
		<div class="woocommerce-billing-fields__field-wrapper">
			<?php
			foreach ( $fields as $key => $field ) {
				if ( 'billing_email' === $key ) {
					continue;
				}
				woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
			}
			?>
		</div>
		<label class="ccmck-check ccmck-save-info">
			<input type="checkbox" name="ccmck_save_info" value="1" />
			<span><?php esc_html_e( 'Guardar mi información y consultar más rápidamente la próxima vez.', 'ccm-checkout' ); ?></span>
		</label>
	</section>

</div>
<?php
do_action( 'woocommerce_after_checkout_billing_form', $checkout );
```

- [ ] **Step 2: Lint**

Run: `php -l templates/checkout/form-billing.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add templates/checkout/form-billing.php
git commit -m "feat(checkout): override form-billing parte campos en Contacto + Entrega"
```

---

## Task 4: Reestructurar form-checkout.php (secciones + contenedor de envío)

**Files:**
- Modify: `templates/checkout/form-checkout.php:52-75`

- [ ] **Step 1: Reemplazar el bloque col2-set y el h3 de Pago**

En `templates/checkout/form-checkout.php`, reemplazar el bloque desde la línea 52
(`<div class="col2-set" id="customer_details">`) hasta la línea 75 (el cierre `?>`
de `woocommerce_checkout_payment();`) por:

```php
				<div id="customer_details">
					<?php
					// woocommerce_checkout_billing emite ahora DOS secciones
					// (Contacto + Entrega) vía el override de form-billing.php.
					do_action( 'woocommerce_checkout_billing' );
					do_action( 'woocommerce_checkout_shipping' );
					?>
				</div>

				<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

			<?php endif; ?>

			<section class="ccmck-section ccmck-shipping-section">
				<h2><?php esc_html_e( 'Métodos de envío', 'ccm-checkout' ); ?></h2>
				<div id="ccmck_shipping_methods" class="ccmck-shipping-methods">
					<?php
					// HTML ya escapado dentro de CCMCK_Shipping::render_cards().
					echo CCMCK_Shipping::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
			</section>

			<section class="ccmck-section ccmck-payment-section">
				<h2 id="order_review_heading"><?php esc_html_e( 'Pago', 'ccm-checkout' ); ?></h2>
				<p class="ccmck-secure-note"><?php esc_html_e( 'Todas las transacciones son seguras y están encriptadas.', 'ccm-checkout' ); ?></p>
				<?php
				// woocommerce_checkout_payment() emite #payment, los gateways,
				// el botón #place_order y el nonce — todo dentro del <form>.
				woocommerce_checkout_payment();
				?>
```

> Importante: el `<?php endif; ?>` que cerraba `if ( $checkout->get_checkout_fields() )`
> (línea 64 original) queda ANTES de la sección de envío en el reemplazo de arriba — la
> sección de envío y la de pago van fuera de ese `if` para que siempre se rendericen.
> El `do_action( 'woocommerce_checkout_before_customer_details' )` (línea 50) y la
> apertura `<?php if ( $checkout->get_checkout_fields() ) : ?>` (línea 48) se conservan
> tal cual antes del bloque reemplazado.

- [ ] **Step 2: Lint**

Run: `php -l templates/checkout/form-checkout.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verificar balance de etiquetas PHP/HTML**

Run: `php -r "echo substr_count(file_get_contents('templates/checkout/form-checkout.php'), '<section');"`
Expected: imprime `2` (las dos secciones nuevas: envío y pago).

- [ ] **Step 4: Commit**

```bash
git add templates/checkout/form-checkout.php
git commit -m "feat(checkout): columna principal en secciones + contenedor de envio"
```

---

## Task 5: review-order.php — envío como línea de total (no selector)

**Files:**
- Modify: `templates/checkout/review-order.php:88-96`

- [ ] **Step 1: Reemplazar el bloque de envío**

En `templates/checkout/review-order.php`, reemplazar las líneas 88-96 (el bloque
`if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() )` que llama a
`wc_cart_totals_shipping_html()`) por:

```php
		<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>

			<?php do_action( 'woocommerce_review_order_before_shipping' ); ?>

			<tr class="cart-shipping">
				<th><?php esc_html_e( 'Envío', 'woocommerce' ); ?></th>
				<td><?php echo wp_kses_post( WC()->cart->get_cart_shipping_total() ); ?></td>
			</tr>

			<?php do_action( 'woocommerce_review_order_after_shipping' ); ?>

		<?php endif; ?>
```

> Esto quita los radios duplicados del sidebar (ahora viven en la columna principal) y
> deja sólo el costo del método elegido. `get_cart_shipping_total()` devuelve el total ya
> formateado ("Gratis" o `$X`).

- [ ] **Step 2: Lint**

Run: `php -l templates/checkout/review-order.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Confirmar que ya no se llama al selector**

Run: `php -r "echo (int) (strpos(file_get_contents('templates/checkout/review-order.php'), 'wc_cart_totals_shipping_html') === false);"`
Expected: imprime `1` (la función del selector ya no aparece).

- [ ] **Step 4: Commit**

```bash
git add templates/checkout/review-order.php
git commit -m "feat(checkout): sidebar muestra envio como total, sin selector duplicado"
```

---

## Task 6: Estilos de secciones y cards de envío

**Files:**
- Modify: `assets/ccmck-checkout.css` (añadir al final del bloque del checkout-main)

- [ ] **Step 1: Añadir el CSS**

Añadir al final de `assets/ccmck-checkout.css`:

```css
/* ===== Secciones del checkout (Contacto / Entrega / Envío / Pago) ===== */
.ccmck .checkout-main .ccmck-section { margin-bottom: 34px; }
.ccmck .checkout-main .ccmck-section > h2 {
  font-size: 24px;
  font-weight: 700;
  color: var(--ccmck-sidebar, #1a1a1a);
  margin: 0 0 16px;
  line-height: 1.2;
}
.ccmck .checkout-main .ccmck-section-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: 16px;
}
.ccmck .checkout-main .ccmck-section-head > h2 { margin: 0; }
.ccmck .checkout-main .ccmck-login-link {
  font-size: 14px;
  color: var(--ccmck-sidebar, #1a1a1a);
  text-decoration: underline;
}
.ccmck .checkout-main .ccmck-login-link:hover { color: var(--ccmck-accent, #e63946); }

/* Checkboxes visuales (novedades / guardar info) */
.ccmck .checkout-main .ccmck-check {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 12px;
  font-size: 14px;
  color: #444;
  cursor: pointer;
}
.ccmck .checkout-main .ccmck-check input { width: 18px; height: 18px; accent-color: var(--ccmck-sidebar, #1a1a1a); }

.ccmck .checkout-main .ccmck-secure-note { font-size: 13px; color: #777; margin: 0 0 14px; }

/* ===== Cards de métodos de envío ===== */
.ccmck .checkout-main .ccmck-shipping-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.ccmck .checkout-main .ccmck-shipping-method { position: relative; }
.ccmck .checkout-main .ccmck-shipping-method input { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); margin: 0; accent-color: var(--ccmck-sidebar, #1a1a1a); }
.ccmck .checkout-main .ccmck-shipping-method label {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px 16px 16px 44px;
  border: 1px solid #d1d1d1;
  border-radius: 8px;
  cursor: pointer;
  transition: border-color 0.15s;
}
.ccmck .checkout-main .ccmck-shipping-method.is-selected label {
  border-color: var(--ccmck-sidebar, #1a1a1a);
  border-width: 2px;
  padding: 15px 15px 15px 43px; /* compensa el +1px de borde, sin desplazar */
}
.ccmck .checkout-main .ccmck-ship-label { font-size: 15px; color: #1a1a1a; }
.ccmck .checkout-main .ccmck-ship-cost { font-size: 15px; font-weight: 600; color: #1a1a1a; }
.ccmck .checkout-main .ccmck-no-shipping { font-size: 14px; color: #777; padding: 14px 0; }
```

- [ ] **Step 2: Confirmar que el CSS se añadió**

Run: `php -r "echo (int) (strpos(file_get_contents('assets/ccmck-checkout.css'), 'ccmck-shipping-list') !== false);"`
Expected: imprime `1`.

- [ ] **Step 3: Commit**

```bash
git add assets/ccmck-checkout.css
git commit -m "style(checkout): estilos de secciones y cards de metodos de envio"
```

---

## Task 7: Verificación completa, despliegue y prueba en vivo

**Files:** ninguno (verificación + deploy)

- [ ] **Step 1: Suite de tests completa**

Run: `php phpunit.phar`
Expected: PASS — todos los tests (Document, Payments, Settings, Thankyou, Shipping) en verde.

- [ ] **Step 2: Lint de todos los PHP tocados**

Run (uno por uno):
```bash
php -l ccm-checkout.php
php -l includes/class-ccmck-shipping.php
php -l templates/checkout/form-billing.php
php -l templates/checkout/form-checkout.php
php -l templates/checkout/review-order.php
```
Expected: `No syntax errors detected` en los 5.

- [ ] **Step 3: Verificar que form-billing entra por el localizador de templates**

Abrir `includes/class-ccmck-templates.php` y confirmar que `woocommerce_locate_template`
redirige `checkout/form-billing.php` al override (igual que form-checkout/review-order/payment).
Si la clase usa una lista blanca de archivos, añadir `'form-billing.php'`. Si redirige por
carpeta `checkout/`, no hace falta cambio. (Documentar el hallazgo en el commit si se edita.)

- [ ] **Step 4: Push**

```bash
git push origin main
```

- [ ] **Step 5: Desplegar por File Manager (manual — la carpeta local NO sincroniza)**

Subir al servidor, reemplazando, vía File Manager del hosting:
- `ccm-checkout.php`
- `includes/class-ccmck-shipping.php` (nuevo)
- `templates/checkout/form-billing.php` (nuevo)
- `templates/checkout/form-checkout.php`
- `templates/checkout/review-order.php`
- `assets/ccmck-checkout.css`

Luego **purgar OPcache** (los PHP no aplican sin esto). El CSS auto-busta por `?ver=<filemtime>`.

- [ ] **Step 6: Verificación en vivo (chrome-devtools MCP)**

1. Navegar a `https://dev.dev.ccmtiendadelsonido.com/pago/` con carrito; hard-reload.
2. `evaluate_script`: confirmar que existen 4 encabezados en orden:
   `[...document.querySelectorAll('.checkout-main h2')].map(h=>h.textContent.trim())`
   Expected: `["Contacto","Entrega","Métodos de envío","Pago"]`.
3. Confirmar el email en Contacto y el enlace login:
   `!!document.querySelector('.ccmck-contacto #billing_email') && !!document.querySelector('.ccmck-login-link')` → `true`.
4. Confirmar cards de envío en el cuerpo y NO selector en el sidebar:
   `document.querySelectorAll('#ccmck_shipping_methods input[name^=shipping_method]').length > 0` → `true`;
   `document.querySelectorAll('#order_review input[name^=shipping_method]').length` → `0`.
5. Cambiar de método de envío (click en otra card) y verificar vía `list_network_requests`
   que dispara `?wc-ajax=update_order_review` y que el costo/total del sidebar se actualiza
   (Coordinadora recotiza). Tomar screenshot.
6. Confirmar que el botón "Pagar ahora" sigue presente y el form postea
   (`document.querySelector('#place_order')` existe).
7. Captura desktop + móvil (resize 390px) del flujo de secciones.

- [ ] **Step 7: Actualizar memoria y CHANGELOG**

Anotar en `docs/CHANGELOG.md` la feature y actualizar la memoria del proyecto
(`ccm-checkout-project.md`) con el nuevo layout de secciones y `CCMCK_Shipping`.

```bash
git add docs/CHANGELOG.md
git commit -m "docs: changelog checkout en secciones + metodos de envio"
git push origin main
```

---

## Self-Review (cobertura del spec)

- **§1 Reestructuración columna principal** → Task 4. ✓
- **§2 Partición Contacto/Entrega** → Task 3. ✓
- **§3 Envío en columna principal (fragment)** → Task 1 (`CCMCK_Shipping`) + Task 2 (init) + Task 4 (contenedor). ✓
- **§4 Sidebar sólo total** → Task 5. ✓
- **§5 Estilos** → Task 6. ✓
- **§6 Carga de la clase** → Task 2. ✓
- **Verificación en vivo del spec** → Task 7. ✓
- Tipos/nombres consistentes: `CCMCK_Shipping::build_methods` / `render_cards` / `render` / `fragments` / `CONTAINER_ID = 'ccmck_shipping_methods'` se usan igual en clase, template (Task 4) y tests (Task 1). ✓
- Sin placeholders: todos los pasos con código/comando real. ✓
```
