<?php
defined( 'ABSPATH' ) || exit;

/**
 * Renderiza los métodos de envío de WooCommerce como cards en la columna
 * principal del checkout y los mantiene actualizados vía el sistema de
 * fragments nativo de WC. NO integra transportadoras: sólo presenta los
 * métodos que las Zonas de Envío de WooCommerce ya ofrecen.
 */
final class CCMCK_Shipping {
    const CONTAINER_ID = 'ccmck_shipping_methods';

    public static function init(): void {
        add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'fragments' ) );
    }

    /**
     * Normaliza los paquetes de WC()->shipping()->get_packages() a una lista
     * plana y predecible. Método PURO (sin globals) para poder testearlo.
     *
     * @param array $packages Paquetes de envío de WooCommerce.
     * @param array $chosen   chosen_shipping_methods de la sesión (index => rate_id).
     * @return array<int,array{index:int,rates:array<int,array{id:string,label:string,cost:float,checked:bool}>}>
     */
    public static function build_methods( array $packages, array $chosen ): array {
        $out = array();
        foreach ( $packages as $i => $package ) {
            $rates     = ( isset( $package['rates'] ) && is_array( $package['rates'] ) ) ? $package['rates'] : array();
            $chosen_id = isset( $chosen[ $i ] ) ? (string) $chosen[ $i ] : '';
            $methods   = array();

            foreach ( $rates as $rate ) {
                $id    = is_object( $rate ) && method_exists( $rate, 'get_id' ) ? (string) $rate->get_id() : '';
                $label = is_object( $rate ) && method_exists( $rate, 'get_label' ) ? (string) $rate->get_label() : '';
                $cost  = is_object( $rate ) && method_exists( $rate, 'get_cost' ) ? (float) $rate->get_cost() : 0.0;
                $methods[] = array(
                    'id'      => $id,
                    'label'   => $label,
                    'cost'    => $cost,
                    'checked' => ( '' !== $id && $id === $chosen_id ),
                );
            }

            // Si ninguno está marcado y hay opciones, marca la primera (estado inicial).
            if ( $methods && ! in_array( true, array_column( $methods, 'checked' ), true ) ) {
                $methods[0]['checked'] = true;
            }

            $out[] = array( 'index' => (int) $i, 'rates' => $methods );
        }
        return $out;
    }

    /**
     * Genera el HTML de las cards a partir de la salida de build_methods().
     * Método PURO. Cada rate es un radio name="shipping_method[$index]" para
     * que la selección postee y dispare el recálculo nativo de WC.
     */
    public static function render_cards( array $methods ): string {
        $has_rate = false;
        foreach ( $methods as $p ) {
            if ( ! empty( $p['rates'] ) ) { $has_rate = true; break; }
        }
        if ( ! $has_rate ) {
            return '<p class="ccmck-no-shipping">' . esc_html__( 'No hay envíos disponibles para tu dirección.', 'ccm-checkout' ) . '</p>';
        }

        $html = '';
        foreach ( $methods as $package ) {
            $index = (int) $package['index'];
            if ( empty( $package['rates'] ) ) { continue; }
            $html .= '<ul class="ccmck-shipping-list" data-package="' . esc_attr( (string) $index ) . '">';
            foreach ( $package['rates'] as $rate ) {
                $safe   = preg_replace( '/[^a-z0-9_-]/i', '_', (string) $rate['id'] );
                $dom_id = 'ccmck_ship_' . $index . '_' . $safe;
                $sel    = $rate['checked'] ? ' is-selected' : '';
                $chk    = $rate['checked'] ? ' checked' : '';
                $html  .= '<li class="' . esc_attr( 'ccmck-shipping-method' . $sel ) . '">';
                $html  .= '<input type="radio" class="shipping_method" name="shipping_method[' . esc_attr( (string) $index ) . ']" data-index="' . esc_attr( (string) $index ) . '" id="' . esc_attr( $dom_id ) . '" value="' . esc_attr( (string) $rate['id'] ) . '"' . $chk . ' />';
                $html  .= '<label for="' . esc_attr( $dom_id ) . '">';
                $html  .= '<span class="ccmck-ship-label">' . esc_html( (string) $rate['label'] ) . '</span>';
                $html  .= '<span class="ccmck-ship-cost">' . self::format_cost( (float) $rate['cost'] ) . '</span>';
                $html  .= '</label></li>';
            }
            $html .= '</ul>';
        }
        return $html;
    }

    /**
     * Extrae los títulos de los métodos de envío habilitados a partir de una
     * lista normalizada de zonas. Método PURO (sin globals) para poder testearlo.
     * Devuelve títulos únicos, en orden de aparición, descartando los
     * deshabilitados y los vacíos.
     *
     * @param array<int,array{methods:array<int,array{title:string,enabled:bool}>}> $zones
     * @return array<int,string>
     */
    public static function collect_zone_method_labels( array $zones ): array {
        $labels = array();
        foreach ( $zones as $zone ) {
            $methods = ( isset( $zone['methods'] ) && is_array( $zone['methods'] ) ) ? $zone['methods'] : array();
            foreach ( $methods as $method ) {
                if ( empty( $method['enabled'] ) ) { continue; }
                $title = trim( (string) ( $method['title'] ?? '' ) );
                if ( '' === $title || in_array( $title, $labels, true ) ) { continue; }
                $labels[] = $title;
            }
        }
        return $labels;
    }

    /**
     * Normaliza los métodos de UNA zona a [['title'=>, 'enabled'=>bool], …].
     * Maneja las dos formas que entrega WC: las zonas de
     * WC_Shipping_Zones::get_zones() son ARRAYS con los métodos bajo la clave
     * 'shipping_methods'; la zona 0 (get_zone(0)) es un OBJETO con
     * get_shipping_methods(). Método PURO (acepta stubs) para poder testearlo.
     *
     * @param array|object|mixed $zone
     * @return array<int,array{title:string,enabled:bool}>
     */
    public static function extract_zone_methods( $zone ): array {
        $objs = array();
        if ( is_array( $zone ) && isset( $zone['shipping_methods'] ) && is_array( $zone['shipping_methods'] ) ) {
            $objs = $zone['shipping_methods'];
        } elseif ( is_object( $zone ) && method_exists( $zone, 'get_shipping_methods' ) ) {
            $objs = (array) $zone->get_shipping_methods( false );
        }

        $out = array();
        foreach ( $objs as $method ) {
            if ( ! is_object( $method ) ) { continue; }
            if ( method_exists( $method, 'get_title' ) ) {
                $title = (string) $method->get_title();
            } else {
                $title = isset( $method->title ) ? (string) $method->title : '';
            }
            $enabled = isset( $method->enabled ) ? ( 'yes' === $method->enabled ) : true;
            $out[] = array( 'title' => $title, 'enabled' => $enabled );
        }
        return $out;
    }

    /**
     * Lee los métodos habilitados en todas las Zonas de Envío de WooCommerce
     * (incluida la zona 0 "Resto del mundo") y los normaliza para
     * collect_zone_method_labels(). Devuelve [] si WC no está disponible.
     *
     * @return array<int,string>
     */
    public static function get_zone_method_labels(): array {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
            return array();
        }
        $zones = WC_Shipping_Zones::get_zones();
        $zones = is_array( $zones ) ? array_values( $zones ) : array();
        $zones[] = WC_Shipping_Zones::get_zone( 0 ); // Resto del mundo.

        $normalized = array();
        foreach ( $zones as $zone ) {
            $normalized[] = array( 'methods' => self::extract_zone_methods( $zone ) );
        }
        return self::collect_zone_method_labels( $normalized );
    }

    /**
     * Pinta las cards de envío en estado deshabilitado (sin precio) a partir de
     * una lista de títulos. Se usa cuando todavía no hay una dirección que WC
     * pueda cotizar. Método PURO. Devuelve '' si no hay labels (el caller decide
     * el fallback). Los radios van sin name para que no posteen shipping_method.
     *
     * @param array<int,string> $labels
     */
    public static function render_placeholder_cards( array $labels ): string {
        $labels = array_values( array_filter( $labels, static function ( $l ) {
            return '' !== trim( (string) $l );
        } ) );
        if ( ! $labels ) {
            return '';
        }

        $html  = '<p class="ccmck-shipping-hint">' . esc_html__( 'Ingresa tu dirección para ver el costo', 'ccm-checkout' ) . '</p>';
        $html .= '<ul class="ccmck-shipping-list ccmck-shipping-list--disabled">';
        foreach ( $labels as $i => $label ) {
            $dom_id = 'ccmck_ship_ph_' . (int) $i;
            $html  .= '<li class="ccmck-shipping-method ccmck-shipping-method--disabled">';
            $html  .= '<input type="radio" class="shipping_method" id="' . esc_attr( $dom_id ) . '" disabled />';
            $html  .= '<label for="' . esc_attr( $dom_id ) . '">';
            $html  .= '<span class="ccmck-ship-label">' . esc_html( (string) $label ) . '</span>';
            $html  .= '</label></li>';
        }
        $html .= '</ul>';
        return $html;
    }

    /** Formatea el costo: wc_price() en producción, fallback predecible en tests. */
    private static function format_cost( float $cost ): string {
        if ( $cost <= 0 ) {
            return esc_html__( 'Gratis', 'ccm-checkout' );
        }
        if ( function_exists( 'wc_price' ) ) {
            return wp_kses_post( wc_price( $cost ) );
        }
        return '$' . number_format( $cost, 0, ',', '.' );
    }

    /** Render acoplado a WC para el template y el fragment. */
    public static function render(): string {
        if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->cart->needs_shipping() ) {
            return '';
        }
        $packages = WC()->shipping()->get_packages();
        $chosen   = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : array();
        $methods  = self::build_methods( $packages, $chosen );

        // ¿Hay alguna tarifa real cotizada? Entonces, cards normales.
        foreach ( $methods as $package ) {
            if ( ! empty( $package['rates'] ) ) {
                return self::render_cards( $methods );
            }
        }

        // Sin dirección que WC pueda cotizar: cards deshabilitadas con los
        // métodos que ofrecen las Zonas de Envío. Si no hay ninguno, fallback.
        $placeholder = self::render_placeholder_cards( self::get_zone_method_labels() );
        return '' !== $placeholder ? $placeholder : self::render_cards( array() );
    }

    /**
     * Inyecta el contenedor de envío como fragment para que el AJAX
     * update_order_review de WooCommerce lo refresque automáticamente.
     */
    public static function fragments( $fragments ): array {
        $fragments = is_array( $fragments ) ? $fragments : array();
        $fragments[ '#' . self::CONTAINER_ID ] =
            '<div id="' . self::CONTAINER_ID . '" class="ccmck-shipping-methods">' . self::render() . '</div>';
        return $fragments;
    }
}
