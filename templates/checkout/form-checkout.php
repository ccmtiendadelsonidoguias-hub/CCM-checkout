<?php
/**
 * Checkout Form — override CCM.
 *
 * Reorganiza el checkout clásico de WooCommerce dentro del layout del mockup CCM,
 * preservando TODOS los hooks, funciones e IDs que checkout.js y el procesamiento
 * de pedidos de WooCommerce necesitan.
 *
 * @see WooCommerce templates/checkout/form-checkout.php (v9.4.0)
 * @package CCM_Checkout
 *
 * @var WC_Checkout $checkout
 */

defined( 'ABSPATH' ) || exit;

$s = CCMCK_Settings::all();

do_action( 'woocommerce_before_checkout_form', $checkout );

// If checkout registration is disabled and not logged in, the user cannot checkout.
if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
	return;
}

/*
 * Layout de 2 columnas (mockup CCM): el PAGO va en la columna principal y el
 * RESUMEN del pedido en el sidebar oscuro derecho. WooCommerce normalmente
 * renderiza ambos juntos dentro de la acción woocommerce_checkout_order_review
 * (review-order en prioridad 10, payment en prioridad 20). Quitamos solo el
 * callback de pago de esa acción y lo renderizamos aparte con
 * woocommerce_checkout_payment(). El AJAX de WC reemplaza cada fragmento por su
 * CLASE (.woocommerce-checkout-review-order-table y .woocommerce-checkout-payment),
 * sin importar dónde estén en el DOM, así que el recálculo de totales sigue
 * funcionando con la nueva disposición.
 */
remove_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );
?>
<div class="ccmck ccmck-checkout-page">

	<?php require CCMCK_DIR . 'templates/parts/header.php'; ?>

	<form name="checkout" method="post" class="checkout woocommerce-checkout checkout-wrapper" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php echo esc_attr__( 'Checkout', 'woocommerce' ); ?>">

		<main class="checkout-main">

			<?php if ( $checkout->get_checkout_fields() ) : ?>

				<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

				<div class="col2-set" id="customer_details">
					<div class="col-1">
						<?php do_action( 'woocommerce_checkout_billing' ); ?>
					</div>

					<div class="col-2">
						<?php do_action( 'woocommerce_checkout_shipping' ); ?>
					</div>
				</div>

				<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

			<?php endif; ?>

			<h3 id="order_review_heading"><?php esc_html_e( 'Pago', 'ccm-checkout' ); ?></h3>

			<?php
			/*
			 * Pago en la columna principal. woocommerce_checkout_payment() emite el
			 * <div id="payment" class="woocommerce-checkout-payment"> con los gateways,
			 * el botón #place_order y el nonce — todo dentro del <form>.
			 */
			woocommerce_checkout_payment();
			?>

			<?php require CCMCK_DIR . 'templates/parts/footer.php'; ?>

		</main>

		<aside class="checkout-sidebar">
			<div class="sidebar-inner">

				<div class="sidebar-title"><?php esc_html_e( 'Resumen del pedido', 'ccm-checkout' ); ?></div>

				<?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>
				<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>

				<div id="order_review" class="woocommerce-checkout-review-order">
					<?php do_action( 'woocommerce_checkout_order_review' ); ?>
				</div>

				<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>

			</div>
		</aside>

	</form>

	<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>

</div>
