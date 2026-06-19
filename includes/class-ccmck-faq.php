<?php
/**
 * CCM Checkout — sección FAQ (acordeón) del sidebar.
 *
 * Renderiza las preguntas frecuentes configuradas en Ajustes
 * (CCMCK_Settings 'faq_items') dentro del sidebar del checkout, usando el
 * hook woocommerce_checkout_after_order_review (que en form-checkout.php se
 * dispara dentro de .sidebar-inner, justo tras el resumen del pedido).
 *
 * `markup()` es puro y reutilizable: lo usan tanto el sidebar (desktop) como el
 * bloque inferior móvil (.mobile-bottom-info, fondo claro) de form-checkout.php.
 *
 * El CSS (.faq-section/.faq-item/…) y el JS del acordeón (.faq-header →
 * toggle .open) ya viven en assets/ccmck-checkout.css y ccmck-checkout.js.
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

final class CCMCK_Faq {

	public static function init(): void {
		add_action( 'woocommerce_checkout_after_order_review', array( __CLASS__, 'render' ), 20 );
	}

	public static function render(): void {
		// markup_from_settings() devuelve HTML ya escapado.
		echo self::markup_from_settings(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * HTML de la sección FAQ según Ajustes, o '' si está desactivada o sin
	 * preguntas. Reutilizable en sidebar (desktop) y en el bloque móvil.
	 */
	public static function markup_from_settings(): string {
		$s = CCMCK_Settings::all();

		if ( empty( $s['faq_enabled'] ) || empty( $s['faq_items'] ) ) {
			return '';
		}

		return self::markup( (array) $s['faq_items'] );
	}

	/**
	 * Construye el HTML `.faq-section` a partir de las preguntas dadas. Cada
	 * item: { q, a, icon_image }. Devuelve '' si ninguna pregunta tiene texto.
	 *
	 * @param array $items Filas de FAQ.
	 */
	public static function markup( array $items ): string {
		ob_start();
		?>
		<div class="faq-section">
			<div class="faq-section-title"><?php esc_html_e( 'Preguntas Frecuentes', 'ccm-checkout' ); ?></div>

			<?php
			$rendered = 0;
			foreach ( $items as $item ) :
				$q          = isset( $item['q'] ) ? (string) $item['q'] : '';
				$a          = isset( $item['a'] ) ? (string) $item['a'] : '';
				$icon_image = isset( $item['icon_image'] ) ? (string) $item['icon_image'] : '';

				if ( '' === $q ) {
					continue;
				}
				++$rendered;
				?>
				<div class="faq-item">
					<div class="faq-header">
						<div class="faq-icon" aria-hidden="true"><?php
						if ( '' !== $icon_image ) {
							echo '<img src="' . esc_url( $icon_image ) . '" alt="">';
						} else {
							echo '?';
						}
						?></div>
						<span class="faq-title"><?php echo esc_html( $q ); ?></span>
						<svg class="faq-arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
					</div>
					<div class="faq-body">
						<div class="faq-body-inner"><?php echo wp_kses_post( nl2br( $a ) ); ?></div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		$html = (string) ob_get_clean();

		return $rendered > 0 ? $html : '';
	}
}
