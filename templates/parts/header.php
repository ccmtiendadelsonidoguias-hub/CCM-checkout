<?php
/**
 * CCM Checkout — cabecera reutilizable.
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

$s = isset( $s ) ? $s : CCMCK_Settings::all();
?>
<header class="checkout-header">
	<?php if ( ! empty( $s['logo_image'] ) ) : ?>
		<?php
		// Logo = imagen "above the fold" → se prioriza para LCP y NUNCA lazy.
		// Si es de la biblioteca de medios, wp_get_attachment_image trae width/height/
		// srcset nativos (mejor CLS); si es URL externa, atributos manuales.
		$ccmck_logo_alt = esc_attr( trim( $s['logo_text_1'] . ' ' . $s['logo_text_2'] ) );
		$ccmck_logo_id  = function_exists( 'attachment_url_to_postid' ) ? attachment_url_to_postid( $s['logo_image'] ) : 0;
		?>
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="logo">
			<?php
			if ( $ccmck_logo_id ) {
				echo wp_get_attachment_image( $ccmck_logo_id, 'full', false, array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					'alt'           => $ccmck_logo_alt,
					'class'         => 'ccmck-logo-img',
					'loading'       => 'eager',
					'fetchpriority' => 'high',
					'decoding'      => 'async',
				) );
			} else {
				printf(
					'<img src="%s" alt="%s" class="ccmck-logo-img" fetchpriority="high" loading="eager" decoding="async" />',
					esc_url( $s['logo_image'] ),
					$ccmck_logo_alt
				);
			}
			?>
		</a>
	<?php else : ?>
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="logo">
			<?php echo esc_html( $s['logo_text_1'] ); ?> <span><?php echo esc_html( $s['logo_text_2'] ); ?></span>
		</a>
	<?php endif; ?>

	<?php if ( ! empty( $s['header_links'] ) ) : ?>
		<nav class="header-links">
			<?php foreach ( (array) $s['header_links'] as $link ) : ?>
				<?php if ( ! empty( $link['url'] ) && ! empty( $link['label'] ) ) : ?>
					<a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<?php // Acceso al carrito del cliente, en la esquina opuesta al logo. ?>
	<a href="<?php echo esc_url( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ) ); ?>" class="header-cart" aria-label="<?php esc_attr_e( 'Ir al carrito', 'ccm-checkout' ); ?>">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
			<circle cx="9" cy="21" r="1"></circle>
			<circle cx="20" cy="21" r="1"></circle>
			<path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"></path>
		</svg>
		<span><?php esc_html_e( 'Carrito', 'ccm-checkout' ); ?></span>
	</a>
</header>
