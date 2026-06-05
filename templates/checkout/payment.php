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
								<?php $gateway->payment_fields(); ?>
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
