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
}
