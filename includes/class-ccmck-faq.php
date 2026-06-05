<?php
/**
 * CCM Checkout — sección FAQ (acordeón) del sidebar.
 *
 * Renderiza las preguntas frecuentes configuradas en Ajustes
 * (CCMCK_Settings 'faq_items') dentro del sidebar del checkout, usando el
 * hook woocommerce_checkout_after_order_review (que en form-checkout.php se
 * dispara dentro de .sidebar-inner, justo tras el resumen del pedido).
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
		$s = CCMCK_Settings::all();

		if ( empty( $s['faq_enabled'] ) || empty( $s['faq_items'] ) ) {
			return;
		}
		?>
		<div class="faq-section">
			<div class="faq-section-title"><?php esc_html_e( 'Preguntas Frecuentes', 'ccm-checkout' ); ?></div>

			<?php
			foreach ( (array) $s['faq_items'] as $item ) :
				$q = isset( $item['q'] ) ? (string) $item['q'] : '';
				$a = isset( $item['a'] ) ? (string) $item['a'] : '';

				if ( '' === $q ) {
					continue;
				}
				?>
				<div class="faq-item">
					<div class="faq-header">
						<div class="faq-icon" aria-hidden="true">?</div>
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
	}
}
