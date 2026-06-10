<?php
defined( 'ABSPATH' ) || exit;

final class CCMCK_Payments {
    public static function init(): void {
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'apply_settings' ) );
        // Addi imprime su descripción con <div> dentro de <b> (HTML inválido). Eso hace que
        // el parser del navegador esparza <b> sueltos por la página, llegando a envolver el
        // <aside> del resumen y a romper el layout de la grilla. Cambiamos <b> por <span>
        // (mismo estilo visual, pero NO es "elemento de formato", así que no dispara ese bug).
        add_filter( 'woocommerce_gateway_description', array( __CLASS__, 'sanitize_addi_description' ), 100, 2 );
    }

    /**
     * Reemplaza <b>…</b> por <span>…</span> en la descripción del gateway de Addi para
     * evitar la corrupción de DOM que provoca su HTML inválido.
     *
     * @param string $description Descripción HTML del gateway.
     * @param string $gateway_id  ID del gateway.
     * @return string
     */
    public static function sanitize_addi_description( $description, $gateway_id ) {
        $description = (string) $description;
        if ( false === stripos( (string) $gateway_id, 'addi' )
            && false === strpos( $description, 'addi_description_container' ) ) {
            return $description;
        }
        $description = preg_replace( '/<b(\s[^>]*)?>/i', '<span$1>', $description );
        $description = str_ireplace( '</b>', '</span>', $description );
        return $description;
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
