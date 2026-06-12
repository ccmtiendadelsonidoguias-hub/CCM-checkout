<?php
/**
 * Checkout Payment Section — override CCM.
 *
 * Conserva la estructura completa de #payment, ul.wc_payment_methods, los radios
 * input#payment_method_{id}, los .payment_box, $gateway->payment_fields(), el
 * botón #place_order, el nonce y TODOS los hooks woocommerce_review_order_*.
 * El bucle de gateways se renderiza en línea (en lugar de delegar a
 * payment-method.php) para envolver cada método con la tarjeta "payment-box".
 *
 * @see WooCommerce templates/checkout/payment.php (v9.8.0)
 * @see WooCommerce templates/checkout/payment-method.php (v3.5.0)
 * @package CCM_Checkout
 *
 * @var array  $available_gateways
 * @var string $order_button_text
 */

defined( 'ABSPATH' ) || exit;

if ( ! wp_doing_ajax() ) {
	do_action( 'woocommerce_review_order_before_payment' );
}
?>
<div id="payment" class="woocommerce-checkout-payment">
	<?php if ( WC()->cart && WC()->cart->needs_payment() ) : ?>
		<ul class="wc_payment_methods payment_methods methods payment-methods-stack">
			<?php
			if ( ! empty( $available_gateways ) ) {
				foreach ( $available_gateways as $gateway ) {
					$has_box = $gateway->has_fields() || $gateway->get_description();
					$icon    = CCMCK_Payments::icon_for( $gateway->id );
					?>
					<li class="wc_payment_method payment_method_<?php echo esc_attr( $gateway->id ); ?> payment-box<?php echo $gateway->chosen ? ' selected' : ''; ?>">
						<div class="payment-header">
							<span class="payment-header-left">
								<input id="payment_method_<?php echo esc_attr( $gateway->id ); ?>" type="radio" class="input-radio" name="payment_method" value="<?php echo esc_attr( $gateway->id ); ?>" <?php checked( $gateway->chosen, true ); ?> data-order_button_text="<?php echo esc_attr( $gateway->order_button_text ); ?>" />
								<label for="payment_method_<?php echo esc_attr( $gateway->id ); ?>">
									<?php echo wp_kses_post( $gateway->get_title() ); ?>
								</label>
							</span>
							<span class="payment-icons">
								<?php
								if ( ! empty( $icon['image'] ) ) {
									printf(
										'<img src="%1$s" alt="%2$s" class="ccmck-pay-icon" />',
										esc_url( $icon['image'] ),
										esc_attr( $icon['label'] ?? $gateway->get_title() )
									);
								} elseif ( ! empty( $icon['label'] ) ) {
									$style = ! empty( $icon['bg'] ) ? ' style="background:' . esc_attr( $icon['bg'] ) . ';color:#fff;"' : '';
									echo '<span class="card-icon"' . $style . '>' . esc_html( $icon['label'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								} else {
									echo $gateway->get_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}
								?>
							</span>
						</div>

						<?php if ( $has_box ) : ?>
							<div class="payment_box payment-body payment_method_<?php echo esc_attr( $gateway->id ); ?>" <?php echo $gateway->chosen ? '' : 'style="display:none;"'; ?>>
								<?php CCMCK_Payments::render_payment_fields( $gateway ); ?>
							</div>
						<?php endif; ?>
					</li>
					<?php
				}
			} else {
				echo '<li>';
				wc_print_notice( apply_filters( 'woocommerce_no_available_payment_methods_message', WC()->customer->get_billing_country() ? esc_html__( 'Sorry, it seems that there are no available payment methods. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce' ) : esc_html__( 'Please fill in your details above to see available payment methods.', 'woocommerce' ) ), 'notice' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
				echo '</li>';
			}
			?>
		</ul>
	<?php endif; ?>
	<div class="form-row place-order">
		<noscript>
			<?php
			/* translators: $1 and $2 opening and closing emphasis tags respectively */
			printf( esc_html__( 'Since your browser does not support JavaScript, or it is disabled, please ensure you click the %1$sUpdate Totals%2$s button before placing your order. You may be charged more than the amount stated above if you fail to do so.', 'woocommerce' ), '<em>', '</em>' );
			?>
			<br/><button type="submit" class="button alt<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="woocommerce_checkout_update_totals" value="<?php esc_attr_e( 'Update totals', 'woocommerce' ); ?>"><?php esc_html_e( 'Update totals', 'woocommerce' ); ?></button>
		</noscript>

		<?php wc_get_template( 'checkout/terms.php' ); ?>

		<?php do_action( 'woocommerce_review_order_before_submit' ); ?>

		<?php
		// Resumen compacto SOLO móvil, antes del botón (estilo Shopify). Vive dentro de
		// #payment → WooCommerce lo re-renderiza en cada refresco AJAX con totales frescos.
		// El JS solo pliega/despliega (ccmck-checkout.js). El CSS lo oculta en desktop.
		if ( WC()->cart && ! WC()->cart->is_empty() ) :
			$ccmck_cart  = WC()->cart->get_cart();
			$ccmck_count = WC()->cart->get_cart_contents_count();
			$ccmck_first = reset( $ccmck_cart );
			?>
			<div class="ccmck-mos">
				<button type="button" class="ccmck-mos-bar" aria-expanded="false" aria-controls="ccmck-mos-details">
					<span class="ccmck-mos-thumb"><?php echo $ccmck_first ? $ccmck_first['data']->get_image( 'woocommerce_thumbnail' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="ccmck-mos-label">
						<b><?php esc_html_e( 'Total', 'ccm-checkout' ); ?></b>
						<small><?php echo esc_html( sprintf( _n( '%d artículo', '%d artículos', $ccmck_count, 'ccm-checkout' ), $ccmck_count ) ); ?></small>
					</span>
					<span class="ccmck-mos-total"><?php echo wp_kses_post( WC()->cart->get_total() ); ?></span>
					<svg class="ccmck-mos-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
				</button>
				<div class="ccmck-mos-details" id="ccmck-mos-details">
					<?php
					foreach ( $ccmck_cart as $ccmck_ci ) :
						$ccmck_p = $ccmck_ci['data'];
						if ( ! $ccmck_p || ! $ccmck_p->exists() || $ccmck_ci['quantity'] <= 0 ) {
							continue;
						}
						?>
						<div class="ccmck-mos-item">
							<span class="ccmck-mos-item-thumb"><?php echo $ccmck_p->get_image( array( 48, 48 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="ccmck-mos-item-name"><?php echo esc_html( $ccmck_p->get_name() ); ?> <span class="ccmck-mos-item-qty">&times; <?php echo esc_html( $ccmck_ci['quantity'] ); ?></span></span>
							<span class="ccmck-mos-item-price"><?php echo wp_kses_post( WC()->cart->get_product_subtotal( $ccmck_p, $ccmck_ci['quantity'] ) ); ?></span>
						</div>
					<?php endforeach; ?>
					<div class="ccmck-mos-sum"><span><?php esc_html_e( 'Subtotal', 'ccm-checkout' ); ?></span><span><?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?></span></div>
					<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>
						<div class="ccmck-mos-sum"><span><?php esc_html_e( 'Envío', 'ccm-checkout' ); ?></span><span><?php echo wp_kses_post( WC()->cart->get_cart_shipping_total() ); ?></span></div>
					<?php endif; ?>
					<div class="ccmck-mos-sum ccmck-mos-grand"><span><?php esc_html_e( 'Total', 'ccm-checkout' ); ?></span><span><?php echo wp_kses_post( WC()->cart->get_total() ); ?></span></div>
				</div>
			</div>
			<?php
		endif;

		$ccmck_button_text = esc_html__( 'Pagar ahora', 'ccm-checkout' );
		echo apply_filters( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'woocommerce_order_button_html',
			'<button type="submit" class="button alt pay-button' . esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ) . '" name="woocommerce_checkout_place_order" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . $ccmck_button_text . '</button>'
		);
		?>

		<?php do_action( 'woocommerce_review_order_after_submit' ); ?>

		<?php wp_nonce_field( 'woocommerce-process_checkout', 'woocommerce-process-checkout-nonce' ); ?>
	</div>
</div>
<?php
if ( ! wp_doing_ajax() ) {
	do_action( 'woocommerce_review_order_after_payment' );
}
