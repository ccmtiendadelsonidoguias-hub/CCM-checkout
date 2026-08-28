<?php
use PHPUnit\Framework\TestCase;

final class TemplatesTest extends TestCase {

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

	public function test_shipping_calculator_city_field_is_a_select_not_an_input(): void {
		// woocommerce_form_field_args nunca se dispara para calc_shipping_city:
		// shipping-calculator.php pinta ese campo con un <input> a pelo, sin
		// pasar por woocommerce_form_field(). La única forma real de que la
		// ciudad del carrito sea un <select> es sobreescribir la plantilla
		// entera, como cart.php / cart-totals.php / cart-empty.php.
		$r = new ReflectionClass( 'CCMCK_Templates' );
		$this->assertContains(
			'cart/shipping-calculator.php',
			$r->getConstant( 'OVERRIDES' ),
			'shipping-calculator.php no está en la lista blanca: la ciudad del carrito sigue siendo texto libre'
		);

		$html = file_get_contents( CCMCK_DIR . 'templates/cart/shipping-calculator.php' );
		$this->assertNotFalse( $html, 'no se pudo leer la plantilla del plugin' );

		$this->assertMatchesRegularExpression(
			'/<select[^>]*name="calc_shipping_city"/',
			$html,
			'el campo de ciudad debe ser un <select>, no un <input> de texto libre'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<input[^>]*name="calc_shipping_city"/',
			$html,
			'quedó (o volvió) un <input> de texto libre para la ciudad del carrito'
		);
	}
}
