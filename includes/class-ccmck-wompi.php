<?php
/**
 * CCM Checkout — Wompi como popup (no página nueva).
 *
 * El gateway Wompi (woocommerce-gateway-wompi) procesa el pago en la página
 * order-pay, donde pinta un botón que REDIRIGE a checkout.wompi.co (página
 * nueva). Wompi también ofrece un modo widget/popup (`window.WidgetCheckout`).
 *
 * Esta clase solo CARGA el widget de Wompi en el checkout para exponer
 * `WidgetCheckout` (normalmente solo está en order-pay). La lógica que abre el
 * popup al colocar el pedido vive en ccmck-checkout.js, que reutiliza los datos
 * FIRMADOS que el propio plugin Wompi pinta en order-pay (no recreamos la firma).
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

final class CCMCK_Wompi {
    const WIDGET_HANDLE = 'ccmck-wompi-widget';
    const WIDGET_SRC    = 'https://checkout.wompi.co/widget.js';

    public static function init(): void {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
    }

    /**
     * Carga widget.js de Wompi en el checkout. Sin atributos data-* NO
     * auto-renderiza ningún botón: solo define `window.WidgetCheckout`, que
     * ccmck-checkout.js usa para abrir el modal. Filtrable por si se quiere
     * desactivar el popup y volver al flujo de redirección nativo.
     */
    public static function enqueue(): void {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }
        if ( ! apply_filters( 'ccmck_wompi_popup_enabled', true ) ) {
            return;
        }
        wp_enqueue_script( self::WIDGET_HANDLE, self::WIDGET_SRC, array(), null, true );
    }
}
