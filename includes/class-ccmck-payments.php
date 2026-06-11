<?php
defined( 'ABSPATH' ) || exit;

final class CCMCK_Payments {
    public static function init(): void {
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'apply_settings' ) );
    }

    /**
     * Renderiza los campos de pago de un gateway. Para Addi —que imprime su
     * descripción con <div> dentro de <b> (HTML inválido), lo que hace que el
     * parser del navegador esparza <b> sueltos por la página y termine
     * envolviendo el <aside> del resumen, rompiendo la grilla— bufferiza la
     * salida y convierte los <b> en <span> (mismo render, pero <span> NO es
     * "elemento de formato": no dispara la corrupción). El resto de gateways
     * se imprimen sin cambios. Se llama desde templates/checkout/payment.php.
     *
     * NOTA: Addi pinta su HTML directo en payment_fields(), NO vía
     * get_description()/woocommerce_gateway_description, por eso hay que
     * bufferizar aquí en lugar de filtrar la descripción.
     */
    public static function render_payment_fields( $gateway ): void {
        $id = is_object( $gateway ) && isset( $gateway->id ) ? (string) $gateway->id : '';
        if ( false === stripos( $id, 'addi' ) ) {
            $gateway->payment_fields();
            return;
        }
        ob_start();
        $gateway->payment_fields();
        echo self::fix_b_tags( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Convierte <b …>…</b> en <span …>…</span> conservando atributos. PURO.
     * Neutraliza el <div> dentro de <b> que corrompe el DOM (un <span> sí puede
     * contener bloques sin disparar el "adoption agency" del parser).
     */
    public static function fix_b_tags( string $html ): string {
        if ( false === stripos( $html, '<b' ) ) {
            return $html;
        }
        $html = preg_replace( '/<b(\s[^>]*)?>/i', '<span$1>', $html );
        return str_ireplace( '</b>', '</span>', $html );
    }

    public static function apply_settings( array $gateways ): array {
        if ( ! is_checkout() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            return $gateways;
        }
        return self::sort_and_filter( $gateways, CCMCK_Settings::all() );
    }

    public static function sort_and_filter( array $gateways, array $settings ): array {
        $hidden = array_flip( (array) ( $settings['payment_hidden'] ?? array() ) );
        $order  = (array) ( $settings['payment_order'] ?? array() );

        $visible = array();
        foreach ( $gateways as $id => $gw ) {
            if ( ! isset( $hidden[ $id ] ) ) {
                $visible[ $id ] = $gw;
            }
        }

        $sorted = array();
        foreach ( $order as $id ) {
            if ( isset( $visible[ $id ] ) ) {
                $sorted[ $id ] = $visible[ $id ];
                unset( $visible[ $id ] );
            }
        }
        foreach ( $visible as $id => $gw ) {
            $sorted[ $id ] = $gw;
        }
        return $sorted;
    }

    public static function icon_for( string $gateway_id ): array {
        $icons = CCMCK_Settings::get( 'payment_icons', array() );
        return $icons[ $gateway_id ] ?? array();
    }
}
