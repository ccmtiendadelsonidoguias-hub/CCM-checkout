<?php
/**
 * CCM Checkout — recargo por financiación.
 *
 * Cuando el cliente paga con Addi (`addi`) o Sistecrédito (`wcsistecredito`),
 * el precio de CADA producto del carrito se incrementa un 10,48%. NO se usa un
 * "fee" con línea propia: el aumento va sobre el precio del producto, así el
 * subtotal y el total salen ya incrementados, sin una línea de recargo visible
 * y sin descuadre Subtotal≠Total. El total mayor lo cobran las pasarelas.
 *
 * El método de pago elegido se lee del request del checkout (en el AJAX
 * `update_order_review` WooCommerce postea `payment_method`); por eso el JS
 * dispara `update_checkout` al cambiar de método, para que el precio se
 * recalcule y el total del sidebar se actualice.
 *
 * Implementación IDEMPOTENTE: en cada recálculo el precio se fija como
 * precio_original × multiplicador (fetch fresco del precio de catálogo), de modo
 * que el aumento NO se compone aunque el hook corra varias veces, y se revierte
 * solo si el cliente cambia a un método sin recargo.
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

final class CCMCK_Surcharge {

	/** Tasa del recargo (10,48%). Filtrable con `ccmck_surcharge_rate`. */
	const RATE = 0.1048;

	/** IDs de gateway con recargo. Filtrable con `ccmck_surcharge_methods`. */
	const METHODS = array( 'addi', 'wcsistecredito' );

	/** Taxonomías candidatas de "marca" (la 1ª que exista). Filtrable. */
	const BRAND_TAXONOMIES = array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'product_brands', 'berocket_brand' );

	/** Cache de precios originales por product/variation id (por request). */
	private static $orig_prices = array();

	public static function init(): void {
		// Prioridad alta para correr tras otros ajustes de precio. Idempotente.
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_surcharge' ), 1000 );
	}

	/** Multiplicador a aplicar al precio según el método. PURO. */
	public static function multiplier( string $method ): float {
		return self::method_has_surcharge( $method ) ? ( 1.0 + self::rate() ) : 1.0;
	}

	/** Taxonomía de marcas usada por la tienda (la 1ª candidata que exista). */
	public static function brand_taxonomy(): string {
		$candidates = (array) apply_filters( 'ccmck_surcharge_brand_taxonomy_candidates', self::BRAND_TAXONOMIES );
		$found = '';
		foreach ( $candidates as $tax ) {
			if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( $tax ) ) {
				$found = $tax;
				break;
			}
		}
		return (string) apply_filters( 'ccmck_surcharge_brand_taxonomy', $found );
	}

	/** Marcas (IDs de término) a las que aplica el recargo. Vacío = todas. */
	public static function brands(): array {
		return array_map( 'intval', (array) CCMCK_Settings::get( 'surcharge_brands', array() ) );
	}

	/**
	 * ¿Un producto califica para el recargo, dadas las marcas seleccionadas?
	 * Sin marcas seleccionadas → aplica a TODOS. Con marcas → solo si el producto
	 * pertenece a alguna. PURO (recibe ya resuelto si el producto tiene la marca).
	 *
	 * @param array $selected_brands          IDs de marca seleccionados.
	 * @param bool  $product_in_selected_brand ¿El producto pertenece a alguna?
	 */
	public static function product_qualifies( array $selected_brands, bool $product_in_selected_brand ): bool {
		return empty( $selected_brands ) ? true : $product_in_selected_brand;
	}

	/**
	 * Ajusta el precio de cada ítem del carrito según el método de pago elegido.
	 * Idempotente: usa el precio de catálogo fresco como base, nunca el ya
	 * modificado, así no se compone. Si el método no lleva recargo, el
	 * multiplicador es 1 (precio original).
	 *
	 * @param WC_Cart $cart Carrito en cálculo.
	 */
	public static function apply_surcharge( $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}
		$mult = self::multiplier( self::chosen_method() );
		if ( 1.0 === $mult ) {
			return; // sin recargo: no se toca el precio.
		}
		$brands = self::brands();
		$tax    = self::brand_taxonomy();
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['data'] ) || ! is_object( $item['data'] ) ) {
				continue;
			}
			// Filtro por marca: el término vive en el producto PADRE (product_id).
			$parent_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$in_brand  = ! empty( $brands ) && '' !== $tax && function_exists( 'has_term' )
				&& $parent_id && has_term( $brands, $tax, $parent_id );
			if ( ! self::product_qualifies( $brands, (bool) $in_brand ) ) {
				continue; // este producto no lleva recargo.
			}
			$product = $item['data'];
			$id      = $product->get_id();
			if ( ! isset( self::$orig_prices[ $id ] ) ) {
				// Precio de catálogo fresco (no el del objeto del carrito, que
				// podría venir ya modificado por una corrida previa del hook).
				$fresh = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
				self::$orig_prices[ $id ] = $fresh ? (float) $fresh->get_price( 'edit' ) : (float) $product->get_price( 'edit' );
			}
			$product->set_price( self::$orig_prices[ $id ] * $mult );
		}
	}

	/** Tasa efectiva: del ajuste (porcentaje) ÷ 100, filtrable. */
	private static function rate(): float {
		$pct  = (float) CCMCK_Settings::get( 'surcharge_rate', self::RATE * 100 );
		$rate = $pct / 100;
		return (float) apply_filters( 'ccmck_surcharge_rate', $rate );
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

	/** Importe del recargo (10,48%) para un subtotal dado. PURO (helper de cálculo). */
	public static function surcharge_amount( float $subtotal ): float {
		return round( $subtotal * self::rate(), 2 );
	}
}
