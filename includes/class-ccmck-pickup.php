<?php
defined( 'ABSPATH' ) || exit;

/**
 * Recogida local (pickup en tienda): inyecta una tarifa de envío gratis siempre
 * disponible y relaja la obligatoriedad de los campos de dirección cuando el
 * cliente elige recoger en tienda. No depende de las Zonas de Envío de WC.
 */
final class CCMCK_Pickup {
    const RATE_ID = 'ccmck_local_pickup';
    const LABEL   = 'Recogida local';

    /** Campos de dirección de entrega que se vuelven opcionales al elegir pickup. */
    const ADDRESS_FIELDS = array( 'billing_address_1', 'billing_city', 'billing_state', 'billing_postcode' );

    /** ¿El id de tarifa es el de pickup? PURO. */
    public static function is_pickup_rate( string $rate_id ): bool {
        return self::RATE_ID === $rate_id;
    }

    /** ¿Alguno de los métodos elegidos (por paquete) es pickup? PURO. */
    public static function chosen_is_pickup( array $chosen ): bool {
        foreach ( $chosen as $rate_id ) {
            if ( self::is_pickup_rate( (string) $rate_id ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Inyecta la tarifa gratis de pickup en las tarifas del paquete. Idempotente.
     * Filtro woocommerce_package_rates.
     *
     * @param array $rates   rate_id => WC_Shipping_Rate
     * @param array $package Paquete de envío (no usado; firma del filtro).
     * @return array
     */
    public static function inject( $rates, $package = array() ): array {
        $rates = is_array( $rates ) ? $rates : array();
        if ( isset( $rates[ self::RATE_ID ] ) || ! class_exists( 'WC_Shipping_Rate' ) ) {
            return $rates;
        }
        $rates[ self::RATE_ID ] = new WC_Shipping_Rate( self::RATE_ID, self::LABEL, 0.0, array(), 'local_pickup' );
        return $rates;
    }

    /**
     * Marca como NO obligatorios los campos de dirección cuando $is_pickup. PURO.
     *
     * @param array $fields Estructura de woocommerce_checkout_fields.
     * @param bool  $is_pickup
     * @return array
     */
    public static function relax_fields( array $fields, bool $is_pickup ): array {
        if ( ! $is_pickup || empty( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
            return $fields;
        }
        foreach ( self::ADDRESS_FIELDS as $key ) {
            if ( isset( $fields['billing'][ $key ] ) && is_array( $fields['billing'][ $key ] ) ) {
                $fields['billing'][ $key ]['required'] = false;
            }
        }
        return $fields;
    }

    public static function init(): void {
        add_filter( 'woocommerce_package_rates', array( __CLASS__, 'inject' ), 10, 2 );
        add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'relax_checkout_fields' ), 9999 );
    }

    /** ¿El método elegido ahora mismo es pickup? Lee POST (submit/AJAX) o sesión. */
    public static function current_is_pickup(): bool {
        if ( isset( $_POST['shipping_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $posted = wp_unslash( $_POST['shipping_method'] ); // phpcs:ignore
            $posted = is_array( $posted ) ? array_map( 'sanitize_text_field', $posted ) : array( sanitize_text_field( (string) $posted ) );
            return self::chosen_is_pickup( $posted );
        }
        if ( function_exists( 'WC' ) && WC()->session ) {
            return self::chosen_is_pickup( (array) WC()->session->get( 'chosen_shipping_methods' ) );
        }
        return false;
    }

    /** Filtro woocommerce_checkout_fields: relaja la dirección si pickup. */
    public static function relax_checkout_fields( $fields ) {
        return self::relax_fields( is_array( $fields ) ? $fields : array(), self::current_is_pickup() );
    }
}
