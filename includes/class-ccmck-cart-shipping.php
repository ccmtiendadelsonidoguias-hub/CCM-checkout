<?php
defined( 'ABSPATH' ) || exit;

/**
 * El campo de ciudad de la calculadora del carrito, con su código DANE.
 *
 * Motivo: CCMCK_Coordinadora::rates() ya corre en el carrito (su filtro
 * woocommerce_package_rates no está limitado al checkout), pero saca el destino
 * con dane_from_city(), que busca 8 dígitos al final de la ciudad. La
 * calculadora del carrito postea la ciudad a mano ("Bogotá"), así que no hay
 * DANE, no hay cotización, y al cliente solo le queda "Recogida local".
 *
 * Es el mismo fallo del "caso William" documentado en CCMCK_Cities, una capa
 * más arriba: allí la ciudad escrita a mano dejaba el pedido sin tarifa.
 */
final class CCMCK_Cart_Shipping {

	/**
	 * Valor de una opción de ciudad. PURO.
	 *
	 * Formato EXACTO del checkout: "NOMBRE (ABREV) (DANE)". No cambiar sin
	 * cambiar dane_from_city(), o el carrito deja de cotizar sin avisar.
	 */
	public static function format_value( string $dane, string $name ): string {
		return trim( $name ) . ' (' . trim( $dane ) . ')';
	}

	/**
	 * Ciudades de un departamento, listas para un <select>. PURO.
	 *
	 * @param array  $catalog Departamento => (DANE => 'NOMBRE (ABREV)').
	 * @param string $state   Departamento elegido.
	 * @return array<string,string> valor => etiqueta, ordenado por etiqueta.
	 */
	public static function city_options( array $catalog, string $state ): array {
		$state = trim( $state );
		if ( '' === $state || ! $catalog ) {
			return array();
		}

		$dept = $catalog[ $state ] ?? null;
		if ( ! is_array( $dept ) ) {
			// Misma tolerancia que CCMCK_Cities::validate_destination().
			foreach ( $catalog as $key => $cities ) {
				if ( 0 === strcasecmp( (string) $key, $state ) ) {
					$dept = $cities;
					break;
				}
			}
		}
		if ( ! is_array( $dept ) ) {
			return array();
		}

		$out = array();
		foreach ( $dept as $dane => $name ) {
			$out[ self::format_value( (string) $dane, (string) $name ) ] = (string) $name;
		}
		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		return $out;
	}

	/**
	 * Cómo queda el campo de ciudad. PURO.
	 *
	 * @param array  $args    Args originales de woocommerce_form_field.
	 * @param array  $options Salida de city_options().
	 * @param string $state   Departamento en sesión.
	 */
	public static function city_field_args( array $args, array $options, string $state ): array {
		$args['type'] = 'select';
		$args['input_class'] = array_merge( (array) ( $args['input_class'] ?? array() ), array( 'ccmck-cart-city' ) );

		if ( ! $options ) {
			$args['options'] = array(
				'' => '' === trim( $state )
					? __( 'Elige primero el departamento', 'ccm-checkout' )
					: __( 'No hay ciudades para ese departamento', 'ccm-checkout' ),
			);
			$args['custom_attributes'] = array_merge(
				(array) ( $args['custom_attributes'] ?? array() ),
				array( 'disabled' => 'disabled' )
			);
			return $args;
		}

		$args['options'] = array( '' => __( 'Elige tu ciudad', 'ccm-checkout' ) ) + $options;
		return $args;
	}

	/** Filtro woocommerce_form_field_args. Solo junta datos y delega. */
	public static function filter_field( $args, $key, $value ) {
		if ( 'calc_shipping_city' !== $key || ! is_array( $args ) ) {
			return $args;
		}
		$state = '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$state = (string) WC()->customer->get_shipping_state();
		}
		return self::city_field_args( $args, self::city_options( CCMCK_Cities::catalog(), $state ), $state );
	}

	public static function init(): void {
		add_filter( 'woocommerce_form_field_args', array( __CLASS__, 'filter_field' ), 20, 3 );
	}
}
