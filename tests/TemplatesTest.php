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
}
