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
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="logo">
			<img src="<?php echo esc_url( $s['logo_image'] ); ?>" alt="<?php echo esc_attr( trim( $s['logo_text_1'] . ' ' . $s['logo_text_2'] ) ); ?>" />
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
</header>
