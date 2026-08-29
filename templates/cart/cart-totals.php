<?php
/**
 * cart-totals.php — CCM Checkout.
 *
 * Basado en woocommerce/templates/cart/cart-totals.php @version 2.3.6: la tabla
 * de totales se sustituye por una tarjeta de resumen (.ccmck-resumen) con filas
 * de div. Los ganchos de la plantilla original se conservan tal cual — otros
 * plugins cuelgan de ellos.
 *
 * Tres cosas del núcleo se conservan a propósito, y ninguna es decorativa:
 *
 * 1. La clase `cart_totals` del contenedor, y que sea un `div`. El JavaScript
 *    del carrito de WooCommerce hace `$( '.cart_totals' ).replaceWith( html )`
 *    al aplicar un cupón, elegir envío o quitar un artículo, y bloquea
 *    `div.cart_totals` para el velo de «cargando». Sin esa clase los importes se
 *    quedan viejos y el velo no aparece.
 * 2. Las clases `cart-subtotal` y `shipping` de las filas: la pasarela de Stripe
 *    (funnelkit-stripe-woo-payment-gateway, activa en la tienda) las lee del
 *    HTML para armar la hoja de Google Pay.
 * 3. `wc-proceed-to-checkout` en el bloque del botón: la misma pasarela busca
 *    ahí el botón de pagar para copiarle el estilo al botón exprés.
 *
 * El envío va dentro de una tabla mínima porque la plantilla del núcleo que lo
 * pinta (`cart/cart-shipping.php`) devuelve un `<tr>`: fuera de una tabla el
 * navegador descarta esas etiquetas y con ellas la clase del envío.
 *
 * Dos cosas del núcleo se dejan fuera a propósito:
 *
 * - La línea de impuestos. En Colombia el IVA va en el precio y esta tienda no
 *   calcula ninguno hoy (`get_tax_totals()` viene vacío en desarrollo, 0 filas).
 *   Calcularlo aquí rompería el checkout.
 * - La calculadora de envío suelta. Solo se pintaba cuando `show_shipping()`
 *   daba falso, y con `woocommerce_shipping_cost_requires_address` en «no» eso
 *   no ocurre; además `cart/cart-shipping.php` ya la trae cuando hace falta.
 *
 * Revisar tras cada actualización de WooCommerce.
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="cart_totals ccmck-resumen <?php echo ( WC()->customer->has_calculated_shipping() ) ? 'calculated_shipping' : ''; ?>">

	<?php do_action( 'woocommerce_before_cart_totals' ); ?>

	<h2 class="ccmck-resumen__titulo"><?php esc_html_e( 'Resumen del pedido', 'ccm-checkout' ); ?></h2>

	<div class="ccmck-resumen__fila cart-subtotal">
		<span><?php esc_html_e( 'Subtotal', 'ccm-checkout' ); ?></span>
		<span><?php wc_cart_totals_subtotal_html(); ?></span>
	</div>

	<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
		<div class="ccmck-resumen__fila ccmck-resumen__fila--cupon cart-discount coupon-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
			<span><?php wc_cart_totals_coupon_label( $coupon ); ?></span>
			<span><?php wc_cart_totals_coupon_html( $coupon ); ?></span>
		</div>
	<?php endforeach; ?>

	<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>

		<?php do_action( 'woocommerce_cart_totals_before_shipping' ); ?>

		<table class="ccmck-resumen__envio"><?php wc_cart_totals_shipping_html(); ?></table>

		<?php do_action( 'woocommerce_cart_totals_after_shipping' ); ?>

	<?php endif; ?>

	<?php foreach ( WC()->cart->get_fees() as $fee ) : ?>
		<div class="ccmck-resumen__fila fee">
			<span><?php echo esc_html( $fee->name ); ?></span>
			<span><?php wc_cart_totals_fee_html( $fee ); ?></span>
		</div>
	<?php endforeach; ?>

	<?php do_action( 'woocommerce_cart_totals_before_order_total' ); ?>

	<div class="ccmck-resumen__fila ccmck-resumen__total order-total">
		<span><?php esc_html_e( 'Total', 'ccm-checkout' ); ?></span>
		<span><?php wc_cart_totals_order_total_html(); ?></span>
	</div>

	<?php do_action( 'woocommerce_cart_totals_after_order_total' ); ?>

	<?php
	// Reposicion: el nucleo traia esta caja escrita a mano dentro del
	// <td class="actions"> de cart.php, y al sustituir la tabla se fue con ella.
	// Aqui necesita su PROPIO <form>, porque la tarjeta de resumen se pinta
	// fuera del formulario del carrito y los formularios no se anidan.
	//
	// El nucleo aplica el descuento con solo recibir apply_coupon y coupon_code
	// por POST (WC_Form_Handler::update_cart_action), sin exigir nonce; el nonce
	// lo pide la rama de update_cart. Se manda igual: es gratis, y asi no
	// dependemos de que el nucleo siga siendo laxo.
	//
	// Quitar un cupon va por $_GET['remove_coupon'], que es el enlace que
	// wc_cart_totals_coupon_html() ya pinta en la fila del descuento.
	//
	// El id no puede ser `coupon_code`: ese lo usa el checkout de este mismo
	// plugin, y un id repetido rompe la asociacion de la etiqueta.
	?>
	<?php if ( wc_coupons_enabled() ) : ?>
		<form class="ccmck-cupon" method="post" action="<?php echo esc_url( wc_get_cart_url() ); ?>">
			<label for="ccmck-cupon-codigo" class="ccmck-cupon__etiqueta"><?php esc_html_e( '¿Tienes un cupón?', 'ccm-checkout' ); ?></label>
			<div class="ccmck-cupon__campos">
				<input type="text" name="coupon_code" class="ccmck-cupon__codigo" id="ccmck-cupon-codigo" value="" placeholder="<?php esc_attr_e( 'Código del cupón', 'ccm-checkout' ); ?>" />
				<button type="submit" class="ccmck-cupon__aplicar" name="apply_coupon" value="<?php esc_attr_e( 'Aplicar', 'ccm-checkout' ); ?>"><?php esc_html_e( 'Aplicar', 'ccm-checkout' ); ?></button>
			</div>
			<?php
			// A mano, y no con wp_nonce_field(), porque esa funcion fuerza
			// id="<nombre>" y el formulario del carrito ya usa ese mismo nombre:
			// dos veces el mismo id es HTML invalido. El nucleo lee este campo de
			// $_REQUEST, no por id. Y aplicar un cupon no le exige nonce
			// (class-wc-form-handler.php:646); se manda igual por si eso cambia.
			?>
			<input type="hidden" name="woocommerce-cart-nonce" value="<?php echo esc_attr( wp_create_nonce( 'woocommerce-cart' ) ); ?>" />
			<?php // Se conserva: hay plugins que cuelgan de este gancho. ?>
			<?php do_action( 'woocommerce_cart_coupon' ); ?>
		</form>
	<?php endif; ?>

	<div class="wc-proceed-to-checkout ccmck-resumen__pagar">
		<?php do_action( 'woocommerce_proceed_to_checkout' ); ?>
	</div>

	<?php do_action( 'woocommerce_after_cart_totals' ); ?>

</div>
