<?php
/**
 * CCM Checkout — recargo por financiación.
 *
 * Cuando el cliente paga con Addi (`addi`) o Sistecrédito (`wcsistecredito`),
 * se añade un recargo del 10,48% calculado SOLO sobre el subtotal de productos
 * (no sobre el envío). Se implementa como "fee" nativo de WooCommerce, así que
 * entra en el total del pedido y lo cobran las pasarelas.
 *
 * El método de pago elegido se lee del request del checkout (en el AJAX
 * `update_order_review` WooCommerce postea `payment_method`); por eso el JS
 * dispara `update_checkout` al cambiar de método, para que el recargo se
 * recalcule y el total del sidebar se actualice.
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

final class CCMCK_Surcharge {

	/** Tasa del recargo (10,48%). Filtrable con `ccmck_surcharge_rate`. */
	const RATE = 0.1048;

	/** IDs de gateway con recargo. Filtrable con `ccmck_surcharge_methods`. */
	const METHODS = array( 'addi', 'wcsistecredito' );

	public static function init(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_surcharge' ) );
	}

	/** Tasa efectiva (con filtro). */
	private static function rate(): float {
		return (float) apply_filters( 'ccmck_surcharge_rate', self::RATE );
	}

	/** Métodos con recargo (con filtro). */
	private static function methods(): array {
		return (array) apply_filters( 'ccmck_surcharge_methods', self::METHODS );
	}

	/**
	 * Método de pago elegido en el checkout en este request. Lo lee de $_POST
	 * (el AJAX update_order_review y el submit lo postean) o de la sesión.
	 * La nonce del checkout la valida WooCommerce en su propio flujo.
	 */
	public static function chosen_method(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['payment_method'] ) ) {
			return sanitize_text_field( wp_unslash( $_POST['payment_method'] ) );
		}
		if ( isset( $_POST['post_data'] ) ) {
			parse_str( wp_unslash( $_POST['post_data'] ), $pd ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! empty( $pd['payment_method'] ) ) {
				return sanitize_text_field( (string) $pd['payment_method'] );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( function_exists( 'WC' ) && WC()->session ) {
			return (string) WC()->session->get( 'chosen_payment_method' );
		}
		return '';
	}

	/** ¿El método dado lleva recargo? PURO. */
	public static function method_has_surcharge( string $method ): bool {
		return in_array( $method, self::methods(), true );
	}

	/** Importe del recargo para un subtotal dado. PURO. */
	public static function surcharge_amount( float $subtotal ): float {
		return round( $subtotal * self::rate(), 2 );
	}

	/**
	 * Añade el recargo al carrito si el método elegido lo requiere.
	 *
	 * @param WC_Cart $cart Carrito en cálculo.
	 */
	public static function add_surcharge( $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}
		if ( ! self::method_has_surcharge( self::chosen_method() ) ) {
			return;
		}
		// Base = subtotal de PRODUCTOS (sin envío). get_subtotal() excluye envío.
		$base = (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax();
		$fee  = self::surcharge_amount( $base );
		if ( $fee <= 0 ) {
			return;
		}
		/* translators: %s: porcentaje del recargo */
		$label = sprintf( __( 'Recargo por financiación (%s)', 'ccm-checkout' ), self::rate_label() );
		$label = (string) apply_filters( 'ccmck_surcharge_label', $label );
		$cart->add_fee( $label, $fee, false ); // sin impuesto.
	}

	/** Texto del porcentaje, p. ej. "10,48%". */
	private static function rate_label(): string {
		$pct = self::rate() * 100;
		// Coma decimal (es-CO) y sin ceros de más.
		$str = rtrim( rtrim( number_format( $pct, 2, ',', '.' ), '0' ), ',' );
		return $str . '%';
	}
}
