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

// Experimental: orden alternativo con los métodos de pago al inicio (botón "Realizar
// el pedido" al final). Solo añade una clase; el reordenamiento es por CSS.
$ccmck_layout_class = ! empty( $s['checkout_payment_first'] ) ? ' ccmck-payment-first' : '';
?>
<div class="ccmck ccmck-checkout-page ccmck-preload<?php echo esc_attr( $ccmck_layout_class ); ?>">

	<?php require CCMCK_DIR . 'templates/parts/header.php'; ?>

	<form name="checkout" method="post" class="checkout woocommerce-checkout checkout-wrapper" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php echo esc_attr__( 'Checkout', 'woocommerce' ); ?>">

		<main class="checkout-main">

			<?php if ( $checkout->get_checkout_fields() ) : ?>

				<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

				<div id="customer_details">
					<?php
					// woocommerce_checkout_billing emite ahora DOS secciones
					// (Contacto + Entrega) vía el override de form-billing.php.
					do_action( 'woocommerce_checkout_billing' );
					do_action( 'woocommerce_checkout_shipping' );
					?>
				</div>

				<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

			<?php endif; ?>

			<section class="ccmck-section ccmck-shipping-section">
				<h2><?php esc_html_e( 'Métodos de envío', 'ccm-checkout' ); ?></h2>
				<div id="ccmck_shipping_methods" class="ccmck-shipping-methods">
					<?php
					// HTML ya escapado dentro de CCMCK_Shipping::render_cards().
					echo CCMCK_Shipping::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
				<?php // Skeleton (carga inicial / AJAX): cards de envío. CSS lo muestra y oculta
				// #ccmck_shipping_methods cuando hay .ccmck-preload / .ccmck-skel. ?>
				<div class="ccmck-skel-tpl ccmck-skel-shipping" aria-hidden="true">
					<div class="ccmck-sks-card"><span class="ccmck-skb" style="width:20px;height:20px;border-radius:50%"></span><span class="ccmck-skb" style="width:42%;height:13px"></span><span class="ccmck-sks-grow"></span><span class="ccmck-skb" style="width:60px;height:13px"></span></div>
					<div class="ccmck-sks-card"><span class="ccmck-skb" style="width:20px;height:20px;border-radius:50%"></span><span class="ccmck-skb" style="width:38%;height:13px"></span><span class="ccmck-sks-grow"></span><span class="ccmck-skb" style="width:54px;height:13px"></span></div>
				</div>
			</section>

			<section class="ccmck-section ccmck-payment-section">
				<h2 id="order_review_heading"><?php esc_html_e( 'Pago', 'ccm-checkout' ); ?></h2>
				<p class="ccmck-secure-note"><?php esc_html_e( 'Todas las transacciones son seguras y están encriptadas.', 'ccm-checkout' ); ?></p>
				<?php
				// woocommerce_checkout_payment() emite #payment, los gateways,
				// el botón #place_order y el nonce — todo dentro del <form>.
				woocommerce_checkout_payment();
				?>
				<?php // Skeleton (carga inicial / AJAX): filas de método de pago. ?>
				<div class="ccmck-skel-tpl ccmck-skel-payment" aria-hidden="true">
					<div class="ccmck-sks-paybox">
						<div class="ccmck-sks-prow"><span class="ccmck-skb" style="width:20px;height:20px;border-radius:50%"></span><span class="ccmck-skb" style="width:38%;height:13px"></span><span class="ccmck-sks-grow"></span><span class="ccmck-skb" style="width:28px;height:18px"></span><span class="ccmck-skb" style="width:28px;height:18px"></span></div>
						<div class="ccmck-sks-prow"><span class="ccmck-skb" style="width:20px;height:20px;border-radius:50%"></span><span class="ccmck-skb" style="width:32%;height:13px"></span><span class="ccmck-sks-grow"></span><span class="ccmck-skb" style="width:28px;height:18px"></span></div>
						<div class="ccmck-sks-prow"><span class="ccmck-skb" style="width:20px;height:20px;border-radius:50%"></span><span class="ccmck-skb" style="width:30%;height:13px"></span></div>
					</div>
				</div>
			</section>

			<?php require CCMCK_DIR . 'templates/parts/footer.php'; ?>

		</main>

		<aside class="checkout-sidebar">

			<?php // Cabecera plegable SOLO en móvil (CSS la oculta en desktop). Muestra el total
			// en estado cerrado; al pulsarla, ccmck-checkout.js despliega .sidebar-inner. ?>
			<button type="button" class="mobile-summary-toggle" aria-expanded="false">
				<span class="toggle-left">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
					<?php esc_html_e( 'Resumen del pedido', 'ccm-checkout' ); ?>
				</span>
				<span class="toggle-price"><?php echo wp_kses_post( WC()->cart ? WC()->cart->get_total() : '' ); ?></span>
			</button>

			<div class="sidebar-inner">

				<div class="sidebar-title"><?php esc_html_e( 'Resumen del pedido', 'ccm-checkout' ); ?></div>

				<?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>
				<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>

				<?php // Skeleton (carga inicial / AJAX): tarjeta de producto + totales. ?>
				<div class="ccmck-skel-tpl ccmck-skel-summary" aria-hidden="true">
					<div class="ccmck-sks-prod">
						<span class="ccmck-skb" style="width:64px;height:64px;border-radius:8px;flex:0 0 auto"></span>
						<span class="ccmck-sks-mid"><span class="ccmck-skb" style="width:80%;height:13px"></span><span class="ccmck-skb" style="width:45%;height:11px"></span></span>
						<span class="ccmck-skb" style="width:60px;height:13px;flex:0 0 auto"></span>
					</div>
					<span class="ccmck-skb ccmck-sks-rule" style="width:100%;height:1px"></span>
					<div class="ccmck-sks-line"><span class="ccmck-skb" style="width:28%;height:11px"></span><span class="ccmck-skb" style="width:24%;height:11px"></span></div>
					<div class="ccmck-sks-line"><span class="ccmck-skb" style="width:22%;height:11px"></span><span class="ccmck-skb" style="width:18%;height:11px"></span></div>
					<span class="ccmck-skb ccmck-sks-rule" style="width:100%;height:1px"></span>
					<div class="ccmck-sks-line"><span class="ccmck-skb" style="width:30%;height:18px"></span><span class="ccmck-skb" style="width:34%;height:20px"></span></div>
				</div>

				<div id="order_review" class="woocommerce-checkout-review-order">
					<?php do_action( 'woocommerce_checkout_order_review' ); ?>
				</div>

				<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>

			</div>
		</aside>

	</form>

	<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>

</div>
