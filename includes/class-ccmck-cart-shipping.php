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

	/** Cuerpo de la respuesta REST. PURO. */
	public static function rest_payload( array $catalog, string $state ): array {
		return array( 'opciones' => self::city_options( $catalog, $state ) );
	}

	public static function register_rest_routes(): void {
		register_rest_route( 'ccmck/v1', '/ciudades', array(
			'methods'  => 'GET',
			'callback' => static function ( $request ) {
				return rest_ensure_response(
					self::rest_payload( CCMCK_Cities::catalog(), (string) $request->get_param( 'departamento' ) )
				);
			},
			// Catálogo público de municipios: no hay dato de cliente que proteger.
			'permission_callback' => '__return_true',
			'args' => array(
				'departamento' => array( 'required' => true, 'type' => 'string' ),
			),
		) );
	}

	/**
	 * Qué avisar en la línea de Envío. PURO.
	 *
	 * @param bool  $tiene_dane   ¿La ciudad en sesión trae DANE?
	 * @param array $sin_medidas  Nombres de productos sin peso o dimensiones.
	 */
	public static function estado( bool $tiene_dane, array $sin_medidas ): array {
		if ( ! $tiene_dane ) {
			return array(
				'clave' => 'sin_ciudad',
				'texto' => __( 'Elige tu ciudad para ver el envío.', 'ccm-checkout' ),
			);
		}
		if ( $sin_medidas ) {
			return array(
				'clave' => 'sin_medidas',
				'texto' => sprintf(
					/* translators: %s: lista de productos */
					__( 'No podemos calcular aquí el envío de %s. Escríbenos y te lo cotizamos.', 'ccm-checkout' ),
					implode( ', ', $sin_medidas )
				),
			);
		}
		return array( 'clave' => 'ok', 'texto' => '' );
	}

	/** Productos del carrito sin peso o dimensiones. */
	public static function items_sin_medidas(): array {
		$falta = array();
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $falta;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$p = $item['data'] ?? null;
			if ( ! $p || ! $p->needs_shipping() ) {
				continue;
			}
			if ( ! $p->get_weight() || ! $p->get_length() || ! $p->get_width() || ! $p->get_height() ) {
				$falta[] = $p->get_name();
			}
		}
		return array_values( array_unique( $falta ) );
	}

	/** Pinta el aviso encima de la línea de Envío. */
	public static function print_notice(): void {
		$ciudad = '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$ciudad = (string) WC()->customer->get_shipping_city();
		}
		$estado = self::estado( '' !== CCMCK_Coordinadora::dane_from_city( $ciudad ), self::items_sin_medidas() );
		if ( 'ok' === $estado['clave'] ) {
			return;
		}
		if ( 'sin_medidas' === $estado['clave'] ) {
			error_log( '[ccmck] ' . $estado['texto'] );
		}
		printf(
			'<tr class="ccmck-cart-aviso ccmck-cart-aviso--%1$s"><td colspan="2">%2$s</td></tr>',
			esc_attr( $estado['clave'] ),
			esc_html( $estado['texto'] )
		);
	}

	public static function init(): void {
		add_filter( 'woocommerce_form_field_args', array( __CLASS__, 'filter_field' ), 20, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'woocommerce_cart_totals_before_shipping', array( __CLASS__, 'print_notice' ) );
	}
}
