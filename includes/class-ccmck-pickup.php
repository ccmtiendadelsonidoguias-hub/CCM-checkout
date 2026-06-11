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
}
