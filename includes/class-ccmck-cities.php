<?php
defined( 'ABSPATH' ) || exit;

/**
 * Valida ciudad y departamento contra el catálogo del plugin
 * wc-departamentos-y-ciudades-colombia ANTES de permitir el pago.
 *
 * Motivo (caso William): una ciudad escrita/autocompletada que no matchea el
 * dropdown deja al pedido sin tarifa de Coordinadora y el checkout salía con
 * pickup como única "elección". Ahora, si el carrito necesita envío y el
 * cliente NO eligió pickup explícitamente, la ciudad debe ser un código DANE
 * válido dentro del departamento elegido o el checkout no continúa.
 *
 * Fail-open: si el plugin de ciudades no está (catálogo vacío) no se valida,
 * para no bloquear ventas por un plugin desactivado.
 */
final class CCMCK_Cities {

    public static function init(): void {
        add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
    }

    /**
     * Catálogo departamento => (código DANE => ciudad) del plugin de ciudades.
     * Cache estático + filtro `ccmck_cities_catalog`.
     *
     * @return array<string,array<string,string>>
     */
    public static function catalog(): array {
        static $cache = null;
        if ( null !== $cache ) {
            return $cache;
        }
        $cache = array();
        if ( defined( 'WP_PLUGIN_DIR' ) ) {
            $path = WP_PLUGIN_DIR . '/wc-departamentos-y-ciudades-colombia/assets/places/CO-cities.php';
            if ( file_exists( $path ) ) {
                global $places;
                include $path;
                if ( isset( $places['CO'] ) && is_array( $places['CO'] ) ) {
                    $cache = $places['CO'];
                }
            }
        }
        $cache = (array) apply_filters( 'ccmck_cities_catalog', $cache );
        return $cache;
    }

    /**
     * Valida destino contra el catálogo. PURO.
     *
     * @param array  $catalog Departamento => (código DANE => ciudad).
     * @param string $state   billing_state posteado.
     * @param string $city    billing_city posteado. place-select.js lo manda
     *                        como "NOMBRE (ABREV) (DANE)", no el DANE crudo.
     * @return string '' si es válido; 'state' o 'city' según el campo que falla.
     */
    public static function validate_destination( array $catalog, string $state, string $city ): string {
        $state = trim( $state );
        $city  = trim( $city );

        $dept = null;
        if ( '' !== $state ) {
            if ( isset( $catalog[ $state ] ) ) {
                $dept = $catalog[ $state ];
            } else {
                // Tolerancia a mayúsculas/acentos simples: comparación case-insensitive.
                foreach ( $catalog as $key => $cities ) {
                    if ( 0 === strcasecmp( (string) $key, $state ) ) {
                        $dept = $cities;
                        break;
                    }
                }
            }
        }
        if ( ! is_array( $dept ) ) {
            return 'state';
        }
        // El catálogo se indexa por DANE crudo; el dropdown postea el DANE
        // dentro de "NOMBRE (ABREV) (DANE)". Extraemos con la misma lógica que
        // dane_from_city() (fuente única, ya usada por la generación de guía).
        $dane = CCMCK_Coordinadora::dane_from_city( $city );
        if ( '' === $dane || ! isset( $dept[ $dane ] ) ) {
            return 'city';
        }
        return '';
    }

    /**
     * ¿El POST del checkout eligió pickup EXPLÍCITAMENTE? A diferencia de
     * CCMCK_Pickup::current_is_pickup(), no cae a la sesión: si el radio de
     * envío no se posteó (p. ej. pickup sin auto-seleccionar), se valida la
     * ciudad igual. PURO respecto a $posted.
     *
     * @param mixed $posted Valor de $_POST['shipping_method'] ya unslashed, o null.
     */
    public static function posted_is_pickup( $posted ): bool {
        if ( null === $posted ) {
            return false;
        }
        $posted = is_array( $posted ) ? array_map( 'sanitize_text_field', $posted ) : array( sanitize_text_field( (string) $posted ) );
        return CCMCK_Pickup::chosen_is_pickup( $posted );
    }

    /** Hook woocommerce_after_checkout_validation. */
    public static function validate( $data, $errors ): void {
        if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->cart->needs_shipping() ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification
        $posted = isset( $_POST['shipping_method'] ) ? wp_unslash( $_POST['shipping_method'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( self::posted_is_pickup( $posted ) ) {
            return;
        }
        $catalog = self::catalog();
        if ( ! $catalog ) {
            return;
        }

        $data = is_array( $data ) ? $data : array();
        $fail = self::validate_destination(
            $catalog,
            (string) ( $data['billing_state'] ?? '' ),
            (string) ( $data['billing_city'] ?? '' )
        );

        if ( 'state' === $fail ) {
            $errors->add( 'ccmck_state_invalid', __( 'Selecciona tu <strong>departamento</strong> de la lista para calcular el envío.', 'ccm-checkout' ) );
        } elseif ( 'city' === $fail ) {
            $errors->add( 'ccmck_city_invalid', __( 'Selecciona tu <strong>ciudad</strong> de la lista para calcular el envío.', 'ccm-checkout' ) );
        }
    }
}
