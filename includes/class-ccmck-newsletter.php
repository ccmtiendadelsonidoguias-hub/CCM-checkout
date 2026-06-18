<?php
/**
 * CCM Checkout — opt-in de novedades (checkbox "ccmck_newsletter").
 *
 * El checkbox de novedades del formulario de Contacto (ccmck-news) NO es un
 * campo nativo de WooCommerce, así que su valor no se persistía. Esta clase
 * lo guarda como meta del pedido (_ccmck_newsletter = yes|no) al crear el
 * pedido y lo muestra en la ficha del pedido en el admin.
 *
 * No integra ningún proveedor externo (Mailchimp/Brevo/etc.); solo registra
 * la preferencia en el pedido para consultarla/exportarla.
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

class CCMCK_Newsletter {

	const META_KEY = '_ccmck_newsletter';

	public static function init(): void {
		// Persistir la preferencia al crear el pedido (HPOS-safe).
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_to_order' ), 10, 2 );
		// Mostrarla en la ficha del pedido (admin), bajo la dirección de facturación.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_in_admin' ), 10, 1 );
	}

	/**
	 * Guarda el opt-in en el pedido. El checkbox viaja en $_POST del request de
	 * checkout (la nonce ya la valida WooCommerce antes de llegar aquí).
	 *
	 * @param WC_Order $order Pedido en construcción.
	 * @param array    $data  Datos posteados ya saneados por WC (no incluye el campo propio).
	 */
	public static function save_to_order( $order, $data ): void {
		unset( $data ); // El campo no es nativo; se lee de $_POST.
		$opted_in = isset( $_POST['ccmck_newsletter'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['ccmck_newsletter'] ) );
		$order->update_meta_data( self::META_KEY, $opted_in ? 'yes' : 'no' );
	}

	/**
	 * Pinta la preferencia en la ficha del pedido en el admin.
	 *
	 * @param WC_Order $order Pedido.
	 */
	public static function render_in_admin( $order ): void {
		$val = $order->get_meta( self::META_KEY );
		if ( '' === $val ) {
			return; // Pedidos antiguos sin el dato.
		}
		$label = ( 'yes' === $val ) ? __( 'Sí', 'ccm-checkout' ) : __( 'No', 'ccm-checkout' );
		echo '<p><strong>' . esc_html__( 'Acepta novedades', 'ccm-checkout' ) . ':</strong> ' . esc_html( $label ) . '</p>';
	}
}
