<?php
defined( 'ABSPATH' ) || exit;

final class CCMCK_Cart_Ajax {
    public static function init(): void {
        add_action( 'wp_ajax_ccmck_update_cart_item', array( __CLASS__, 'handle' ) );
        add_action( 'wp_ajax_nopriv_ccmck_update_cart_item', array( __CLASS__, 'handle' ) );
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
