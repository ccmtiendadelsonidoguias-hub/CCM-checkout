# Página de carrito — Plan de implementación (1 de 3)

> **Para quien ejecute esto:** SUB-SKILL OBLIGATORIA: usa `superpowers:subagent-driven-development` (recomendada) o `superpowers:executing-plans` para ir tarea por tarea. Los pasos llevan casilla (`- [ ]`) para marcarlos.

**Objetivo:** que `/carrito/` sea una página de carrito de verdad, con el diseño de dos columnas de la referencia: artículos a la izquierda, tarjeta de resumen con cupón y total a la derecha.

**Arquitectura:** plantillas propias servidas por la lista blanca que `CCMCK_Templates` ya tiene sobre `woocommerce_locate_template`. Los importes, los cupones y las existencias los sigue calculando WooCommerce; aquí solo se pintan. Las cantidades se actualizan con el endpoint `ccmck_update_cart_item` que **ya existe** en el plugin.

**Tecnología:** PHP 8.3, WordPress 7.0.2, WooCommerce 10.7.0, PHPUnit 11.5.56.

**Spec:** `docs/superpowers/specs/2026-08-15-ccmck-carrito-design.md`

## Este plan es 1 de 3

La spec cubre tres cosas separables. Se parten para que cada una entregue algo usable y revisable por separado:

1. **La página de carrito** — este plan. Cierra un bloqueador del despliegue y construye el pintado de líneas.
2. **El cajón lateral** — reutiliza ese pintado y reemplaza a `woocommerce-side-cart-premium`.
3. **El motor de sugerencias** — las dos capas, el cálculo diario y la pantalla de reglas.

Este plan **no** toca el cajón ni las sugerencias. La página queda sin bloque de sugerencias hasta el plan 3.

## Restricciones globales

- Repo **propio**: `mu-plugins/ccm-checkout` (`github.com/ccmtiendadelsonidoguias-hub/CCM-checkout`). Rama **`feat/carrito`**, desde `6a99ab5`. **Sin push.**
- **Nada se despliega a producción.** Ni leer ni escribir en `public_html/wp-content/`; solo `public_html/dev/`.
- Pruebas: `cd .../mu-plugins/ccm-checkout && ../ccm-account/vendor/bin/phpunit --no-coverage`. **Línea base: 249 pruebas en verde.**
- Terminar una tarea exige la **suite completa**, nunca `--filter`. En una sesión anterior se reportó verde con 6 errores por mirar solo el subconjunto filtrado.
- Despliegue a dev: `sync-dev.sh` **no** sube este plugin. Antes de sobrescribir, comparar **normalizando finales de línea** (local CRLF, servidor LF; un `md5sum` crudo difiere siempre):
  ```bash
  tr -d '\r' < RUTA | md5sum
  ssh -p 65002 u164047049@195.35.13.136 "tr -d '\r' < domains/ccmtiendadelsonido.com/public_html/dev/wp-content/mu-plugins/ccm-checkout/RUTA | md5sum"
  ```
  Distintos: **PARAR**. Y subir siempre copias en LF: `tr -d '\r' < ARCHIVO > /tmp/subir && scp ...`
- **Los importes no se recalculan.** Se leen de `WC()->cart` y se pintan. El IVA colombiano va incluido en el precio; tocarlo no está pedido y rompería el checkout.
- Comentarios en español; código en inglés donde ya lo está.
- Convención de nombres del plugin: clases `CCMCK_*`, constantes `CCMCK_*`.

---

### Tarea 1: Que la página vuelva a ser un carrito

**Archivos:** ninguno. Es configuración de dev y verificación.

**Por qué es la primera:** la página 26011 tiene un `<form>` de HTML estático pegado donde debería ir el shortcode. Sin arreglar eso no hay nada que rediseñar — y además es uno de los tres bloqueadores del despliegue de la Etapa 1.

- [ ] **Paso 1: Ver qué hay ahora, y guardarlo**

```bash
ssh -p 65002 u164047049@195.35.13.136 'cd domains/ccmtiendadelsonido.com/public_html/dev && wp post get 26011 --field=post_content > /tmp/carrito-26011-original.html && wc -c /tmp/carrito-26011-original.html && head -c 300 /tmp/carrito-26011-original.html'
```

Guarda ese archivo antes de tocar nada. **Es la única copia de lo que alguien puso ahí a mano**, y la spec deja anotado que conviene entender por qué está antes de tirarlo.

- [ ] **Paso 2: Comprobar que hoy la página no es un carrito**

```bash
curl -s https://dev.ccmtiendadelsonido.com/carrito/ | grep -c "woocommerce-cart-form"
```

Esperado: `0`. Si sale 1, la página ya funciona y hay que entender por qué antes de seguir.

- [ ] **Paso 3: Poner el shortcode**

```bash
ssh -p 65002 u164047049@195.35.13.136 'cd domains/ccmtiendadelsonido.com/public_html/dev && wp post update 26011 --post_content="[woocommerce_cart]"'
```

- [ ] **Paso 4: Excluir `/carrito/` de la caché**

Un carrito cacheado enseña el de otro cliente. Hoy `cache-exc` está vacío.

```bash
ssh -p 65002 u164047049@195.35.13.136 'cd domains/ccmtiendadelsonido.com/public_html/dev && wp litespeed-option set cache-exc "/carrito/" && wp litespeed-purge all'
```

- [ ] **Paso 5: Verificar que ahora sí es un carrito**

```bash
curl -s https://dev.ccmtiendadelsonido.com/carrito/ | grep -c "woocommerce-cart-form"
curl -sI https://dev.ccmtiendadelsonido.com/carrito/ | grep -i "x-litespeed-cache\|cache-control"
```

Esperado: `1` en el primero. En el segundo, **no** debe aparecer `x-litespeed-cache: hit`.

- [ ] **Paso 6: Anotar en `docs/CHANGELOG.md` y commit**

No hay cambios de código, pero sí una decisión que hay que poder rastrear: qué había en la página y por qué se sustituyó. **Sin esto el commit saldría vacío y git lo rechazaría.**

Añadir al principio de `docs/CHANGELOG.md`:

```markdown
## Carrito — la página 26011 vuelve a ser un carrito (dev, 2026-08-15)

La página tenía un `<form>` de HTML estático pegado donde debía ir
`[woocommerce_cart]`, así que `/carrito/` no era un carrito: era una tabla
muerta. Se sustituye por el shortcode.

Copia de lo que había, antes de tirarlo: `/tmp/carrito-26011-original.html` en
el servidor (pesaba <BYTES> bytes). **Nadie sabe todavía quién lo puso ahí ni
por qué**; conviene averiguarlo antes de repetir esto en producción.

Se excluye además `/carrito/` de la caché de LiteSpeed: hasta hoy se servía
pública y cacheada, y un carrito cacheado enseña el de otro cliente.

Hecho **solo en dev**.
```

Sustituir `<BYTES>` por el tamaño real que dio el paso 1.

```bash
git add docs/CHANGELOG.md
git commit -m "docs: la pagina 26011 vuelve a ser un carrito (dev)"
```

---

### Tarea 2: Las plantillas pasan por el plugin, sin cambiar de aspecto

**Archivos:**
- Modificar: `includes/class-ccmck-templates.php`
- Modificar: `includes/class-ccmck-assets.php`
- Crear: `templates/cart/cart.php`, `templates/cart/cart-totals.php`, `templates/cart/cart-empty.php`
- Crear: `assets/ccmck-cart.css`, `assets/ccmck-cart.js`
- **Crear**: `tests/TemplatesTest.php` (no existe hoy)
- Modificar: `tests/bootstrap.php`

**Interfaces:**
- Consume: `CCMCK_Templates::OVERRIDES` (lista blanca ya existente), `CCMCK_Assets::asset_version()`.
- Produce: `CCMCK_Assets::loads_cart( bool $is_cart ): bool`.

**El truco de esta tarea:** las tres plantillas se crean como **copias literales** de las de WooCommerce. La página tiene que verse **exactamente igual** al terminar. Así se prueba que el desvío funciona antes de cambiar ni un píxel — y si algo se rompe después, se sabe que fue el diseño y no el mecanismo.

- [ ] **Paso 1: Preparar el banco de pruebas**

`tests/TemplatesTest.php` **no existe** y `tests/bootstrap.php` **no carga** ni `CCMCK_Templates` ni `CCMCK_Assets`, así que sin esto las pruebas del paso siguiente fallarían por clase indefinida y no por lo que quieren probar.

Añadir al final de `tests/bootstrap.php`, **antes** de cualquier `require_once` de clases que ya haya:

```php
// Stubs que necesitan CCMCK_Templates y CCMCK_Assets. Ninguno hace nada: las
// pruebas solo ejercitan las funciones PURAS de esas clases (la lista blanca y
// la decisión de encolar), no el encolado de verdad, que es de WordPress.
if ( ! function_exists( 'is_cart' ) ) {
    function is_cart() { return false; }
}
if ( ! function_exists( 'is_checkout' ) ) {
    function is_checkout() { return false; }
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( ...$a ) { return null; }
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( ...$a ) { return null; }
}
if ( ! function_exists( 'wp_localize_script' ) ) {
    function wp_localize_script( ...$a ) { return true; }
}
if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) { return 'https://ejemplo.test/wp-admin/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( $action = -1 ) { return 'nonce-de-prueba'; }
}
if ( ! defined( 'CCMCK_DIR' ) ) {
    define( 'CCMCK_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'CCMCK_URL' ) ) {
    define( 'CCMCK_URL', 'https://ejemplo.test/wp-content/mu-plugins/ccm-checkout/' );
}
```

Y los dos `require_once`, al final del archivo junto a los que ya hay:

```php
require_once dirname( __DIR__ ) . '/includes/class-ccmck-templates.php';
require_once dirname( __DIR__ ) . '/includes/class-ccmck-assets.php';
```

- [ ] **Paso 2: Escribir las pruebas que fallan**

Crear `tests/TemplatesTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

final class TemplatesTest extends TestCase {
```
	public function test_the_cart_templates_are_whitelisted(): void {
		$r = new ReflectionClass( 'CCMCK_Templates' );
		$overrides = $r->getConstant( 'OVERRIDES' );

		foreach ( array( 'cart/cart.php', 'cart/cart-totals.php', 'cart/cart-empty.php' ) as $t ) {
			$this->assertContains( $t, $overrides, "falta $t en la lista blanca" );
		}
	}

	public function test_the_whitelist_never_touches_emails_or_account(): void {
		// Un filtro amplio sobre woocommerce_locate_template secuestraria las
		// plantillas de correo sin que se note, y las de myaccount son de
		// ccm-account: dos plugins pintando la misma pantalla es un conflicto
		// que no falla, solo da un resultado raro.
		$r = new ReflectionClass( 'CCMCK_Templates' );

		foreach ( $r->getConstant( 'OVERRIDES' ) as $t ) {
			$this->assertStringNotContainsString( 'emails/', $t );
			$this->assertStringNotContainsString( 'myaccount/', $t );
		}
	}

	public function test_cart_assets_load_only_on_the_cart(): void {
		// Cargar el CSS del carrito en las 1.002 fichas de producto seria
		// pagarlo en cada visita para nada.
		$this->assertTrue( CCMCK_Assets::loads_cart( true ) );
		$this->assertFalse( CCMCK_Assets::loads_cart( false ) );
	}
}
```

- [ ] **Paso 3: Ejecutarlas y ver que fallan**

Subir los archivos a dev (ver "Restricciones globales" para el guardián de hashes y el LF) y:

```bash
ssh -p 65002 u164047049@195.35.13.136 'cd domains/ccmtiendadelsonido.com/public_html/dev/wp-content/mu-plugins/ccm-checkout && ../ccm-account/vendor/bin/phpunit --no-coverage --filter TemplatesTest'
```

Esperado: fallo en `cart/cart.php` no está en la lista, y `Call to undefined method CCMCK_Assets::loads_cart()`.

- [ ] **Paso 4: Ampliar la lista blanca**

En `includes/class-ccmck-templates.php`, dentro de `OVERRIDES`:

```php
    private const OVERRIDES = array(
        'checkout/form-checkout.php',
        'checkout/form-billing.php',
        'checkout/review-order.php',
        'checkout/payment.php',
        'checkout/thankyou.php',
        // Carrito. Se sustituyen aquí y no en un plugin aparte porque el
        // carrito y el checkout comparten el cotizador de Coordinadora, las
        // ciudades con DANE y los recargos.
        'cart/cart.php',
        'cart/cart-totals.php',
        'cart/cart-empty.php',
    );
```

- [ ] **Paso 5: Encolar los assets del carrito**

En `includes/class-ccmck-assets.php`, añadir el método y la llamada:

```php
    /**
     * ¿Toca cargar los assets del carrito? PURO.
     *
     * Se separa del `enqueue()` para poder probarlo: `is_cart()` necesita
     * WordPress entero y el banco de pruebas no lo carga.
     */
    public static function loads_cart( bool $is_cart ): bool {
        return $is_cart;
    }
```

Y dentro de `enqueue()`, **antes** del `if ( ! is_checkout() ) { return; }` que ya está —porque ese `return` corta y dejaría el carrito sin nada—:

```php
        if ( self::loads_cart( is_cart() ) ) {
            wp_enqueue_style( 'ccmck-cart', CCMCK_URL . 'assets/ccmck-cart.css', array(), self::asset_version( 'assets/ccmck-cart.css' ) );
            wp_enqueue_script( 'ccmck-cart', CCMCK_URL . 'assets/ccmck-cart.js', array(), self::asset_version( 'assets/ccmck-cart.js' ), true );

            // El endpoint y el nonce que ya usa CCMCK_Cart_Ajax.
            wp_localize_script( 'ccmck-cart', 'ccmckCart', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'ccmck_cart' ),
            ) );
        }
```

- [ ] **Paso 6: Crear las tres plantillas como copias literales**

Traerlas de WooCommerce tal cual, y añadir solo la cabecera que documenta el origen:

```bash
ssh -p 65002 u164047049@195.35.13.136 'cd domains/ccmtiendadelsonido.com/public_html/dev/wp-content/plugins/woocommerce/templates/cart && cat cart.php' > templates/cart/cart.php
ssh -p 65002 u164047049@195.35.13.136 'cd domains/ccmtiendadelsonido.com/public_html/dev/wp-content/plugins/woocommerce/templates/cart && cat cart-totals.php' > templates/cart/cart-totals.php
ssh -p 65002 u164047049@195.35.13.136 'cd domains/ccmtiendadelsonido.com/public_html/dev/wp-content/plugins/woocommerce/templates/cart && cat cart-empty.php' > templates/cart/cart-empty.php
```

En cada una, sustituir el bloque de comentario de cabecera por este, poniendo el nombre del archivo y **el `@version` que declare esa misma plantilla de WooCommerce** (en `cart.php` es `10.1.0`; las otras dos declaran el suyo en su primera línea de comentario, cópialo de ahí):

```php
/**
 * <NOMBRE> — CCM Checkout.
 *
 * Copia literal de woocommerce/templates/cart/<NOMBRE> @version <LA QUE DIGA EL ARCHIVO>.
 * En esta tarea NO se cambia nada: se copia tal cual para probar que el desvío
 * funciona antes de tocar el diseño. Si algo se rompe después, se sabrá que fue
 * el diseño y no el mecanismo.
 *
 * Revisar tras cada actualización de WooCommerce.
 *
 * @package CCM_Checkout
 */
```

Crear también los dos assets, vacíos salvo un comentario:

```css
/* Estilos de la página de carrito. Se rellenan en la tarea 6. */
```

```js
/* Cantidades del carrito. Se rellena en la tarea 4. */
```

- [ ] **Paso 7: Ejecutar la suite completa**

```bash
ssh -p 65002 u164047049@195.35.13.136 'cd domains/ccmtiendadelsonido.com/public_html/dev/wp-content/mu-plugins/ccm-checkout && ../ccm-account/vendor/bin/phpunit --no-coverage'
```

Esperado: **252 pruebas** en verde (249 + 3).

- [ ] **Paso 8: Verificar que la página se ve IGUAL y viene de nosotros**

```bash
ssh -p 65002 u164047049@195.35.13.136 'cd domains/ccmtiendadelsonido.com/public_html/dev && wp eval "echo wc_locate_template( \"cart/cart.php\" );"'
```

Esperado: una ruta dentro de `mu-plugins/ccm-checkout/templates/`, no dentro de `plugins/woocommerce/`.

Y que el CSS del carrito se encola:

```bash
curl -s https://dev.ccmtiendadelsonido.com/carrito/ | grep -c "ccmck-cart.css"
```

Esperado: `1`.

- [ ] **Paso 9: Commit**

```bash
git add includes/class-ccmck-templates.php includes/class-ccmck-assets.php templates/cart assets/ccmck-cart.css assets/ccmck-cart.js tests/TemplatesTest.php
git commit -m "feat(carrito): las plantillas pasan por el plugin, sin cambiar de aspecto"
```

---

### Tarea 3: La lista de artículos

**Archivos:**
- Modificar: `templates/cart/cart.php`

**Interfaces:**
- Consume: nada nuevo.
- Produce: el marcado `.ccmck-cart`, `.ccmck-cart__items`, `.ccmck-item`, `.ccmck-item__photo`, `.ccmck-item__name`, `.ccmck-item__meta`, `.ccmck-qty`, `.ccmck-item__total`, `.ccmck-item__remove`. La tarea 6 los estiliza y el plan 2 (cajón) los reutiliza.

**Lo que se conserva de la original, y no es negociable:** los ganchos `woocommerce_before_cart`, `woocommerce_before_cart_table`, `woocommerce_before_cart_contents`, `woocommerce_cart_contents`, `woocommerce_after_cart_contents`, `woocommerce_after_cart_table`, `woocommerce_before_cart_collaterals`, `woocommerce_cart_collaterals`, `woocommerce_after_cart`; y los filtros `woocommerce_cart_item_product`, `woocommerce_cart_item_visible`, `woocommerce_cart_item_permalink`, `woocommerce_cart_item_name`, `woocommerce_cart_item_thumbnail`, `woocommerce_cart_item_remove_link`, `woocommerce_cart_item_quantity`, `woocommerce_cart_item_subtotal`. Otros plugins cuelgan de ellos.

- [ ] **Paso 1: Reescribir el cuerpo de la tabla**

En `templates/cart/cart.php`, sustituir la `<table class="shop_table ...">` entera por una lista. El envoltorio `<form class="woocommerce-cart-form">` y su `action`/`method` **se quedan**: sin él, el botón de actualizar y los cupones dejan de funcionar.

```php
<div class="ccmck-cart">

	<form class="woocommerce-cart-form ccmck-cart__form" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
		<?php do_action( 'woocommerce_before_cart_table' ); ?>

		<ul class="ccmck-cart__items">
			<?php do_action( 'woocommerce_before_cart_contents' ); ?>

			<?php
			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				$_product   = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
				$product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );

				if ( ! $_product || ! $_product->exists() || $cart_item['quantity'] <= 0 || ! apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
					continue;
				}

				$permalink = apply_filters( 'woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
				$thumbnail = apply_filters( 'woocommerce_cart_item_thumbnail', $_product->get_image( 'woocommerce_thumbnail', array( 'alt' => '' ) ), $cart_item, $cart_item_key );
				?>
				<li class="ccmck-item <?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>" data-key="<?php echo esc_attr( $cart_item_key ); ?>">

					<span class="ccmck-item__photo" aria-hidden="true">
						<?php
						// Decorativa: el nombre va al lado y lo dice mejor. Un alt
						// con el nombre repetido lo hace leer dos veces.
						echo $permalink
							? '<a href="' . esc_url( $permalink ) . '">' . wp_kses_post( $thumbnail ) . '</a>'
							: wp_kses_post( $thumbnail );
						?>
					</span>

					<div class="ccmck-item__body">
						<span class="ccmck-item__name">
							<?php
							echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $permalink ? sprintf( '<a href="%s">%s</a>', esc_url( $permalink ), $_product->get_name() ) : $_product->get_name(), $cart_item, $cart_item_key ) );
							?>
						</span>

						<span class="ccmck-item__meta">
							<?php echo wc_get_formatted_cart_item_data( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</span>

						<?php if ( $_product->backorders_require_notification() && $_product->is_on_backorder( $cart_item['quantity'] ) ) : ?>
							<span class="ccmck-item__aviso"><?php esc_html_e( 'Disponible bajo pedido', 'ccm-checkout' ); ?></span>
						<?php endif; ?>
					</div>

					<div class="ccmck-qty">
						<?php
						// El campo de cantidad de WooCommerce, con sus limites de
						// stock. El boton de mas y el de menos son de la tarea 4:
						// aqui va el campo, que es lo que se envia al actualizar.
						echo apply_filters(
							'woocommerce_cart_item_quantity',
							$_product->is_sold_individually()
								? '<input type="hidden" name="cart[' . $cart_item_key . '][qty]" value="1" />'
								: woocommerce_quantity_input(
									array(
										'input_name'  => "cart[{$cart_item_key}][qty]",
										'input_value' => $cart_item['quantity'],
										'max_value'   => $_product->get_max_purchase_quantity(),
										'min_value'   => '0',
										'product_name' => $_product->get_name(),
									),
									$_product,
									false
								),
							$cart_item_key,
							$cart_item
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</div>

					<span class="ccmck-item__total">
						<?php echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>

					<span class="ccmck-item__remove">
						<?php
						echo apply_filters( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							'woocommerce_cart_item_remove_link',
							sprintf(
								'<a href="%s" class="remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">&times;</a>',
								esc_url( wc_get_cart_remove_url( $cart_item_key ) ),
								/* translators: %s: nombre del producto */
								esc_attr( sprintf( __( 'Quitar %s del carrito', 'ccm-checkout' ), $_product->get_name() ) ),
								esc_attr( $product_id ),
								esc_attr( $_product->get_sku() )
							),
							$cart_item_key
						);
						?>
					</span>

				</li>
				<?php
			}
			?>

			<?php do_action( 'woocommerce_cart_contents' ); ?>
			<?php do_action( 'woocommerce_after_cart_contents' ); ?>
		</ul>

		<?php
		// Este bloque lleva el campo de cupon y el boton de actualizar. Se deja
		// dentro del formulario: los dos se envian por POST.
		do_action( 'woocommerce_cart_actions' );
		wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' );
		?>

		<?php do_action( 'woocommerce_after_cart_table' ); ?>
	</form>

	<?php do_action( 'woocommerce_before_cart_collaterals' ); ?>

	<div class="ccmck-cart__resumen">
		<?php do_action( 'woocommerce_cart_collaterals' ); ?>
	</div>

</div>
```

Conservar arriba del todo el `do_action( 'woocommerce_before_cart' )` y abajo el `do_action( 'woocommerce_after_cart' )` que ya trae el archivo.

- [ ] **Paso 2: Comprobar que no se perdió ningún gancho**

```bash
for h in woocommerce_before_cart woocommerce_before_cart_table woocommerce_before_cart_contents woocommerce_cart_contents woocommerce_after_cart_contents woocommerce_after_cart_table woocommerce_cart_actions woocommerce_before_cart_collaterals woocommerce_cart_collaterals woocommerce_after_cart; do
  printf "%-42s %s\n" "$h" "$(grep -c "$h" templates/cart/cart.php)"
done
```

Esperado: **1 en todos**. Un `0` es un plugin de terceros que deja de funcionar sin avisar.

- [ ] **Paso 3: Ejecutar la suite completa**

```bash
ssh -p 65002 u164047049@195.35.13.136 'cd domains/ccmtiendadelsonido.com/public_html/dev/wp-content/mu-plugins/ccm-checkout && ../ccm-account/vendor/bin/phpunit --no-coverage'
```

Esperado: **252 en verde**. Esta tarea no añade pruebas: es marcado, y se verifica en el navegador.

- [ ] **Paso 4: Verificar en la página real**

Con un carrito que tenga al menos dos productos distintos:

```bash
curl -s https://dev.ccmtiendadelsonido.com/carrito/ | grep -c "ccmck-item"
```

Esperado: mayor que 0. Y que la página no traiga avisos de PHP:

```bash
curl -s https://dev.ccmtiendadelsonido.com/carrito/ | grep -ci "Undefined variable\|Fatal error\|Warning:"
```

Esperado: `0`.

- [ ] **Paso 5: Commit**

```bash
git add templates/cart/cart.php
git commit -m "feat(carrito): la lista de articulos, con foto y cantidad"
```

---

### Tarea 4: El más y el menos, sin recargar

**Archivos:**
- Modificar: `assets/ccmck-cart.js`
- Modificar: `templates/cart/cart.php`

**Interfaces:**
- Consume: la acción AJAX `ccmck_update_cart_item` de `CCMCK_Cart_Ajax`, con nonce `ccmck_cart`, que acepta `key` y `qty` y devuelve `{ item_count }`. **Ya existe: no se toca.**
- Produce: los botones `.ccmck-qty__mas` y `.ccmck-qty__menos`.

**Cómo se recarga la página después:** el endpoint devuelve solo el número de artículos, no el HTML. Tras cambiar una cantidad hay que refrescar los importes. La forma que menos código nuevo mete: recargar la página. **No es elegante, y es lo correcto aquí**: los importes dependen del envío cotizado a Coordinadora, de los recargos y de los cupones, y reconstruirlos en el cliente sería reimplementar el carrito de WooCommerce en JavaScript.

- [ ] **Paso 1: Añadir los botones al marcado**

En `templates/cart/cart.php`, dentro de `<div class="ccmck-qty">`, envolver el campo:

```php
					<div class="ccmck-qty">
						<button type="button" class="ccmck-qty__menos" aria-label="<?php esc_attr_e( 'Quitar uno', 'ccm-checkout' ); ?>">&minus;</button>
						<?php /* ... el bloque de woocommerce_cart_item_quantity que ya está ... */ ?>
						<button type="button" class="ccmck-qty__mas" aria-label="<?php esc_attr_e( 'Añadir uno', 'ccm-checkout' ); ?>">+</button>
					</div>
```

- [ ] **Paso 2: Escribir el JavaScript**

`assets/ccmck-cart.js` completo:

```js
/**
 * Cantidades del carrito, sin recargar a mano.
 *
 * Mejora progresiva: si esto no corre, el campo de cantidad y el boton
 * "Actualizar carrito" de WooCommerce siguen funcionando como siempre.
 */
( function () {
	'use strict';

	var config = window.ccmckCart;

	if ( ! config || ! config.ajaxUrl || ! config.nonce ) {
		return;
	}

	function campoDe( boton ) {
		var fila = boton.closest( '.ccmck-item' );
		return fila ? fila.querySelector( 'input.qty' ) : null;
	}

	function enviar( clave, cantidad, boton ) {
		var body = new FormData();
		body.append( 'action', 'ccmck_update_cart_item' );
		body.append( 'nonce', config.nonce );
		body.append( 'key', clave );
		body.append( 'qty', cantidad );

		boton.disabled = true;

		fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( 'no se pudo actualizar' );
				}
				// Se recarga a proposito: los importes dependen del envio
				// cotizado, de los recargos y de los cupones. Reconstruirlos
				// aqui seria reimplementar el carrito de WooCommerce.
				window.location.reload();
			} )
			.catch( function () {
				boton.disabled = false;
				// Recuperacion: el boton "Actualizar carrito" de WooCommerce
				// sigue ahi y hace lo mismo con una recarga completa.
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var boton = event.target.closest( '.ccmck-qty__mas, .ccmck-qty__menos' );

		if ( ! boton ) {
			return;
		}

		var fila = boton.closest( '.ccmck-item' );
		var campo = campoDe( boton );

		if ( ! fila || ! campo ) {
			return;
		}

		event.preventDefault();

		var actual = parseInt( campo.value, 10 ) || 0;
		var maximo = parseInt( campo.getAttribute( 'max' ), 10 );
		var nueva  = boton.classList.contains( 'ccmck-qty__mas' ) ? actual + 1 : actual - 1;

		if ( nueva < 0 ) {
			return;
		}

		// El limite lo pone el stock del producto, que WooCommerce ya escribio
		// en el atributo `max`. Pasarse daria un error del servidor.
		if ( ! isNaN( maximo ) && nueva > maximo ) {
			return;
		}

		enviar( fila.getAttribute( 'data-key' ), nueva, boton );
	} );
} )();
```

- [ ] **Paso 3: Ejecutar la suite completa**

```bash
ssh -p 65002 u164047049@195.35.13.136 'cd domains/ccmtiendadelsonido.com/public_html/dev/wp-content/mu-plugins/ccm-checkout && ../ccm-account/vendor/bin/phpunit --no-coverage'
```

Esperado: **252 en verde**. El JavaScript no tiene pruebas automáticas en este proyecto; se verifica en el navegador en la tarea 7.

- [ ] **Paso 4: Commit**

```bash
git add templates/cart/cart.php assets/ccmck-cart.js
git commit -m "feat(carrito): mas y menos en la cantidad, por AJAX"
```

---

### Tarea 5: La tarjeta de resumen

**Archivos:**
- Modificar: `templates/cart/cart-totals.php`

**Interfaces:**
- Consume: nada nuevo.
- Produce: `.ccmck-resumen`, `.ccmck-resumen__fila`, `.ccmck-resumen__total`, `.ccmck-resumen__pagar`.

**Antes de empezar — los cupones.** La referencia trae la caja de cupón y el dueño la quiere. Hoy están **desactivados** en WooCommerce y hay **0 publicados**. Activarlos:

```bash
ssh -p 65002 u164047049@195.35.13.136 'cd domains/ccmtiendadelsonido.com/public_html/dev && wp option update woocommerce_enable_coupons yes'
```

**Consecuencia que hay que aceptar:** la caja de cupón aparecerá **también en el checkout**, que es de este mismo plugin. No es evitable; es cómo funciona WooCommerce.

- [ ] **Paso 1: Reescribir la plantilla**

`templates/cart/cart-totals.php`, conservando los ganchos `woocommerce_before_cart_totals`, `woocommerce_cart_totals_before_shipping`, `woocommerce_cart_totals_after_shipping`, `woocommerce_cart_totals_before_order_total`, `woocommerce_cart_totals_after_order_total`, `woocommerce_proceed_to_checkout` y `woocommerce_after_cart_totals`:

```php
<div class="ccmck-resumen">

	<?php do_action( 'woocommerce_before_cart_totals' ); ?>

	<h2 class="ccmck-resumen__titulo"><?php esc_html_e( 'Resumen del pedido', 'ccm-checkout' ); ?></h2>

	<div class="ccmck-resumen__fila">
		<span><?php esc_html_e( 'Subtotal', 'ccm-checkout' ); ?></span>
		<span><?php wc_cart_totals_subtotal_html(); ?></span>
	</div>

	<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
		<div class="ccmck-resumen__fila ccmck-resumen__fila--cupon">
			<span><?php wc_cart_totals_coupon_label( $coupon ); ?></span>
			<span><?php wc_cart_totals_coupon_html( $coupon ); ?></span>
		</div>
	<?php endforeach; ?>

	<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>
		<?php do_action( 'woocommerce_cart_totals_before_shipping' ); ?>
		<?php wc_cart_totals_shipping_html(); ?>
		<?php do_action( 'woocommerce_cart_totals_after_shipping' ); ?>
	<?php endif; ?>

	<?php foreach ( WC()->cart->get_fees() as $fee ) : ?>
		<div class="ccmck-resumen__fila">
			<span><?php echo esc_html( $fee->name ); ?></span>
			<span><?php wc_cart_totals_fee_html( $fee ); ?></span>
		</div>
	<?php endforeach; ?>

	<?php do_action( 'woocommerce_cart_totals_before_order_total' ); ?>

	<div class="ccmck-resumen__fila ccmck-resumen__total">
		<span><?php esc_html_e( 'Total', 'ccm-checkout' ); ?></span>
		<span><?php wc_cart_totals_order_total_html(); ?></span>
	</div>

	<?php do_action( 'woocommerce_cart_totals_after_order_total' ); ?>

	<div class="ccmck-resumen__pagar">
		<?php do_action( 'woocommerce_proceed_to_checkout' ); ?>
	</div>

	<?php do_action( 'woocommerce_after_cart_totals' ); ?>

</div>
```

**Nota sobre los impuestos:** no se pinta ninguna línea de "impuesto estimado". En Colombia el IVA va incluido en el precio y WooCommerce ya lo refleja en el subtotal. Añadir esa línea confundiría, y calcularla nosotros rompería el checkout.

- [ ] **Paso 2: Comprobar los ganchos**

```bash
for h in woocommerce_before_cart_totals woocommerce_cart_totals_before_shipping woocommerce_cart_totals_after_shipping woocommerce_cart_totals_before_order_total woocommerce_cart_totals_after_order_total woocommerce_proceed_to_checkout woocommerce_after_cart_totals; do
  printf "%-46s %s\n" "$h" "$(grep -c "$h" templates/cart/cart-totals.php)"
done
```

Esperado: **1 en todos**.

- [ ] **Paso 3: Ejecutar la suite y verificar la caja de cupón**

```bash
ssh -p 65002 u164047049@195.35.13.136 'cd domains/ccmtiendadelsonido.com/public_html/dev/wp-content/mu-plugins/ccm-checkout && ../ccm-account/vendor/bin/phpunit --no-coverage'
curl -s https://dev.ccmtiendadelsonido.com/carrito/ | grep -c "coupon_code"
```

Esperado: 252 en verde, y `1` o más en el segundo (la caja de cupón la pinta WooCommerce en `woocommerce_cart_actions`, ya conservado en la tarea 3).

- [ ] **Paso 4: Commit**

```bash
git add templates/cart/cart-totals.php
git commit -m "feat(carrito): tarjeta de resumen con cupon y total"
```

---

### Tarea 6: Los estilos

**Archivos:**
- Modificar: `assets/ccmck-cart.css`
- Modificar: `templates/cart/cart-empty.php`
- Modificar: `ccm-checkout.php` (subir `CCMCK_VERSION`)

**Interfaces:**
- Consume: las clases de las tareas 3 y 5.
- Produce: nada que consuma otra tarea.

**Los colores son los del kit de Elementor:** `#E60000` (Principal) y `#191818` (Secundario). **No** el `#e63946` que este plugin usa hoy en el checkout — ese no aparece en ninguna otra parte del sitio y está anotado como deuda en el ROADMAP de `wp-content`.

- [ ] **Paso 1: Escribir el CSS**

`assets/ccmck-cart.css`:

```css
/* ---------------------------------------------------------------------------
 * Página de carrito
 *
 * Dos columnas en escritorio: los artículos a la izquierda y el resumen a la
 * derecha, pegajoso, para que el total y el botón de pagar sigan a la vista
 * mientras se recorre una lista larga. En móvil, una columna y el resumen al
 * final, que es donde se decide.
 * ------------------------------------------------------------------------ */

.ccmck-cart {
    --ccmck-rojo: #e60000;
    --ccmck-tinta: #1a1a1a;
    --ccmck-apagado: #6b7280;
    --ccmck-linea: #e8e8e8;
    --ccmck-superficie: #fff;

    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 24px;
    max-width: 1240px;
    margin: 0 auto;
    padding: 24px 16px 56px;
}

.ccmck-cart__items {
    margin: 0;
    padding: 0;
    list-style: none;
    border: 1px solid var(--ccmck-linea);
    border-radius: 16px;
    background: var(--ccmck-superficie);
}

.ccmck-item {
    display: grid;
    grid-template-columns: 72px minmax(0, 1fr) auto;
    align-items: center;
    gap: 14px;
    padding: 16px;
    border-bottom: 1px solid var(--ccmck-linea);
}

.ccmck-item:last-child {
    border-bottom: 0;
}

.ccmck-item__photo img {
    width: 72px;
    height: 72px;
    border-radius: 10px;
    object-fit: cover;
}

.ccmck-item__name {
    display: block;
    font-size: .9375rem;
    font-weight: 600;
    line-height: 1.35;
    color: var(--ccmck-tinta);
}

/* El nombre es un enlace y el tema pinta los enlaces de rojo. En una lista de
   varias líneas eso es un bloque rojo que compite con el total. */
.ccmck-item__name a {
    color: inherit;
    text-decoration: none;
}

.ccmck-item__name a:hover {
    color: var(--ccmck-rojo);
    text-decoration: underline;
}

.ccmck-item__meta,
.ccmck-item__aviso {
    display: block;
    margin-top: 2px;
    font-size: .75rem;
    color: var(--ccmck-apagado);
}

.ccmck-qty {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    border: 1px solid var(--ccmck-linea);
    border-radius: 999px;
    padding: 2px;
}

.ccmck-qty__mas,
.ccmck-qty__menos {
    width: 30px;
    height: 30px;
    border: 0;
    border-radius: 50%;
    background: none;
    font-size: 1rem;
    line-height: 1;
    color: var(--ccmck-tinta);
    cursor: pointer;
}

.ccmck-qty__mas:hover,
.ccmck-qty__menos:hover {
    background: #f5f5f5;
}

/* El campo de WooCommerce, sin la cara nativa del navegador. */
.ccmck-qty input.qty {
    width: 40px;
    border: 0;
    background: none;
    text-align: center;
    font-size: .875rem;
    -moz-appearance: textfield;
    appearance: textfield;
}

.ccmck-qty input.qty::-webkit-outer-spin-button,
.ccmck-qty input.qty::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.ccmck-item__total {
    font-size: .9375rem;
    font-weight: 700;
    color: var(--ccmck-tinta);
    white-space: nowrap;
}

.ccmck-item__remove a {
    display: grid;
    place-items: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    color: var(--ccmck-apagado);
    text-decoration: none;
    font-size: 1.125rem;
}

.ccmck-item__remove a:hover {
    background: #fef7f7;
    color: var(--ccmck-rojo);
}

/* --- Resumen --- */

.ccmck-resumen {
    padding: 20px;
    border: 1px solid var(--ccmck-linea);
    border-radius: 16px;
    background: var(--ccmck-superficie);
}

.ccmck-resumen__titulo {
    margin: 0 0 16px;
    font-size: 1rem;
    font-weight: 600;
    color: var(--ccmck-tinta);
}

.ccmck-resumen__fila {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 16px;
    padding: 8px 0;
    font-size: .875rem;
    color: var(--ccmck-apagado);
}

.ccmck-resumen__fila > span:last-child {
    font-weight: 600;
    color: var(--ccmck-tinta);
}

.ccmck-resumen__total {
    margin-top: 8px;
    padding-top: 14px;
    border-top: 1px solid var(--ccmck-linea);
    font-size: .9375rem;
    color: var(--ccmck-tinta);
}

.ccmck-resumen__total > span:last-child {
    font-size: 1.25rem;
    font-weight: 700;
}

.ccmck-resumen__pagar {
    margin-top: 18px;
}

.ccmck-resumen__pagar .checkout-button {
    display: block;
    width: 100%;
    padding: 14px 20px;
    border-radius: 10px;
    background: var(--ccmck-rojo);
    color: #fff;
    font-weight: 600;
    text-align: center;
    text-decoration: none;
}

/* --- Escritorio: dos columnas --- */

@media (min-width: 900px) {
    .ccmck-cart {
        grid-template-columns: minmax(0, 1fr) 360px;
        align-items: start;
    }

    /* Pegajoso para que el total siga a la vista con una lista larga. El 24px
       lo separa del header compacto, que se queda arriba al bajar. */
    .ccmck-cart__resumen {
        position: sticky;
        top: 24px;
    }
}

/* --- Móvil estrecho --- */

@media (max-width: 560px) {
    .ccmck-item {
        grid-template-columns: 56px minmax(0, 1fr);
        row-gap: 10px;
    }

    .ccmck-item__photo img {
        width: 56px;
        height: 56px;
    }

    /* La cantidad y el importe bajan a su propia fila, juntos. */
    .ccmck-qty {
        grid-column: 1 / -1;
        justify-self: start;
    }

    .ccmck-item__total {
        grid-column: 2;
        text-align: right;
    }
}
```

- [ ] **Paso 2: El carrito vacío**

`templates/cart/cart-empty.php`, conservando `woocommerce_cart_is_empty` y `woocommerce_cart_is_empty_message`:

```php
<?php do_action( 'woocommerce_cart_is_empty' ); ?>

<div class="ccmck-vacio">
	<p><?php esc_html_e( 'Tu carrito está vacío.', 'ccm-checkout' ); ?></p>
	<a class="button" href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>">
		<?php esc_html_e( 'Ir a la tienda', 'ccm-checkout' ); ?>
	</a>
</div>
```

Y su estilo, al final del CSS:

```css
.ccmck-vacio {
    max-width: 1240px;
    margin: 0 auto;
    padding: 48px 20px 64px;
    text-align: center;
}

.ccmck-vacio p {
    margin: 0 0 18px;
    font-size: 1rem;
    color: var(--ccmck-apagado, #6b7280);
}
```

- [ ] **Paso 3: Subir la versión**

En `ccm-checkout.php`, `CCMCK_VERSION` de `1.0.3` a `1.1.0`.

- [ ] **Paso 4: Ejecutar la suite y desplegar**

```bash
ssh -p 65002 u164047049@195.35.13.136 'cd domains/ccmtiendadelsonido.com/public_html/dev/wp-content/mu-plugins/ccm-checkout && ../ccm-account/vendor/bin/phpunit --no-coverage'
```

Esperado: **252 en verde**.

- [ ] **Paso 5: Commit**

```bash
git add assets/ccmck-cart.css templates/cart/cart-empty.php ccm-checkout.php
git commit -m "style(carrito): dos columnas, resumen pegajoso y estado vacio"
```

---

### Tarea 7: Verificación en el navegador

**Archivos:** ninguno. Es verificación.

**Por qué es una tarea y no un paso suelto:** este proyecto ya se equivocó tres veces verificando bien la pantalla equivocada. La página de carrito se sirve de una sola forma —carga completa—, pero las cantidades van por AJAX, y eso hay que verlo funcionar, no deducirlo.

- [ ] **Paso 1: Poner productos en el carrito**

Hacen falta al menos **dos** líneas distintas y una con cantidad mayor que 1. Añadirlos desde la tienda de dev, no por base de datos: así se ejercita el camino real.

- [ ] **Paso 2: Escritorio**

Servir la página a 1280px y medir:

```js
(() => {
  const q = s => document.querySelector(s), r = e => { const b = e.getBoundingClientRect(); return Math.round(b.width) + 'x' + Math.round(b.height); };
  return JSON.stringify({
    columnas: getComputedStyle(q('.ccmck-cart')).gridTemplateColumns,
    articulos: document.querySelectorAll('.ccmck-item').length,
    resumenPegajoso: getComputedStyle(q('.ccmck-cart__resumen')).position,
    foto: r(q('.ccmck-item__photo img')),
    botonPagar: !!q('.checkout-button'),
    cajaCupon: !!q('[name="coupon_code"]'),
    desborde: document.documentElement.scrollWidth - document.documentElement.clientWidth
  }, null, 1);
})()
```

Esperado: dos columnas, `sticky` en el resumen, foto 72x72, botón y caja de cupón presentes, desborde `0`.

- [ ] **Paso 3: Móvil**

A 375px: una columna, foto 56x56, la cantidad y el importe en su propia fila, y `desborde: 0`.

- [ ] **Paso 4: El más y el menos**

Pulsar `+` en un artículo y comprobar que la cantidad sube, la página se refresca y **el total cambia**. Después `−` hasta 0 y comprobar que la línea desaparece.

Esto es lo que hay que ver funcionando, no medir: una cantidad que sube en el campo pero no mueve el total es exactamente el fallo que un `getComputedStyle` no detecta.

- [ ] **Paso 5: El carrito vacío**

Quitar todo y comprobar que sale el mensaje y el botón a la tienda, sin restos de la tabla.

- [ ] **Paso 6: Que el checkout sigue entero**

El cambio más arriesgado de este plan es haber activado los cupones, que aparecen **también** en el checkout. Recorrer `/finalizar-compra/` y comprobar que el resumen, el envío de Coordinadora y los métodos de pago siguen como estaban.

- [ ] **Paso 7: Anotar el resultado en `docs/CHANGELOG.md` y commit**

**Sin esto el commit saldría vacío y git lo rechazaría.** Añadir al principio del archivo, con las cifras REALES que hayan salido, no las esperadas:

```markdown
## Carrito — página verificada en dev (2026-08-15)

Escritorio a 1280px: <COLUMNAS>, <N> artículos, resumen `sticky`, foto de
<TAMAÑO>, desborde horizontal <D>. Móvil a 375px: una columna, foto 56x56,
desborde <D>.

El más y el menos: la cantidad sube, la página se refresca y **el total
cambia** — comprobado con los ojos, no medido. Bajando a 0 la línea
desaparece.

Carrito vacío: mensaje y botón a la tienda, sin restos de la lista.

Checkout recorrido entero tras activar los cupones: <QUÉ SE VIO>.
```

```bash
git add docs/CHANGELOG.md
git commit -m "docs(carrito): pagina verificada en dev"
```

---

## Qué queda para los planes 2 y 3

- **Plan 2, el cajón lateral:** reutiliza `.ccmck-item` y el endpoint de cantidades. Reemplaza a `woocommerce-side-cart-premium`, que hay que desactivar en el mismo cambio o habrá dos cajones.
- **Plan 3, las sugerencias:** las dos capas, el cálculo diario con su umbral de dos pedidos, y la pantalla de reglas por categoría. Entra en las dos pantallas.

## Lo que este plan NO hace, a propósito

- **La barra de envío gratis** de la referencia. No existe ningún método de envío gratuito con umbral; fingirla sería mentir sobre lo que falta para conseguirlo.
- **Bloque de sugerencias.** Es el plan 3.
- **Nada en producción.** Ni el shortcode de la página 26011, ni la exclusión de caché, ni los cupones: todo eso se hace aquí solo en dev, y se repite en producción cuando toque el despliegue, con el checklist del ROADMAP delante.
