<?php
/**
 * CCM Checkout — desencolado de assets de terceros sobrantes en el checkout.
 *
 * El checkout clásico de ccmck arrastra ~180 scripts y ~64 styles del sitio,
 * la mayoría de Elementor (free + Pro), Essential Addons (EAEL), Fluent Forms
 * y sliders/menús que NO se usan en /pago/. Verificado en vivo (chrome-devtools):
 * el DOM del checkout tiene 0 widgets de Elementor, 0 EAEL, 0 lightslider,
 * 0 smartmenus y 0 fluentform, así que su JS/CSS es puro peso muerto.
 *
 * Se EXCLUYEN a propósito (NO se tocan):
 *  - Tema Hello (hello-*): renderiza el shell de la página.
 *  - Clúster wc-blocks + Addi (widget-addi*, wc-addi-blocks-integration): Addi
 *    es pasarela de pago y su integración de bloques depende de wc-blocks.
 *  - font-awesome / um-fontawesome: iconos del header/footer.
 *  - nta-*-popup: popup de WhatsApp.
 *
 * @package CCM_Checkout
 */

defined( 'ABSPATH' ) || exit;

final class CCMCK_Dequeue {
    public static function init(): void {
        // Prioridad alta para correr DESPUÉS de que todos hayan encolado.
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue' ), 100 );
    }

    /**
     * Handles a retirar del checkout. Filtrable vía `ccmck_dequeue_handles`.
     *
     * @return array{scripts:string[],styles:string[]}
     */
    public static function handles(): array {
        $handles = array(
            'scripts' => array(
                // Elementor Pro
                'elementor-pro-webpack-runtime',
                'elementor-pro-frontend',
                'page-transitions',
                // Elementor (free)
                'elementor-webpack-runtime',
                'elementor-frontend-modules',
                'elementor-frontend',
                // Essential Addons for Elementor
                'eael-general',
                'eael-17699',
                'eael-18476',
                // Fluent Forms widget de Elementor
                'fluentform-elementor',
                // Menús / sliders
                'smartmenus',
                'lightslider',
            ),
            'styles' => array(
                // Elementor (free)
                'elementor-frontend',
                'e-animation-fadeInRight',
                'e-popup',
                'widget-nav-menu',
                // Essential Addons
                'eael-general',
                // Fluent Forms widget de Elementor
                'fluentform-elementor-widget',
                // Slider
                'lightslider',
            ),
        );

        return apply_filters( 'ccmck_dequeue_handles', $handles );
    }

    /**
     * Desencola (y desregistra) los handles sobrantes solo en el checkout.
     */
    public static function dequeue(): void {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }

        $handles = self::handles();

        foreach ( $handles['scripts'] as $handle ) {
            wp_dequeue_script( $handle );
            wp_deregister_script( $handle );
        }

        foreach ( $handles['styles'] as $handle ) {
            wp_dequeue_style( $handle );
            wp_deregister_style( $handle );
        }
    }
}
