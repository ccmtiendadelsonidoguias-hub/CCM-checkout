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
	 * Texto de "este departamento no tiene ciudades", en una sola fuente.
	 * PURO.
	 *
	 * Antes vivía duplicado como literal en dos sitios (aquí y en el
	 * `wp_localize_script` de CCMCK_Assets para el JS de repoblado): los dos
	 * decían lo mismo por coincidencia, no por diseño, y nada impedía que un
	 * cambio futuro en uno de los dos los desincronizara — el aviso inicial
	 * (render del servidor) diciendo una cosa y el repoblado por AJAX diciendo
	 * otra para el MISMO estado. Un único método fuente elimina esa deriva.
	 */
	public static function texto_departamento_sin_ciudades(): string {
		return __( 'No hay ciudades para ese departamento', 'ccm-checkout' );
	}

	/**
	 * Cómo queda el campo de ciudad. PURO.
	 *
	 * @param array  $args               Args originales de woocommerce_form_field.
	 * @param array  $options             Salida de city_options().
	 * @param string $state               Departamento en sesión.
	 * @param bool   $catalogo_disponible ¿CCMCK_Cities::catalog() no está vacío?
	 *                                    Fail-open: CCMCK_Cities decide a propósito
	 *                                    no bloquear la venta cuando el plugin de
	 *                                    ciudades está desactivado (catálogo
	 *                                    vacío). Si aquí deshabilitáramos el
	 *                                    campo en ese mismo caso, jQuery
	 *                                    descartaría el <select disabled> al
	 *                                    serializar el formulario de la
	 *                                    calculadora y el próximo envío borraría
	 *                                    la ciudad ya guardada en la sesión del
	 *                                    cliente. Por eso, sin catálogo, el
	 *                                    campo queda SIEMPRE habilitado
	 *                                    (aunque no tengamos ciudades que
	 *                                    ofrecerle): más vale un campo
	 *                                    utilizable que perder un dato válido.
	 *                                    Cuando el catálogo SÍ está disponible
	 *                                    pero el departamento puntual no trae
	 *                                    ciudades (dato incompleto, no plugin
	 *                                    caído), el campo sigue deshabilitado:
	 *                                    ahí no hay nada real que perder.
	 */
	public static function city_field_args( array $args, array $options, string $state, bool $catalogo_disponible = true ): array {
		$args['type'] = 'select';
		$args['input_class'] = array_merge( (array) ( $args['input_class'] ?? array() ), array( 'ccmck-cart-city' ) );

		if ( ! $catalogo_disponible ) {
			$args['options'] = array(
				'' => __( 'Catálogo de ciudades no disponible. Escríbenos si el envío no calcula bien.', 'ccm-checkout' ),
			);
			return $args;
		}

		if ( ! $options ) {
			$args['options'] = array(
				'' => '' === trim( $state )
					? __( 'Elige primero el departamento', 'ccm-checkout' )
					: self::texto_departamento_sin_ciudades(),
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
	 * ¿Falta esta medida? Castea a float como hace rates(). PURO.
	 *
	 * @param mixed $medida Peso o dimensión (string, float, etc).
	 * @return bool True si falta o es cero.
	 */
	public static function falta_medida( $medida ): bool {
		return (float) $medida <= 0;
	}

	/**
	 * Qué avisar en la línea de Envío. PURO: recibe por parámetro todo lo que
	 * necesita, no lee CCMCK_Settings ni WC() directamente.
	 *
	 * CCMCK_Coordinadora::rates() tiene 5 salidas donde NO cotiza y devuelve
	 * las tarifas intactas (en la práctica, "solo Recogida local" sin ninguna
	 * explicación): toggle apagado, credenciales vacías, producto sin
	 * peso/dimensiones, ciudad sin DANE, y cotización fallida (HTTP, JSON o
	 * error de la API). Antes solo se cubrían 2 (medidas y ciudad); las otras
	 * 3 se veían idénticas a "no hay envío a ese destino" y con el TTL
	 * negativo de la caché (5 min) ese silencio queda pegado para todos los
	 * que compartan el envío. Es la razón de ser de esta rama.
	 *
	 * @param bool  $tiene_dane              ¿La ciudad en sesión trae DANE?
	 * @param array $sin_medidas             Nombres de productos sin peso o dimensiones.
	 * @param bool  $toggle_activo           CCMCK_Settings::get('coordinadora_enabled').
	 * @param bool  $credenciales_completas  ¿apikey Y clave configuradas?
	 * @param bool  $cotizacion_fallida      ¿Se intentó cotizar (todo lo anterior en
	 *                                       orden) y la llamada a Coordinadora falló?
	 */
	public static function estado(
		bool $tiene_dane,
		array $sin_medidas,
		bool $toggle_activo = true,
		bool $credenciales_completas = true,
		bool $cotizacion_fallida = false
	): array {
		// Precedencia deliberada, NO calcada del orden de early-return de
		// rates(): toggle y credenciales son bloqueos de configuración (nada
		// que el cliente pueda resolver desde el carrito) y van primero.
		// Entre ciudad y medidas, "sin_ciudad" gana aunque también falten
		// medidas (precedente ya cubierto por pruebas): elegir la ciudad es
		// lo primero y más accionable para el cliente. "cotizacion_fallida"
		// va al final porque solo es discernible cuando todo lo anterior
		// pasó y aun así la llamada a Coordinadora falló.
		if ( ! $toggle_activo ) {
			return array(
				'clave' => 'toggle_apagado',
				'texto' => __( 'El cálculo de envío por Coordinadora está desactivado. Escríbenos y te cotizamos el envío.', 'ccm-checkout' ),
			);
		}
		if ( ! $credenciales_completas ) {
			return array(
				'clave' => 'sin_credenciales',
				'texto' => __( 'Falta terminar de configurar el envío por Coordinadora. Escríbenos y te cotizamos.', 'ccm-checkout' ),
			);
		}
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
		if ( $cotizacion_fallida ) {
			return array(
				'clave' => 'cotizacion_fallida',
				'texto' => __( 'No pudimos calcular el envío en este momento. Escríbenos y te cotizamos.', 'ccm-checkout' ),
			);
		}
		return array( 'clave' => 'ok', 'texto' => '' );
	}

	/**
	 * Líneas del carrito sin peso o dimensiones, de una lista ya normalizada.
	 * PURO. Una fila POR LÍNEA afectada, SIN deduplicar: quien deduplica (o
	 * no) es cada consumidor, según lo que necesite.
	 *
	 * @param array $lines Lista de {name,id,sku,needs_shipping,weight,length,width,height}.
	 * @return array<int,array{name:string,log:string}>
	 */
	public static function lines_sin_medidas( array $lines ): array {
		$falta = array();
		foreach ( $lines as $line ) {
			if ( empty( $line['needs_shipping'] ) ) {
				continue;
			}
			if ( self::falta_medida( $line['weight'] ?? null ) || self::falta_medida( $line['length'] ?? null ) || self::falta_medida( $line['width'] ?? null ) || self::falta_medida( $line['height'] ?? null ) ) {
				$sku     = (string) ( $line['sku'] ?? '' );
				$falta[] = array(
					'name' => (string) ( $line['name'] ?? '' ),
					// SKU si lo hay; si no, el ID con '#' para que no se
					// confunda con un SKU numérico de verdad.
					'log'  => '' !== $sku ? $sku : ( '#' . (int) ( $line['id'] ?? 0 ) ),
				);
			}
		}
		return $falta;
	}

	/** Líneas del carrito, normalizadas para lines_sin_medidas(). */
	private static function cart_lines(): array {
		$lines = array();
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $lines;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$p = $item['data'] ?? null;
			if ( ! $p ) {
				continue;
			}
			$lines[] = array(
				'name'           => method_exists( $p, 'get_name' ) ? (string) $p->get_name() : '',
				'id'             => method_exists( $p, 'get_id' ) ? (int) $p->get_id() : 0,
				'sku'            => method_exists( $p, 'get_sku' ) ? (string) $p->get_sku() : '',
				'needs_shipping' => method_exists( $p, 'needs_shipping' ) ? (bool) $p->needs_shipping() : false,
				'weight'         => method_exists( $p, 'get_weight' ) ? $p->get_weight() : 0,
				'length'         => method_exists( $p, 'get_length' ) ? $p->get_length() : 0,
				'width'          => method_exists( $p, 'get_width' ) ? $p->get_width() : 0,
				'height'         => method_exists( $p, 'get_height' ) ? $p->get_height() : 0,
			);
		}
		return $lines;
	}

	/**
	 * Nombres para el AVISO AL CLIENTE. Deduplicado: un mismo nombre no debe
	 * repetirse en el mensaje que lee la persona.
	 */
	public static function items_sin_medidas(): array {
		$nombres = array_map(
			static function ( $row ) { return $row['name']; },
			self::lines_sin_medidas( self::cart_lines() )
		);
		return array_values( array_unique( $nombres ) );
	}

	/**
	 * IDs/SKUs para el LOG interno. SIN deduplicar.
	 *
	 * array_unique() sobre nombres (lo que hace items_sin_medidas() para el
	 * mensaje al cliente) colapsa productos DISTINTOS que comparten título
	 * (variaciones, genéricos re-listados) en una sola entrada: el log
	 * subestima cuántos carritos/productos están afectados de verdad. El
	 * mensaje al cliente sí puede deduplicar (no tiene sentido repetir el
	 * mismo nombre dos veces en una frase); el log no.
	 */
	public static function items_sin_medidas_log(): array {
		return array_map(
			static function ( $row ) { return $row['log']; },
			self::lines_sin_medidas( self::cart_lines() )
		);
	}

	/**
	 * ¿La tarifa de Coordinadora quedó fuera pese a que todo lo demás (toggle,
	 * credenciales, ciudad, medidas) estaba en orden? Best-effort: mira los
	 * paquetes que WooCommerce YA calculó para pintar el carrito -- no vuelve
	 * a cotizar ni toca CCMCK_Coordinadora::rates()/quote() (zona protegida:
	 * de esa clase solo se toca la caché).
	 */
	private static function cotizacion_fallo(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return false;
		}
		foreach ( (array) WC()->shipping()->get_packages() as $package ) {
			if ( isset( $package['rates'][ CCMCK_Coordinadora::RATE_ID ] ) ) {
				return false;
			}
		}
		return true;
	}

	/** Pinta el aviso encima de la línea de Envío. */
	public static function print_notice(): void {
		$ciudad = '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$ciudad = (string) WC()->customer->get_shipping_city();
		}
		$apikey                 = (string) CCMCK_Settings::get( 'coordinadora_apikey', '' );
		$clave                  = (string) CCMCK_Settings::get( 'coordinadora_clave', '' );
		$credenciales_completas = '' !== $apikey && '' !== $clave;

		$estado = self::estado(
			'' !== CCMCK_Coordinadora::dane_from_city( $ciudad ),
			self::items_sin_medidas(),
			(bool) CCMCK_Settings::get( 'coordinadora_enabled', false ),
			$credenciales_completas,
			self::cotizacion_fallo()
		);
		if ( 'ok' === $estado['clave'] ) {
			return;
		}
		if ( 'sin_medidas' === $estado['clave'] && function_exists( 'wc_get_logger' ) ) {
			// IDs/SKUs, NUNCA el texto traducido de cara al cliente: ese texto
			// puede llevar nombres de producto tal como el cliente los ve. El
			// canal es el de WooCommerce, no la función legada de PHP para
			// escribir al log de errores del servidor.
			wc_get_logger()->warning(
				'Carrito con envío sin cotizar por falta de peso/dimensiones: ' . implode( ', ', self::items_sin_medidas_log() ),
				array( 'source' => 'ccmck-cart-shipping' )
			);
		}
		// Un <div>, NO un <tr>. Este gancho se dispara ANTES de que se abra la
		// <table> del envío, así que un <tr> aquí queda fuera de tabla y el
		// analizador del navegador se come las etiquetas y deja el texto suelto:
		// el aviso salía sin su filete rojo ni su fondo, y el CSS
		// `tr.ccmck-cart-aviso td` no llegaba a aplicarse nunca. Comprobado el
		// 29-ago en el navegador.
		printf(
			'<div class="ccmck-cart-aviso ccmck-cart-aviso--%1$s">%2$s</div>',
			esc_attr( $estado['clave'] ),
			esc_html( $estado['texto'] )
		);
	}

	/**
	 * La ciudad como la lee el cliente. PURO.
	 *
	 * El desplegable postea `NOMBRE (ABREV) (DANE)` porque el motor de flete
	 * necesita el DANE exacto para cotizar. Al cliente le sobran las dos cosas:
	 * el DANE es maquinaria nuestra, y la abreviatura del departamento es
	 * redundante porque la línea imprime el departamento entero justo después.
	 * Se leía «Enviar a BARRANQUILLA (ATL) (08001000), Atlantico»: tres formas
	 * de decir un sitio, una de ellas un código.
	 *
	 * NO se re-capitaliza a propósito. El catálogo es quien manda la ortografía,
	 * y ningún title-case automático acierta con «SANTIAGO DE TOLU» ni con
	 * «BOGOTA D.C.».
	 *
	 * Si al quitar los paréntesis no queda nada, se devuelve lo que había: una
	 * ciudad rara es mejor que un destino en blanco.
	 */
	public static function ciudad_legible( string $city ): string {
		$limpia = trim( (string) preg_replace( '/\s*\([^)]*\)/', '', $city ) );
		return '' === $limpia ? trim( $city ) : $limpia;
	}

	/**
	 * Quita el DANE de la línea «Enviar a …».
	 *
	 * Va sobre `woocommerce_formatted_address_replacements`, que WooCommerce
	 * aplica ANTES de escapar, así que aquí los valores llegan crudos.
	 *
	 * @param array $replace Sustituciones {campo} => valor.
	 */
	public static function destino_sin_dane( $replace ) {
		if ( is_array( $replace ) && isset( $replace['{city}'] ) ) {
			$replace['{city}'] = self::ciudad_legible( (string) $replace['{city}'] );
		}
		return $replace;
	}

	/**
	 * Pone «Gratis» a las opciones que no cuestan nada.
	 *
	 * WooCommerce oculta el coste de `local_pickup` y `free_shipping` cuando es
	 * cero (`wc_cart_totals_shipping_method_label`), y en una frase corrida eso
	 * tiene sentido. Aquí las opciones se leen como una comparación de dos
	 * precios en columna, y una celda vacía al lado de «$37.100» obliga al
	 * cliente a deducir que la otra no cuesta. Se dice.
	 *
	 * Solo se añade si la etiqueta no trae ya un importe, para no duplicarlo si
	 * el núcleo cambia de idea.
	 *
	 * @param string $label  Etiqueta completa, con HTML.
	 * @param object $method WC_Shipping_Rate.
	 */
	public static function etiqueta_gratis( $label, $method = null ) {
		if ( ! is_string( $label ) || ! is_object( $method ) || ! method_exists( $method, 'get_cost' ) ) {
			return $label;
		}
		if ( 0.0 < (float) $method->get_cost() || false !== strpos( $label, 'woocommerce-Price-amount' ) ) {
			return $label;
		}
		return $label . ' <span class="ccmck-envio__gratis">' . esc_html__( 'Gratis', 'ccm-checkout' ) . '</span>';
	}

	/**
	 * Quita los dos puntos que el núcleo pone antes del importe. PURO.
	 *
	 * `wc_cart_totals_shipping_method_label()` compone «Coordinadora: $37.100»
	 * porque ahí la etiqueta es una frase corrida. Aquí el nombre y el importe
	 * van a los dos extremos de la fila, y unos dos puntos colgando en mitad del
	 * hueco son puntuación de otra maqueta.
	 *
	 * Se toca SOLO el separador que precede al importe, no cualquier «:» — hay
	 * transportadoras con dos puntos en el nombre.
	 */
	public static function etiqueta_sin_dos_puntos( string $label ): string {
		return str_replace( ': <span class="woocommerce-Price-amount', ' <span class="woocommerce-Price-amount', $label );
	}

	/**
	 * Lo que se cuelga del filtro: compone los dos retoques de la etiqueta.
	 *
	 * @param string $label  Etiqueta completa, con HTML.
	 * @param object $method WC_Shipping_Rate.
	 */
	public static function etiqueta_opcion( $label, $method = null ) {
		if ( ! is_string( $label ) ) {
			return $label;
		}
		return self::etiqueta_gratis( self::etiqueta_sin_dos_puntos( $label ), $method );
	}

	/**
	 * Los dos retoques de arriba se encienden y se apagan alrededor del bloque
	 * de envío del carrito, y solo ahí.
	 *
	 * `woocommerce_formatted_address_replacements` lo usa TODA la tienda —
	 * correos de pedido, panel de administración, rótulos —, y ahí el DANE sí
	 * hace falta. Dejarlo puesto para siempre sería arreglar una línea y romper
	 * lo que va a la transportadora.
	 */
	public static function retoques_on(): void {
		add_filter( 'woocommerce_formatted_address_replacements', array( __CLASS__, 'destino_sin_dane' ) );
		add_filter( 'woocommerce_cart_shipping_method_full_label', array( __CLASS__, 'etiqueta_opcion' ), 10, 2 );
	}

	public static function retoques_off(): void {
		remove_filter( 'woocommerce_formatted_address_replacements', array( __CLASS__, 'destino_sin_dane' ) );
		remove_filter( 'woocommerce_cart_shipping_method_full_label', array( __CLASS__, 'etiqueta_opcion' ), 10 );
	}

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'woocommerce_cart_totals_before_shipping', array( __CLASS__, 'print_notice' ) );
		// Prioridad alta para encender ANTES que nada más que cuelgue del gancho,
		// y baja para apagar DESPUÉS. El bloque de envío queda dentro.
		add_action( 'woocommerce_cart_totals_before_shipping', array( __CLASS__, 'retoques_on' ), 5 );
		add_action( 'woocommerce_cart_totals_after_shipping', array( __CLASS__, 'retoques_off' ), 99 );
	}
}
