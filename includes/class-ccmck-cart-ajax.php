<?php
defined( 'ABSPATH' ) || exit;

final class CCMCK_Cart_Ajax {
    public static function init(): void {
        add_action( 'wp_ajax_ccmck_update_cart_item', array( __CLASS__, 'handle' ) );
        add_action( 'wp_ajax_nopriv_ccmck_update_cart_item', array( __CLASS__, 'handle' ) );
    }

    /**
     * ¿Esta línea ya llegó al tope de existencias? PURO.
     *
     * `WC_Product::get_max_purchase_quantity()` devuelve **-1** cuando NO hay
     * tope: el producto no gestiona stock, o admite reservas. Ese -1 es la
     * trampa — comparado a pelo, cualquier cantidad quedaría "por encima del
     * tope" y el botón de más saldría apagado en toda la tienda.
     *
     * @param int $cantidad Unidades que ya hay en el carrito.
     * @param int $maximo   Tope de WooCommerce; -1 (o 0) significan «sin tope».
     */
    public static function en_tope( int $cantidad, int $maximo ): bool {
        return $maximo > 0 && $cantidad >= $maximo;
    }

    public static function handle(): void {
        check_ajax_referer( 'ccmck_cart', 'nonce' );

        $key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
        $qty = isset( $_POST['qty'] ) ? absint( $_POST['qty'] ) : 0;

        if ( '' === $key || ! WC()->cart || ! WC()->cart->get_cart_item( $key ) ) {
            wp_send_json_error( 'invalid_item' );
        }

        if ( 0 === $qty ) {
            WC()->cart->remove_cart_item( $key );
        } else {
            WC()->cart->set_quantity( $key, $qty, true );
        }
        WC()->cart->calculate_totals();

        wp_send_json_success( array( 'item_count' => WC()->cart->get_cart_contents_count() ) );
    }
}
