<?php
defined( 'ABSPATH' ) || exit;

final class CCMCK_Templates {
    private const OVERRIDES = array(
        'checkout/form-checkout.php',
        'checkout/form-billing.php',
        'checkout/review-order.php',
        'checkout/payment.php',
        'checkout/thankyou.php',
    );

    public static function init(): void {
        add_filter( 'woocommerce_locate_template', array( __CLASS__, 'locate' ), 10, 3 );
    }

    public static function locate( string $template, string $template_name, string $template_path ): string {
        if ( in_array( $template_name, self::OVERRIDES, true ) ) {
            $candidate = CCMCK_DIR . 'templates/' . $template_name;
            if ( file_exists( $candidate ) ) {
                return $candidate;
            }
        }
        return $template;
    }
}
