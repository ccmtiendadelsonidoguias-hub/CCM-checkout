<?php
defined( 'ABSPATH' ) || exit;

final class CCMCK_Payments {
    public static function init(): void {
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'apply_settings' ) );
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
