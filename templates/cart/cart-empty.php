<?php
/**
 * cart-empty.php — CCM Checkout.
 *
 * Basado en woocommerce/templates/cart/cart-empty.php @version 7.0.1: el
 * contenido se envuelve en `.ccmck-vacio` para poder centrarlo y darle aire, y
 * el enlace de vuelta a la tienda pasa a decir «Ir a la tienda».
 *
 * El aviso de «carrito vacío» NO se escribe aquí, y es a propósito. Lo pinta
 * `wc_empty_cart_message()`, que el núcleo tiene colgado de
 * `woocommerce_cart_is_empty` con prioridad 10; de ese mismo gancho cuelga
 * `woocommerce_output_all_notices` con prioridad 5, que es por donde salen los
 * avisos de verdad («Cupón eliminado.»). Escribir aquí además un párrafo propio
 * dejaría el mismo mensaje dos veces seguidas, uno debajo del otro.
 *
 * Se conservan del núcleo, y ninguna de las tres es decorativa:
 *
 * 1. El `do_action( 'woocommerce_cart_is_empty' )`, por lo de arriba.
 * 2. La comprobación de que la página de tienda existe. Sin ella, en una
 *    instalación donde no esté fijada, `wc_get_page_permalink( 'shop' )`
 *    devuelve falso y se pintaría un botón con el `href` vacío.
 * 3. Las clases `return-to-shop` y `wc-backward` del enlace: `cart.js` del
 *    núcleo y algún plugin las buscan en el HTML.
 *
 * Revisar tras cada actualización de WooCommerce.
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="ccmck-vacio">

	<?php
	/*
	 * @hooked woocommerce_output_all_notices - 5
	 * @hooked wc_empty_cart_message - 10
	 */
	do_action( 'woocommerce_cart_is_empty' );
	?>

	<?php if ( wc_get_page_id( 'shop' ) > 0 ) : ?>
		<p class="return-to-shop">
			<a class="button wc-backward<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>">
				<?php esc_html_e( 'Ir a la tienda', 'ccm-checkout' ); ?>
			</a>
		</p>
	<?php endif; ?>

</div>
