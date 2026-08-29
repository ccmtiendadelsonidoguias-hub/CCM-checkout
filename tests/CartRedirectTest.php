<?php
use PHPUnit\Framework\TestCase;

final class CartRedirectTest extends TestCase {

	public function test_the_orphan_page_redirects_to_the_real_cart(): void {
		// 28 es "Carrito", vacia desde abril. 26011 es "Mi carrito", la que
		// WooCommerce usa de verdad.
		$this->assertTrue( CCMCK_Cart_Redirect::should_redirect( 28, 26011, 28 ) );
	}

	public function test_the_real_cart_never_redirects_to_itself(): void {
		// Un bucle de redireccion aqui deja el carrito inaccesible para todos.
		$this->assertFalse( CCMCK_Cart_Redirect::should_redirect( 26011, 26011, 28 ) );
	}

	public function test_no_other_page_is_touched(): void {
		foreach ( array( 1, 844, 18962, 16972 ) as $otra ) {
			$this->assertFalse( CCMCK_Cart_Redirect::should_redirect( $otra, 26011, 28 ), "toco la pagina $otra" );
		}
	}

	public function test_a_misconfigured_store_redirects_nothing(): void {
		// Si algun dia las dos opciones apuntan al mismo sitio, o alguna falta,
		// no se redirige: mejor una pagina huerfana que un bucle.
		$this->assertFalse( CCMCK_Cart_Redirect::should_redirect( 28, 28, 28 ) );
		$this->assertFalse( CCMCK_Cart_Redirect::should_redirect( 28, 0, 28 ) );
		$this->assertFalse( CCMCK_Cart_Redirect::should_redirect( 28, 26011, 0 ) );
	}
}
