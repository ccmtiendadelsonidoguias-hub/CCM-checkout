<?php
/**
 * CCM Checkout — tarjetas informativas de envío (delivery cards) del sidebar.
 *
 * Renderiza las "Tarjetas informativas de Envío" configuradas en Ajustes
 * (CCMCK_Settings 'shipping_cards': icon/title/text) dentro del sidebar del
 * checkout, usando el hook woocommerce_checkout_after_order_review en prioridad
 * 10 — ANTES que la sección FAQ (CCMCK_Faq, prioridad 20) — para reproducir el
 * orden del mockup (resumen → tarjetas de envío → FAQ).
 *
 * El CSS (.delivery-info/.delivery-card/.dc-icon/.dc-text) ya vive en
 * assets/ccmck-checkout.css. El icono es texto libre (emoji, como el mockup).
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

final class CCMCK_Info_Cards {

	public static function init(): void {
		add_action( 'woocommerce_checkout_after_order_review', array( __CLASS__, 'render' ), 10 );
	}

	public static function render(): void {
		// markup() ya escapa cada valor con esc_html().
		echo self::markup( (array) CCMCK_Settings::get( 'shipping_cards', array() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Construye el bloque .delivery-info. Devuelve '' si no hay tarjetas con título.
	 *
	 * @param array $cards Filas { icon, title, text }.
	 */
	public static function markup( array $cards ): string {
		$cards = array_values( array_filter( $cards, static function ( $c ) {
			return is_array( $c ) && '' !== trim( (string) ( $c['title'] ?? '' ) );
		} ) );

		if ( empty( $cards ) ) {
			return '';
		}

		$html = '<div class="delivery-info">';

		foreach ( $cards as $c ) {
			$icon  = (string) ( $c['icon'] ?? '' );
			$image = (string) ( $c['image'] ?? '' );
			$title = (string) ( $c['title'] ?? '' );
			$text  = (string) ( $c['text'] ?? '' );

			$html .= '<div class="delivery-card">';
			if ( '' !== $image ) {
				$html .= '<div class="dc-icon" aria-hidden="true"><img src="' . esc_url( $image ) . '" alt=""></div>';
			} elseif ( '' !== $icon ) {
				$html .= '<div class="dc-icon" aria-hidden="true">' . esc_html( $icon ) . '</div>';
			}
			$html .= '<div class="dc-text"><strong>' . esc_html( $title ) . '</strong>';
			if ( '' !== $text ) {
				$html .= '<span>' . esc_html( $text ) . '</span>';
			}
			$html .= '</div></div>';
		}

		$html .= '</div>';

		return $html;
	}
}
