<?php
/**
 * cart-empty.php — CCM Checkout.
 *
 * Copia literal de woocommerce/templates/cart/cart-empty.php @version 7.0.1.
 * En esta tarea NO se cambia nada: se copia tal cual para probar que el desvío
 * funciona antes de tocar el diseño. Si algo se rompe después, se sabrá que fue
 * el diseño y no el mecanismo.
 *
 * Revisar tras cada actualización de WooCommerce.
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

/*
 * @hooked wc_empty_cart_message - 10
 */
do_action( 'woocommerce_cart_is_empty' );

if ( wc_get_page_id( 'shop' ) > 0 ) : ?>
	<p class="return-to-shop">
		<a class="button wc-backward<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>">
			<?php
				/**
				 * Filter "Return To Shop" text.
				 *
				 * @since 4.6.0
				 * @param string $default_text Default text.
				 */
				echo esc_html( apply_filters( 'woocommerce_return_to_shop_text', __( 'Return to shop', 'woocommerce' ) ) );
			?>
		</a>
	</p>
<?php endif; ?>
