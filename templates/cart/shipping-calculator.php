<?php
/**
 * Shipping Calculator
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/cart/shipping-calculator.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 9.7.0
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_shipping_calculator' ); ?>

<form class="woocommerce-shipping-calculator" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">

	<?php printf( '<a href="#" class="shipping-calculator-button" aria-expanded="false" aria-controls="shipping-calculator-form" role="button">%s</a>', esc_html( ! empty( $button_text ) ? $button_text : __( 'Calculate shipping', 'woocommerce' ) ) ); ?>

	<section class="shipping-calculator-form" id="shipping-calculator-form" style="display:none;">

		<?php if ( apply_filters( 'woocommerce_shipping_calculator_enable_country', true ) ) : ?>
			<p class="form-row form-row-wide" id="calc_shipping_country_field">
				<label for="calc_shipping_country"><?php esc_html_e( 'Country / region', 'woocommerce' ); ?></label>
				<select name="calc_shipping_country" id="calc_shipping_country" class="country_to_state country_select" rel="calc_shipping_state">
					<option value="default"><?php esc_html_e( 'Select a country / region&hellip;', 'woocommerce' ); ?></option>
					<?php
					foreach ( WC()->countries->get_shipping_countries() as $key => $value ) {
						echo '<option value="' . esc_attr( $key ) . '"' . selected( WC()->customer->get_shipping_country(), esc_attr( $key ), false ) . '>' . esc_html( $value ) . '</option>';
					}
					?>
				</select>
			</p>
		<?php endif; ?>

		<?php if ( apply_filters( 'woocommerce_shipping_calculator_enable_state', true ) ) : ?>
			<p class="form-row form-row-wide" id="calc_shipping_state_field">
				<?php
				$current_cc = WC()->customer->get_shipping_country();
				$current_r  = WC()->customer->get_shipping_state();
				$states     = WC()->countries->get_states( $current_cc );

				if ( is_array( $states ) && empty( $states ) ) {
					?>
					<input type="hidden" name="calc_shipping_state" id="calc_shipping_state" />
					<?php
				} elseif ( is_array( $states ) ) {
					?>
					<span>
						<label for="calc_shipping_state"><?php esc_html_e( 'State / County', 'woocommerce' ); ?></label>
						<select name="calc_shipping_state" class="state_select" id="calc_shipping_state">
							<option value=""><?php esc_html_e( 'Select an option&hellip;', 'woocommerce' ); ?></option>
							<?php
							foreach ( $states as $ckey => $cvalue ) {
								echo '<option value="' . esc_attr( $ckey ) . '" ' . selected( $current_r, $ckey, false ) . '>' . esc_html( $cvalue ) . '</option>';
							}
							?>
						</select>
					</span>
					<?php
				} else {
					?>
					<label for="calc_shipping_state"><?php esc_html_e( 'State / County', 'woocommerce' ); ?></label>
					<input type="text" class="input-text" value="<?php echo esc_attr( $current_r ); ?>" name="calc_shipping_state" id="calc_shipping_state" />
					<?php
				}
				?>
			</p>
		<?php endif; ?>

		<?php if ( apply_filters( 'woocommerce_shipping_calculator_enable_city', true ) ) : ?>
			<p class="form-row form-row-wide" id="calc_shipping_city_field">
				<label for="calc_shipping_city"><?php esc_html_e( 'City:', 'woocommerce' ); ?></label>
				<?php
				// CCM Checkout: la ciudad pasa a <select> con su DANE (ver
				// CCMCK_Cart_Shipping). woocommerce_form_field_args nunca se
				// dispara aquí porque esta plantilla no llama a
				// woocommerce_form_field(), así que se arma el <select> a mano
				// con la misma decisión pura que usa el checkout.
				$ccmck_city_state   = (string) WC()->customer->get_shipping_state();
				$ccmck_city_value   = (string) WC()->customer->get_shipping_city();
				$ccmck_city_catalog = CCMCK_Cities::catalog();
				$ccmck_city_args    = CCMCK_Cart_Shipping::city_field_args(
					array( 'input_class' => array( 'input-text' ) ),
					CCMCK_Cart_Shipping::city_options( $ccmck_city_catalog, $ccmck_city_state ),
					$ccmck_city_state,
					(bool) $ccmck_city_catalog
				);
				?>
				<select
					name="calc_shipping_city"
					id="calc_shipping_city"
					class="<?php echo esc_attr( implode( ' ', (array) ( $ccmck_city_args['input_class'] ?? array() ) ) ); ?>"
					<?php echo ! empty( $ccmck_city_args['custom_attributes']['disabled'] ) ? 'disabled="disabled"' : ''; ?>
				>
					<?php foreach ( (array) ( $ccmck_city_args['options'] ?? array() ) as $ccmck_city_option_value => $ccmck_city_option_label ) : ?>
						<option value="<?php echo esc_attr( $ccmck_city_option_value ); ?>" <?php selected( $ccmck_city_value, $ccmck_city_option_value ); ?>><?php echo esc_html( $ccmck_city_option_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endif; ?>

		<?php if ( apply_filters( 'woocommerce_shipping_calculator_enable_postcode', true ) ) : ?>
			<p class="form-row form-row-wide" id="calc_shipping_postcode_field">
				<label for="calc_shipping_postcode"><?php esc_html_e( 'Postcode / ZIP:', 'woocommerce' ); ?></label>
				<input type="text" class="input-text" value="<?php echo esc_attr( WC()->customer->get_shipping_postcode() ); ?>" name="calc_shipping_postcode" id="calc_shipping_postcode" />
			</p>
		<?php endif; ?>

		<p><button type="submit" name="calc_shipping" value="1" class="button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>"><?php esc_html_e( 'Update', 'woocommerce' ); ?></button></p>
		<?php wp_nonce_field( 'woocommerce-shipping-calculator', 'woocommerce-shipping-calculator-nonce' ); ?>
	</section>
</form>

<?php do_action( 'woocommerce_after_shipping_calculator' ); ?>
