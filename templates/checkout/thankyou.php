<?php
/**
 * Thankyou page — override CCM.
 *
 * Conserva el flujo de WooCommerce (estado "failed", do_action woocommerce_thankyou)
 * mientras presenta el pedido con el layout del mockup CCM.
 *
 * @see WooCommerce templates/checkout/thankyou.php (v8.1.0)
 * @package CCM_Checkout
 *
 * @var WC_Order $order
 */

defined( 'ABSPATH' ) || exit;

$s = CCMCK_Settings::all();

if ( ! $order instanceof WC_Order ) {
	wc_get_template( 'checkout/order-received.php', array( 'order' => false ) );
	return;
}

do_action( 'woocommerce_before_thankyou', $order->get_id() );

$vm = CCMCK_Thankyou::build_view_model( $order );
?>
<div class="ccmck ccmck-thankyou-page woocommerce-order">

	<?php require CCMCK_DIR . 'templates/parts/header.php'; ?>

	<?php if ( $order->has_status( 'failed' ) ) : ?>

		<div class="page-wrapper">
			<main class="page-main">
				<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed"><?php esc_html_e( 'Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.', 'woocommerce' ); ?></p>

				<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed-actions action-buttons">
					<a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="button pay btn-primary"><?php esc_html_e( 'Pay', 'woocommerce' ); ?></a>
					<?php if ( is_user_logged_in() ) : ?>
						<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="button pay btn-secondary"><?php esc_html_e( 'My account', 'woocommerce' ); ?></a>
					<?php endif; ?>
				</p>
			</main>
		</div>

	<?php else : ?>

		<div class="page-wrapper">

			<main class="page-main">

				<div class="confirm-hero">
					<div class="confirm-icon">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
					</div>
					<h1><?php esc_html_e( '¡Gracias por tu compra!', 'ccm-checkout' ); ?></h1>
					<p class="order-number">
						<?php esc_html_e( 'Pedido', 'ccm-checkout' ); ?>
						<strong>#<?php echo esc_html( $vm['number'] ); ?></strong>
					</p>
					<?php if ( ! empty( $s['thankyou_message'] ) ) : ?>
						<p class="confirm-msg">
							<?php echo esc_html( $s['thankyou_message'] ); ?>
							<?php if ( $vm['email'] ) : ?>
								<strong><?php echo esc_html( $vm['email'] ); ?></strong>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>

				<?php
				if ( ! empty( $s['tracker_enabled'] ) ) :
					$labels      = array_values( (array) $s['tracker_labels'] );
					$active_step = (int) $vm['active_step'];
					?>
					<div class="status-tracker">
						<?php
						foreach ( $labels as $i => $label ) :
							$step  = $i + 1;
							$state = '';
							if ( $step < $active_step ) {
								$state = 'completed';
							} elseif ( $step === $active_step ) {
								$state = 'active';
							}
							if ( $i > 0 ) :
								?>
								<div class="status-line<?php echo ( $step <= $active_step ) ? ' done' : ''; ?>"></div>
								<?php
							endif;
							?>
							<div class="status-step <?php echo esc_attr( $state ); ?>">
								<div class="status-dot">
									<?php if ( 'completed' === $state ) : ?>
										<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
									<?php else : ?>
										<?php echo esc_html( $step ); ?>
									<?php endif; ?>
								</div>
								<span class="status-label"><?php echo esc_html( $label ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<hr class="section-divider">

				<div class="info-grid">
					<?php if ( $vm['shipping_addr'] ) : ?>
						<div class="info-card">
							<div class="info-card-label"><?php esc_html_e( 'Dirección de envío', 'ccm-checkout' ); ?></div>
							<div class="info-card-value"><?php echo wp_kses_post( $vm['shipping_addr'] ); ?></div>
						</div>
					<?php endif; ?>

					<?php if ( $vm['billing_addr'] ) : ?>
						<div class="info-card">
							<div class="info-card-label"><?php esc_html_e( 'Dirección de facturación', 'ccm-checkout' ); ?></div>
							<div class="info-card-value"><?php echo wp_kses_post( $vm['billing_addr'] ); ?></div>
						</div>
					<?php endif; ?>

					<?php if ( $order->get_shipping_method() ) : ?>
						<div class="info-card">
							<div class="info-card-label"><?php esc_html_e( 'Método de envío', 'ccm-checkout' ); ?></div>
							<div class="info-card-value"><strong><?php echo esc_html( $order->get_shipping_method() ); ?></strong></div>
						</div>
					<?php endif; ?>

					<?php if ( $vm['payment_title'] ) : ?>
						<div class="info-card">
							<div class="info-card-label"><?php esc_html_e( 'Método de pago', 'ccm-checkout' ); ?></div>
							<div class="info-card-value"><strong><?php echo wp_kses_post( $vm['payment_title'] ); ?></strong></div>
						</div>
					<?php endif; ?>
				</div>

				<?php require CCMCK_DIR . 'templates/parts/footer.php'; ?>

			</main>

			<aside class="page-sidebar">
				<div class="sidebar-title"><?php esc_html_e( 'Resumen del pedido', 'ccm-checkout' ); ?></div>

				<?php foreach ( $order->get_items() as $item_id => $item ) : ?>
					<?php
					$product   = $item->get_product();
					$thumb     = ( $product && $product->get_image_id() ) ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '';
					?>
					<div class="sidebar-product">
						<div class="product-img-wrap">
							<?php if ( $thumb ) : ?>
								<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $item->get_name() ); ?>" />
							<?php endif; ?>
							<span class="product-qty-badge"><?php echo esc_html( $item->get_quantity() ); ?></span>
						</div>
						<div class="product-info">
							<div class="product-name"><?php echo esc_html( $item->get_name() ); ?></div>
						</div>
						<div class="product-price"><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></div>
					</div>
				<?php endforeach; ?>

				<div class="summary-line total">
					<span><?php esc_html_e( 'Total', 'ccm-checkout' ); ?></span>
					<span class="total-amount"><?php echo wp_kses_post( $vm['total'] ); ?></span>
				</div>

				<?php if ( ! empty( $s['secure_badge'] ) ) : ?>
					<div class="secure-badge">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#888"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
						<?php echo esc_html( $s['secure_badge'] ); ?>
					</div>
				<?php endif; ?>
			</aside>

		</div>

	<?php endif; ?>

	<div class="ccmck-gateway-output">
		<?php do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() ); ?>
		<?php do_action( 'woocommerce_thankyou', $order->get_id() ); ?>
	</div>

</div>
