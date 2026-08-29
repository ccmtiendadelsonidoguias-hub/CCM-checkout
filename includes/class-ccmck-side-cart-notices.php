<?php
defined( 'ABSPATH' ) || exit;

/**
 * Devuelve al carrito y al pago los avisos que el cajón lateral se lleva.
 *
 * MEDIDO EL 29-AGO con una sonda en el render de /mi-carrito/: el aviso existía
 * en `wp_body_open` prio 1 y valía cero en prio 999. Quitando UN callback —
 * `Xoo_Wsc_Frontend::cart_markup` — volvía a salir. La línea culpable está en
 * `Xoo_Wsc_Cart::print_notices_html()`:
 *
 *     $wc_notices = wc_get_notices( 'error' );   // lee SOLO los de error
 *     foreach ( ... ) { $this->set_notice( ... ); }
 *     wc_clear_notices();                        // pero BORRA LOS TRES TIPOS
 *
 * Se lleva los de error a su cajón y destruye los de éxito y los informativos.
 * Lo que eso costaba:
 *
 *  - «Producto eliminado. ¿Deshacer?» y «se ha añadido a tu carrito» no salían
 *    NUNCA. En móvil el «−» con cantidad 1 borra la línea de un toque, y el
 *    cliente se quedaba sin aviso y sin manera de deshacerlo. Esto es lo que
 *    arregla esta clase.
 *  - Los de ERROR, en cambio, sí llegaban: WooCommerce los regenera al pintar
 *    el carrito (`WC_Shortcode_Cart::output()` dispara
 *    `woocommerce_check_cart_items`), así que el «no hay suficientes
 *    existencias» del que depende `ccmck-cart.js` estaba a salvo. Medido el
 *    29-ago con el arreglo apagado a propósito: seguía saliendo. Se escribe
 *    aquí porque el primer diagnóstico dijo lo contrario y conviene que no se
 *    repita.
 *
 * Lo que NO arregla: en la página de PAGO los avisos siguen sin salir, pero por
 * otra causa — ahí la cola llega vacía desde `wp_loaded`, así que no es el
 * cajón. Sin diagnosticar. Esta clase cubre el pago igualmente por si algún día
 * hay algo que salvar; hoy es inerte ahí.
 *
 * POR QUÉ SE GUARDA Y SE DEVUELVE, Y NO SE SUSTITUYE LA PLANTILLA:
 * el primer intento sobreescribió `global/markup-notice.php` por una copia que
 * no borraba. No sirvió: `print_notices_html()` se llama desde CUATRO sitios
 * —`markup-notice.php`, `xoo-wsc-header.php`, `xoo-wsc-slider.php` y
 * `xoo-wsc-drawer.php`— y tres usan el borrado por defecto. Tapar uno deja tres
 * abiertos, y la próxima versión del plugin puede añadir un quinto. Así que se
 * envuelve el bloque entero: se guarda la cola justo antes y se devuelve justo
 * después. Un solo sitio, sin depender de cuántas llamadas haya dentro.
 *
 * Los errores de stock NO hace falta devolverlos: WooCommerce los regenera en
 * `WC_Shortcode_Cart::output()`, que dispara `woocommerce_check_cart_items`
 * después de esto. Por eso `unir()` deduplica — si no, el cliente los vería dos
 * veces.
 *
 * Y solo en carrito y pago: en la ficha de producto o en el inicio el cajón SÍ
 * es la interfaz —se abre solo al añadir— y ahí quedarse los avisos es lo
 * correcto. En carrito y pago la interfaz es la página.
 */
final class CCMCK_Side_Cart_Notices {

	/** Donde WooCommerce guarda la cola de avisos. */
	const CLAVE_SESION = 'wc_notices';

	/** Lo guardado entre el prio 9 y el prio 11 de `wp_body_open`. */
	private static $guardadas = null;

	/**
	 * ¿Toca intervenir? PURO. Solo donde la página es la interfaz.
	 */
	public static function debe_actuar( bool $es_carrito, bool $es_pago ): bool {
		return $es_carrito || $es_pago;
	}

	/**
	 * Une lo guardado con lo que haya ahora, sin duplicar. PURO.
	 *
	 * Forma de la cola de WooCommerce: tipo => lista de `array( 'notice' =>
	 * html, 'data' => array() )`. Cualquier otra forma se descarta: la cola la
	 * pueden haber tocado otros plugins y preferimos perder un aviso antes que
	 * romper la página con una estructura que WooCommerce no sabe leer.
	 *
	 * @param array $guardadas Lo que había antes de que el cajón la vaciara.
	 * @param array $actuales  Lo que hay ahora.
	 */
	public static function unir( array $guardadas, array $actuales ): array {
		$union = array();

		foreach ( array( $guardadas, $actuales ) as $lote ) {
			foreach ( $lote as $tipo => $lista ) {
				if ( ! is_array( $lista ) ) {
					continue;
				}
				foreach ( $lista as $aviso ) {
					if ( ! is_array( $aviso ) || ! isset( $aviso['notice'] ) ) {
						continue;
					}
					$texto = (string) $aviso['notice'];
					if ( ! isset( $union[ $tipo ] ) ) {
						$union[ $tipo ] = array();
					}
					foreach ( $union[ $tipo ] as $ya ) {
						if ( (string) $ya['notice'] === $texto ) {
							continue 2;
						}
					}
					$union[ $tipo ][] = $aviso;
				}
			}
		}

		// Un tipo que quedó sin avisos no se deja como lista vacía: la cola de
		// WooCommerce no las tiene y `wc_notice_count()` las contaría como 0
		// pero `wc_has_notice()` haría trabajo de más.
		return array_filter( $union, static function ( $lista ) { return ! empty( $lista ); } );
	}

	/** Guarda la cola justo ANTES de que el cajón pinte su marcado. */
	public static function guardar(): void {
		self::$guardadas = null;
		if ( ! self::aqui_manda_la_pagina() ) {
			return;
		}
		self::$guardadas = (array) wc_get_notices();
	}

	/** La devuelve justo DESPUÉS. */
	public static function devolver(): void {
		if ( null === self::$guardadas ) {
			return;
		}
		$union = self::unir( self::$guardadas, (array) wc_get_notices() );
		self::$guardadas = null;

		// Sin nada que devolver no se toca la sesión.
		if ( ! $union ) {
			return;
		}
		WC()->session->set( self::CLAVE_SESION, $union );
	}

	private static function aqui_manda_la_pagina(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! function_exists( 'wc_get_notices' ) ) {
			return false;
		}
		if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
			return false;
		}
		return self::debe_actuar( is_cart(), is_checkout() );
	}

	public static function init(): void {
		// El cajón pinta en `wp_body_open` prio 10. Se abraza ese hueco.
		add_action( 'wp_body_open', array( __CLASS__, 'guardar' ), 9 );
		add_action( 'wp_body_open', array( __CLASS__, 'devolver' ), 11 );
	}
}
