<?php
/**
 * Billing fields override — secciones Contacto + Entrega (mockup CCM).
 *
 * Parte los campos de facturación: billing_email va en "Contacto"; el resto
 * (ordenado por CCMCK_Document::finalize_fields) va en "Entrega". Conserva los
 * hooks woocommerce_before/after_checkout_billing_form. Checkout sólo invitado:
 * NO renderiza los campos de creación de cuenta (login es visual por ahora).
 *
 * @see WooCommerce templates/checkout/form-billing.php
 * @package CCM_Checkout
 *
 * @var WC_Checkout $checkout
 */

defined( 'ABSPATH' ) || exit;

$fields    = $checkout->get_checkout_fields( 'billing' );
$login_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '#';
// Si ya hay sesión, el enlace lleva a "Mi cuenta"; si no, a "Iniciar sesión".
// En ambos casos abre en una pestaña nueva para no perder el checkout en curso.
$account_label = is_user_logged_in() ? __( 'Mi cuenta', 'ccm-checkout' ) : __( 'Iniciar sesión', 'ccm-checkout' );

do_action( 'woocommerce_before_checkout_billing_form', $checkout );
?>
<div class="woocommerce-billing-fields">

	<section class="ccmck-section ccmck-contacto">
		<div class="ccmck-section-head">
			<h2><?php esc_html_e( 'Contacto', 'ccm-checkout' ); ?></h2>
			<a class="ccmck-login-link" href="<?php echo esc_url( $login_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $account_label ); ?></a>
		</div>
		<div class="woocommerce-billing-fields__field-wrapper">
			<?php
			if ( isset( $fields['billing_email'] ) ) {
				woocommerce_form_field( 'billing_email', $fields['billing_email'], $checkout->get_value( 'billing_email' ) );
			}
			?>
		</div>
		<label class="ccmck-check ccmck-news">
			<input type="checkbox" name="ccmck_newsletter" value="1" />
			<span><?php esc_html_e( 'Enviarme novedades y ofertas por correo electrónico.', 'ccm-checkout' ); ?></span>
		</label>
	</section>

	<section class="ccmck-section ccmck-entrega">
		<h2><?php esc_html_e( 'Entrega', 'ccm-checkout' ); ?></h2>
		<div class="woocommerce-billing-fields__field-wrapper">
			<?php
			foreach ( $fields as $key => $field ) {
				if ( 'billing_email' === $key ) {
					continue;
				}
				woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
			}
			?>
		</div>
		<label class="ccmck-check ccmck-save-info">
			<input type="checkbox" name="ccmck_save_info" value="1" />
			<span><?php esc_html_e( 'Guardar mi información y consultar más rápidamente la próxima vez.', 'ccm-checkout' ); ?></span>
		</label>
	</section>

</div>
<?php
do_action( 'woocommerce_after_checkout_billing_form', $checkout );
