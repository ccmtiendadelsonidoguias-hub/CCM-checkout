<?php
defined( 'ABSPATH' ) || exit;

final class CCMCK_Assets {
    public static function init(): void {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function enqueue(): void {
        if ( ! is_checkout() ) {
            return;
        }
        wp_enqueue_style( 'ccmck-checkout', CCMCK_URL . 'assets/ccmck-checkout.css', array(), CCMCK_VERSION );
        wp_enqueue_script( 'ccmck-checkout', CCMCK_URL . 'assets/ccmck-checkout.js', array( 'jquery' ), CCMCK_VERSION, true );
        wp_localize_script( 'ccmck-checkout', 'CCMCK', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ccmck_cart' ),
        ) );
    }
}
