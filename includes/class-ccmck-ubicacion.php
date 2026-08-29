<?php
defined( 'ABSPATH' ) || exit;

/**
 * Recuerda la ciudad del cliente para no volver a preguntársela.
 *
 * Tres fuentes posibles, por fiabilidad:
 *
 *  1. Cliente con cuenta → su dirección guardada. Lo hace WooCommerce solo; aquí
 *     no se toca. Medido el 28-ago: 634 clientes con dirección de envío, 470 con
 *     el DANE exacto. Los otros 164 la tienen en texto libre antiguo, así que no
 *     cotizan — y eso es correcto: sale el aviso de elegir ciudad en vez de un
 *     precio a un destino que no sabemos resolver.
 *
 *  2. Invitado que vuelve → esta clase. Se guarda lo que ELIGIÓ en la
 *     calculadora, con su DANE, y se repone en visitas posteriores.
 *
 *  3. Geolocalización por IP → NO implementada, y a propósito. Medido el 28-ago:
 *     `WC_Geolocation::geolocate_ip()` devuelve país y departamento VACÍOS para
 *     IPs colombianas reales, porque no hay licencia de MaxMind configurada y la
 *     base nunca se descargó. Enchufarla hoy sería código que corre sin hacer
 *     nada. Cuando haya licencia (WooCommerce › Ajustes › Integración › MaxMind),
 *     úsese solo para preseleccionar el DEPARTAMENTO, nunca la ciudad: el flete
 *     necesita un DANE exacto y una ciudad adivinada por IP es el incidente de
 *     TOLÚ vs TOLÚ VIEJO esperando a repetirse.
 *
 * Regla que gobierna todo esto: **nunca se rellena una ciudad que el cliente no
 * haya elegido él mismo alguna vez.** Recordar lo suyo es servicio; adivinarlo es
 * inventar un destino.
 */
final class CCMCK_Ubicacion {

	/** Nombre de la cookie. */
	const COOKIE = 'ccmck_ubicacion';

	/** 90 días: lo bastante para el cliente que vuelve, no tanto como para arrastrar una mudanza. */
	const DIAS = 90;

	/**
	 * Lo que se guarda en la cookie. PURO.
	 *
	 * @param string $state Departamento.
	 * @param string $city  Ciudad con su DANE, tal como la postea el desplegable.
	 */
	public static function valor_cookie( string $state, string $city ): string {
		return trim( $state ) . '|' . trim( $city );
	}

	/**
	 * Lee y VALIDA la cookie contra el catálogo. PURO.
	 *
	 * Una cookie la escribe el navegador y puede venir manipulada, así que su
	 * contenido no se cree: tiene que existir en el catálogo, igual que exige
	 * CCMCK_Cities al pagar. Si no cuadra, se devuelve vacío y el cliente elige.
	 *
	 * @param string $raw     Contenido crudo de la cookie.
	 * @param array  $catalog Departamento => (DANE => nombre).
	 * @return array{state:string,city:string}|array vacío si no es de fiar.
	 */
	public static function leer_cookie( string $raw, array $catalog ): array {
		$raw = trim( $raw );
		if ( '' === $raw || ! $catalog || false === strpos( $raw, '|' ) ) {
			return array();
		}

		list( $state, $city ) = array_map( 'trim', explode( '|', $raw, 2 ) );
		if ( '' === $state || '' === $city ) {
			return array();
		}

		// Mismo criterio que el checkout: el departamento tiene que existir y la
		// ciudad tiene que ser un DANE suyo. Se reutiliza la validación de
		// CCMCK_Cities para no tener dos reglas distintas.
		if ( '' !== CCMCK_Cities::validate_destination( $catalog, $state, $city ) ) {
			return array();
		}

		return array(
			'state' => $state,
			'city'  => $city,
		);
	}

	/** ¿Hay que reponer la ubicación? PURO. Solo si el cliente no tiene ya una. */
	public static function debe_reponer( string $ciudad_actual ): bool {
		return '' === trim( $ciudad_actual );
	}

	/** Guarda lo que el cliente eligió. Se engancha tras calcular el envío. */
	public static function recordar(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->customer || headers_sent() ) {
			return;
		}
		$state = (string) WC()->customer->get_shipping_state();
		$city  = (string) WC()->customer->get_shipping_city();
		if ( '' === $state || '' === $city ) {
			return;
		}
		setcookie(
			self::COOKIE,
			self::valor_cookie( $state, $city ),
			time() + ( self::DIAS * DAY_IN_SECONDS ),
			COOKIEPATH ? COOKIEPATH : '/',
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);
	}

	/** Repone la ubicación guardada si el cliente todavía no tiene ninguna. */
	public static function reponer(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return;
		}
		if ( ! self::debe_reponer( (string) WC()->customer->get_shipping_city() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$ubi = self::leer_cookie( $raw, CCMCK_Cities::catalog() );
		if ( ! $ubi ) {
			return;
		}
		WC()->customer->set_shipping_state( $ubi['state'] );
		WC()->customer->set_shipping_city( $ubi['city'] );
		WC()->customer->set_billing_state( $ubi['state'] );
		WC()->customer->set_billing_city( $ubi['city'] );
	}

	public static function init(): void {
		// Reponer antes de que se calculen los paquetes de envío.
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'reponer' ), 5 );
		// Guardar en cuanto el cliente elige destino en la calculadora.
		add_action( 'woocommerce_calculated_shipping', array( __CLASS__, 'recordar' ) );
	}
}
