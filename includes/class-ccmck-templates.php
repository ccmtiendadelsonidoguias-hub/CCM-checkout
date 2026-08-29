<?php
defined( 'ABSPATH' ) || exit;

final class CCMCK_Templates {
    private const OVERRIDES = array(
        'checkout/form-checkout.php',
        'checkout/form-billing.php',
        'checkout/review-order.php',
        'checkout/payment.php',
        'checkout/thankyou.php',
        // Carrito. Se sustituyen aquí y no en un plugin aparte porque el
        // carrito y el checkout comparten el cotizador de Coordinadora, las
        // ciudades con DANE y los recargos.
        'cart/cart.php',
        'cart/cart-totals.php',
        'cart/cart-empty.php',
        // woocommerce_form_field_args nunca se dispara para calc_shipping_city:
        // shipping-calculator.php pinta ese campo con un <input> a pelo, sin
        // pasar por woocommerce_form_field(). Por eso la ciudad se convierte en
        // <select> sobreescribiendo la plantilla entera, no enganchando un filtro.
        'cart/shipping-calculator.php',
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
