<?php
/**
 * La página huérfana de carrito lleva a la buena.
 *
 * Esta tienda tiene dos: "Mi carrito" (26011, `/mi-carrito/`), que es la que
 * WooCommerce usa, y "Carrito" (28, `/carrito/`), vacía desde abril de 2026
 * pero dueña del slug corto — el que la gente teclea y el que está en los
 * enlaces viejos.
 *
 * Se redirige de forma permanente para que Google traslade el valor de esa URL
 * a la que funciona.
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

final class CCMCK_Cart_Redirect {

	/** La página huérfana. */
	const ORPHAN_ID = 28;

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ) );
	}

	/**
	 * ¿Hay que redirigir? PURA.
	 *
	 * Devuelve false ante cualquier configuración rara —ids a cero, las dos
	 * opciones apuntando al mismo sitio— porque una página huérfana molesta,
	 * pero un bucle de redirección deja el carrito inaccesible para todos.
	 */
	public static function should_redirect( int $current_id, int $cart_id, int $orphan_id ): bool {
		if ( $current_id < 1 || $cart_id < 1 || $orphan_id < 1 ) {
			return false;
		}

		if ( $cart_id === $orphan_id ) {
			return false;
		}

		return $current_id === $orphan_id;
	}

	/** Redirige, si toca. */
	public static function maybe_redirect(): void {
		if ( ! function_exists( 'wc_get_page_id' ) || ! is_page() ) {
			return;
		}

		$actual = (int) get_queried_object_id();
		$cart   = (int) wc_get_page_id( 'cart' );

		if ( ! self::should_redirect( $actual, $cart, self::ORPHAN_ID ) ) {
			return;
		}

		wp_safe_redirect( wc_get_cart_url(), 301 );
		exit;
	}
}
